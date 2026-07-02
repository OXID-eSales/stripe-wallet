<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Translator;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Translator\StripeEventTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeEventTranslator.
 *
 * Sprint 114.13 (O10): tests added before refactoring the instanceof ladder
 * to a mapping table. These tests constitute the characterization suite that
 * guards behavior parity during the refactor (R-1.4).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\EventSystem\Translator\StripeEventTranslator::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-114-13')]
final class StripeEventTranslatorTest extends TestCase
{
    private StripeEventTranslator $translator;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->translator = new StripeEventTranslator();
        $this->context = new EventContext([]);
    }

    // --- supports() ---

    public function testSupportsReturnsTrueForStripeProvider(): void
    {
        self::assertTrue($this->translator->supports(StripeDefinitions::PROVIDER));
    }

    public function testSupportsReturnsFalseForOtherProvider(): void
    {
        self::assertFalse($this->translator->supports('paypal'));
    }

    public function testSupportsReturnsFalseForEmptyProvider(): void
    {
        self::assertFalse($this->translator->supports(''));
    }

    // --- translate() mapped events ---

    public function testTranslateRefundRequestedEventReturnsStripeRefundRequestEvent(): void
    {
        $abstract = new RefundRequestedEvent($this->context);

        $result = $this->translator->translate($abstract);

        self::assertInstanceOf(StripeRefundRequestEvent::class, $result);
    }

    public function testTranslateCaptureRequestedEventReturnsStripeCaptureRequestEvent(): void
    {
        $abstract = new CaptureRequestedEvent($this->context);

        $result = $this->translator->translate($abstract);

        self::assertInstanceOf(StripeCaptureRequestEvent::class, $result);
    }

    public function testTranslateCancelAuthorizationRequestedEventReturnsStripeCancelAuthorizationRequestEvent(): void
    {
        $abstract = new CancelAuthorizationRequestedEvent($this->context);

        $result = $this->translator->translate($abstract);

        self::assertInstanceOf(StripeCancelAuthorizationRequestEvent::class, $result);
    }

    // --- translate() context forwarding ---

    public function testTranslateForwardsContextToStripeRefundEvent(): void
    {
        $context = new EventContext(['contractId' => 'c-123', 'amount' => 50.0]);
        $abstract = new RefundRequestedEvent($context);

        /** @var StripeRefundRequestEvent $result */
        $result = $this->translator->translate($abstract);

        self::assertInstanceOf(StripeRefundRequestEvent::class, $result);
        self::assertSame('c-123', $result->getContext()->get('contractId'));
    }

    public function testTranslateForwardsContextToStripeCaptureEvent(): void
    {
        $context = new EventContext(['contractId' => 'c-456', 'initiator' => 'webhook']);
        $abstract = new CaptureRequestedEvent($context);

        /** @var StripeCaptureRequestEvent $result */
        $result = $this->translator->translate($abstract);

        self::assertInstanceOf(StripeCaptureRequestEvent::class, $result);
        self::assertSame('webhook', $result->getContext()->get('initiator'));
    }

    // --- translate() unmapped event returns null ---

    public function testTranslateUnmappedEventReturnsNull(): void
    {
        // VoidAuthorizationRequestedEvent is a real subclass of
        // AbstractProviderRequestEvent that has no Stripe mapping.
        $unmapped = new \OxidEsales\PaymentBase\EventSystem\Event\Request\VoidAuthorizationRequestedEvent(
            $this->context
        );

        $result = $this->translator->translate($unmapped);

        self::assertNull($result);
    }

    public function testTranslateReturnsNullWhenContextIsNotConcreteEventContext(): void
    {
        // AbstractProviderRequestEvent accepts EventContextInterface, but
        // StripeEventTranslator guards against non-concrete EventContext.
        $nonConcreteContext = $this->createMock(EventContextInterface::class);

        // We need a real AbstractProviderRequestEvent subclass — use RefundRequestedEvent
        // but pass a non-concrete context via reflection.
        $abstract = new RefundRequestedEvent($nonConcreteContext);

        $result = $this->translator->translate($abstract);

        self::assertNull($result);
    }
}
