<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookDeliveryLogRecord;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Centralized webhook logging service.
 *
 * Provides consistent logging format across all webhook operations.
 *
 * @since 2.0.0
 */
class WebhookLogService implements WebhookLogServiceInterface
{
    private const UNKNOWN = 'unknown';

    public function __construct(
        private readonly FileLoggerInterface $fileLogger
    ) {
    }

    public function logReceived(string $payload, string $signature, string $remoteIp): void
    {
        try {
            $eventInfo = $this->parseEventInfo($payload);

            $this->fileLogger->log('WEBHOOK_RECEIVED', [
                'event_id' => $eventInfo['eventId'] ?? self::UNKNOWN,
                'event_type' => $eventInfo['eventType'] ?? self::UNKNOWN,
                'objectId' => $eventInfo['objectId'] ?? self::UNKNOWN,
                'remote_ip' => $remoteIp,
                'payload_size' => strlen($payload),
                'has_signature' => $signature !== '',
            ]);
        } catch (\Throwable $e) {
            // Silent fail - don't break webhook processing
        }
    }

    public function logResult(
        string $payload,
        WebhookResult $result,
        int $httpCode,
        int $durationMs,
        ?string $contractId
    ): void {
        try {
            $eventInfo = $this->parseEventInfo($payload);

            $this->fileLogger->log('WEBHOOK_RESULT', WebhookDeliveryLogRecord::fromResult(
                StripeDefinitions::PROVIDER,
                $eventInfo['eventId'],
                $eventInfo['eventType'],
                $eventInfo['objectId'],
                $contractId,
                $result,
                $httpCode,
                $durationMs
            )->toContext());
        } catch (\Throwable $e) {
            // Silent fail - don't break webhook processing
        }
    }

    /**
     * The ids the payload names. `objectId` is the id of the event's own object (`pi_`, `cs_`, `ch_`...), whatever
     * its kind: the log line says which object the delivery was about, the contract id says which contract.
     *
     * @return array{eventId: ?string, eventType: ?string, objectId: ?string}
     */
    private function parseEventInfo(string $payload): array
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return ['eventId' => null, 'eventType' => null, 'objectId' => null];
        }

        $object = is_array($data['data'] ?? null) ? ($data['data']['object'] ?? null) : null;

        return [
            'eventId' => $this->stringOrNull($data['id'] ?? null),
            'eventType' => $this->stringOrNull($data['type'] ?? null),
            'objectId' => is_array($object) ? $this->stringOrNull($object['id'] ?? null) : null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
