<?php

namespace Vfixtechnology\Cashfree\Payment;

use Webkul\Payment\Payment\Payment;
use Illuminate\Support\Facades\Storage;

class Cashfree extends Payment
{
    /**
     * Payment method code
     *
     * @var string
     */
    protected $code  = 'cashfree';

    public function getRedirectUrl()
    {
        return route('cashfree.redirect');
    }

    public function isAvailable()
    {
        if (!$this->cart) {
            $this->setCart();
        }

        return $this->getConfigData('active') && $this->cart?->haveStockableItems();
    }

    public function getImage()
    {
        $url = $this->getConfigData('image');

        return $url ? Storage::url($url) : '';

    }
}
