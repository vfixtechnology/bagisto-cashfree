# 💳 Cashfree Payment Gateway for Bagisto

[![Latest Version](https://img.shields.io/packagist/v/vfixtechnology/bagisto-cashfree.svg?style=flat-square)](https://packagist.org/packages/vfixtechnology/bagisto-cashfree)
[![Total Downloads](https://img.shields.io/packagist/dt/vfixtechnology/bagisto-cashfree.svg?style=flat-square)](https://packagist.org/packages/vfixtechnology/bagisto-cashfree)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

> A modern Cashfree payment gateway integration for Bagisto with secure webhook-based order creation.

---

## ✨ Features

- 💳 Cashfree Checkout support for **Sandbox** and **Live** environments
- 🔁 **Automatic order creation via webhook** — even if the customer never returns to the store
- 🔐 HMAC signature verification for every webhook request
- ♻️ Idempotent order creation — no duplicate orders from redirect + webhook overlaps
- 🧾 Automatic invoice generation after successful payment
- 🏷️ Cart mapping via Cashfree `order_tags`
- 🛡️ Server-side payment status verification with the Cashfree API
- 🧩 Supports both modern JSON webhooks and legacy form-encoded webhooks
- 📦 Bagisto native order, invoice, and payment records

---

## 📋 Requirements

- 🛒 Bagisto **2.x**
- 🐘 PHP **8.1+**
- 🔌 PHP cURL extension
- 🔑 Cashfree merchant account with API credentials

---

## 🚀 Installation

The package ships with its own service provider, payment method configuration, routes, and webhook handler, so once Composer has downloaded it you only need to register the provider and enable the payment method from the Bagisto admin panel.

> 🛠️ **Need help with installation?** Paid installation support is available.
>
> 📞 Call: [+91 8447 525 204](tel:+918447525204)  
> 💬 WhatsApp: [+91 8447 525 204](https://wa.me/918447525204)

### 1️⃣ Install via Composer

```bash
composer require vfixtechnology/bagisto-cashfree
```

### 2️⃣ Register the service provider

Add the provider to `bootstrap/providers.php`:

```php
Vfixtechnology\Cashfree\Providers\CashfreeServiceProvider::class,
```

### 3️⃣ Clear the configuration cache

```bash
php artisan optimize:clear
```

### 4️⃣ Enable the payment method

Go to **Admin Panel → Configure → Sales → Payment Methods → Cashfree**.

---

## ⚙️ Configuration

Get your credentials from the Cashfree dashboard:

**Cashfree Dashboard → Developers → API Keys**

| Setting | Description |
| --- | --- |
| 🏷️ **Title** | Payment method name shown at checkout |
| 🌐 **Website Status** | `Sandbox` for testing, `Live` for production |
| 🔑 **Client ID** | Cashfree App ID |
| 🔒 **Secret Key** | Cashfree Secret Key |
| 🔗 **Webhook URL** | Optional. Leave blank to use `/cashfree/webhook` |
| ✅ **Status** | Enable or disable the payment method |

---

## 🔔 Webhook Setup (Recommended)

The webhook is the most reliable way to create the Bagisto order. It runs server-to-server, so the order is still created when:

- 🚪 The customer closes the browser after paying
- 📴 The connection drops during the redirect back to the store
- 📱 The customer switches to a UPI app and never returns
- ⏳ The return URL arrives before Cashfree finishes processing the payment

### Configure it in Cashfree

1. Go to **Cashfree Dashboard → Payment Gateway → Developers → Webhook Configuration**.
2. Add your endpoint:

```text
https://your-store.com/cashfree/webhook
```

3. Subscribe to the **Payment Success** event:

```text
PAYMENT_SUCCESS_WEBHOOK
```

4. Save the configuration.

> 💡 The webhook URL is also sent automatically in the Cashfree order's `notify_url`. In production, Cashfree requires an **HTTPS** URL.

### How the webhook works

```text
Customer pays
      │
      ├── ▶ Return URL  ──┐
      │                   ├── ▶ Verify payment ── ▶ Create order (once)
      └── ▶ Webhook ──────┘
```

- 🔐 Verifies the `x-webhook-signature` header using your Cashfree secret
- 🛒 Resolves the Bagisto cart from `order_tags.cart_id` or the Cashfree order id
- 💰 Confirms the payment status with the Cashfree API
- 📦 Creates the order and invoice inside a database transaction
- ♻️ Skips creation if an order already exists for that cart
- 🧾 Supports legacy form-encoded webhooks by confirming the order with Cashfree before creating anything

---

## ↩️ Return URL

The package automatically sends its return URL to Cashfree in the order's `return_url`:

```text
https://your-store.com/cashfree/callback
```

You do **not** need to configure this manually. After payment, Cashfree redirects the customer to this URL, where the package verifies the payment and shows the success page.

> 💡 The return URL can fail if the customer closes the browser or loses connection — that is why the webhook is recommended as the primary order-creation path. If both fire, the order is still created only once.

---

## 🔄 Payment Flow

1. 🛍️ Customer selects **Cashfree** at checkout.
2. 🌐 Bagisto creates a Cashfree order and redirects the customer to the payment page.
3. ✅ After payment, Cashfree calls both the **return URL** and the **webhook**.
4. 🔍 The package verifies the payment and creates the Bagisto order exactly once.
5. 🎉 The customer is redirected to the success page, or the order is created silently by the webhook.

---

## 🧪 Sandbox Testing

1. Set **Website Status** to `Sandbox`.
2. Use your sandbox **Client ID** and **Secret Key**.
3. Add your webhook URL in the Cashfree sandbox dashboard.
4. For local development, expose your store with a tunnel such as **ngrok** and use the HTTPS URL as the webhook URL.

---

## 🛠️ Troubleshooting

| Problem | Solution |
| --- | --- |
| ❌ Webhook does not create an order | Check the webhook URL, event selection, and Cashfree secret |
| 🔐 Signature verification fails | Ensure the **Secret Key** matches the Cashfree environment |
| 🌐 Webhook is not reachable | Use a public HTTPS URL; localhost is not accessible from Cashfree |
| ♻️ Duplicate order concern | Orders are created only once per cart, even if both redirect and webhook fire |
| ⚙️ Settings not updating | Run `php artisan optimize:clear` |

Logs are written to `storage/logs/laravel.log` with the `Cashfree` prefix.

---

## 🛠️ Paid Installation Support

Need Cashfree installed, configured, or customized for your Bagisto store? Vfix Technology provides paid installation and customization support.

- 📞 **Call:** [+91 8447 525 204](tel:+918447525204)
- 💬 **WhatsApp:** [+91 8447 525 204](https://wa.me/918447525204)
- ✉️ **Email:** [info@vfixtechnology.com](mailto:info@vfixtechnology.com)

---

## 🤝 Support This Project

If this package helps your store, please consider:

- ⭐ Starring the repository
- 📣 Sharing it with your developer community
- 🐛 Reporting issues you encounter

Your support helps keep this project maintained and improving for everyone. ❤️

---

## 📬 Contact

For help, paid installation, or customization:

- 🌐 Website: [vfixtechnology.com](https://www.vfixtechnology.com)
- ✉️ Email: [info@vfixtechnology.com](mailto:info@vfixtechnology.com)
- 📞 Phone: [+91 8447 525 204](tel:+918447525204)
- 💬 WhatsApp: [+91 8447 525 204](https://wa.me/918447525204)

---

<p align="center">Made with ❤️ by <strong>Vfix Technology</strong></p>
