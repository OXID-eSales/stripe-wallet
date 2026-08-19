<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRequestGuardInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 133 · Story 5 (F4).
 *
 * init() caught a container failure, logged one warning, and left the guard
 * null; render()'s `$this->getGuard()?->check(...)` then skipped the entire
 * chain -- HTTPS, IP allowlist, payload-size cap and rate limiting -- for every
 * subsequent request, with no per-request trace. One DI error silently
 * downgraded a public endpoint.
 */
final class WebhookControllerGuardTest extends TestCase
{
    private function controller(?WebhookRequestGuardInterface $guard): TestableWebhookController
    {
        $controller = new TestableWebhookController();
        $controller->testGuard = $guard;

        return $controller;
    }

    public function testWhenGuardChainUnavailableRespondsUnavailableAndDoesNotProcess(): void
    {
        $controller = $this->controller(null);

        $this->renderIgnoringTermination($controller);

        $this->assertSame(503, $controller->sentStatus, 'A missing guard chain must fail closed.');
        $this->assertSame('GUARD_CHAIN_UNAVAILABLE', $controller->sentLogAction);
        $this->assertFalse($controller->processorWasUsed, 'Nothing may be processed without guards.');
    }

    public function testWhenGuardRejectsPropagatesTheGuardStatusCode(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(new WebhookGuardResult('payload_too_large', 413, 'Too large'));

        $controller = $this->controller($guard);
        $this->renderIgnoringTermination($controller);

        $this->assertSame(413, $controller->sentStatus);
        $this->assertFalse($controller->processorWasUsed);
    }

    public function testWhenAllGuardsPassProcessingProceeds(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(null);

        $controller = $this->controller($guard);
        $this->renderIgnoringTermination($controller);

        $this->assertTrue($controller->processorWasUsed);
    }

    public function testMinimalFallbackChainStillEnforcesPayloadSize(): void
    {
        // Even with the container unavailable, the cheap invariants that need no
        // DB must keep working once the fallback chain is installed.
        $controller = new TestableWebhookController();
        $controller->installMinimalFallbackChain();

        $this->assertNotNull($controller->exposedGuard(), 'A fallback chain must exist.');

        $oversized = str_repeat('x', 70000);

        // Insecure transport is rejected first, which is itself fail-closed.
        $insecure = $controller->exposedGuard()?->check($oversized, 'sig', '203.0.113.7');
        $this->assertNotNull($insecure);
        $this->assertSame('insecure_connection', $insecure->reason);

        // Over HTTPS the size cap is what rejects it — no DB, no container.
        $previous = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        try {
            $result = $controller->exposedGuard()?->check($oversized, 'sig', '203.0.113.7');
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $previous;
            }
        }

        $this->assertNotNull($result, 'The fallback chain must reject an oversized payload.');
        $this->assertSame(413, $result->httpStatusCode);
    }

    public function testDegradedChainRefusesToProcessEvenWhenTheMinimalGuardsPass(): void
    {
        $controller = new TestableWebhookController();
        $controller->installMinimalFallbackChain();
        $controller->markDegraded();

        $previous = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        try {
            $this->renderIgnoringTermination($controller);
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $previous;
            }
        }

        $this->assertSame(503, $controller->sentStatus);
        $this->assertFalse($controller->processorWasUsed);
    }

    private function renderIgnoringTermination(TestableWebhookController $controller): void
    {
        try {
            $controller->render();
        } catch (StopRenderingException) {
            // production sends the response and exit()s here
        }
    }
}
