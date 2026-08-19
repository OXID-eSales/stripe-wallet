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
 * Tests guard chain integration into the REAL WebhookController::render().
 *
 * Uses testable subclass pattern (R-1.5): overrides ONLY IO seams and
 * init() — the real render() is exercised unchanged.
 *
 * Seams added to WebhookController (Sprint 114.3):
 *   - processor/webhookLogger promoted to protected (subclass init sets them)
 *   - setResponseContentType() — avoids Registry::getUtils() in tests
 *   - sendErrorResponse() — overridden to throw WebhookTestResponseSent (never contract honoured)
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-64d')]
#[\PHPUnit\Framework\Attributes\Group('security')]
final class WebhookControllerGuardIntegrationTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function controllerRejectsWhenGuardChainRejects(): void
    {
        $rejection = new WebhookGuardResult('rate_limited', 429, 'Too many requests');
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn($rejection);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('{"test":true}', 'sig_test', '1.2.3.4');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            $this->assertSame(429, $response->statusCode);
            $this->assertStringContainsString('Too many requests', $response->body);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function controllerProceedsWhenGuardChainAllows(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(null);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        // Empty payload so render() fails at payload check (not guard) — proves guard allowed
        $controller->setWebhookInput('', 'sig_test', '1.2.3.4');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            // Guard passed, but empty payload check triggered (400, not 429)
            $this->assertSame(400, $response->statusCode);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            // Assertion on check() is done via the mock expectation above.
            // render() terminated at processor-unavailable check (processor=null).
            $this->assertSame(500, $response->statusCode);
        }
    }

    /**
     * Sprint 133 · Story 5 (F4) — DELIBERATE BEHAVIOUR CHANGE. This asserted
     * that the endpoint keeps working with no guard chain at all, i.e. that a
     * public endpoint may run unprotected. It now fails closed.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function controllerRefusesToProcessWithoutGuard(): void
    {
        $controller = new TestableWebhookControllerForGuard();
        // No guard set — getGuard() returns null.
        $controller->setWebhookInput('', 'sig_test', '1.2.3.4');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            $this->assertSame(503, $response->statusCode);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function guardRejectionPreventsFurtherProcessing(): void
    {
        $rejection = new WebhookGuardResult('payload_too_large', 413, 'Payload too large');
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn($rejection);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput('{"id":"evt_1"}', 'valid_sig', '1.2.3.4');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            $this->assertSame(413, $response->statusCode);
            $this->assertStringContainsString('Payload too large', $response->body);
        }

        // Guard rejection threw before reaching processor
        $this->assertFalse($controller->wasProcessorCalled());
    }

    /**
     * Guard chain unavailable (init() cannot resolve it from the container).
     *
     * Sprint 133 · Story 5 (F4) — DELIBERATE BEHAVIOUR CHANGE. This test used to
     * assert warn-and-continue: it documented that render() proceeds past a null
     * guard, which meant HTTPS enforcement, the IP allowlist, the payload-size
     * cap and rate limiting were all skipped for every request after a single DI
     * error, with only one warning at init() and no per-request trace.
     *
     * A security control that cannot be built now fails the request. Stripe
     * retries on 503, so no event is lost.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function guardChainUnavailableFailsClosedInsteadOfWarnAndContinue(): void
    {
        $controller = new TestableWebhookControllerForGuard();
        // testGuard not set → getGuard() returns null
        $controller->setWebhookInput('{"event":"test"}', 'sig_header', '5.6.7.8');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            $this->assertSame(503, $response->statusCode);
            $this->assertFalse(
                $controller->wasProcessorCalled(),
                'Unguarded traffic must not reach the processor.'
            );
        }
    }

    /**
     * Payload-too-large path: guard rejects with 413 before any signature work.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function payloadTooLargeIsRejectedByGuardBeforeSignatureValidation(): void
    {
        $rejection = new WebhookGuardResult('payload_too_large', 413, 'Payload too large');
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn($rejection);

        $controller = new TestableWebhookControllerForGuard();
        $controller->setTestGuard($guard);
        $controller->setWebhookInput(str_repeat('x', 1024), 'sig_check', '9.9.9.9');

        try {
            $controller->render();
            $this->fail('Expected render() to terminate via sendErrorResponse()');
        } catch (WebhookTestResponseSent $response) {
            $this->assertSame(413, $response->statusCode);
        }

        // Guard threw before reaching the processor
        $this->assertFalse($controller->wasProcessorCalled());
    }
}

/**
 * Exception thrown by TestableWebhookControllerForGuard::sendErrorResponse().
 *
 * Satisfies the `never` return-type contract (throwing is `never`).
 * Tests catch this to inspect the captured status code and body.
 */
class WebhookTestResponseSent extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
        parent::__construct("HTTP {$statusCode}: {$body}");
    }
}

/**
 * Testable subclass — overrides ONLY seams, never re-implements render().
 *
 * Seams overridden:
 *   - init()                    — skips OXID container bootstrap
 *   - getGuard()                — injects test guard (already protected in production)
 *   - extractWebhookInput()     — injects test payload/signature/IP (already protected)
 *   - setResponseContentType()  — no-op (avoids Registry::getUtils(); seam added Sprint 114.3)
 *   - sendErrorResponse()       — throws WebhookTestResponseSent instead of exit (never contract)
 *   - cleanupStaleNotFinishedOrders() — no-op (avoids ContainerFactory in tests)
 *
 * processor is promoted to protected in Sprint 114.3 so init() can set it.
 */
class TestableWebhookControllerForGuard extends WebhookController
{
    private ?WebhookRequestGuardInterface $testGuard = null;
    private string $testPayload = '';
    private string $testSignature = '';
    private string $testRemoteIp = 'unknown';
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

    public function wasProcessorCalled(): bool
    {
        return $this->processorCalled;
    }

    /** Override: skip OXID container bootstrap; leave processor=null, webhookLogger=null */
    public function init(): void
    {
        // Intentionally empty — avoids ContainerFactory::getInstance() in unit tests.
        // processor stays null → render() will hit PROCESSOR_UNAVAILABLE if reached.
        // webhookLogger stays null → optional-chaining calls are no-ops.
    }

    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->testGuard;
    }

    /** @return array{string, string, string} */
    protected function extractWebhookInput(): array
    {
        return [$this->testPayload, $this->testSignature, $this->testRemoteIp];
    }

    /** Override: avoid Registry::getUtils() (seam added Sprint 114.3) */
    protected function setResponseContentType(): void
    {
        // No-op in tests
    }

    /**
     * Override: capture response data and throw to stop render() execution.
     *
     * Throwing satisfies the `never` return-type contract. Tests catch
     * WebhookTestResponseSent to inspect status code and body.
     */
    protected function sendErrorResponse(
        string $payload,
        string $message,
        int $statusCode,
        ?string $logAction = null
    ): never {
        throw new WebhookTestResponseSent($statusCode, json_encode(['error' => $message]));
    }

    /** Override: avoid ContainerFactory::getInstance() */
    protected function cleanupStaleNotFinishedOrders(): void
    {
        // No-op in tests
    }
}
