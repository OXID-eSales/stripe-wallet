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
    'STRIPE_WEBHOOK_CREATE_ERROR_DELETE_FAILED' => 'The Webhook Endpoint could not be created. Deletion of existing WH Endpoint failed.',
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
];
