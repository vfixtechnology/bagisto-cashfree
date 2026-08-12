<?php

namespace Vfixtechnology\Cashfree\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartProxy;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

class CashfreeController extends Controller
{
    protected $orderRepository;

    protected $invoiceRepository;

    public function __construct(OrderRepository $orderRepository, InvoiceRepository $invoiceRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
    }

    public function redirect(Request $request)
    {
        $cart = Cart::getCart();

        if (! $cart) {
            session()->flash('error', 'Your cart is empty.');

            return redirect()->route('shop.checkout.cart.index');
        }

        $billingAddress = $cart->billing_address;

        $shipping_rate = $cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0;
        $discount_amount = $cart->discount_amount;
        $total_amount = ($cart->sub_total + $cart->tax_total + $shipping_rate) - $discount_amount;

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
        $env = core()->getConfigData('sales.payment_methods.cashfree.website');

        $url = $env === 'sandbox'
            ? 'https://sandbox.cashfree.com/pg/orders'
            : 'https://api.cashfree.com/pg/orders';

        $headers = [
            'Content-Type: application/json',
            'x-api-version: 2022-01-01',
            'x-client-id: '.core()->getConfigData('sales.payment_methods.cashfree.key_id'),
            'x-client-secret: '.core()->getConfigData('sales.payment_methods.cashfree.secret'),
        ];

        $data = json_encode([
            'order_id' => $orderId,
            'order_amount' => $total_amount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => $customerId,
                'customer_name' => trim($billingAddress->first_name.' '.$billingAddress->last_name),
                'customer_email' => $billingAddress->email,
                'customer_phone' => $phone,
            ],
            'order_meta' => [
                'return_url' => route('cashfree.success').'?order_id={order_id}&order_token={order_token}',
            ],
        ]);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

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

        return redirect()->to($responseData->payment_link);
    }

    public function verify(Request $request)
    {
        $orderId = $request->input('order_id');

        if (! $orderId) {
            session()->flash('error', 'Payment verification failed: Missing order ID');

            return redirect()->route('shop.checkout.cart.index');
        }

        // Fetch the environment setting for website status (sandbox/production) dynamically
        $env = core()->getConfigData('sales.payment_methods.cashfree.website');

        // Construct the verification URL dynamically based on the environment setting
        $url = ($env === 'sandbox'
            ? 'https://sandbox.cashfree.com/pg/orders/'
            : 'https://api.cashfree.com/pg/orders/').$orderId;

        // Set the headers with correct API credentials dynamically from core config
        $headers = [
            'Content-Type: application/json',
            'x-api-version: 2022-01-01',
            'x-client-id: '.core()->getConfigData('sales.payment_methods.cashfree.key_id'),
            'x-client-secret: '.core()->getConfigData('sales.payment_methods.cashfree.secret'),
        ];

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
        $cartId = session()->get('cashfree_cart_id');

        if ($cartId && $existingOrder = $this->orderRepository->findOneWhere(['cart_id' => $cartId])) {
            session()->flash('order_id', $existingOrder->id);

            return redirect()->route('shop.checkout.onepage.success');
        }

        // Session may have been lost or cart already deactivated by a parallel
        // callback. Recover the cart from the persisted cart id.
        $cart = Cart::getCart();

        if (! $cart && $cartId) {
            $cart = CartProxy::find($cartId);

            if ($cart) {
                Cart::setCart($cart);
            }
        }

        if (! $cart) {
            session()->flash('error', 'Cart not found. Please contact support.');

            return redirect()->route('shop.checkout.cart.index');
        }

        try {
            $order = DB::transaction(function () use ($cart) {
                // Atomically claim the cart so only one concurrent callback can
                // create the order. A concurrent request will see is_active = 0
                // and bail out instead of creating a duplicate order.
                $claimed = DB::table('carts')
                    ->where('id', $cart->id)
                    ->where('is_active', 1)
                    ->update(['is_active' => 0]);

                if (! $claimed) {
                    $existing = $this->orderRepository->findOneWhere(['cart_id' => $cart->id]);

                    if ($existing) {
                        session()->flash('order_id', $existing->id);
                    }

                    return null;
                }

                $data = (new OrderResource($cart))->jsonSerialize();

                $order = $this->orderRepository->create($data);

                $this->orderRepository->update(['status' => 'processing'], $order->id);

                // Create an invoice if possible
                if ($order->canInvoice()) {
                    $this->invoiceRepository->create($this->prepareInvoiceData($order));
                }

                // Deactivate the cart and finalize the process
                Cart::deActivateCart();

                return $order;
            });
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

        session()->flash('order_id', $order->id);

        return redirect()->route('shop.checkout.onepage.success');
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