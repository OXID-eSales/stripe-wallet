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
        [
            'field' => 'additionalInfo',
            'rules' => [
                'allow' => "LETTERS NUMBERS SPACES ' - . , / #",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'street',
            'rules' => [
                'allow' => "LETTERS NUMBERS SPACES ' - . , /",
                'block' => ': ; < > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'houseNumber',
            'rules' => [
                'allow' => 'NUMBERS LETTERS - /',
            ],
        ],
        [
            'field' => 'postalCode',
            'rules' => [
                'allow' => 'LETTERS NUMBERS SPACES -',
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
                'allow' => "LETTERS NUMBERS SPACES ' - . & ,",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'vatId',
            'rules' => [
                'allow' => 'LETTERS NUMBERS SPACES -',
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
