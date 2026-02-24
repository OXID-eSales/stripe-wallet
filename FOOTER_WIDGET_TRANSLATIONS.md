# Stripe Footer Widget - Translation Keys

This document lists all translation keys required for the Stripe Footer Widget.

Add these translations to your Stripe module's language files:
- `Application/translations/en/stripe_lang.php`
- `Application/translations/de/stripe_lang.php`

## English Translations

```php
<?php

$aLang = [
    'charset' => 'UTF-8',

    // Footer Widget - Security Disclaimers
    'STRIPE_SECURE_PAYMENT_TITLE' => 'Secure Payment with Stripe',
    'STRIPE_PCI_DISCLAIMER' => 'Your payment information is processed securely by Stripe. We never store your card details on our servers. All transactions are PCI DSS compliant.',
    'STRIPE_PRIVACY_POLICY' => 'Stripe Privacy Policy',
    'STRIPE_SECURE_PAYMENT_BADGE' => 'Secured by Stripe',

    // Footer Widget - Terms
    'STRIPE_AND_STRIPE_TERMS_CONNECTOR' => 'and the',
    'STRIPE_CONSUMER_TERMS' => 'Stripe Consumer Terms',

    // Footer Widget - Submit Button
    'STRIPE_PAY_SECURELY' => 'Pay Securely',
    'STRIPE_PROCESSING_PAYMENT' => 'Processing Payment...',
    'STRIPE_PROCESSING_PAYMENT_MESSAGE' => 'Please wait while we process your payment securely.',
    'STRIPE_DO_NOT_CLOSE' => 'Please do not close this window or press the back button.',

    // Footer Widget - Error Messages
    'STRIPE_PAYMENT_ERROR_TITLE' => 'Payment Error',

    // Footer Widget - Debug Mode
    'STRIPE_DEBUG_MODE' => 'Debug Mode Active',
];
```

## German Translations

```php
<?php

$aLang = [
    'charset' => 'UTF-8',

    // Footer Widget - Security Disclaimers
    'STRIPE_SECURE_PAYMENT_TITLE' => 'Sichere Zahlung mit Stripe',
    'STRIPE_PCI_DISCLAIMER' => 'Ihre Zahlungsinformationen werden sicher von Stripe verarbeitet. Wir speichern niemals Ihre Kartendaten auf unseren Servern. Alle Transaktionen sind PCI DSS konform.',
    'STRIPE_PRIVACY_POLICY' => 'Stripe Datenschutzerklärung',
    'STRIPE_SECURE_PAYMENT_BADGE' => 'Gesichert durch Stripe',

    // Footer Widget - Terms
    'STRIPE_AND_STRIPE_TERMS_CONNECTOR' => 'und die',
    'STRIPE_CONSUMER_TERMS' => 'Stripe Nutzungsbedingungen',

    // Footer Widget - Submit Button
    'STRIPE_PAY_SECURELY' => 'Sicher bezahlen',
    'STRIPE_PROCESSING_PAYMENT' => 'Zahlung wird verarbeitet...',
    'STRIPE_PROCESSING_PAYMENT_MESSAGE' => 'Bitte warten Sie, während wir Ihre Zahlung sicher verarbeiten.',
    'STRIPE_DO_NOT_CLOSE' => 'Bitte schließen Sie dieses Fenster nicht und drücken Sie nicht die Zurück-Taste.',

    // Footer Widget - Error Messages
    'STRIPE_PAYMENT_ERROR_TITLE' => 'Zahlungsfehler',

    // Footer Widget - Debug Mode
    'STRIPE_DEBUG_MODE' => 'Debug-Modus aktiv',
];
```

## Usage in Templates

These translation keys are used in the footer widget template:

```twig
{{ translate({ ident: "STRIPE_SECURE_PAYMENT_TITLE" }) }}
{{ translate({ ident: "STRIPE_PCI_DISCLAIMER" }) }}
{{ translate({ ident: "STRIPE_PAY_SECURELY" }) }}
```

## Adding Translations to Your Module

1. Create or edit language files:
   - `Application/translations/en/stripe_lang.php`
   - `Application/translations/de/stripe_lang.php`

2. Add the translation arrays from above

3. Clear OXID cache:
   ```bash
   make clear-cache
   # or
   ./clear-cache.sh
   ```

4. Verify translations appear in the footer widget
