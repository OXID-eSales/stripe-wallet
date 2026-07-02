<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Component\Widget;

use PHPUnit\Framework\TestCase;

/**
 * OPC-47 regression guard: the Stripe footer widget MUST forward the user's
 * consent state to the OPC `processCheckout` endpoint, otherwise the OPC
 * server-side consent guard (CheckoutService since v1.3.9) rejects every
 * Stripe checkout attempt — even when the user has ticked both required
 * checkboxes — surfacing "Failed to create checkout session" in the UI.
 *
 * Razvan's 2026-06-09 OSC1 reproduction (video on OPC-47) captures the
 * regression: consents both ticked, requirements met, but the Stripe footer
 * still triggers the OPC reject because it sends ONLY `paymentMethodId` in
 * the request body. The OPC default footer was patched as part of OPC-47
 * itself (`_readConsentState()` in default_checkout_footer_controller.js,
 * commit 607d87a in the OPC module); the Stripe footer renders inside the
 * same OPC modal — same DOM, same `#confirmTermsCheckout` /
 * `#confirmPrivacyCheckout` checkboxes — so it must mirror the read.
 *
 * Pinning this contract at the template-content level rather than rendering
 * the Twig + executing the JS, because the only thing that matters here is
 * that the JSON body sent to `/index.php?cl=OeCheckoutApi&fnc=processCheckout`
 * carries the two consent keys. Same text-level pattern as
 * `opc_29_x_button_non_destructive.test.js` in the OPC module.
 */
final class StripeFooterConsentForwardingTest extends TestCase
{
    private const TEMPLATE_PATH =
        __DIR__ . '/../../../../../views/twig/widget/checkout/stripe-footer.html.twig';

    public function testStripeFooterTemplateExists(): void
    {
        self::assertFileExists(self::TEMPLATE_PATH);
    }

    public function testStripeFooterForwardsConfirmTermsAndConditionsToProcessCheckout(): void
    {
        $source = (string)file_get_contents(self::TEMPLATE_PATH);

        // Strip Twig comments so historical references in {# ... #} blocks
        // (e.g. JSDoc-style headers documenting the fix) don't satisfy the
        // contract by accident.
        $stripped = preg_replace('/\{#.*?#\}/s', '', $source);

        self::assertMatchesRegularExpression(
            '/confirmTermsAndConditions\s*:/',
            (string)$stripped,
            'Stripe footer JSON body must include a confirmTermsAndConditions key'
            . ' so OPC CheckoutService::processCheckout consent guard does not reject.'
        );
    }

    public function testStripeFooterForwardsConfirmPrivacyPolicyToProcessCheckout(): void
    {
        $source = (string)file_get_contents(self::TEMPLATE_PATH);
        $stripped = preg_replace('/\{#.*?#\}/s', '', $source);

        self::assertMatchesRegularExpression(
            '/confirmPrivacyPolicy\s*:/',
            (string)$stripped,
            'Stripe footer JSON body must include a confirmPrivacyPolicy key'
            . ' so OPC CheckoutService::processCheckout consent guard does not reject.'
        );
    }

    public function testStripeFooterReadsConsentCheckboxesFromOpcModalDom(): void
    {
        // The OPC modal renders #confirmTermsCheckout and #confirmPrivacyCheckout
        // in its shared footer fragment (modal-footer.html.twig). The Stripe
        // footer loads inside that modal as an AJAX-injected widget, sharing
        // DOM. Read via document.getElementById('confirmTermsCheckout') and
        // ('confirmPrivacyCheckout') keeps the source of truth in the OPC
        // modal markup (no duplicated state).
        $source = (string)file_get_contents(self::TEMPLATE_PATH);
        $stripped = preg_replace('/\{#.*?#\}/s', '', $source);

        self::assertStringContainsString(
            "getElementById('confirmTermsCheckout')",
            (string)$stripped,
            'Stripe footer should read the OPC modal checkbox by its canonical ID.'
        );
        self::assertStringContainsString(
            "getElementById('confirmPrivacyCheckout')",
            (string)$stripped,
            'Stripe footer should read the OPC modal checkbox by its canonical ID.'
        );
    }
}
