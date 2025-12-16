<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

$sLangName  = "Deutsch";

// -------------------------------
// RESOURCE IDENTIFIER = STRING
// -------------------------------
$aLang = [
    'charset'                                   => 'UTF-8',
    'STRIPE_LOCALE'                             => 'de_DE',
    'STRIPE_SHIPPINGCOST'                       => "Versandkosten",
    'STRIPE_PAYMENTTYPE'                        => "Zahlungsart-Aufschlag",
    'STRIPE_WRAPPING'                           => "Geschenkverpackung",
    'STRIPE_GIFTCARD'                           => "Grußkarte",
    'STRIPE_VOUCHER'                            => 'Gutschein',
    'STRIPE_DISCOUNT'                           => 'Rabatt',
    'STRIPE_ROUNDINGCORRECTION'                 => 'Rundungskorrektur',
    'STRIPE_SELECT_BANK'                        => 'Bank ausw&auml;hlen',
    'STRIPE_PLEASE_SELECT'                      => '-- Bitte w&auml;hlen --',
    'STRIPE_SOFORT_COUNTRY'                     => 'Land',
    'STRIPE_COUNTRY_AT'                         => 'Österreich',
    'STRIPE_COUNTRY_BE'                         => 'Belgien',
    'STRIPE_COUNTRY_DE'                         => 'Deutschland',
    'STRIPE_COUNTRY_ES'                         => 'Spanien',
    'STRIPE_COUNTRY_IT'                         => 'Italien',
    'STRIPE_COUNTRY_NL'                         => 'Niederlande',

    'STRIPE_ERROR_ORDER_NOT_FOUND'              => 'Bestellung konnte nicht gefunden werden',
    'STRIPE_ERROR_TRANSACTIONID_NOT_FOUND'      => 'Transaktions-Id konnte nicht gefunden werden',
    'STRIPE_ERROR_SOMETHING_WENT_WRONG'         => 'Ein unbekannter Fehler ist aufgetreten',
    'STRIPE_ERROR_ORDER_CANCELED'               => 'Die Bezahlung wurde storniert, bitte versuchen Sie es erneut',
    'STRIPE_ERROR_ORDER_FAILED'                 => 'Die Bezahlung ist fehlgeschlagen, bitte versuchen Sie es erneut',
    'STRIPE_SECOND_CHANCE_MAIL_SUBJECT'         => 'Abschluss Ihrer Bestellung bei',
    'STRIPE_ERROR_ORDER_CONFIG_PUBKEY'          => 'Bitte konfigurieren Sie den ver&ouml;ffentlichbaren Stripe-Schl&uuml;ssel, um diese Zahlungsmethode zu verwenden.',
    'STRIPE_WEBHOOK_CREATE_ERROR'               => 'Der Webhook-Endpunkt konnte nicht erstellt werden.',
    'STRIPE_WEBHOOK_CREATE_ERROR_DELETE_FAILED' => 'Der Webhook-Endpunkt konnte nicht erstellt werden. Das Löschen des vorhandenen WH-Endpunkts ist fehlgeschlagen.',
    'STRIPE_CREDIT_CARD'                        => 'Kreditkarte',

    // Buy Now feature
    'STRIPE_BUY_NOW'                            => 'Jetzt kaufen',
    'STRIPE_BUY_NOW_HINT'                       => 'Schnellkauf - Warenkorb überspringen und sofort bezahlen',

    // Module settings
    'SHOP_MODULE_sStripeDevMode'                => 'Entwicklungsmodus',
    'HELP_SHOP_MODULE_sStripeDevMode'           => 'Aktivieren, um JavaScript-Dateien separat zu laden für einfacheres Debugging. Wird automatisch auf .local, .dev, .test Domains aktiviert.',
    'SHOP_MODULE_sStripeCaptureMode'            => 'Erfassungsmodus',
    'SHOP_MODULE_sStripeCaptureMode_automatic'  => 'Automatisch (sofortige Erfassung)',
    'SHOP_MODULE_sStripeCaptureMode_manual'     => 'Manuell (verzögerte Erfassung)',
    'HELP_SHOP_MODULE_sStripeCaptureMode'       => 'Automatisch: Zahlung wird sofort nach Autorisierung erfasst. Manuell: Zahlung wird nur autorisiert und muss später erfasst werden (z.B. beim Versand). Autorisierungen verfallen nach 7 Tagen.',

    // Payment Element - Standard Checkout
    'OSC_STRIPE_CARD_PAYMENT'                   => 'Kreditkartenzahlung',
    'OSC_STRIPE_PAYMENT_DESC'                   => 'Zahlen Sie sicher mit Ihrer Kredit- oder Debitkarte. Ihre Zahlungsinformationen sind verschlüsselt und sicher.',
    'OSC_STRIPE_CARD_DETAILS'                   => 'Kartendetails',
    'OSC_STRIPE_PAY_NOW'                        => 'Jetzt bezahlen',
    'OSC_STRIPE_PROCESSING'                     => 'Verarbeitung',
    'OSC_STRIPE_PROCESSING_PAYMENT'             => 'Ihre Zahlung wird verarbeitet. Bitte warten...',
    'OSC_STRIPE_SECURE_PAYMENT'                 => 'Sichere Zahlung powered by Stripe',

    // Error Messages - Payment Element
    'OSC_STRIPE_CONFIG_ERROR'                   => 'Stripe ist nicht richtig konfiguriert. Bitte kontaktieren Sie den Support.',
    'OSC_STRIPE_INTENT_ERROR'                   => 'Zahlung konnte nicht initialisiert werden. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
    'OSC_STRIPE_UNEXPECTED_ERROR'               => 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es erneut.',
    'OSC_STRIPE_PAYMENT_FAILED'                 => 'Zahlung fehlgeschlagen. Bitte überprüfen Sie Ihre Kartendaten und versuchen Sie es erneut.',
    'OSC_STRIPE_PAYMENT_DECLINED'               => 'Ihre Zahlung wurde abgelehnt. Bitte versuchen Sie eine andere Karte.',
    'OSC_STRIPE_INSUFFICIENT_FUNDS'             => 'Nicht ausreichende Deckung. Bitte überprüfen Sie Ihr Kartenguthaben.',
    'OSC_STRIPE_CARD_ERROR'                     => 'Bei der Verarbeitung Ihrer Karte ist ein Fehler aufgetreten. Bitte überprüfen Sie Ihre Angaben.',
    'OSC_STRIPE_NETWORK_ERROR'                  => 'Netzwerkfehler. Bitte überprüfen Sie Ihre Verbindung und versuchen Sie es erneut.',

    // 3D Secure - Payment Element
    'OSC_STRIPE_3DS_TITLE'                      => 'Sichere Zahlungsauthentifizierung',
    'OSC_STRIPE_3DS_INFO'                       => 'Ihre Bank benötigt eine zusätzliche Authentifizierung, um diese Zahlung abzuschließen.',
    'OSC_STRIPE_AUTHENTICATING'                 => 'Ihre Zahlung wird authentifiziert...',
    'OSC_STRIPE_3DS_COMPLETE'                   => 'Authentifizierung abgeschlossen. Ihre Bestellung wird verarbeitet...',
    'OSC_STRIPE_3DS_FAILED'                     => 'Authentifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.',

    // Success Messages
    'OSC_STRIPE_PAYMENT_SUCCESS'                => 'Zahlung erfolgreich!',
    'OSC_STRIPE_ORDER_CREATED'                  => 'Ihre Bestellung wurde erfolgreich erstellt.',

    // Transaction Details
    'OSC_STRIPE_TRANSACTION_ID'                 => 'Transaktions-ID',
    'OSC_STRIPE_PAYMENT_ID'                     => 'Zahlungs-ID',
    'OSC_STRIPE_CARD_BRAND'                     => 'Kartenmarke',
    'OSC_STRIPE_CARD_LAST4'                     => 'Karte endet auf',
    'OSC_STRIPE_PAYMENT_DATE'                   => 'Zahlungsdatum',
    'OSC_STRIPE_PAYMENT_AMOUNT'                 => 'Zahlungsbetrag',
];
