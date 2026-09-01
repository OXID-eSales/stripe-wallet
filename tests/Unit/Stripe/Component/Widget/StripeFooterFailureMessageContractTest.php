<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Component\Widget;

use PHPUnit\Framework\TestCase;

/**
 * OPC-153 regression guard: when a checkout is refused, this widget must show a
 * translated label chosen by the server's `errorCode` — and must never render
 * server text.
 *
 * The widget read `checkoutResult.error`, a key OPC's `CheckoutResult::toArray()`
 * does not emit (it names the field `errorMessage`), so the hardcoded
 * 'Failed to create checkout session' was painted for every refusal alike:
 * consents rejected, payment method unsupported, invalid address, handler
 * failure. Three tickets describe that one sentence — OPC-153, OPC-171,
 * OPC-181 — none separable from the UI, and OPC-47 hid behind it earlier (see
 * StripeFooterConsentForwardingTest's docblock in this directory).
 *
 * Displaying `errorMessage` instead would have been the wrong fix twice over:
 * OPC's `PROCESSING_ERROR` interpolates `$e->getMessage()`, so an exception
 * would land in the shopper's box, and every message in `CheckoutService` is
 * hardcoded English regardless of shop language.
 *
 * The code → label mapping therefore lives in exactly ONE place, OPC's
 * `utils/checkout_error_catalog.js`, published as
 * `window.OnepageCheckout.checkoutErrorText`. This widget is inline JS outside
 * that bundle and consumes the published resolver rather than carrying a second
 * copy of the code table. What this test protects is that arrangement: a
 * private copy here would drift the day OPC adds a code.
 *
 * Pinned at template-content level rather than by rendering the Twig and
 * executing the JS, matching StripeFooterConsentForwardingTest.
 */
final class StripeFooterFailureMessageContractTest extends TestCase
{
    private const TEMPLATE_PATH =
        __DIR__ . '/../../../../../views/twig/widget/checkout/stripe-footer.html.twig';

    /**
     * Twig comments are stripped before every assertion: this fix documents the
     * discarded key and the rejected alternative by name on purpose, and
     * asserting against raw source would make the explanation fail the test it
     * explains.
     */
    private function templateSource(): string
    {
        $source = (string)file_get_contents(self::TEMPLATE_PATH);

        return (string)preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * The JS docblocks are `/** ... *\/`, not Twig comments, so strip those too
     * where an assertion must see code only.
     */
    private function templateCode(): string
    {
        return (string)preg_replace('#/\*.*?\*/#s', '', $this->templateSource());
    }

    public function testFooterResolvesTheLabelThroughOpcPublishedCatalog(): void
    {
        self::assertMatchesRegularExpression(
            '/window\.OnepageCheckout\.checkoutErrorText/',
            $this->templateCode(),
            'The widget must resolve failure labels through the catalog OPC publishes,'
            . ' so the code → label mapping has exactly one owner.'
        );
    }

    /**
     * The resolver body only. Scoped deliberately: the template's prose does
     * name server codes — the consent-forwarding comment cites
     * CONSENTS_NOT_ACCEPTED to explain why it forwards, which is worth keeping —
     * and a whole-file assertion would either fail on that or force a `//`
     * comment strip that mangles the `https://` in real code lines.
     */
    private function resolverBody(): string
    {
        $code = $this->templateCode();
        $start = strpos($code, '_failureTextFrom(result)');
        self::assertNotFalse($start, 'The resolver must exist to be asserted about.');
        $end = strpos($code, "\n    }", (int)$start);
        self::assertNotFalse($end, 'The resolver must be a closed method body.');

        return substr($code, (int)$start, (int)$end - (int)$start);
    }

    public function testResolverCarriesNoPrivateCopyOfTheServerCodeTable(): void
    {
        $body = $this->resolverBody();

        foreach (['CONSENTS_NOT_ACCEPTED', 'PROCESSING_ERROR', 'INVALID_ADDRESS'] as $serverCode) {
            self::assertStringNotContainsString(
                $serverCode,
                $body,
                'A second copy of the code table here would drift the day OPC adds a code;'
                . ' the resolver is published for exactly that reason.'
            );
        }
    }

    public function testFooterNeverReturnsServerAuthoredText(): void
    {
        $code = $this->templateCode();

        self::assertDoesNotMatchRegularExpression(
            '/return\s+payload\.errorMessage/',
            $code,
            'PROCESSING_ERROR interpolates an exception message server-side — returning'
            . ' errorMessage would paint file paths into the shopper error box.'
        );

        self::assertDoesNotMatchRegularExpression(
            '/checkoutResult\.error\s*\|\|/',
            $code,
            'Reading `checkoutResult.error` alone was the original defect: that key is'
            . ' absent from the OPC payload, so the fallback sentence always won.'
        );
    }

    public function testFooterFallsBackToItsOwnI18nOutsideOpc(): void
    {
        self::assertMatchesRegularExpression(
            '/oStripe\.i18n\.SESSION_FAILED/',
            $this->templateCode(),
            'Rendered outside the OPC modal there is no published resolver, so the'
            . ' widget must still produce a translated sentence of its own.'
        );
    }

    /**
     * Counts updated 2026-09-01: OPC-192 merged the eager mount and submitPayment
     * into a single processCheckout call, so there is now one failure branch
     * rather than two. What is still pinned is that the branch resolves its text
     * through the shared resolver instead of inline — and that the resolver is
     * actually declared. OPC-192 deleted it and left this call site behind, so
     * every refusal threw "_failureTextFrom is not a function"; the count below
     * is what caught that.
     */
    public function testTheProcessCheckoutFailureBranchUsesTheSharedResolver(): void
    {
        $code = $this->templateCode();

        self::assertSame(
            1,
            preg_match_all('/_failureTextFrom\(result\)/', $code),
            '_failureTextFrom() must be declared exactly once — a call site without a'
            . ' declaration turns every refusal into a TypeError.'
        );

        self::assertSame(
            1,
            preg_match_all('/this\._failureTextFrom\(/', $code),
            'The failure branch must go through _failureTextFrom() rather than'
            . ' resolving the text inline.'
        );
    }

    public function testTheFailureBranchLogsTheErrorCodeForDiagnosis(): void
    {
        self::assertSame(
            1,
            preg_match_all('/errorCode\s*\|\|\s*\'no code\'/', $this->templateCode()),
            'The failure branch must log the errorCode, so a repro names the guard'
            . ' that rejected the checkout instead of leaving QA to guess.'
        );
    }
}
