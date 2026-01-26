<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Centralized webhook logging service.
 *
 * Sprint 16: Extracted from WebhookController for SRP compliance.
 * Provides consistent logging format across all webhook operations.
 *
 * @since 2.0.0
 */
final class WebhookLogService implements WebhookLogServiceInterface
{
    public function __construct(
        private readonly FileLoggerInterface $fileLogger
    ) {
    }

    public function logReceived(string $payload, string $signature, string $remoteIp): void
    {
        try {
            $eventInfo = $this->parseEventInfo($payload);

            $this->fileLogger->log('WEBHOOK_RECEIVED', [
                'event_id' => $eventInfo['eventId'],
                'event_type' => $eventInfo['eventType'],
                'payment_intent_id' => $eventInfo['paymentIntentId'],
                'remote_ip' => $remoteIp,
                'payload_size' => strlen($payload),
                'has_signature' => $signature !== '',
            ]);
        } catch (\Throwable $e) {
            // Silent fail - don't break webhook processing
        }
    }

    public function logResult(string $payload, string $result, int $httpCode): void
    {
        try {
            $eventInfo = $this->parseEventInfo($payload);

            $this->fileLogger->log('WEBHOOK_RESULT', [
                'result' => $result,
                'http_code' => $httpCode,
                'event_type' => $eventInfo['eventType'],
                'event_id' => $eventInfo['eventId'],
            ]);
        } catch (\Throwable $e) {
            // Silent fail - don't break webhook processing
        }
    }

    /**
     * Parse webhook payload to extract event info.
     *
     * @return array{eventId: string, eventType: string, paymentIntentId: string}
     */
    private function parseEventInfo(string $payload): array
    {
        $result = [
            'eventId' => 'unknown',
            'eventType' => 'unknown',
            'paymentIntentId' => 'unknown',
        ];

        if ($payload === '') {
            return $result;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return $result;
        }

        $result['eventId'] = is_string($data['id'] ?? null) ? $data['id'] : 'unknown';
        $result['eventType'] = is_string($data['type'] ?? null) ? $data['type'] : 'unknown';

        // Extract payment intent ID from nested structure
        $dataObj = $data['data'] ?? null;
        if (is_array($dataObj)) {
            $object = $dataObj['object'] ?? null;
            if (is_array($object)) {
                $id = $object['id'] ?? $object['payment_intent'] ?? null;
                $result['paymentIntentId'] = is_string($id) ? $id : 'unknown';
            }
        }

        return $result;
    }
}
