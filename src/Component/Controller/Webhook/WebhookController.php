<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Webhook;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookProcessorInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookSignatureVerifierInterface;
use Psr\Log\LoggerInterface;

class WebhookController implements WebhookControllerInterface
{
    public function __construct(
        private readonly WebhookSignatureVerifierInterface $signatureVerifier,
        private readonly WebhookProcessorInterface $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        try {
            if (empty($signature)) {
                return [
                    'statusCode' => 400,
                    'body' => ['error' => 'Missing signature header'],
                ];
            }

            if (!$this->signatureVerifier->verify($payload, $signature)) {
                return [
                    'statusCode' => 401,
                    'body' => ['error' => 'Invalid signature'],
                ];
            }

            $webhookData = $this->signatureVerifier->parseEvent($payload, $signature);

            $this->processor->process($webhookData);

            return [
                'statusCode' => 200,
                'body' => [
                    'status' => 'success',
                    'received' => true,
                ],
            ];
        } catch (\JsonException $e) {
            return [
                'statusCode' => 400,
                'body' => ['error' => 'Invalid JSON payload: ' . $e->getMessage()],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'statusCode' => 500,
                'body' => ['error' => 'Internal server error'],
            ];
        }
    }
}
