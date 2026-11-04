<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardChain;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * VR-18 L4: what the controller hands the webhook file log - the processor's result with its reason (a skipped
 * delivery used to be logged as `SUCCESS: skipped`), the contract it touched and the time it took.
 */
#[CoversClass(WebhookController::class)]
final class WebhookControllerDeliveryLogTest extends TestCase
{
    public function testASkippedDeliveryIsLoggedWithItsReason(): void
    {
        $log = new RecordingWebhookLog();

        $this->deliver(WebhookResult::skipped('Contract not found'), null, $log);

        self::assertSame('skipped', $log->results[0]['result']->action);
        self::assertSame('Contract not found', $log->results[0]['result']->error);
        self::assertSame(200, $log->results[0]['httpCode']);
    }

    public function testTheContractTheProcessorTouchedIsLogged(): void
    {
        $log = new RecordingWebhookLog();

        $this->deliver(WebhookResult::success('contract_fulfilled'), 'contract-9', $log);

        self::assertSame('contract-9', $log->results[0]['contractId']);
    }

    public function testAFailedDeliveryIsLoggedWithItsActionAndReason(): void
    {
        $log = new RecordingWebhookLog();

        $this->deliver(WebhookResult::failure('processing_failed', 'Webhook processing failed'), null, $log);

        self::assertSame('processing_failed', $log->results[0]['result']->action);
        self::assertSame('Webhook processing failed', $log->results[0]['result']->error);
        self::assertSame(500, $log->results[0]['httpCode']);
    }

    public function testASignatureFailureIsLoggedWith400(): void
    {
        $log = new RecordingWebhookLog();

        $this->deliver(WebhookResult::failure('signature_invalid', 'Webhook signature verification failed'), null, $log);

        self::assertSame(400, $log->results[0]['httpCode']);
    }

    private function deliver(WebhookResult $result, ?string $contractId, RecordingWebhookLog $log): void
    {
        $processor = $this->createMock(StripeWebhookProcessor::class);
        $processor->method('process')->willReturn($result);
        $processor->method('lastContractId')->willReturn($contractId);

        $controller = new class ($processor, $log) extends WebhookController {
            public function __construct(StripeWebhookProcessor $processor, WebhookLogServiceInterface $log)
            {
                $this->processor = $processor;
                $this->webhookLogger = $log;
            }

            protected function getGuard(): ?WebhookGuardInterface
            {
                return new WebhookGuardChain([]);
            }

            protected function setResponseContentType(): void
            {
            }

            /** @return array{string, string, string} */
            protected function extractWebhookInput(): array
            {
                return ['{"id":"evt_1","type":"x","data":{"object":{"id":"pi_1"}}}', 't=1,v1=sig', '127.0.0.1'];
            }

            protected function sendSuccessResponse(string $action): void
            {
            }

            protected function terminate(): never
            {
                throw new StopRenderingException();
            }

            protected function emitError(string $message, int $statusCode): never
            {
                throw new StopRenderingException();
            }
        };

        try {
            $controller->render();
        } catch (StopRenderingException) {
            // the production exit()
        }
    }
}

/**
 * @internal
 */
final class RecordingWebhookLog implements WebhookLogServiceInterface
{
    /** @var list<array{result: WebhookResult, httpCode: int, contractId: ?string}> */
    public array $results = [];

    public function logReceived(string $payload, string $signature, string $remoteIp): void
    {
    }

    public function logResult(
        string $payload,
        WebhookResult $result,
        int $httpCode,
        int $durationMs,
        ?string $contractId
    ): void {
        $this->results[] = ['result' => $result, 'httpCode' => $httpCode, 'contractId' => $contractId];
    }
}
