<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardChain;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The stale NOT_FINISHED sweep (STRP-100) ran inline after every webhook. It costs up to two Stripe round trips per
 * stale contract, and 66 % of the webhook traffic on pay1 was the shop's own sweep cancelling sessions and receiving
 * their expiry back. VR-23 T3: the webhook answers and ends; the sweep is the cron command
 * `oe:payments:stripe:sweep` (StaleCheckoutSweepCommand).
 */
#[CoversClass(WebhookController::class)]
final class WebhookControllerAnswersWithoutSweepTest extends TestCase
{
    public function testTheSuccessAnswerIsFollowedByTheEndAndNoSweep(): void
    {
        $controller = $this->controllerAnswering(WebhookResult::skipped('Unhandled event type: x'));

        $this->renderUntilTerminated($controller);

        self::assertSame(['response 200 skipped', 'terminate'], $controller->sequence);
    }

    public function testAHandledEventIsAnsweredTheSameWay(): void
    {
        $controller = $this->controllerAnswering(WebhookResult::success('contract_committed'));

        $this->renderUntilTerminated($controller);

        self::assertSame(['response 200 contract_committed', 'terminate'], $controller->sequence);
    }

    public function testAFailedResultIsAnsweredWithAnError(): void
    {
        $controller = $this->controllerAnswering(WebhookResult::failure('handler_error', 'boom'));

        $this->renderUntilTerminated($controller);

        self::assertSame(['error 500'], $controller->sequence);
    }

    private function controllerAnswering(WebhookResult $result): TestableWebhookControllerForOrdering
    {
        $processor = $this->createMock(StripeWebhookProcessor::class);
        $processor->method('process')->willReturn($result);

        return new TestableWebhookControllerForOrdering($processor, new WebhookGuardChain([]));
    }

    private function renderUntilTerminated(TestableWebhookControllerForOrdering $controller): void
    {
        try {
            $controller->render();
            self::fail('render() must terminate the request');
        } catch (StopRenderingException) {
            // the production exit()
        }
    }
}

/**
 * Testable subclass (module CLAUDE.md pattern): only seams are overridden,
 * render() and processWebhook() are the production ones. Every seam that
 * would touch the SAPI records itself instead, so the test can read the
 * order in which the request was answered and ended.
 */
class TestableWebhookControllerForOrdering extends WebhookController
{
    /** @var list<string> */
    public array $sequence = [];

    public function __construct(
        StripeWebhookProcessor $processor,
        private readonly WebhookGuardInterface $testGuard
    ) {
        $this->processor = $processor;
    }

    public function init(): void
    {
    }

    protected function getGuard(): ?WebhookGuardInterface
    {
        return $this->testGuard;
    }

    protected function setResponseContentType(): void
    {
    }

    /** @return array{string, string, string} */
    protected function extractWebhookInput(): array
    {
        return ['{"id":"evt_1","type":"x"}', 't=1,v1=sig', '127.0.0.1'];
    }

    protected function sendSuccessResponse(string $action): void
    {
        $this->sequence[] = "response 200 {$action}";
    }

    protected function terminate(): never
    {
        $this->sequence[] = 'terminate';

        throw new StopRenderingException();
    }

    protected function sendErrorResponse(
        string $payload,
        string $message,
        int $statusCode,
        ?string $logAction = null
    ): never {
        $this->sequence[] = "error {$statusCode}";

        throw new StopRenderingException();
    }
}
