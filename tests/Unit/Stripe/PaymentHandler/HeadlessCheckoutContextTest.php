<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\Payments\Stripe\PaymentHandler\HeadlessCheckoutContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeadlessCheckoutContext::class)]
final class HeadlessCheckoutContextTest extends TestCase
{
    #[Test]
    public function aContextIsHeadlessOnlyWhenTheFlagIsExactlyTrue(): void
    {
        $this->assertTrue($this->contextWith(['headless' => true])->isHeadless());
        $this->assertFalse($this->contextWith(['headless' => 'true'])->isHeadless());
        $this->assertFalse($this->contextWith([])->isHeadless());
    }

    #[Test]
    public function theUiModeIsHostedUnlessAnEmbeddedOneIsGiven(): void
    {
        $this->assertFalse($this->contextWith([])->isEmbedded());
        $this->assertFalse($this->contextWith(['uiMode' => ''])->isEmbedded());
        $this->assertFalse($this->contextWith(['uiMode' => 'hosted'])->isEmbedded());
        $this->assertTrue($this->contextWith(['uiMode' => 'embedded'])->isEmbedded());
    }

    #[Test]
    public function theUiModeDefaultsToHostedWhenMissingEmptyOrNotAString(): void
    {
        $this->assertSame('hosted', $this->contextWith([])->uiMode());
        $this->assertSame('hosted', $this->contextWith(['uiMode' => ''])->uiMode());
        $this->assertSame('hosted', $this->contextWith(['uiMode' => 7])->uiMode());
        $this->assertSame('custom', $this->contextWith(['uiMode' => 'custom'])->uiMode());
    }

    #[Test]
    public function theBasketIdIsNullWhenMissingOrEmpty(): void
    {
        $this->assertNull($this->contextWith([])->basketId());
        $this->assertNull($this->contextWith(['basketId' => ''])->basketId());
        $this->assertSame('b-1', $this->contextWith(['basketId' => 'b-1'])->basketId());
    }

    #[Test]
    public function theSessionIdIsTheGivenOneOrDerivedFromTheBasket(): void
    {
        $this->assertSame('sess-9', $this->contextWith(['sessionId' => 'sess-9', 'basketId' => 'b-1'])->sessionId());
        $this->assertSame('headless:b-1', $this->contextWith(['basketId' => 'b-1'])->sessionId());
        $this->assertSame('headless:', $this->contextWith([])->sessionId());
    }

    #[Test]
    public function theSuccessUrlGetsTheCheckoutSessionPlaceholderAppended(): void
    {
        [$success] = $this->contextWith([], 'https://app.example/return', null)->returnUrls();
        $this->assertSame('https://app.example/return?session_id={CHECKOUT_SESSION_ID}', $success);

        [$success] = $this->contextWith([], 'https://app.example/return?x=1', null)->returnUrls();
        $this->assertSame('https://app.example/return?x=1&session_id={CHECKOUT_SESSION_ID}', $success);
    }

    #[Test]
    public function withoutACancelUrlTheShopperComesBackToTheReturnUrl(): void
    {
        [, $cancel] = $this->contextWith([], 'https://app.example/return', null)->returnUrls();
        $this->assertSame('https://app.example/return', $cancel);

        [, $cancel] = $this->contextWith([], 'https://app.example/return', '')->returnUrls();
        $this->assertSame('https://app.example/return', $cancel);

        [, $cancel] = $this->contextWith([], 'https://app.example/return', 'https://app.example/cancel')->returnUrls();
        $this->assertSame('https://app.example/cancel', $cancel);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function contextWith(array $metadata, ?string $returnUrl = null, ?string $cancelUrl = null): HeadlessCheckoutContext
    {
        $context = $this->createStub(PaymentContextInterface::class);
        $context->method('getMetadataValue')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $metadata[$key] ?? $default
        );
        $context->method('getReturnUrl')->willReturn($returnUrl);
        $context->method('getCancelUrl')->willReturn($cancelUrl);

        return new HeadlessCheckoutContext($context);
    }
}
