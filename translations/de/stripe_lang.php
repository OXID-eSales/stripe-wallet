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

    // Alt-Schlüssel (vom Formatter nicht mehr verwendet; aus Kompatibilität belassen).
    'STRIPE_VALIDATION_GENERIC'                              => "Der eingegebene Wert für das Feld %s enthält ein ungültiges Zeichen: '%s'.",

    // firstName → Vorname
    'STRIPE_VALIDATION_FIRSTNAME_BLOCKED_CHARACTER'          => "Der Vorname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_FIRSTNAME_DISALLOWED_CHARACTER'       => "Der Vorname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_FIRSTNAME_CONTROL_CHARACTER'          => 'Der Vorname enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // lastName → Nachname
    'STRIPE_VALIDATION_LASTNAME_BLOCKED_CHARACTER'           => "Der Nachname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_LASTNAME_DISALLOWED_CHARACTER'        => "Der Nachname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_LASTNAME_CONTROL_CHARACTER'           => 'Der Nachname enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // additionalInfo → Adresszusatz
    'STRIPE_VALIDATION_ADDITIONALINFO_BLOCKED_CHARACTER'     => "Der Adresszusatz darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_ADDITIONALINFO_DISALLOWED_CHARACTER'  => "Der Adresszusatz darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_ADDITIONALINFO_CONTROL_CHARACTER'     => 'Der Adresszusatz enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // street → Straße
    'STRIPE_VALIDATION_STREET_BLOCKED_CHARACTER'             => "Die Straße darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_STREET_DISALLOWED_CHARACTER'          => "Die Straße darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_STREET_CONTROL_CHARACTER'             => 'Die Straße enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // houseNumber → Hausnummer
    'STRIPE_VALIDATION_HOUSENUMBER_BLOCKED_CHARACTER'        => "Die Hausnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_HOUSENUMBER_DISALLOWED_CHARACTER'     => "Die Hausnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_HOUSENUMBER_CONTROL_CHARACTER'        => 'Die Hausnummer enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // postalCode → Postleitzahl
    'STRIPE_VALIDATION_POSTALCODE_BLOCKED_CHARACTER'         => "Die Postleitzahl darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_POSTALCODE_DISALLOWED_CHARACTER'      => "Die Postleitzahl darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_POSTALCODE_CONTROL_CHARACTER'         => 'Die Postleitzahl enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // city → Stadt
    'STRIPE_VALIDATION_CITY_BLOCKED_CHARACTER'               => "Die Stadt darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_CITY_DISALLOWED_CHARACTER'            => "Die Stadt darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_CITY_CONTROL_CHARACTER'               => 'Die Stadt enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // company → Firma
    'STRIPE_VALIDATION_COMPANY_BLOCKED_CHARACTER'            => "Der Firmenname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_COMPANY_DISALLOWED_CHARACTER'         => "Der Firmenname darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_COMPANY_CONTROL_CHARACTER'            => 'Der Firmenname enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // vatId → USt-IdNr.
    'STRIPE_VALIDATION_VATID_BLOCKED_CHARACTER'              => "Die USt-IdNr. darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_VATID_DISALLOWED_CHARACTER'           => "Die USt-IdNr. darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_VATID_CONTROL_CHARACTER'              => 'Die USt-IdNr. enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // phone → Telefon
    'STRIPE_VALIDATION_PHONE_BLOCKED_CHARACTER'              => "Die Telefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_PHONE_DISALLOWED_CHARACTER'           => "Die Telefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_PHONE_CONTROL_CHARACTER'              => 'Die Telefonnummer enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // cellPhone → Privattelefon
    'STRIPE_VALIDATION_CELLPHONE_BLOCKED_CHARACTER'          => "Die Privattelefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_CELLPHONE_DISALLOWED_CHARACTER'       => "Die Privattelefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_CELLPHONE_CONTROL_CHARACTER'          => 'Die Privattelefonnummer enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // personalPhone → Mobiltelefon
    'STRIPE_VALIDATION_PERSONALPHONE_BLOCKED_CHARACTER'      => "Die Mobiltelefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_PERSONALPHONE_DISALLOWED_CHARACTER'   => "Die Mobiltelefonnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_PERSONALPHONE_CONTROL_CHARACTER'      => 'Die Mobiltelefonnummer enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',

    // fax → Fax
    'STRIPE_VALIDATION_FAX_BLOCKED_CHARACTER'                => "Die Faxnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_FAX_DISALLOWED_CHARACTER'             => "Die Faxnummer darf das Zeichen '%s' nicht enthalten.",
    'STRIPE_VALIDATION_FAX_CONTROL_CHARACTER'                => 'Die Faxnummer enthält ein unsichtbares oder Steuerzeichen. Bitte geben Sie den Wert erneut ein.',
];
