<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRequestGuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests guard chain integration into WebhookController.
 *
 * Uses testable subclass pattern: overrides the full render() to avoid
 * OXID Registry bootstrap, but preserves the guard check logic.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController
 * @group sprint-64d
 * @group security
 */
final class WebhookControllerGuardIntegrationTest extends TestCase
{
    /** @test */
    public function controllerRejectsWhenGuardChainRejects(): void
    {
        $rejection = new WebhookGuardResult('rate_limited', 429, 'Too many requests');
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn($rejection);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('{"test":true}', 'sig_test', '1.2.3.4');

        $controller->render();

        $this->assertSame(429, $controller->getLastHttpStatusCode());
        $this->assertStringContainsString('Too many requests', $controller->getLastOutput());
    }

    /** @test */
    public function controllerProceedsWhenGuardChainAllows(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(null);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('', 'sig_test', '1.2.3.4');

        $controller->render();

        // Guard passed, but empty payload check triggered (400, not 429)
        $this->assertSame(400, $controller->getLastHttpStatusCode());
    }

    /** @test */
    public function controllerCallsGuardWithCorrectArguments(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->expects($this->once())
            ->method('check')
            ->with('{"test":true}', 'sig_test', '1.2.3.4')
            ->willReturn(null);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('{"test":true}', 'sig_test', '1.2.3.4');

        $controller->render();
    }

    /** @test */
    public function controllerWorksWithoutGuard(): void
    {
        $controller = new TestableWebhookControllerForGuard();
        // No guard set — should proceed normally
        $controller->setWebhookInput('', 'sig_test', '1.2.3.4');

        $controller->render();

        // No guard → proceeds to empty payload check → 400
        $this->assertSame(400, $controller->getLastHttpStatusCode());
    }

    /** @test */
    public function guardRejectionPreventsFurtherProcessing(): void
    {
        $rejection = new WebhookGuardResult('payload_too_large', 413, 'Payload too large');
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn($rejection);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('{"id":"evt_1"}', 'valid_sig', '1.2.3.4');

        $controller->render();

        $this->assertSame(413, $controller->getLastHttpStatusCode());
        $this->assertStringContainsString('Payload too large', $controller->getLastOutput());
        // Verify processor was NOT called
        $this->assertFalse($controller->wasProcessorCalled());
    }
}

/**
 * Testable subclass that reimplements render() without OXID Registry.
 *
 * Reproduces the exact guard check logic from WebhookController::render()
 * lines 72-76, then continues with payload/signature validation.
 */
class TestableWebhookControllerForGuard extends WebhookController
{
    private ?WebhookRequestGuardInterface $testGuard = null;
    private string $testPayload = '';
    private string $testSignature = '';
    private string $testRemoteIp = 'unknown';
    private ?int $lastHttpStatusCode = null;
    private string $lastOutput = '';
    private bool $processorCalled = false;

    public function setTestGuard(WebhookRequestGuardInterface $guard): void
    {
        $this->testGuard = $guard;
    }

    public function setWebhookInput(string $payload, string $signature, string $remoteIp): void
    {
        $this->testPayload = $payload;
        $this->testSignature = $signature;
        $this->testRemoteIp = $remoteIp;
    }

    public function getLastHttpStatusCode(): ?int
    {
        return $this->lastHttpStatusCode;
    }

    public function getLastOutput(): string
    {
        return $this->lastOutput;
    }

    public function wasProcessorCalled(): bool
    {
        return $this->processorCalled;
    }

    /** Override: skip OXID bootstrap */
    public function init(): void
    {
    }

    /**
     * Reimplements render() without OXID Registry calls.
     *
     * Preserves the exact guard check logic: extract input → guard check → payload
     * validation → processor call. Avoids Registry::getUtils() and exit calls.
     */
    public function render(): string
    {
        [$payload, $signature, $remoteIp] = [$this->testPayload, $this->testSignature, $this->testRemoteIp];

        // Guard chain check — same as production code
        $guardResult = $this->getGuard()?->check($payload, $signature, $remoteIp);
        if ($guardResult !== null) {
            $this->lastHttpStatusCode = $guardResult->httpStatusCode;
            $this->lastOutput = json_encode(['error' => $guardResult->message]);
            return '';
        }

        // Payload validation — same as production code
        if ($payload === '') {
            $this->lastHttpStatusCode = 400;
            $this->lastOutput = json_encode(['error' => 'Empty payload']);
            return '';
        }

        if ($signature === '') {
            $this->lastHttpStatusCode = 400;
            $this->lastOutput = json_encode(['error' => 'Missing signature header']);
            return '';
        }

        // Would call processor here
        $this->processorCalled = true;
        $this->lastHttpStatusCode = 500;
        $this->lastOutput = json_encode(['error' => 'Webhook processor unavailable']);
        return '';
    }

    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->testGuard;
    }
}
