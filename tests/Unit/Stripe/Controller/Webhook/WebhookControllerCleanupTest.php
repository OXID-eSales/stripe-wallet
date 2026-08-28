<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * S5 (sprint-114.11a): WebhookController::cleanupStaleNotFinishedOrders() must use
 * the RetryCleanupService stored in init() — it must NOT re-fetch the DI container
 * mid-request (R-4.2: never twice).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController::class)]
final class WebhookControllerCleanupTest extends TestCase
{
    /**
     * After init() stores the cleanup service, cleanupStaleNotFinishedOrders()
     * must delegate to it — not call ContainerFactory::getInstance() again.
     */
    public function testCleanupUsesStoredCleanupService(): void
    {
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService
            ->expects($this->once())
            ->method('cleanupStaleContracts')
            ->with(30, 50)
            ->willReturn(0);

        $controller = new TestableWebhookControllerForCleanup($cleanupService);
        $controller->exposeCleanup();
    }

    /**
     * When the stored cleanup service returns a positive count, the method
     * must proceed without throwing (logging is an OXID Registry concern and
     * is not tested here).
     */
    public function testCleanupHandlesNonZeroResult(): void
    {
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService->method('cleanupStaleContracts')->willReturn(3);

        $controller = new TestableWebhookControllerForCleanup($cleanupService);

        // Must not throw
        $controller->exposeCleanup();
        $this->addToAssertionCount(1);
    }

    /**
     * STRP-168 item 3. The horizon was a hardcoded 30 minutes, so a shop whose
     * customers legitimately take longer — bank transfer, Klarna — had the
     * sweep cancelling checkouts they were still in, with no way to raise it
     * short of a code change.
     */
    public function testSweepUsesTheConfiguredStaleCheckoutTimeout(): void
    {
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService
            ->expects($this->once())
            ->method('cleanupStaleContracts')
            ->with(120, 50)
            ->willReturn(0);

        $settings = $this->createMock(NotFinishedOrderCleanupSettingsInterface::class);
        $settings->method('getStaleCheckoutMinutes')->willReturn(120);

        (new TestableWebhookControllerForCleanup($cleanupService, $settings))->exposeCleanup();
    }

    /**
     * The setting lives behind the container. If it cannot be read the sweep
     * still has to run — on the horizon it had before it was configurable.
     */
    public function testSweepFallsBackToThirtyMinutesWhenTheSettingIsUnavailable(): void
    {
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService
            ->expects($this->once())
            ->method('cleanupStaleContracts')
            ->with(30, 50)
            ->willReturn(0);

        (new TestableWebhookControllerForCleanup($cleanupService))->exposeCleanup();
    }

    /**
     * STRP-168 item 4. The sweep runs inline in the webhook request, so one
     * pass must be bounded: an unbounded backlog is paid for out of the
     * response time, and a provider that times out retries.
     */
    public function testSweepIsBoundedToOneBatch(): void
    {
        $observed = null;
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService
            ->method('cleanupStaleContracts')
            ->willReturnCallback(function (int $minutes, ?int $limit) use (&$observed): int {
                $observed = $limit;

                return 0;
            });

        (new TestableWebhookControllerForCleanup($cleanupService))->exposeCleanup();

        $this->assertNotNull($observed, 'the sweep must pass a bound, not null');
        $this->assertLessThanOrEqual(200, $observed, 'the bound must actually bound something');
    }

    /**
     * When the stored cleanup service throws, cleanupStaleNotFinishedOrders()
     * must swallow the exception (fail-soft, non-critical path).
     */
    public function testCleanupSwallowsServiceException(): void
    {
        $cleanupService = $this->createMock(RetryCleanupService::class);
        $cleanupService
            ->method('cleanupStaleContracts')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        $controller = new TestableWebhookControllerForCleanup($cleanupService);

        // Must not propagate the exception
        $controller->exposeCleanup();
        $this->addToAssertionCount(1);
    }
}

/**
 * Testable subclass for WebhookController cleanup seam tests.
 *
 * Injects a mock RetryCleanupService via the protected property and
 * skips OXID admin bootstrap. exposeCleanup() calls the production
 * cleanupStaleNotFinishedOrders() method without invoking render().
 */
class TestableWebhookControllerForCleanup extends WebhookController
{
    public function __construct(
        RetryCleanupService $cleanupService,
        ?NotFinishedOrderCleanupSettingsInterface $cleanupSettings = null
    ) {
        // Skip OXID parent bootstrap — not needed for these unit tests.
        $this->cleanupService = $cleanupService;
        $this->cleanupSettings = $cleanupSettings;
    }

    public function exposeCleanup(): void
    {
        $this->cleanupStaleNotFinishedOrders();
    }
}
