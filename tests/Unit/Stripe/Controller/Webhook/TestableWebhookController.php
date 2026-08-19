<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRequestGuardInterface;

/**
 * Testable subclass (module CLAUDE.md pattern): OXID controllers cannot be
 * constructed with DI, so the seams the controller already exposes are
 * overridden instead of booting the shop.
 */
class TestableWebhookController extends WebhookController
{
    public ?WebhookRequestGuardInterface $testGuard = null;
    public int $sentStatus = 0;
    public ?string $sentLogAction = null;
    public bool $processorWasUsed = false;

    /** @phpstan-ignore-next-line deliberately skips the OXID controller bootstrap */
    public function __construct()
    {
    }

    public function exposedGuard(): ?WebhookRequestGuardInterface
    {
        return $this->getGuard();
    }

    public function installMinimalFallbackChain(): void
    {
        $this->testGuard = $this->buildMinimalGuardChain();
    }

    public function markDegraded(): void
    {
        $this->setGuardChainDegraded(true);
    }

    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->testGuard;
    }

    protected function setResponseContentType(): void
    {
    }

    /** @return array{string, string, string} */
    protected function extractWebhookInput(): array
    {
        return ['{"id":"evt_1"}', 'sig_1', '127.0.0.1'];
    }

    protected function processWebhook(string $payload, string $signature, string $remoteIp): void
    {
        $this->processorWasUsed = true;
    }

    protected function sendErrorResponse(
        string $payload,
        string $message,
        int $statusCode,
        ?string $logAction = null
    ): never {
        $this->sentStatus = $statusCode;
        $this->sentLogAction = $logAction;

        throw new StopRenderingException();
    }
}
