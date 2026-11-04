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
    'STRIPE_PLEASE_SELECT'                      => '-- Please select --',

    'STRIPE_WEBHOOK_CREATE_ERROR'               => 'The Webhook Endpoint could not be created.',

    // Payment Element - Standard Checkout
    'OSC_STRIPE_CARD_PAYMENT'                   => 'Credit Card Payment',

    // JavaScript Controller Translations
    'STRIPE_AGB_CONTINUE_TO_PAYMENT'            => 'Please accept the Terms and Conditions above to continue to the payment form.',
    'STRIPE_JS_AGB_REQUIRED'                    => 'Please accept the terms and conditions',
    'STRIPE_JS_PAYMENT_FAILED'                  => 'Payment processing failed',
    'STRIPE_JS_NOT_LOADED'                      => 'Stripe.js not loaded',
    'STRIPE_JS_KEY_NOT_CONFIGURED'              => 'Stripe publishable key not configured',
    'STRIPE_JS_CREATING_SESSION'                => 'Creating checkout session...',
    'STRIPE_JS_SESSION_FAILED'                  => 'Failed to create checkout session',
    'STRIPE_JS_SESSION_INVALID'                 => 'Invalid checkout session response',
    'STRIPE_JS_PROCESSING'                      => 'Processing...',

    // -----------------------------------------------------------------------
    // User-data field validation messages (STRP-129)
    // Single message lists the ALLOWED symbols: STRIPE_VALIDATION_FIELD_INVALID
    // %1$s = field label, %2$s = allowed-symbols description.
    // -----------------------------------------------------------------------
    'STRIPE_VALIDATION_FIELD_INVALID'                        => 'The %1$s field is not valid. Allowed symbols are: %2$s',
    'STRIPE_VALIDATION_UNDERSTAND'                           => 'Understand',
    'STRIPE_VALIDATION_REVIEW_ADDRESS'                       => 'Please review your address details.',

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

    // OPC-192: the embedded sheet quotes the basket its session was built
    // from, so a changed basket makes it stale. These name that state and
    // the action that resolves it.
    'STRIPE_SHEET_STALE'                                      => "Your basket changed, so this payment form is out of date.",
    'STRIPE_SHEET_REFRESH'                                    => "Refresh payment form",
];
