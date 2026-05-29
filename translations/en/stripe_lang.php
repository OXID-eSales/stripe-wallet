<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

$sLangName  = "English";

// -------------------------------
// RESOURCE IDENTIFIER = STRING
// -------------------------------
$aLang = [
    'charset'                                   => 'UTF-8',
    'STRIPE_LOCALE'                             => 'en_US',
    'STRIPE_SHIPPINGCOST'                       => "Shipping cost",
    'STRIPE_PAYMENTTYPE'                        => "Payment type",
    'STRIPE_WRAPPING'                           => "Gift wrapping",
    'STRIPE_GIFTCARD'                           => "Giftcard",
    'STRIPE_VOUCHER'                            => 'Voucher',
    'STRIPE_DISCOUNT'                           => 'Discount',
    'STRIPE_ROUNDINGCORRECTION'                 => 'Rounding correction',
    'STRIPE_SELECT_BANK'                        => 'Select bank',
    'STRIPE_PLEASE_SELECT'                      => '-- Please select --',
    'STRIPE_SOFORT_COUNTRY'                     => 'Country',
    'STRIPE_COUNTRY_AT'                         => 'Austria',
    'STRIPE_COUNTRY_BE'                         => 'Belgium',
    'STRIPE_COUNTRY_DE'                         => 'Germany',
    'STRIPE_COUNTRY_ES'                         => 'Spain',
    'STRIPE_COUNTRY_IT'                         => 'Italy',
    'STRIPE_COUNTRY_NL'                         => 'Netherlands',

    'STRIPE_ERROR_ORDER_NOT_FOUND'              => 'Order not found',
    'STRIPE_ERROR_TRANSACTIONID_NOT_FOUND'      => 'Transaction id not found',
    'STRIPE_ERROR_SOMETHING_WENT_WRONG'         => 'An unknown error occured',
    'STRIPE_ERROR_ORDER_CANCELED'               => 'Payment was canceled, please try again',
    'STRIPE_ERROR_ORDER_FAILED'                 => 'Payment failed, please try again',
    'STRIPE_SECOND_CHANCE_MAIL_SUBJECT'         => 'Completion of your order at',
    'STRIPE_ERROR_ORDER_CONFIG_PUBKEY'          => 'Please configure Stripe publishable key to use this payment method.',
    'STRIPE_WEBHOOK_CREATE_ERROR'               => 'The Webhook Endpoint could not be created.',
    'STRIPE_CREDIT_CARD'                        => 'Credit card',

    // Buy Now feature
    'STRIPE_BUY_NOW'                            => 'Buy Now',
    'STRIPE_BUY_NOW_HINT'                       => 'Fast checkout - Skip the cart and pay instantly',

    // Payment Element - Standard Checkout
    'OSC_STRIPE_CARD_PAYMENT'                   => 'Credit Card Payment',
    'OSC_STRIPE_PAYMENT_DESC'                   => 'Pay securely with your credit or debit card. Your payment information is encrypted and secure.',
    'OSC_STRIPE_CARD_DETAILS'                   => 'Card Details',
    'OSC_STRIPE_PAY_NOW'                        => 'Pay Now',
    'OSC_STRIPE_PROCESSING'                     => 'Processing',
    'OSC_STRIPE_PROCESSING_PAYMENT'             => 'Processing your payment. Please wait...',
    'OSC_STRIPE_SECURE_PAYMENT'                 => 'Secure payment powered by Stripe',

    // Error Messages - Payment Element
    'OSC_STRIPE_CONFIG_ERROR'                   => 'Stripe is not properly configured. Please contact support.',
    'OSC_STRIPE_INTENT_ERROR'                   => 'Unable to initialize payment. Please refresh the page and try again.',
    'OSC_STRIPE_UNEXPECTED_ERROR'               => 'An unexpected error occurred. Please try again.',
    'OSC_STRIPE_PAYMENT_FAILED'                 => 'Payment failed. Please check your card details and try again.',
    'OSC_STRIPE_PAYMENT_DECLINED'               => 'Your payment was declined. Please try a different card.',
    'OSC_STRIPE_INSUFFICIENT_FUNDS'             => 'Insufficient funds. Please check your card balance.',
    'OSC_STRIPE_CARD_ERROR'                     => 'There was an error processing your card. Please check your details.',
    'OSC_STRIPE_NETWORK_ERROR'                  => 'Network error. Please check your connection and try again.',

    // 3D Secure - Payment Element
    'OSC_STRIPE_3DS_TITLE'                      => 'Secure Payment Authentication',
    'OSC_STRIPE_3DS_INFO'                       => 'Your bank requires additional authentication to complete this payment.',
    'OSC_STRIPE_AUTHENTICATING'                 => 'Authenticating your payment...',
    'OSC_STRIPE_3DS_COMPLETE'                   => 'Authentication complete. Processing your order...',
    'OSC_STRIPE_3DS_FAILED'                     => 'Authentication failed. Please try again.',

    // Success Messages
    'OSC_STRIPE_PAYMENT_SUCCESS'                => 'Payment successful!',
    'OSC_STRIPE_ORDER_CREATED'                  => 'Your order has been created successfully.',

    // Transaction Details
    'OSC_STRIPE_TRANSACTION_ID'                 => 'Transaction ID',
    'OSC_STRIPE_PAYMENT_ID'                     => 'Payment ID',
    'OSC_STRIPE_CARD_BRAND'                     => 'Card Brand',
    'OSC_STRIPE_CARD_LAST4'                     => 'Card ending in',
    'OSC_STRIPE_PAYMENT_DATE'                   => 'Payment Date',
    'OSC_STRIPE_PAYMENT_AMOUNT'                 => 'Payment Amount',

    // Card Element Labels + Validation (Sprint 91)
    'OSC_STRIPE_CARD_NUMBER'                    => 'Card Number',
    'OSC_STRIPE_CARD_EXDATE'                    => 'Expiry Date',
    'OSC_STRIPE_CARD_CVC'                       => 'CVC',
    'OSC_STRIPE_CARD_NAME'                      => 'Cardholder Name',
    'OSC_STRIPE_ERROR_MISSING_NAME'             => 'Please enter the cardholder name.',
    'OSC_STRIPE_ERROR_MISSING_NUMBER'           => 'Please enter a valid card number.',
    'OSC_STRIPE_ERROR_MISSING_CVC'              => 'Please enter the CVC code.',
    'OSC_STRIPE_ERROR_MISSING_EXDATE'           => 'Please enter the expiry date.',
    'OSC_STRIPE_ERROR_INBOX'                    => 'Please check your input.',
    'OSC_STRIPE_UNKNOWN_ERROR'                  => 'An unknown error occurred. Please try again.',
    'OSC_STRIPE_AUTHORIZATION_DENIED_ERROR'     => 'Authorization denied. Please use a different payment method.',
    'OSC_STRIPE_VAULTING_VAULTED_PAYMENTS'      => 'Saved Payment Methods',
    'OSC_STRIPE_CONTINUE_TO_NEXT_STEP'          => 'Continue',

    // JavaScript Controller Translations
    'STRIPE_JS_AGB_REQUIRED'                    => 'Please accept the terms and conditions',
    'STRIPE_JS_CONFIG_ERROR'                    => 'Stripe configuration error. Please contact support.',
    'STRIPE_JS_INIT_FAILED'                     => 'Failed to initialize payment form. Please refresh the page.',
    'STRIPE_JS_PAYMENT_FAILED'                  => 'Payment processing failed',
    'STRIPE_JS_NOT_LOADED'                      => 'Stripe.js not loaded',
    'STRIPE_JS_KEY_NOT_CONFIGURED'              => 'Stripe publishable key not configured',
    'STRIPE_JS_CREATING_SESSION'                => 'Creating checkout session...',
    'STRIPE_JS_SESSION_FAILED'                  => 'Failed to create checkout session',
    'STRIPE_JS_SESSION_INVALID'                 => 'Invalid checkout session response',
    'STRIPE_JS_CONTROLLER_NOT_FOUND'            => 'Stripe payment controller not found. Please refresh the page.',
    'STRIPE_JS_FORM_NOT_READY'                  => 'Payment form not initialized. Please refresh the page.',
    'STRIPE_JS_PAYMENT_NOT_COMPLETED'           => 'Payment not completed',
    'STRIPE_JS_URL_NOT_CONFIGURED'              => 'Payment URL is not configured',
    'STRIPE_JS_INTENT_INVALID'                  => 'Invalid payment intent response',
    'STRIPE_JS_PROCESSING'                      => 'Processing...',

    // -----------------------------------------------------------------------
    // User-data field validation messages (STRP-129)
    // Single message lists the ALLOWED symbols: STRIPE_VALIDATION_FIELD_INVALID
    // %1$s = field label, %2$s = allowed-symbols description.
    // -----------------------------------------------------------------------
    'STRIPE_VALIDATION_FIELD_INVALID'                        => 'The %1$s field is not valid. Allowed symbols are: %2$s',
    'STRIPE_VALIDATION_UNDERSTAND'                           => 'Understand',

    // Character-class words used to render the allowed-symbols list.
    'STRIPE_VALIDATION_CLASS_LETTERS'                        => 'letters',
    'STRIPE_VALIDATION_CLASS_DIGITS'                         => 'digits',
    'STRIPE_VALIDATION_CLASS_SPACES'                         => 'spaces',

    // Field labels.
    'STRIPE_VALIDATION_LABEL_FIRSTNAME'                      => 'first name',
    'STRIPE_VALIDATION_LABEL_LASTNAME'                       => 'last name',
    'STRIPE_VALIDATION_LABEL_ADDITIONALINFO'                 => 'additional info',
    'STRIPE_VALIDATION_LABEL_STREET'                         => 'street',
    'STRIPE_VALIDATION_LABEL_HOUSENUMBER'                    => 'house number',
    'STRIPE_VALIDATION_LABEL_POSTALCODE'                     => 'postal code',
    'STRIPE_VALIDATION_LABEL_CITY'                           => 'city',
    'STRIPE_VALIDATION_LABEL_COMPANY'                        => 'company',
    'STRIPE_VALIDATION_LABEL_VATID'                          => 'VAT ID',
    'STRIPE_VALIDATION_LABEL_PHONE'                          => 'phone',
    'STRIPE_VALIDATION_LABEL_CELLPHONE'                      => 'cell phone',
    'STRIPE_VALIDATION_LABEL_PERSONALPHONE'                  => 'personal phone',
    'STRIPE_VALIDATION_LABEL_FAX'                            => 'fax',

    // Legacy per-field/per-code keys (no longer used by the formatter; kept for
    // backward compatibility — safe to remove once no consumer references them).
    'STRIPE_VALIDATION_GENERIC'                              => "The value entered for the %s field contains an invalid character: '%s'.",

    // firstName
    'STRIPE_VALIDATION_FIRSTNAME_BLOCKED_CHARACTER'          => "The first name may not contain the character '%s'.",
    'STRIPE_VALIDATION_FIRSTNAME_DISALLOWED_CHARACTER'       => "The first name may not contain the character '%s'.",
    'STRIPE_VALIDATION_FIRSTNAME_CONTROL_CHARACTER'          => 'The first name contains an invisible or control character. Please re-type the value.',

    // lastName
    'STRIPE_VALIDATION_LASTNAME_BLOCKED_CHARACTER'           => "The last name may not contain the character '%s'.",
    'STRIPE_VALIDATION_LASTNAME_DISALLOWED_CHARACTER'        => "The last name may not contain the character '%s'.",
    'STRIPE_VALIDATION_LASTNAME_CONTROL_CHARACTER'           => 'The last name contains an invisible or control character. Please re-type the value.',

    // additionalInfo
    'STRIPE_VALIDATION_ADDITIONALINFO_BLOCKED_CHARACTER'     => "The additional info may not contain the character '%s'.",
    'STRIPE_VALIDATION_ADDITIONALINFO_DISALLOWED_CHARACTER'  => "The additional info may not contain the character '%s'.",
    'STRIPE_VALIDATION_ADDITIONALINFO_CONTROL_CHARACTER'     => 'The additional info contains an invisible or control character. Please re-type the value.',

    // street
    'STRIPE_VALIDATION_STREET_BLOCKED_CHARACTER'             => "The street may not contain the character '%s'.",
    'STRIPE_VALIDATION_STREET_DISALLOWED_CHARACTER'          => "The street may not contain the character '%s'.",
    'STRIPE_VALIDATION_STREET_CONTROL_CHARACTER'             => 'The street contains an invisible or control character. Please re-type the value.',

    // houseNumber
    'STRIPE_VALIDATION_HOUSENUMBER_BLOCKED_CHARACTER'        => "The house number may not contain the character '%s'.",
    'STRIPE_VALIDATION_HOUSENUMBER_DISALLOWED_CHARACTER'     => "The house number may not contain the character '%s'.",
    'STRIPE_VALIDATION_HOUSENUMBER_CONTROL_CHARACTER'        => 'The house number contains an invisible or control character. Please re-type the value.',

    // postalCode
    'STRIPE_VALIDATION_POSTALCODE_BLOCKED_CHARACTER'         => "The postal code may not contain the character '%s'.",
    'STRIPE_VALIDATION_POSTALCODE_DISALLOWED_CHARACTER'      => "The postal code may not contain the character '%s'.",
    'STRIPE_VALIDATION_POSTALCODE_CONTROL_CHARACTER'         => 'The postal code contains an invisible or control character. Please re-type the value.',

    // city
    'STRIPE_VALIDATION_CITY_BLOCKED_CHARACTER'               => "The city may not contain the character '%s'.",
    'STRIPE_VALIDATION_CITY_DISALLOWED_CHARACTER'            => "The city may not contain the character '%s'.",
    'STRIPE_VALIDATION_CITY_CONTROL_CHARACTER'               => 'The city contains an invisible or control character. Please re-type the value.',

    // company
    'STRIPE_VALIDATION_COMPANY_BLOCKED_CHARACTER'            => "The company name may not contain the character '%s'.",
    'STRIPE_VALIDATION_COMPANY_DISALLOWED_CHARACTER'         => "The company name may not contain the character '%s'.",
    'STRIPE_VALIDATION_COMPANY_CONTROL_CHARACTER'            => 'The company name contains an invisible or control character. Please re-type the value.',

    // vatId
    'STRIPE_VALIDATION_VATID_BLOCKED_CHARACTER'              => "The VAT ID may not contain the character '%s'.",
    'STRIPE_VALIDATION_VATID_DISALLOWED_CHARACTER'           => "The VAT ID may not contain the character '%s'.",
    'STRIPE_VALIDATION_VATID_CONTROL_CHARACTER'              => 'The VAT ID contains an invisible or control character. Please re-type the value.',

    // phone
    'STRIPE_VALIDATION_PHONE_BLOCKED_CHARACTER'              => "The phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_PHONE_DISALLOWED_CHARACTER'           => "The phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_PHONE_CONTROL_CHARACTER'              => 'The phone number contains an invisible or control character. Please re-type the value.',

    // cellPhone
    'STRIPE_VALIDATION_CELLPHONE_BLOCKED_CHARACTER'          => "The cell phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_CELLPHONE_DISALLOWED_CHARACTER'       => "The cell phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_CELLPHONE_CONTROL_CHARACTER'          => 'The cell phone number contains an invisible or control character. Please re-type the value.',

    // personalPhone
    'STRIPE_VALIDATION_PERSONALPHONE_BLOCKED_CHARACTER'      => "The personal phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_PERSONALPHONE_DISALLOWED_CHARACTER'   => "The personal phone number may not contain the character '%s'.",
    'STRIPE_VALIDATION_PERSONALPHONE_CONTROL_CHARACTER'      => 'The personal phone number contains an invisible or control character. Please re-type the value.',

    // fax
    'STRIPE_VALIDATION_FAX_BLOCKED_CHARACTER'                => "The fax number may not contain the character '%s'.",
    'STRIPE_VALIDATION_FAX_DISALLOWED_CHARACTER'             => "The fax number may not contain the character '%s'.",
    'STRIPE_VALIDATION_FAX_CONTROL_CHARACTER'                => 'The fax number contains an invisible or control character. Please re-type the value.',
];
