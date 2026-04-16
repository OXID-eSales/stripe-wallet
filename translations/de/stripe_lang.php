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
    'STRIPE_SELECT_BANK'                        => 'Bank auswählen',
    'STRIPE_PLEASE_SELECT'                      => '-- Bitte wählen --',
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
    'STRIPE_ERROR_ORDER_CONFIG_PUBKEY'          => 'Bitte konfigurieren Sie den veröffentlichbaren Stripe-Schlüssel, um diese Zahlungsmethode zu verwenden.',
    'STRIPE_WEBHOOK_CREATE_ERROR'               => 'Der Webhook-Endpunkt konnte nicht erstellt werden.',
    'STRIPE_WEBHOOK_CREATE_ERROR_DELETE_FAILED' => 'Der Webhook-Endpunkt konnte nicht erstellt werden. Das Löschen des vorhandenen WH-Endpunkts ist fehlgeschlagen.',
    'STRIPE_CREDIT_CARD'                        => 'Kreditkarte',

    // Buy Now feature
    'STRIPE_BUY_NOW'                            => 'Jetzt kaufen',
    'STRIPE_BUY_NOW_HINT'                       => 'Schnellkauf - Warenkorb überspringen und sofort bezahlen',

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

    // Card Element Labels + Validation (Sprint 91)
    'OSC_STRIPE_CARD_NUMBER'                    => 'Kartennummer',
    'OSC_STRIPE_CARD_EXDATE'                    => 'Ablaufdatum',
    'OSC_STRIPE_CARD_CVC'                       => 'CVC',
    'OSC_STRIPE_CARD_NAME'                      => 'Name des Karteninhabers',
    'OSC_STRIPE_ERROR_MISSING_NAME'             => 'Bitte geben Sie den Namen des Karteninhabers ein.',
    'OSC_STRIPE_ERROR_MISSING_NUMBER'           => 'Bitte geben Sie eine gültige Kartennummer ein.',
    'OSC_STRIPE_ERROR_MISSING_CVC'              => 'Bitte geben Sie den CVC-Code ein.',
    'OSC_STRIPE_ERROR_MISSING_EXDATE'           => 'Bitte geben Sie das Ablaufdatum ein.',
    'OSC_STRIPE_ERROR_INBOX'                    => 'Bitte überprüfen Sie Ihre Eingabe.',
    'OSC_STRIPE_UNKNOWN_ERROR'                  => 'Ein unbekannter Fehler ist aufgetreten. Bitte versuchen Sie es erneut.',
    'OSC_STRIPE_AUTHORIZATION_DENIED_ERROR'     => 'Autorisierung abgelehnt. Bitte verwenden Sie eine andere Zahlungsmethode.',
    'OSC_STRIPE_VAULTING_VAULTED_PAYMENTS'      => 'Gespeicherte Zahlungsmethoden',
    'OSC_STRIPE_CONTINUE_TO_NEXT_STEP'          => 'Weiter',

    // JavaScript Controller Translations
    'STRIPE_JS_AGB_REQUIRED'                    => 'Bitte akzeptieren Sie die AGB',
    'STRIPE_JS_CONFIG_ERROR'                    => 'Stripe Konfigurationsfehler. Bitte kontaktieren Sie den Support.',
    'STRIPE_JS_INIT_FAILED'                     => 'Zahlungsformular konnte nicht initialisiert werden. Bitte laden Sie die Seite neu.',
    'STRIPE_JS_PAYMENT_FAILED'                  => 'Zahlungsverarbeitung fehlgeschlagen',
    'STRIPE_JS_NOT_LOADED'                      => 'Stripe.js wurde nicht geladen',
    'STRIPE_JS_KEY_NOT_CONFIGURED'              => 'Stripe Publishable Key ist nicht konfiguriert',
    'STRIPE_JS_CREATING_SESSION'                => 'Checkout-Session wird erstellt...',
    'STRIPE_JS_SESSION_FAILED'                  => 'Checkout-Session konnte nicht erstellt werden',
    'STRIPE_JS_SESSION_INVALID'                 => 'Ungültige Checkout-Session Antwort',
    'STRIPE_JS_CONTROLLER_NOT_FOUND'            => 'Stripe Zahlungs-Controller nicht gefunden. Bitte laden Sie die Seite neu.',
    'STRIPE_JS_FORM_NOT_READY'                  => 'Zahlungsformular nicht bereit. Bitte laden Sie die Seite neu.',
    'STRIPE_JS_PAYMENT_NOT_COMPLETED'           => 'Zahlung nicht abgeschlossen',
    'STRIPE_JS_URL_NOT_CONFIGURED'              => 'Zahlungs-URL ist nicht konfiguriert',
    'STRIPE_JS_INTENT_INVALID'                  => 'Ungültige Payment Intent Antwort',
    'STRIPE_JS_PROCESSING'                      => 'Verarbeitung...',
];
