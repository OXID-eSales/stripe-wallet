<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

/**
 * Sprint 121 Phase B (STRP-129): regression pins for the cancel-reason
 * whitelist in cancelPaymentIntent(). Stripe's cancellation_reason is an
 * enum; unknown values must degrade to 'requested_by_customer', never
 * reach the API raw.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper
 * @group sprint-121
 */
final class PaymentIntentHelperCancelReasonTest extends TestCase
{
    public function testUnknownCancelReasonDegradesToRequestedByCustomer(): void
    {
        $client = $this->clientExpectingCancelParams(
            ['cancellation_reason' => 'requested_by_customer'],
        );

        $this->helper()->cancelPaymentIntent($client, 'pi_x', '<script>garbage');
    }

    public function testValidCancelReasonPassesThrough(): void
    {
        $client = $this->clientExpectingCancelParams(
            ['cancellation_reason' => 'fraudulent'],
        );

        $this->helper()->cancelPaymentIntent($client, 'pi_x', 'fraudulent');
    }

    public function testNullCancelReasonSendsNoReasonParam(): void
    {
        $client = $this->clientExpectingCancelParams([]);

        $this->helper()->cancelPaymentIntent($client, 'pi_x', null);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function helper(): PaymentIntentHelper
    {
        return new PaymentIntentHelper($this->createMock(IdempotencyRepositoryInterface::class));
    }

    /**
     * @param array<string, string> $expectedParams
     */
    private function clientExpectingCancelParams(array $expectedParams): StripeClient
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->expects(self::once())
            ->method('cancel')
            ->with('pi_x', $expectedParams)
            ->willReturn($this->createMock(PaymentIntent::class));

        $client = $this->createMock(StripeClient::class);
        $client->paymentIntents = $piService;

        return $client;
    }
}
