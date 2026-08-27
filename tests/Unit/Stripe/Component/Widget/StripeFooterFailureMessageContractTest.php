<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Component\Widget;

use PHPUnit\Framework\TestCase;

/**
 * OPC-153 regression guard: the Stripe footer widget MUST read the reason a
 * failed `processCheckout` actually returned.
 *
 * OPC answers with `CheckoutResult::toArray()`, whose failure key is
 * `errorMessage` — there is no `error` key in that payload. The widget read
 * only `error`, so every server-side reason was discarded and replaced by one
 * generic sentence, "Failed to create checkout session". Consents rejected,
 * payment method unsupported, invalid address and handler failure all painted
 * the same box, in the customer's face and in the console.
 *
 * The cost was three tickets describing one sentence: OPC-153, OPC-171 and
 * OPC-181, none of them separable from the UI. OPC-47 hid behind the same
 * sentence before them — see StripeFooterConsentForwardingTest, whose docblock
 * records that symptom.
 *
 * Pinned at template-content level rather than by rendering the Twig and
 * executing the JS, matching StripeFooterConsentForwardingTest: what matters
 * is which key the widget reads.
 */
final class StripeFooterFailureMessageContractTest extends TestCase
{
    private const TEMPLATE_PATH =
        __DIR__ . '/../../../../../views/twig/widget/checkout/stripe-footer.html.twig';

    /**
     * Twig comments are stripped before every assertion: this fix documents the
     * discarded key by name on purpose, and asserting against raw source would
     * make the explanation fail the test it explains.
     */
    private function templateSource(): string
    {
        $source = (string)file_get_contents(self::TEMPLATE_PATH);

        return (string)preg_replace('/\{#.*?#\}/s', '', $source);
    }

    public function testFooterReadsTheErrorMessageKeyOpcActuallySends(): void
    {
        self::assertMatchesRegularExpression(
            '/errorMessage\s*\|\|/',
            $this->templateSource(),
            'The footer must read `errorMessage` from the failed CheckoutResult —'
            . ' that is the key OPC CheckoutResult::toArray() emits.'
        );
    }

    public function testFooterKeepsTheErrorKeyAsFallbackForNonOpcEmbeddings(): void
    {
        self::assertMatchesRegularExpression(
            '/errorMessage\s*\|\|\s*\w+\.error\b/',
            $this->templateSource(),
            'The classic order-page endpoint answers with `error`, so it must stay'
            . ' in the chain behind `errorMessage`.'
        );
    }

    public function testNoFailureBranchReadsTheErrorKeyAlone(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/checkoutResult\.error\s*\|\|/',
            $this->templateSource(),
            'Reading `checkoutResult.error` alone is the defect: that key is absent'
            . ' from the OPC payload, so the fallback sentence always won.'
        );
    }

    public function testBothProcessCheckoutFailureBranchesUseTheSharedResolver(): void
    {
        $source = $this->templateSource();

        self::assertSame(
            1,
            preg_match_all('/_failureTextFrom\(result\)/', $source),
            '_failureTextFrom() must be declared exactly once.'
        );

        self::assertSame(
            2,
            preg_match_all('/this\._failureTextFrom\(/', $source),
            'Both failure branches — the eager mount and submitPayment — must go'
            . ' through _failureTextFrom() rather than resolving the text inline.'
        );
    }

    public function testFailureBranchesLogTheErrorCodeForDiagnosis(): void
    {
        self::assertSame(
            2,
            preg_match_all('/errorCode\s*\|\|\s*\'no code\'/', $this->templateSource()),
            'Each failure branch must log the errorCode, so a repro names the guard'
            . ' that rejected the checkout instead of leaving QA to guess.'
        );
    }
}
