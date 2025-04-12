# Cashfree Payment Gateway Integration for Bagisto by Vfix Technology

This package provides a seamless integration of Cashfree payment gateway with Bagisto applications.

## Installation

1. Install the package via Composer:

```php
composer require vfixtechnology/bagisto-cashfree
```

2. Register the Cashfree service provider in Bootstrap/providers.php:
 ```php
  Vfixtechnology\Cashfree\Providers\CashfreeServiceProvider::class,
 ```

3. Navigate to your admin panel:
Go to Configure/Payment Methods
Cashfree will appear at the end of the payment method list


4. Clear your configuration cache:
```php
php artisan config:cache
```

## Support This Project

If you find this package useful, please consider showing your support by:

⭐ Giving the repository a star on GitHub  
📣 Sharing it with your developer community  
🐛 Reporting any issues you encounter  

Your support helps maintain and improve this project for everyone.

#### For any help or customization, visit https://www.vfixtechnology.com or email us info@vfixtechnology.com
