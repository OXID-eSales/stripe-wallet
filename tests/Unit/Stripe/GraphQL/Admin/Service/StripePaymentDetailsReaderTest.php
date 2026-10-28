<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OrderPaymentIntentIdReaderInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\PaymentIntentFacts;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetailsReader;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentIntentInsightInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

final class StripePaymentDetailsReaderTest extends TestCase
{
    public function testFind_AContractThatDoesNotExist_IsNull(): void
    {
        self::assertNull($this->reader($this->repositoryWith(null))->find('c-1'));
    }

    public function testFind_AContractOfAnotherProvider_IsNull(): void
    {
        $reader = $this->reader($this->repositoryWith($this->contract(provider: 'paypal')));

        self::assertNull($reader->find('c-1'));
    }

    public function testFind_ReadsTheFactsOfTheIntentAndLinksTheDashboard(): void
    {
        $reader = $this->reader(
            $this->repositoryWith($this->contract()),
            insight: $this->insight(new PaymentIntentFacts('requires_capture', 'ch_123', null)),
        );

        $details = $reader->find('c-1');

        self::assertSame('pi_123', $details?->paymentIntentId);
        self::assertSame('ch_123', $details->chargeId);
        self::assertSame('requires_capture', $details->intentStatus);
        self::assertSame('https://dashboard.stripe.com/test/payments/pi_123', $details->dashboardUrl);
        self::assertNull($details->apiError);
    }

    public function testFind_InLiveMode_TheDashboardLinkHasNoTestSegment(): void
    {
        $details = $this->reader($this->repositoryWith($this->contract()), testMode: false)->find('c-1');

        self::assertSame('https://dashboard.stripe.com/payments/pi_123', $details?->dashboardUrl);
    }

    public function testFind_TheExternalTransactionIdIsTheReferenceTheContractKeptFromCheckout(): void
    {
        $details = $this->reader($this->repositoryWith($this->contract(providerOrderId: 'cs_test_session')))->find('c-1');

        self::assertSame('cs_test_session', $details?->externalTransactionId);
    }

    public function testFind_WhenStripeCannotBeReached_TheStoredFactsStayAndTheErrorIsSaid(): void
    {
        $reader = $this->reader(
            $this->repositoryWith($this->contract()),
            insight: $this->insight(PaymentIntentFacts::unavailable('Could not connect to Stripe')),
        );

        $details = $reader->find('c-1');

        self::assertSame('pi_123', $details?->paymentIntentId);
        self::assertSame('https://dashboard.stripe.com/test/payments/pi_123', $details->dashboardUrl);
        self::assertNull($details->intentStatus);
        self::assertNull($details->chargeId);
        self::assertSame('Could not connect to Stripe', $details->apiError);
    }

    public function testFind_AnOrderWithoutAnIntent_HasNoIntentFactsAndStripeIsNotAsked(): void
    {
        $insight = $this->createMock(StripePaymentIntentInsightInterface::class);
        $insight->expects(self::never())->method('factsOf');

        $details = $this->reader($this->repositoryWith($this->contract()), intentId: '', insight: $insight)->find('c-1');

        self::assertNull($details?->paymentIntentId);
        self::assertNull($details->dashboardUrl);
        self::assertNull($details->intentStatus);
        self::assertNull($details->apiError);
    }

    public function testFind_AContractWithoutAnOrderYet_HasNoIntentFacts(): void
    {
        $details = $this->reader($this->repositoryWith($this->contract(orderId: null)))->find('c-1');

        self::assertNull($details?->paymentIntentId);
    }

    private function reader(
        ContractRepositoryInterface $contracts,
        string $intentId = 'pi_123',
        bool $testMode = true,
        ?StripePaymentIntentInsightInterface $insight = null,
    ): StripePaymentDetailsReader {
        $intentIds = $this->createStub(OrderPaymentIntentIdReaderInterface::class);
        $intentIds->method('intentIdOf')->willReturn($intentId);
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn($testMode);

        return new StripePaymentDetailsReader(
            $contracts,
            $intentIds,
            $insight ?? $this->insight(new PaymentIntentFacts('succeeded', 'ch_1', null)),
            $config,
        );
    }

    private function insight(PaymentIntentFacts $facts): StripePaymentIntentInsightInterface
    {
        $insight = $this->createStub(StripePaymentIntentInsightInterface::class);
        $insight->method('factsOf')->willReturn($facts);

        return $insight;
    }

    private function repositoryWith(?PaymentContractInterface $contract): ContractRepositoryInterface
    {
        $repository = $this->createStub(ContractRepositoryInterface::class);
        $repository->method('findById')->willReturn($contract);

        return $repository;
    }

    private function contract(string $provider = 'stripe', ?string $orderId = 'order-1', ?string $providerOrderId = 'pi_123'): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn($provider);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);

        return $contract;
    }
}
