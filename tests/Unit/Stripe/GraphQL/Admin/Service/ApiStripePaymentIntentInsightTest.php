<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\ApiStripePaymentIntentInsight;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiStripePaymentIntentInsightTest extends TestCase
{
    public function testFactsOf_TakesStatusAndLatestChargeFromStripe(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects(self::once())->method('retrievePaymentIntent')->with('pi_1')
            ->willReturn(new StripePaymentIntentDto('pi_1', 'requires_capture', 1000, 'eur', 1, 'ch_9'));

        $facts = $this->insight($adapter)->factsOf('pi_1');

        self::assertSame('requires_capture', $facts->status);
        self::assertSame('ch_9', $facts->chargeId);
        self::assertNull($facts->apiError);
    }

    public function testFactsOf_IsAskedOncePerIntentAndRequest(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects(self::once())->method('retrievePaymentIntent')
            ->willReturn(new StripePaymentIntentDto('pi_1', 'succeeded', 1000, 'eur', 1, 'ch_9'));
        $insight = $this->insight($adapter);

        $insight->factsOf('pi_1');
        $insight->factsOf('pi_1');
    }

    public function testFactsOf_WhenStripeFails_IsUnavailableWithAMessageThatCarriesNoKeyAndNoPayload(): void
    {
        $adapter = $this->createStub(StripeAdapterInterface::class);
        $adapter->method('retrievePaymentIntent')->willThrowException(
            new RuntimeException('Invalid API Key provided: sk_test_****abcd. Request {"id":"req_1","payload":"secret-body"}')
        );

        $facts = $this->insight($adapter)->factsOf('pi_1');

        self::assertNull($facts->status);
        self::assertNull($facts->chargeId);
        self::assertStringContainsString('Invalid API Key provided', (string) $facts->apiError);
        self::assertStringNotContainsString('sk_test_', (string) $facts->apiError);
    }

    public function testFactsOf_AFactoryThatCannotBuildTheAdapter_IsUnavailableToo(): void
    {
        $factory = $this->createStub(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willThrowException(new RuntimeException('No API key configured'));

        $facts = (new ApiStripePaymentIntentInsight($factory))->factsOf('pi_1');

        self::assertSame('No API key configured', $facts->apiError);
    }

    private function insight(StripeAdapterInterface $adapter): ApiStripePaymentIntentInsight
    {
        $factory = $this->createStub(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);

        return new ApiStripePaymentIntentInsight($factory);
    }
}
