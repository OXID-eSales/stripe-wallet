<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\WebhookLogService;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use OxidEsales\Payments\Stripe\Service\Factory\WebhookDbLogServiceFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 4 TDD tests: WebhookDbLogServiceFactory wires isWebhookLoggingEnabled()
 * into the payment-base WebhookLogService as the $shouldLogPayload closure.
 *
 * Key assertions:
 * - When blStripeLogWebhooks is ON: save() receives a log with payload set;
 *   PSR-3 mirror is emitted.
 * - When blStripeLogWebhooks is OFF: save() is still called (idempotency row
 *   written), but payload is null and PSR-3 mirror is NOT emitted.
 * - Factory always returns a WebhookLogService instance.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\WebhookDbLogServiceFactory::class)]
#[\PHPUnit\Framework\Attributes\Group('logging')]
#[\PHPUnit\Framework\Attributes\Group('phase-4')]
final class WebhookDbLogServiceFactoryTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private ModuleConfigurationServiceInterface&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
    }

    public function testCreateReturnsWebhookLogServiceInstance(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(true);

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $this->assertInstanceOf(WebhookLogService::class, $service);
    }

    public function testWithLoggingEnabledPayloadIsPersisted(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(true);

        $savedLog = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog): bool {
                $savedLog = $log;
                return true;
            }));

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_on', 'payment_intent.succeeded', ['key' => 'val'], 'stripe');

        $this->assertNotNull($savedLog);
        $this->assertSame(['key' => 'val'], $savedLog->getPayload());
    }

    public function testWithLoggingEnabledPsr3MirrorIsEmitted(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(true);

        $this->repository->method('save');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Webhook event received', $this->anything());

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_psr3_on', 'payment_intent.succeeded', [], 'stripe');
    }

    public function testWithLoggingDisabledRowIsStillSaved(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(false);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log): bool {
                return $log->getEventId() === 'evt_off';
            }));

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_off', 'payment_intent.succeeded', ['secret' => 'data'], 'stripe');
    }

    public function testWithLoggingDisabledPayloadIsOmitted(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(false);

        $savedLog = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog): bool {
                $savedLog = $log;
                return true;
            }));

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_nopayload', 'checkout.session.completed', ['amount' => 500], 'stripe');

        $this->assertNotNull($savedLog);
        $this->assertNull($savedLog->getPayload(), 'OXPAYLOAD must be null when webhook logging is disabled');
    }

    public function testWithLoggingDisabledPsr3MirrorIsNotEmitted(): void
    {
        $this->config->method('isWebhookLoggingEnabled')->willReturn(false);

        $this->repository->method('save');

        $this->logger->expects($this->never())
            ->method('info');

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_silent', 'payment_intent.succeeded', [], 'stripe');
    }

    public function testConfigIsConsultedAtCallTimeNotConstructTime(): void
    {
        // The closure captures the config service reference; isWebhookLoggingEnabled()
        // is evaluated on each call to logEventReceived(), not at factory construction.
        // First call: logging ON → payload set. Second call: logging OFF → payload null.
        $callCount = 0;
        $this->config->method('isWebhookLoggingEnabled')
            ->willReturnCallback(function () use (&$callCount): bool {
                $callCount++;
                return $callCount === 1; // true on first call, false on second
            });

        $savedLogs = [];
        $this->repository->expects($this->exactly(2))
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLogs): bool {
                $savedLogs[] = $log;
                return true;
            }));

        $this->logger->method('info');

        $factory = new WebhookDbLogServiceFactory($this->repository, $this->logger, $this->config);
        $service = $factory->create();

        $service->logEventReceived('evt_first', 'payment_intent.succeeded', ['a' => 1], 'stripe');
        $service->logEventReceived('evt_second', 'payment_intent.succeeded', ['b' => 2], 'stripe');

        $this->assertSame(['a' => 1], $savedLogs[0]->getPayload(), 'First call: logging ON, payload set');
        $this->assertNull($savedLogs[1]->getPayload(), 'Second call: logging OFF, payload null');
    }
}
