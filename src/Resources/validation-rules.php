<?php

declare(strict_types=1);

return [
    'fields' => [
        [
            'field' => 'firstName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'lastName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        // Sprint 124 (STRP-129): the six address fields (additionalInfo, street,
        // houseNumber, postalCode, company, vatId) use UNICODE_LETTERS (\p{L}),
        // not ASCII-only LETTERS, so German umlauts (öäüß) and Polish letters
        // (ł ą ę …) pass. Block lists are unchanged; injection surface unchanged.
        [
            'field' => 'additionalInfo',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / #",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'street',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , /",
                'block' => ': ; < > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'houseNumber',
            'rules' => [
                'allow' => 'NUMBERS UNICODE_LETTERS - /',
            ],
        ],
        [
            'field' => 'postalCode',
            'rules' => [
                'allow' => 'UNICODE_LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'city',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'company',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . & ,",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'vatId',
            'rules' => [
                'allow' => 'UNICODE_LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'phone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'cellPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'personalPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'fax',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        // Sprint 120 (STRP-129): admin Payment-tab capture-reason — outbound free
        // text into Stripe PaymentIntent capture metadata. UNICODE_LETTERS so
        // German umlauts pass; block set mirrors additionalInfo plus ".
        [
            'field' => 'captureReason',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "',
            ],
        ],
        // Sprint 121 (STRP-129): admin refund description — POST-reachable
        // free text into Stripe refund metadata (not present in the panel
        // form; the controller forwards $_POST wholesale).
        [
            'field' => 'refundDescription',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "',
            ],
        ],
    ],
];
