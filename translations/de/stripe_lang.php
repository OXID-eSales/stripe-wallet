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
    'STRIPE_PLEASE_SELECT'                      => '-- Bitte wählen --',

    'STRIPE_WEBHOOK_CREATE_ERROR'               => 'Der Webhook-Endpunkt konnte nicht erstellt werden.',

    // Payment Element - Standard Checkout
    'OSC_STRIPE_CARD_PAYMENT'                   => 'Kreditkartenzahlung',

    // JavaScript Controller Translations
    'STRIPE_AGB_CONTINUE_TO_PAYMENT'            => 'Bitte akzeptieren Sie oben die AGB, um zum Zahlungsformular zu gelangen.',
    'STRIPE_JS_AGB_REQUIRED'                    => 'Bitte akzeptieren Sie die AGB',
    'STRIPE_JS_PAYMENT_FAILED'                  => 'Zahlungsverarbeitung fehlgeschlagen',
    'STRIPE_JS_NOT_LOADED'                      => 'Stripe.js wurde nicht geladen',
    'STRIPE_JS_KEY_NOT_CONFIGURED'              => 'Stripe Publishable Key ist nicht konfiguriert',
    'STRIPE_JS_CREATING_SESSION'                => 'Checkout-Session wird erstellt...',
    'STRIPE_JS_SESSION_FAILED'                  => 'Checkout-Session konnte nicht erstellt werden',
    'STRIPE_JS_SESSION_INVALID'                 => 'Ungültige Checkout-Session Antwort',
    'STRIPE_JS_PROCESSING'                      => 'Verarbeitung...',

    // -----------------------------------------------------------------------
    // Benutzer-Daten-Feldvalidierungsmeldungen (STRP-129)
    // Eine Meldung nennt die ERLAUBTEN Zeichen: STRIPE_VALIDATION_FIELD_INVALID
    // %1$s = Feldbezeichnung, %2$s = Beschreibung der erlaubten Zeichen.
    // -----------------------------------------------------------------------
    'STRIPE_VALIDATION_FIELD_INVALID'                        => 'Das Feld %1$s ist ungültig. Erlaubte Zeichen sind: %2$s',
    'STRIPE_VALIDATION_UNDERSTAND'                           => 'Verstanden',
    'STRIPE_VALIDATION_REVIEW_ADDRESS'                       => 'Bitte überprüfen Sie Ihre Adressdaten.',

    // Zeichenklassen-Wörter für die Auflistung der erlaubten Zeichen.
    'STRIPE_VALIDATION_CLASS_LETTERS'                        => 'Buchstaben',
    'STRIPE_VALIDATION_CLASS_DIGITS'                         => 'Ziffern',
    'STRIPE_VALIDATION_CLASS_SPACES'                         => 'Leerzeichen',

    // Feldbezeichnungen.
    'STRIPE_VALIDATION_LABEL_FIRSTNAME'                      => 'Vorname',
    'STRIPE_VALIDATION_LABEL_LASTNAME'                       => 'Nachname',
    'STRIPE_VALIDATION_LABEL_ADDITIONALINFO'                 => 'Adresszusatz',
    'STRIPE_VALIDATION_LABEL_STREET'                         => 'Straße',
    'STRIPE_VALIDATION_LABEL_HOUSENUMBER'                    => 'Hausnummer',
    'STRIPE_VALIDATION_LABEL_POSTALCODE'                     => 'Postleitzahl',
    'STRIPE_VALIDATION_LABEL_CITY'                           => 'Stadt',
    'STRIPE_VALIDATION_LABEL_COMPANY'                        => 'Firma',
    'STRIPE_VALIDATION_LABEL_VATID'                          => 'USt-IdNr.',
    'STRIPE_VALIDATION_LABEL_PHONE'                          => 'Telefon',
    'STRIPE_VALIDATION_LABEL_CELLPHONE'                      => 'Mobiltelefon',
    'STRIPE_VALIDATION_LABEL_PERSONALPHONE'                  => 'Privattelefon',
    'STRIPE_VALIDATION_LABEL_FAX'                            => 'Fax',

    // OPC-192: the embedded sheet quotes the basket its session was built
    // from, so a changed basket makes it stale. These name that state and
    // the action that resolves it.
    'STRIPE_SHEET_STALE'                                      => "Ihr Warenkorb hat sich geändert, dieses Zahlungsformular ist nicht mehr aktuell.",
    'STRIPE_SHEET_REFRESH'                                    => "Zahlungsformular aktualisieren",
];
