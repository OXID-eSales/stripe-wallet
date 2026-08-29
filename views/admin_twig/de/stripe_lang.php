<?php
/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

$sLangName = "Deutsch";
// -------------------------------
// RESOURCE IDENTITFIER = STRING
// -------------------------------
$aLang = array(
    'charset'                                           => 'UTF-8',

    /* SETTINGS */
    'SHOP_MODULE_GROUP_STRIPE_GENERAL'                  => 'Grundkonfiguration',
    'SHOP_MODULE_GROUP_STRIPE_TEST_CONFIG'              => 'Testkonfiguration',
    'SHOP_MODULE_GROUP_STRIPE_LIVE_CONFIG'              => 'Live-Konfiguration',
    'SHOP_MODULE_sStripeMode'                           => 'Live oder Test API',
    'SHOP_MODULE_sStripeMode_live'                      => 'Live',
    'SHOP_MODULE_sStripeMode_test'                      => 'Test',
    'SHOP_MODULE_sStripeTestToken'                      => 'Test API Key',
    'SHOP_MODULE_sStripeTestPk'                         => 'Test API Publishable Key',
    'SHOP_MODULE_sStripeLiveToken'                      => 'Live API Key',
    'SHOP_MODULE_sStripeLivePk'                         => 'Live API Publishable Key',
    'SHOP_MODULE_blStripeLogTransactionInfo'            => 'Ergebnisse von Transaktions-Verarbeitung loggen',
    'SHOP_MODULE_blStripeRemoveDeactivatedMethods'      => 'Deaktivierte Zahlarten entfernen',
    'SHOP_MODULE_blStripeRemoveByBillingCountry'        => 'Für Rechnungsland nicht unterstützte Zahlarten entfernen',
    'SHOP_MODULE_blStripeRemoveByBasketCurrency'        => 'Für Währung nicht unterstützte Zahlarten entfernen',
    'SHOP_MODULE_GROUP_STRIPE_WEBHOOKS'                 => 'Webhooks',
    'SHOP_MODULE_sStripeWebhookEndpoint'                => 'Webhook Endpoint',
    'HELP_SHOP_MODULE_sStripeWebhookEndpoint'           => 'Fügen Sie hier Ihre Stripe-Webhook-Endpoint-URL ein, oder nutzen Sie den Button unten, um den Endpunkt automatisch mit Ihrem Platform-Secret-Key zu erstellen und zu registrieren.',
    'SHOP_MODULE_sStripeWebhookEndpointSecret'          => 'Webhook Sicherheits-Schlüssel',
    'SHOP_MODULE_blStripeProvideCustomerEmailAddress'   => 'Kunden-E-Mail-Adresse an Stripe senden',

    // Phase 2 (Logging-Steuerungs-Sprint): blStripeLogTransactionInfo ist veraltet.
    // Der Hilfetext verwies bisher auf eine Log-Datei, die nicht mehr existiert.
    // Phase 3 liest diesen Legacy-Bool als Ausgangswert für sStripeLogLevel.
    'HELP_SHOP_MODULE_blStripeLogTransactionInfo'       => 'Veraltet. Diese Einstellung wird nur aus Kompatibilitätsgründen beibehalten. Verwenden Sie stattdessen die Logging-Gruppe (sStripeLogLevel, blStripeLogWebhooks). Stripe-Protokolldateien befinden sich unter log/stripe/stripe_*_&lt;Datum&gt;.log.',
    'HELP_SHOP_MODULE_blStripeRemoveDeactivatedMethods' => 'Entfernt in der Zahlart-Auswahl im Frontend die Zahlarten, die im Stripe Dashboard nicht aktiviert wurden und somit zu einem Fehler führen würden.',
    'HELP_SHOP_MODULE_blStripeRemoveByBillingCountry'   => 'Entfernt in der Zahlart-Auswahl im Frontend die Zahlarten, die für das vom Kunden angegebene Rechnungsland nicht unterstützt sind und somit zu einem Fehler führen würden.',
    'HELP_SHOP_MODULE_blStripeRemoveByBasketCurrency'   => 'Entfernt in der Zahlart-Auswahl im Frontend die Zahlarten, die für das vom Warenkorb angegebene Wärung nicht unterstützt sind und somit zu einem Fehler führen würden.',
    'HELP_SHOP_MODULE_blStripeProvideCustomerEmailAddress' => 'Ist diese Option aktiviert so, wird bei einer Stripe-Bestellung die Kunden-EMailadresse ebenfalls übergeben. Das überschreibt die Standard-E-Mail-Einstellungen im Stripe-Account für diese Bestellung. Somit werden die Benachrichtigungen zu dieser Bestellung an die Kunden-EMail-Adresse gesendet, statt an die Stripe-Account-EMail-Adresse.',

    // Erfassungsmodus-Einstellungen
    'SHOP_MODULE_sStripeCaptureMode'                    => 'Erfassungsmodus',
    'SHOP_MODULE_sStripeCaptureMode_automatic'          => 'Automatisch (sofort erfassen)',
    'SHOP_MODULE_sStripeCaptureMode_manual'             => 'Manuell (nur autorisieren, später erfassen)',
    'HELP_SHOP_MODULE_sStripeCaptureMode'               => 'Wählen Sie, wann Zahlungen erfasst werden sollen. Im automatischen Modus werden die Gelder sofort erfasst, wenn der Kunde den Checkout abschließt. Im manuellen Modus wird die Zahlung nur autorisiert - Sie müssen sie innerhalb von 7 Tagen manuell auf der Bestelladmin-Seite erfassen.',

    // Logging-Einstellungen (Phase 2 — Logging-Steuerungs-Sprint)
    // sStripeLogLevel steuert den Umfang der Backend-Datei-Logs.
    // blStripeLogWebhooks ist unabhängig davon, sodass Händler störende
    // Webhook-Protokolle stumm schalten können, ohne andere Kanäle zu deaktivieren.
    // Protokolldateien: log/stripe/stripe_<kanal>_<datum>.log
    'SHOP_MODULE_GROUP_STRIPE_LOGGING'                  => 'Protokollierung',
    'SHOP_MODULE_sStripeLogLevel'                       => 'Protokollierungsstufe',
    'SHOP_MODULE_sStripeLogLevel_off'                   => 'Aus (keine Protokollierung)',
    'SHOP_MODULE_sStripeLogLevel_errors'                => 'Nur Fehler',
    'SHOP_MODULE_sStripeLogLevel_normal'                => 'Normal (Anfragen + Abstimmung)',
    'SHOP_MODULE_sStripeLogLevel_debug'                 => 'Debug (alle Kanäle + Browser-Konsole)',
    'HELP_SHOP_MODULE_sStripeLogLevel'                  => 'Steuert, wie viel das Stripe-Modul in seine dateibasierten Audit-Logs schreibt (log/stripe/stripe_*_&lt;Datum&gt;.log). "Aus" unterdrückt alle Datei-Schreibvorgänge. "Nur Fehler" protokolliert lediglich Ausnahmedetails. "Normal" protokolliert vollständige Anfrage-/Antwortzyklen und Abstimmungsereignisse. "Debug" aktiviert alle Kanäle einschließlich Ereignisfluss und Browser-Konsolenausgabe. Die Webhook-Protokollierung wird separat über die Einstellung "Webhooks protokollieren" gesteuert.',
    'SHOP_MODULE_blStripeLogWebhooks'                   => 'Webhooks protokollieren',
    'HELP_SHOP_MODULE_blStripeLogWebhooks'              => 'Wenn aktiviert, werden eingehende Stripe-Webhook-Ereignisse in log/stripe/stripe_webhooks_&lt;Datum&gt;.log geschrieben. Dies ist unabhängig von der Protokollierungsstufe, sodass störende Webhook-Protokolle stummgeschaltet werden können, ohne Anfragen- oder Abstimmungslogs zu beeinflussen. Der Webhook-Idempotenz-Eintrag wird unabhängig von dieser Einstellung immer geschrieben.',

    'STRIPE_YES'                                        => 'Ja',
    'STRIPE_NO'                                         => 'Nein',
    'STRIPE_DAY'                                        => 'Tag',
    'STRIPE_DAYS'                                       => 'Tage',
    'STRIPE_IS_STRIPE'                                  => 'Dies ist eine Stripe Zahlungsart',
    'STRIPE_IS_METHOD_ACTIVATED'                        => 'Diese Zahlungsart ist in Ihrem Stripe Account nicht aktiviert!',
    'STRIPE_TOKEN_NOT_CONFIGURED'                       => 'Ihr Stripe-Token wurde noch nicht konfiguriert!',
    'STRIPE_KEY_NOT_CONFIGURED'                         => 'Ihr privater Stripe-Schlüssel wurde noch nicht konfiguriert! Bitte konfigurieren Sie es in der Basiskonfiguration.',
    'STRIPE_DUE_DATE'                                   => 'Fälligkeitstage',
    'STRIPE_BANKTRANSFER_PENDING'                       => 'Status Ausstehend',
    'STRIPE_ORDER_REFUND'                               => 'Stripe',
    'STRIPE_REFUND_SUCCESSFUL'                          => 'Rückerstattung war erfolgreich.',
    'STRIPE_NO_STRIPE_PAYMENT'                          => 'Diese Bestellung wurde nicht mit Stripe bezahlt.',
    'STRIPE_REFUND_QUANTITY'                            => 'Erstattungsmenge',
    'STRIPE_REFUND_AMOUNT'                              => 'Erstattungsbetrag',
    'STRIPE_TYPE_SELECT_LABEL'                          => 'Erstattung durchführen über',
    'STRIPE_QUANTITY'                                   => 'Menge',
    'STRIPE_NOTICE'                                     => 'Hinweis',
    'STRIPE_AMOUNT'                                     => 'Betrag',
    'STRIPE_HEADER_ORDERED'                             => 'Bezahlt',
    'STRIPE_HEADER_REFUNDED'                            => 'Erstattet',
    'STRIPE_HEADER_SINGLE_PRICE'                        => 'Einzelpreis',
    'STRIPE_SHIPPINGCOST'                               => "Versandkosten",
    'STRIPE_PAYMENTTYPESURCHARGE'                       => "Zahlungsart-Aufschlag",
    'STRIPE_WRAPPING'                                   => "Geschenkverpackung",
    'STRIPE_GIFTCARD'                                   => "Grußkarte",
    'STRIPE_VOUCHER'                                    => 'Gutschein',
    'STRIPE_DISCOUNT'                                   => 'Rabatt',
    'STRIPE_REFUND_SUBMIT'                              => 'Erstattung durchführen',
    'STRIPE_API_ERROR'                                  => 'Stripe API Fehler',
    'STRIPE_APPLE_PAY_BUTTON_ONLY_LIVE_MODE'            => 'Hinweis: Bezahlung mit Apple Pay Button ist nur im Live-Modus möglich',
    'STRIPE_APIKEY_CONNECTED'                           => 'Verbindung erfolgreich',
    'STRIPE_APIKEY_DISCONNECTED'                        => 'Verbindung nicht erfolgreich',
    'STRIPE_KEY_MISMATCH_WARNING'                       => 'API-Schlüssel Konflikt',
    'STRIPE_DEACTIVATED'                                => 'Deaktiviert',
    'STRIPE_CONNECTION_DATA'                            => 'Abruf der Verbindungsdaten über:',
    'STRIPE_ORDER_PAYMENT_URL'                          => 'Link zum Abschluss der Zahlung',
    'STRIPE_SEND_SECOND_CHANCE_MAIL'                    => 'Second Chance Email versenden',
    'STRIPE_SECOND_CHANCE_MAIL_ALREADY_SENT'            => 'Die Email wurde bereits versendet.',
    'STRIPE_SUBSEQUENT_ORDER_COMPLETION'                => 'Nachträglicher Bestellabschluss',
    'STRIPE_PAYMENT_DESCRIPTION'                        => 'Zahlungsbezeichnung',
    'STRIPE_PAYMENT_DESCRIPTION_HELP'                   => 'Dies wird auf dem Kontoauszug des Kunden angezeigt soweit möglich.<br><br>Sie können die folgenden Parameter benutzen:<br>{orderId}<br>{orderNumber}<br>{storeName}<br>{customer.firstname}<br>{customer.lastname}<br>{customer.company}',
    'STRIPE_MODULE_VERSION_OUTDATED'                    => 'Achtung! Die aktuellste Modulversion ist',
    'STRIPE_PAYMENT_LIMITATION'                         => 'Stripe Beschränkung',
    'STRIPE_PAYMENT_LIMITATION_FROM'                    => 'Von',
    'STRIPE_PAYMENT_LIMITATION_TO'                      => 'bis',
    'STRIPE_PAYMENT_LIMITATION_UNLIMITED'               => 'unbegrenzt',
    'STRIPE_PAYMENT_DETAILS'                            => 'Zahlungsdetails',
    'STRIPE_ORDER_NUMBER'                               => 'Bestell-Nr.',
    'STRIPE_CONTRACT_ID'                                => 'Vertrags-ID',
    'STRIPE_ORDER_ID'                                   => 'Bestell-ID',
    'STRIPE_PAYMENT_TYPE'                               => 'Zahlart',
    'STRIPE_TRANSACTION_ID'                             => 'Stripe Transaktions ID',
    'STRIPE_EXTERNAL_TRANSACTION_ID'                    => 'Externe Transaktions ID',
    'STRIPE_TRANSACTION_HISTORY'                        => 'Transaktionsverlauf',
    'STRIPE_TRANSACTION_TYPE'                           => 'Typ',
    'STRIPE_TRANSACTION_STATUS'                         => 'Status',
    'STRIPE_TRANSACTION_AMOUNT'                         => 'Betrag',
    'STRIPE_TRANSACTION_CURRENCY'                       => 'Währung',
    'STRIPE_TRANSACTION_PROVIDER_ID'                    => 'Anbieter-Transaktions-ID',
    'STRIPE_TRANSACTION_DATE'                           => 'Datum',
    'STRIPE_NO_TRANSACTIONS'                            => 'Keine Transaktionen vorhanden.',
    'STRIPE_PARTIAL_CAPTURE_NOTE'                       => 'Eine Teilerfassung gibt den Restbetrag an den Kunden frei. Dies kann nicht rückgängig gemacht werden.',
    'STRIPE_REFUND'                                     => 'Erstattung',
    'STRIPE_REFUNDABLE_AMOUNT'                          => 'Erstattungsfähiger Betrag',
    'STRIPE_FACTUAL_CAPTURED_AMOUNT'                    => 'Tatsächlich erfasster Betrag',
    'STRIPE_REFUNDED_AMOUNT'                            => 'Erstatteter Betrag',
    'STRIPE_ORDER_EXTRA_INFO'                           => 'Zusätzliche Informationen',
    'STRIPE_CONNECT_SUCCESS'                            => 'Stripe-Onboarding erfolgreich.',
    'STRIPE_CONNECT_ERROR'                              => 'Während des Stripe-Onboardings ist ein Fehler aufgetreten.',
    'STRIPE_BTN_TO_ADMIN'                               => 'Zum Admin-Bereich',
    'STRIPE_WEBHOOK_CREATE_ERROR'                       => 'Der Webhook-Endpunkt konnte nicht erstellt werden.',
    'STRIPE_REFUND_REASON'                              => 'Rückerstattungsgrund (optional)',
    'STRIPE_PLEASE_SELECT'                              => '-- Bitte wählen Sie --',
    'STRIPE_REFUND_DUPLICATE'                           => 'Duplikat',
    'STRIPE_REFUND_CUSTOMER'                            => 'Vom Kunden angefordert',
    'STRIPE_REFUND_FRAUD'                               => 'Betrug',
    'STRIPE_IS_NOT_STRIPE_ORDER'                        => 'Dies ist keine Stripe Bestellung',

    // Connect Button & Webhook Status
    'STRIPE_CONNECT_WITH'                               => 'Verbinden mit',
    'STRIPE_WEBHOOK_CONFIGURED'                         => 'Konfiguriert',
    'STRIPE_WEBHOOK_NOT_SET'                            => 'Nicht gesetzt - aus Stripe Dashboard kopieren',

    // Webhook-Einrichtung (Buttons "Erstellen" / "Löschen" in der Modulkonfiguration)
    'STRIPE_WEBHOOK_CREATE_BUTTON'                      => 'Webhooks erstellen',
    'STRIPE_WEBHOOK_PLATFORM_KEY_MISSING'               => 'Bitte fügen Sie zuerst Ihren Platform-Secret-Key in der Modulkonfiguration ein (sStripeTestKey / sStripeLiveKey).',
    'STRIPE_WEBHOOK_NOT_CONFIGURED'                     => 'Nicht konfiguriert',
    'STRIPE_WEBHOOK_CLEAR_ALL_BUTTON'                   => 'Alle Webhooks löschen',
    'STRIPE_WEBHOOK_CLEAR_ALL_CONFIRM'                  => 'Dies löscht die für diesen Shop registrierten Webhook-Endpoints in Ihrem Stripe-Plattform-Account. Fortfahren?',
    'STRIPE_WEBHOOK_CLEAR_ALL_ERROR'                    => 'Webhooks konnten nicht gelöscht werden.',
    'STRIPE_WEBHOOK_SESSION_EXPIRED'                    => 'Sitzung abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',

    // Platform-Key-Einstellungsbeschriftungen (in der Modulkonfiguration)
    'SHOP_MODULE_sStripeTestKey'                        => 'Test Platform-Secret-Key (für Webhook-Verwaltung)',
    'SHOP_MODULE_sStripeLiveKey'                        => 'Live Platform-Secret-Key (für Webhook-Verwaltung)',
    'HELP_SHOP_MODULE_sStripeTestKey'                   => 'Fügen Sie Ihren Stripe-Platform-Standard-Secret-Key (sk_test_…) aus Stripe Dashboard → Entwickler → API-Schlüssel ein. Wird nur für die Registrierung von Connect-Webhooks verwendet — unterscheidet sich vom Connected-Account-Zugriffstoken. Test- und Live-Schlüssel müssen der richtigen Umgebung entsprechen.',
    'HELP_SHOP_MODULE_sStripeLiveKey'                   => 'Fügen Sie Ihren Stripe-Platform-Standard-Secret-Key (sk_live_…) aus Stripe Dashboard → Entwickler → API-Schlüssel ein. Wird nur für die Registrierung von Connect-Webhooks verwendet — unterscheidet sich vom Connected-Account-Zugriffstoken. Test- und Live-Schlüssel müssen der richtigen Umgebung entsprechen.',

    // Erfassung (Manueller Erfassungsmodus)
    'STRIPE_CAPTURE_PAYMENT'                            => 'Zahlung erfassen',
    'STRIPE_CAPTURE_REQUIRED'                           => 'Zahlungserfassung erforderlich',
    'STRIPE_CAPTURE_REQUIRED_TEXT'                      => 'Diese Zahlung wurde autorisiert, aber noch nicht erfasst. Sie müssen die Zahlung erfassen, um die Transaktion abzuschließen.',
    'STRIPE_CAPTURE_AMOUNT_TEXT'                        => 'Autorisierter Betrag zur Erfassung',
    'STRIPE_CAPTURE_REASON'                             => 'Erfassungshinweis (optional)',
    'STRIPE_CAPTURE_REASON_PLACEHOLDER'                 => 'z.B. Bestellung versandbereit',
    'STRIPE_CAPTURE_SUBMIT'                             => 'Zahlung erfassen',
    'STRIPE_CAPTURE_SUCCESSFUL'                         => 'Zahlungserfassung war erfolgreich.',
    'STRIPE_CAPTURE_FAILED'                             => 'Zahlungserfassung fehlgeschlagen.',
    'STRIPE_CAPTURE_NO_ORDER'                           => 'Bestellung nicht gefunden.',
    'STRIPE_CAPTURE_NO_TRANSACTION'                     => 'Keine Transaktions-ID für diese Bestellung gefunden.',

    // Autorisierung stornieren (Manueller Erfassungsmodus)
    'STRIPE_CANCEL_AUTHORIZATION'                       => 'Autorisierung stornieren',
    'STRIPE_CANCEL_AUTHORIZATION_TEXT'                  => 'Stornieren Sie diese Autorisierung, um die auf der Kundenkarte reservierten Gelder freizugeben. Diese Aktion kann nicht rückgängig gemacht werden.',
    'STRIPE_CANCEL_REASON'                              => 'Stornierungsgrund (optional)',
    'STRIPE_CANCEL_DUPLICATE'                           => 'Duplikat',
    'STRIPE_CANCEL_CUSTOMER'                            => 'Vom Kunden angefordert',
    'STRIPE_CANCEL_FRAUD'                               => 'Betrugsverdacht',
    'STRIPE_CANCEL_ABANDONED'                           => 'Abgebrochen',
    'STRIPE_CANCEL_SUBMIT'                              => 'Autorisierung stornieren',
    'STRIPE_CANCEL_CONFIRM'                             => 'Sind Sie sicher, dass Sie diese Autorisierung stornieren möchten? Diese Aktion kann nicht rückgängig gemacht werden.',
    'STRIPE_CAPTURE_CONFIRM'                            => 'Sind Sie sicher, dass Sie diese Zahlung erfassen möchten?',
    'STRIPE_REFUND_CONFIRM'                             => 'Sind Sie sicher, dass Sie diese Zahlung erstatten möchten?',
    'STRIPE_CANCEL_SUCCESSFUL'                          => 'Autorisierung wurde erfolgreich storniert.',
    'STRIPE_CANCEL_FAILED'                              => 'Stornierung der Autorisierung fehlgeschlagen.',
    'STRIPE_CANCEL_NO_ORDER'                            => 'Bestellung nicht gefunden.',
    'STRIPE_CANCEL_NO_TRANSACTION'                      => 'Keine Transaktions-ID für diese Bestellung gefunden.',

    // Sprint 113 — maskierte API-Schlüssel-Felder mit Augen-Umschalter
    'STRIPE_REVEAL_API_KEY'                             => 'API-Schlüssel anzeigen',
    'STRIPE_HIDE_API_KEY'                               => 'API-Schlüssel verbergen',
    // Sprint 120 (STRP-129) — Validierungsmeldungen für den Buchungsgrund.
    // Spiegelt die Storefront-Schlüssel aus translations/de — der Admin-Kontext
    // löst translateString() über views/admin_twig auf, nicht translations/.
    'STRIPE_VALIDATION_FIELD_INVALID'                   => 'Das Feld %1$s ist ungültig. Erlaubte Zeichen sind: %2$s',
    'STRIPE_VALIDATION_LABEL_CAPTUREREASON'             => 'Buchungsgrund',
    'STRIPE_VALIDATION_CLASS_LETTERS'                   => 'Buchstaben',
    'STRIPE_VALIDATION_CLASS_DIGITS'                    => 'Ziffern',
    'STRIPE_VALIDATION_CLASS_SPACES'                    => 'Leerzeichen',

    // Sprint 121 (STRP-129) — semantische Betragsvalidierung + Erstattungsbeschreibung.
    'STRIPE_VALIDATION_LABEL_REFUNDDESCRIPTION'         => 'Erstattungsbeschreibung',
    'STRIPE_VALIDATION_AMOUNT_MALFORMED'                => 'Der Betrag ist keine gültige Zahl. Verwenden Sie ein Format wie 12,50.',
    'STRIPE_VALIDATION_AMOUNT_NOT_POSITIVE'             => 'Der Betrag muss größer als Null sein.',
    'STRIPE_VALIDATION_AMOUNT_PRECISION'                => 'Der Betrag hat zu viele Nachkommastellen für diese Währung.',
    'STRIPE_VALIDATION_AMOUNT_EXCEEDS_BOUND'            => 'Der Betrag überschreitet das verfügbare Maximum für diese Aktion.',
    'STRIPE_VALIDATION_AMOUNT_BOUND_UNAVAILABLE'        => 'Der verfügbare Betrag konnte nicht mit Stripe verifiziert werden. Bitte versuchen Sie es erneut.',
    // OPC-192: every mount creates a Stripe checkout session, which freezes
    // an amount — so this chooses between one per settled change and one
    // per deliberate click.
    'SHOP_MODULE_sStripeEmbeddedMountMode'                => 'Embedded Checkout: wann das Zahlungsformular erscheint',
    'SHOP_MODULE_sStripeEmbeddedMountMode_manual'         => 'Auf Anforderung (Kunde klickt Bezahlen)',
    'SHOP_MODULE_sStripeEmbeddedMountMode_auto'           => 'Automatisch, sobald der Warenkorb feststeht',
);
