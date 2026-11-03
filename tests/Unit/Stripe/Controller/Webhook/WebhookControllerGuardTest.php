<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardResult;
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
    private function controller(?WebhookGuardInterface $guard): TestableWebhookController
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
        $guard = $this->createMock(WebhookGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::reject(413, 'payload_too_large'));

        $controller = $this->controller($guard);
        $this->renderIgnoringTermination($controller);

        $this->assertSame(413, $controller->sentStatus);
        $this->assertFalse($controller->processorWasUsed);
    }

    public function testWhenAllGuardsPassProcessingProceeds(): void
    {
        $guard = $this->createMock(WebhookGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::pass());

        $controller = $this->controller($guard);
        $this->renderIgnoringTermination($controller);

        $this->assertTrue($controller->processorWasUsed);
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
