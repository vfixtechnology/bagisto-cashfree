<?php

namespace Vfixtechnology\Cashfree\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Sales\Contracts\Order as OrderContract;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

class CashfreeController extends Controller
{
    protected $orderRepository;

    protected $invoiceRepository;

    protected $cartRepository;

    public function __construct(
        OrderRepository $orderRepository,
        InvoiceRepository $invoiceRepository,
        CartRepository $cartRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->cartRepository = $cartRepository;
    }

    public function redirect(Request $request)
    {
        $cart = Cart::getCart();

        if (! $cart) {
            session()->flash('error', 'Your cart is empty.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $billingAddress = $cart->billing_address;

        if (! $billingAddress) {
            session()->flash('error', 'Billing address is missing. Please complete the checkout again.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $totalAmount = $this->getCartTotal($cart);

        // Sanitize phone number: extract only digits
        $rawPhone = $billingAddress->phone;
        $sanitizedPhone = preg_replace('/\D/', '', $rawPhone);
        $phone = substr($sanitizedPhone, -10);

        // Validate phone number
        if (strlen($phone) < 10) {
            session()->flash('error', 'Invalid phone number. Please enter a valid 10-digit mobile number.');

            return redirect()->route('shop.checkout.cart.index');
        }

        // orderId and customerId are both required when sending a request to Cashfree
        $orderId = 'order_'.$cart->id.'_'.time().'_'.mt_rand(100, 999);

        $customer = auth()->guard('customer')->user();

        $customerId = $customer
            ? 'cust_'.$customer->id
            : 'guest_'.$phone;

        // get status of api stagging or live
        $env = $this->getConfig('sales.payment_methods.cashfree.website');

        $url = $this->getBaseUrl().'/orders';

        $headers = $this->getHeaders();

        $orderMeta = [
            'return_url' => route('cashfree.success').'?order_id={order_id}&order_token={order_token}',
        ];

        $notifyUrl = $this->getConfig('sales.payment_methods.cashfree.webhook_url')
            ?: route('cashfree.webhook');

        /**
         * Cashfree only accepts an HTTPS notify URL in production. In sandbox HTTP is
         * allowed, so it is passed as-is while testing.
         */
        if ($env === 'sandbox' || str_starts_with($notifyUrl, 'https://')) {
            $orderMeta['notify_url'] = $notifyUrl;
        }

        $data = json_encode([
            'order_id' => $orderId,
            'order_amount' => $totalAmount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => $customerId,
                'customer_name' => trim($billingAddress->first_name.' '.$billingAddress->last_name),
                'customer_email' => $billingAddress->email,
                'customer_phone' => $phone,
            ],
            'order_meta' => $orderMeta,
            'order_tags' => [
                'cart_id' => (string) $cart->id,
            ],
        ]);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($curl);

        $curlError = curl_error($curl);

        curl_close($curl);

        $responseData = json_decode($response);

        if (! $responseData || empty($responseData->payment_link)) {
            Log::error('Cashfree redirect failed: '.($curlError ?: $response));

            session()->flash('error', 'Unable to initiate payment. Please try again.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $request->session()->put('cashfree_order_id', $orderId);
        $request->session()->put('cashfree_cart_id', $cart->id);
        $request->session()->put('cashfree_order_amount', $totalAmount);

        return redirect()->to($responseData->payment_link);
    }

    public function verify(Request $request)
    {
        $orderId = $request->input('order_id');

        if (! $orderId) {
            session()->flash('error', 'Payment verification failed: Missing order ID');

            return redirect()->route('shop.checkout.cart.index');
        }

        $orderCartId = $this->extractCartId($orderId);

        $sessionCartId = session()->get('cashfree_cart_id');

        /**
         * Never trust an order id for a different cart than the one in this session.
         * This stops one customer from replaying another customer's paid order id.
         */
        if ($orderCartId && $sessionCartId && (int) $orderCartId !== (int) $sessionCartId) {
            Log::warning('Cashfree verify: order id does not belong to the session cart.', [
                'order_id' => $orderId,
                'order_cart_id' => $orderCartId,
                'session_cart_id' => $sessionCartId,
            ]);

            session()->flash('error', 'Payment verification failed. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $cartId = $orderCartId ?: $sessionCartId;

        // Construct the verification URL dynamically based on the environment setting
        $url = $this->getBaseUrl().'/orders/'.$orderId;

        // Set the headers with correct API credentials dynamically from core config
        $headers = $this->getHeaders();

        // Poll Cashfree until we reach a definitive status.
        // This handles async methods (UPI) where the payment is captured a few seconds
        // after the customer is redirected back to the store.
        $responseData = $this->fetchOrderStatus($url, $headers);

        if (! $responseData) {
            session()->flash('error', 'Payment verification failed. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $orderStatus = $responseData->order_status ?? null;

        // Definitive failure states — do not retry.
        if (in_array($orderStatus, ['EXPIRED', 'CANCELLED'], true)) {
            session()->flash('error', 'Payment failed or cancelled.');

            return redirect()->route('shop.checkout.cart.index');
        }

        // Not yet PAID after all polling attempts.
        if ($orderStatus !== 'PAID') {
            session()->flash('error', 'Payment is still being processed. Please check your order status shortly.');

            return redirect()->route('shop.checkout.cart.index');
        }

        // --- Payment confirmed as PAID ---

        // Idempotency: the callback URL can be hit more than once (manual refresh,
        // Cashfree retry, or a second tab). If an order already exists for this
        // cart, never create a duplicate.
        if ($cartId && $existingOrder = $this->orderRepository->findOneWhere(['cart_id' => $cartId])) {
            session()->flash('order_id', $existingOrder->id);

            return redirect()->route('shop.checkout.onepage.success');
        }

        // Session may have been lost or cart already deactivated by a parallel
        // callback. Recover the cart from the persisted cart id.
        $cart = Cart::getCart();

        if ((! $cart || $cart->id != $cartId) && $cartId) {
            $cart = $this->cartRepository->find($cartId);

            if ($cart) {
                Cart::setCart($cart);
            }
        }

        if (! $cart) {
            session()->flash('error', 'Cart not found. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $this->logAmountMismatch($responseData->order_amount ?? null, $cart, $orderId);

        try {
            $order = $this->createOrderForCart($cart, [
                'cashfree_order_id' => $orderId,
                'cashfree_cf_order_id' => (string) ($responseData->cf_order_id ?? ''),
                'cashfree_order_status' => (string) $orderStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('Cashfree verify error: '.$e->getMessage());

            session()->flash('error', 'We could not confirm your order. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        if (! $order) {
            if (session()->has('order_id')) {
                return redirect()->route('shop.checkout.onepage.success');
            }

            session()->flash('error', 'We could not confirm your order. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $this->forgetCartFromSession($cart);

        session()->flash('order_id', $order->id);

        return redirect()->route('shop.checkout.onepage.success');
    }

    /**
     * Cashfree server-to-server webhook.
     *
     * This is what makes the order get created even when the customer never
     * returns to the store (closed browser, lost connection, UPI app switch).
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->hasValidWebhookSignature($request, $rawBody)) {
            /**
             * Older Cashfree accounts/webhook versions post a form-encoded payload
             * with an `orderId` field instead of the signed JSON body. Accept it
             * only when Cashfree's Get Order API confirms that the order is paid.
             */
            $legacyOrderId = (string) $request->input('orderId', '');

            if ($legacyOrderId !== '') {
                return $this->handleLegacyWebhook($legacyOrderId);
            }

            Log::warning('Cashfree webhook: invalid signature.');

            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $type = (string) ($payload['type'] ?? '');
        $data = $payload['data'] ?? [];

        $cashfreeOrderId = (string) data_get($data, 'order.order_id', '');
        $orderStatus = (string) data_get($data, 'order.order_status', '');
        $paymentStatus = (string) data_get($data, 'payment.payment_status', '');

        $isPaid = $type === 'PAYMENT_SUCCESS_WEBHOOK'
            || $orderStatus === 'PAID'
            || $paymentStatus === 'SUCCESS';

        if (! $isPaid) {
            Log::info('Cashfree webhook ignored.', [
                'type' => $type,
                'order_id' => $cashfreeOrderId,
                'order_status' => $orderStatus,
                'payment_status' => $paymentStatus,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        if (! $cashfreeOrderId) {
            return response()->json(['status' => 'missing_order_id'], 400);
        }

        $cartId = (int) data_get($data, 'order.order_tags.cart_id', 0);

        $paidAmount = data_get(
            $data,
            'payment.payment_amount',
            data_get($data, 'order.order_amount')
        );

        $paymentAdditional = array_filter([
            'cashfree_order_id' => $cashfreeOrderId,
            'cashfree_payment_id' => (string) data_get($data, 'payment.cf_payment_id', ''),
            'cashfree_payment_status' => $paymentStatus,
            'cashfree_payment_group' => (string) data_get($data, 'payment.payment_group', ''),
            'cashfree_bank_reference' => (string) data_get($data, 'payment.bank_reference', ''),
            'cashfree_webhook_type' => $type,
        ], fn ($value) => $value !== '');

        try {
            $order = $this->createOrderFromCashfreeOrder(
                $cashfreeOrderId,
                $cartId ?: null,
                $paymentAdditional,
                $paidAmount !== null ? (float) $paidAmount : null
            );
        } catch (\Exception $e) {
            Log::error('Cashfree webhook order creation failed: '.$e->getMessage(), [
                'order_id' => $cashfreeOrderId,
            ]);

            // Let Cashfree retry later.
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json([
            'status' => $order ? 'order_placed' : 'order_not_placed',
            'order_id' => $order?->id,
        ]);
    }

    /**
     * Handles a legacy (form-encoded) Cashfree webhook by confirming the order
     * status server-side before creating anything.
     */
    protected function handleLegacyWebhook(string $cashfreeOrderId): JsonResponse
    {
        $responseData = $this->fetchOrderStatus(
            $this->getBaseUrl().'/orders/'.urlencode($cashfreeOrderId),
            $this->getHeaders(),
            1,
            0
        );

        if (! $responseData || ($responseData->order_status ?? null) !== 'PAID') {
            Log::info('Cashfree legacy webhook ignored: order not confirmed as paid.', [
                'order_id' => $cashfreeOrderId,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $paymentAdditional = array_filter([
            'cashfree_order_id' => $cashfreeOrderId,
            'cashfree_cf_order_id' => (string) ($responseData->cf_order_id ?? ''),
            'cashfree_order_status' => (string) $responseData->order_status,
            'cashfree_reference_id' => (string) request()->input('referenceId', ''),
            'cashfree_webhook_type' => 'legacy',
        ], fn ($value) => $value !== '');

        try {
            $order = $this->createOrderFromCashfreeOrder(
                $cashfreeOrderId,
                $this->extractCartId($cashfreeOrderId),
                $paymentAdditional,
                isset($responseData->order_amount) ? (float) $responseData->order_amount : null
            );
        } catch (\Exception $e) {
            Log::error('Cashfree legacy webhook order creation failed: '.$e->getMessage(), [
                'order_id' => $cashfreeOrderId,
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json([
            'status' => $order ? 'order_placed' : 'order_not_placed',
            'order_id' => $order?->id,
        ]);
    }

    /**
     * Creates the Bagisto order for a paid Cashfree order, exactly once.
     */
    protected function createOrderFromCashfreeOrder(
        string $cashfreeOrderId,
        ?int $cartId,
        array $paymentAdditional = [],
        ?float $paidAmount = null
    ): ?OrderContract {
        $cartId = $cartId ?: $this->extractCartId($cashfreeOrderId);

        if (! $cartId) {
            Log::error('Cashfree: unable to resolve cart from order id.', [
                'cashfree_order_id' => $cashfreeOrderId,
            ]);

            return null;
        }

        if ($existingOrder = $this->orderRepository->findOneWhere(['cart_id' => $cartId])) {
            return $existingOrder;
        }

        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            Log::error('Cashfree: cart not found for paid order.', [
                'cart_id' => $cartId,
                'cashfree_order_id' => $cashfreeOrderId,
            ]);

            return null;
        }

        $this->logAmountMismatch($paidAmount, $cart, $cashfreeOrderId);

        $order = $this->createOrderForCart($cart, $paymentAdditional);

        $this->forgetCartFromSession($cart);

        return $order;
    }

    /**
     * Atomically claims the cart and creates the order/invoice.
     */
    protected function createOrderForCart($cart, array $paymentAdditional = []): ?OrderContract
    {
        return DB::transaction(function () use ($cart, $paymentAdditional) {
            // Serialize concurrent redirect + webhook callbacks for the same cart.
            $cart->newQuery()->whereKey($cart->getKey())->lockForUpdate()->first();

            if ($existingOrder = $this->orderRepository->findOneWhere(['cart_id' => $cart->id])) {
                return $existingOrder;
            }

            $cart->newQuery()->whereKey($cart->getKey())->update([
                'is_active' => 0,
            ]);

            Cart::setCart($cart);

            if ($cart->cart_currency_code) {
                core()->setCurrentCurrency($cart->cart_currency_code);
            }

            $data = (new OrderResource($cart))->jsonSerialize();

            if ($paymentAdditional) {
                $data['payment']['additional'] = array_merge(
                    (array) ($data['payment']['additional'] ?? []),
                    $paymentAdditional
                );
            }

            $order = $this->orderRepository->create($data);

            $this->orderRepository->update(['status' => 'processing'], $order->id);

            // Create an invoice if possible
            if ($order->canInvoice()) {
                $this->invoiceRepository->create($this->prepareInvoiceData($order));
            }

            return $order;
        });
    }

    protected function forgetCartFromSession($cart): void
    {
        if (
            session()->has('cart')
            && data_get(session('cart'), 'id') == $cart->id
        ) {
            session()->forget('cart');
        }

        if (Cart::getCart() && Cart::getCart()->id == $cart->id) {
            Cart::resetCart();
        }
    }

    protected function extractCartId(?string $cashfreeOrderId): ?int
    {
        if (! $cashfreeOrderId) {
            return null;
        }

        if (preg_match('/^order_(\d+)_/', $cashfreeOrderId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function getCartTotal($cart): float
    {
        $shippingRate = $cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0;

        return round(
            ((float) $cart->sub_total + (float) $cart->tax_total + (float) $shippingRate) - (float) $cart->discount_amount,
            2
        );
    }

    protected function logAmountMismatch($paidAmount, $cart, string $cashfreeOrderId): void
    {
        if ($paidAmount === null || $paidAmount === '') {
            return;
        }

        $expectedAmount = $this->getCartTotal($cart);

        if (abs((float) $paidAmount - $expectedAmount) > 0.01) {
            Log::warning('Cashfree: paid amount differs from the cart total.', [
                'cashfree_order_id' => $cashfreeOrderId,
                'cart_id' => $cart->id,
                'paid_amount' => (float) $paidAmount,
                'cart_total' => $expectedAmount,
            ]);
        }
    }

    protected function getConfig(string $field): mixed
    {
        $value = core()->getConfigData($field);

        if ($value !== null && $value !== '') {
            return $value;
        }

        /**
         * Webhook requests have no storefront locale in the URL/session, so fall
         * back to the default locale when the active one has no saved value.
         */
        try {
            return core()->getConfigData($field, null, core()->getDefaultLocaleCodeFromDefaultChannel());
        } catch (\Throwable $e) {
            return $value;
        }
    }

    protected function getBaseUrl(): string
    {
        return $this->getConfig('sales.payment_methods.cashfree.website') === 'sandbox'
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-version: 2022-01-01',
            'x-client-id: '.$this->getConfig('sales.payment_methods.cashfree.key_id'),
            'x-client-secret: '.$this->getConfig('sales.payment_methods.cashfree.secret'),
        ];
    }

    protected function hasValidWebhookSignature(Request $request, string $rawBody): bool
    {
        $secret = (string) $this->getConfig('sales.payment_methods.cashfree.secret');

        $signature = (string) $request->header('x-webhook-signature', '');

        $timestamp = (string) $request->header('x-webhook-timestamp', '');

        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $timestamp.$rawBody, $secret, true)
        );

        return hash_equals($expectedSignature, $signature);
    }

    protected function prepareInvoiceData($order)
    {
        $invoiceData = ['order_id' => $order->id];

        foreach ($order->items as $item) {
            $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }

        return $invoiceData;
    }

    protected function fetchOrderStatus(string $url, array $headers, int $attempts = 6, int $delaySeconds = 2): ?object
    {
        $responseData = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($curl);

            $curlError = curl_error($curl);

            curl_close($curl);

            if ($response) {
                $responseData = json_decode($response);

                $orderStatus = $responseData->order_status ?? null;

                // Definitive statuses — no need to keep polling.
                if (in_array($orderStatus, ['PAID', 'EXPIRED', 'CANCELLED'], true)) {
                    return $responseData;
                }
            } elseif ($curlError) {
                Log::error('Cashfree verify curl error: '.$curlError);
            }

            if ($attempt < $attempts) {
                sleep($delaySeconds);
            }
        }

        return $responseData;
    }
}
