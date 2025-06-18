<?php

namespace Vfixtechnology\Cashfree\Http\Controllers;

use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Sales\Repositories\InvoiceRepository;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;


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
        $billingAddress = $cart->billing_address;

        $shipping_rate = $cart->selected_shipping_rate ? $cart->selected_shipping_rate->price : 0;
        $discount_amount = $cart->discount_amount;
        $total_amount = ($cart->sub_total + $cart->tax_total + $shipping_rate) - $discount_amount;

        // orderId and customerId are both required when sending a request to Cashfree
        $orderId = 'order_' . $cart->id . '_' . time();
        $customer = auth()->guard('customer')->user();

        $customerId = $customer
            ? 'cust_' . $customer->id
            : 'guest_' . $billingAddress->phone; // or phone

        // Sanitize phone number: extract only digits
        $rawPhone = $billingAddress->phone;
        $sanitizedPhone = preg_replace('/\D/', '', $rawPhone);
        $phone = substr($sanitizedPhone, -10);

        // Validate phone number
        if (strlen($phone) < 10) {
            session()->flash('error', 'Invalid phone number. Please enter a valid 10-digit mobile number.');
            return redirect()->route('shop.checkout.cart.index');
        }

        // get status of api stagging or live
        $env = core()->getConfigData('sales.payment_methods.cashfree.website');

        $url = $env === 'sandbox'
            ? "https://sandbox.cashfree.com/pg/orders"
            : "https://api.cashfree.com/pg/orders";

        $headers = [
            "Content-Type: application/json",
            "x-api-version: 2022-01-01",
            "x-client-id: " . core()->getConfigData('sales.payment_methods.cashfree.key_id'),
            "x-client-secret: " . core()->getConfigData('sales.payment_methods.cashfree.secret')
        ];


        $data = json_encode([
            'order_id' => $orderId,
            'order_amount' => $total_amount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => $customerId,
                'customer_name' => $billingAddress->name,
                'customer_email' => $billingAddress->email,
                'customer_phone' => $phone,
            ],
            'order_meta' => [
                'return_url' => route('cashfree.success') . '?order_id={order_id}&order_token={order_token}'
            ]
        ]);


        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $response = curl_exec($curl);
        //curl_close($curl);

        $responseData = json_decode($response);

        $request->session()->put('cashfree_order_id', $orderId);

        return redirect()->to($responseData->payment_link);
    }


    public function verify(Request $request)
    {
        $orderId = $request->input('order_id');

        if (!$orderId) {
            session()->flash('error', 'Payment verification failed: Missing order ID');
            return redirect()->route('shop.checkout.cart.index');
        }

        // Fetch the environment setting for website status (sandbox/production) dynamically
        $env = core()->getConfigData('sales.payment_methods.cashfree.website');

        // Construct the verification URL dynamically based on the environment setting
        $url = ($env === 'sandbox'
            ? "https://sandbox.cashfree.com/pg/orders/"
            : "https://api.cashfree.com/pg/orders/") . $orderId;

        // Set the headers with correct API credentials dynamically from core config
        $headers = [
            "Content-Type: application/json",
            "x-api-version: 2022-01-01",
            "x-client-id: " . core()->getConfigData('sales.payment_methods.cashfree.key_id'),
            "x-client-secret: " . core()->getConfigData('sales.payment_methods.cashfree.secret')
        ];

        // Send the request to Cashfree to verify the order status
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($curl);
        curl_close($curl);

        if (!$response) {
            session()->flash('error', 'Payment verification failed: ' . curl_error($curl));
            return redirect()->route('shop.checkout.cart.index');
        }

        // Decode the response to check the order status
        $responseData = json_decode($response);

        // Log the response to inspect if everything is correct
        // \Log::info('Cashfree Response: ', (array)$responseData);

        // Check if the order status is PAID
        if (isset($responseData->order_status) && $responseData->order_status === 'PAID') {
            // Get the cart data and process the order
            $cart = Cart::getCart();
            $data = (new OrderResource($cart))->jsonSerialize();
            $order = $this->orderRepository->create($data);
            $this->orderRepository->update(['status' => 'processing'], $order->id);

            // Create an invoice if possible
            if ($order->canInvoice()) {
                $this->invoiceRepository->create($this->prepareInvoiceData($order));
            }

            // Deactivate the cart and finalize the process
            Cart::deActivateCart();
            session()->flash('order_id', $order->id);
            return redirect()->route('shop.checkout.onepage.success');
        }

        session()->flash('error', 'Payment failed or cancelled.');
        return redirect()->route('shop.checkout.cart.index');
    }

    protected function prepareInvoiceData($order)
    {
        $invoiceData = ["order_id" => $order->id];

        foreach ($order->items as $item) {
            $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }

        return $invoiceData;
    }

}
