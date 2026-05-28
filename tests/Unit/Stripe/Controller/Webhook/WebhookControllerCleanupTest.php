<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * S5 (sprint-114.11a): WebhookController::cleanupStaleNotFinishedOrders() must use
 * the RetryCleanupService stored in init() — it must NOT re-fetch the DI container
 * mid-request (R-4.2: never twice).
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController
 */
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
            ->with(30)
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
    public function __construct(RetryCleanupService $cleanupService)
    {
        // Skip OXID parent bootstrap — not needed for these unit tests.
        $this->cleanupService = $cleanupService;
    }

    public function exposeCleanup(): void
    {
        $this->cleanupStaleNotFinishedOrders();
    }
}
