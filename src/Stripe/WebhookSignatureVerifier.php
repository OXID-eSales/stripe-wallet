<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookSignatureVerifierInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly int $toleranceSeconds = 300
    ) {
    }

    public function verify(string $payload, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        try {
            Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret,
                $this->toleranceSeconds
            );
            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function parseEvent(string $payload, string $signature): array
    {
        $event = Webhook::constructEvent(
            $payload,
            $signature,
            $this->webhookSecret,
            $this->toleranceSeconds
        );

        return [
            'id' => $event->id,
            'type' => $event->type,
            'data' => $event->data->toArray(),
            'created' => $event->created,
        ];
    }
}
