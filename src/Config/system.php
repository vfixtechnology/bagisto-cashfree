<?php

return [
    [
        'key'    => 'sales.payment_methods.cashfree',
        'name'   => 'Cashfree',
        'info' => 'Cashfree extension created for Bagisto by <a href="https://www.vfixtechnology.com" target="_blank" style="color: blue;">Vfix Technology</a>.',
        'sort'   => 4,
        'fields' => [
            [
                'name'          => 'title',
                'title'         => 'Cashfree Payment Gateway',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
            [
                'name'          => 'description',
                'title'         => '',
                'type'          => 'textarea',
                'channel_based' => false,
                'locale_based'  => true,
            ],
            [
                'name'          => 'image',
                'title'         => 'Logo',
                'type'          => 'image',
                'channel_based' => false,
                'locale_based'  => false,
                'validation'    => 'mimes:bmp,jpeg,jpg,png,webp',
            ],
            [
                'name'          => 'key_id',
                'title'         => 'key id',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
			[
                'name'          => 'secret',
                'title'         => 'key secret',
                'type'          => 'text',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ],
			[
                'name'    => 'website',
                'title'   => 'Website Status',
                'type'    => 'select',
                'validation'    => 'required',
                'options' => [
                    [
                        'title' => 'Staging',
                        'value' => 'sandbox',
                    ], [
                        'title' => 'Live',
                        'value' => 'production',
                    ],
                ],
            ],
            [
                'name'          => 'active',
                'title'         => 'Status',
                'type'          => 'boolean',
                'validation'    => 'required',
                'channel_based' => false,
                'locale_based'  => true,
            ]
        ]
    ]
];
