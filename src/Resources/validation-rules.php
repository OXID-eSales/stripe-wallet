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
    ],
];
