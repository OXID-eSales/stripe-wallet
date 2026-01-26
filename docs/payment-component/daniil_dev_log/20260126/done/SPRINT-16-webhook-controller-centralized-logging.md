# SPRINT-16: WebhookController Centralized Logging

**Priority:** MEDIUM
**Estimated Effort:** 2-3h
**Impact:** Code quality, consistency, maintainability
**Decision:** Keep detailed log format with all fields (confirmed)

---

## Problem Statement

`WebhookController` has ~170 lines of inline logging code that:
- Duplicates `FileLogger` functionality
- Violates Single Responsibility Principle (controller handles HTTP + logging)
- Uses different log format than other Stripe logs
- Cannot be unit tested in isolation

**Inline methods to remove (lines 127-293):**
```php
private function logRawWebhookRequest($payload, string $sigHeader): void { ... }
private function getWebhookLogFilePath(): ?string { ... }
private function parseWebhookEventInfo($payload): array { ... }
private function extractStringField(array $data, string $key): string { ... }
private function extractPaymentIntentId(array $data): array { ... }
private function formatWebhookLogEntry(array $eventInfo, string $sigHeader, $payload): string { ... }
private function logWebhookResult($payload, string $result, int $httpCode): void { ... }
```

---

## Requirements

### R1: Create WebhookLogService
- Encapsulate all webhook logging logic
- Use `FileLoggerInterface` for consistent format
- Methods: `logReceived()`, `logResult()`

### R2: Create WebhookLogServiceInterface
- Define contract for webhook logging
- Enable mocking in tests

### R3: Create WebhookFileLoggerFactory
- Creates `FileLogger` for webhooks
- Logs to `log/osc/stripe_webhooks.log`

### R4: Refactor WebhookController
- Inject `WebhookLogServiceInterface`
- Remove all inline logging methods (~150 lines)
- Controller only handles HTTP concerns

### R5: All tests must pass
- Unit tests for `WebhookLogService`
- PHPStan level 6
- PHPCS PSR-12

---

## TDD Implementation

### Step 1: Create WebhookLogServiceInterface

```php
// src/Stripe/Service/WebhookLogServiceInterface.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for webhook logging service.
 *
 * Sprint 16: Centralized webhook logging.
 *
 * @since 2.0.0
 */
interface WebhookLogServiceInterface
{
    /**
     * Log received webhook request.
     *
     * @param string $payload Raw webhook payload
     * @param string $signature Stripe signature header
     * @param string $remoteIp Remote IP address
     */
    public function logReceived(string $payload, string $signature, string $remoteIp): void;

    /**
     * Log webhook processing result.
     *
     * @param string $payload Raw webhook payload
     * @param string $result Result description (e.g., "SUCCESS: payment_intent.succeeded")
     * @param int $httpCode HTTP response code
     */
    public function logResult(string $payload, string $result, int $httpCode): void;
}
```

### Step 2: Write tests for WebhookLogService

```php
// tests/Unit/Stripe/Service/WebhookLogServiceTest.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\WebhookLogService;
use PHPUnit\Framework\TestCase;

class WebhookLogServiceTest extends TestCase
{
    private const SAMPLE_PAYLOAD = '{"id":"evt_123","type":"payment_intent.succeeded","data":{"object":{"id":"pi_456"}}}';

    public function testLogReceivedExtractsEventInfo(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'WEBHOOK_RECEIVED',
                $this->callback(function (array $context) {
                    return $context['event_id'] === 'evt_123'
                        && $context['event_type'] === 'payment_intent.succeeded'
                        && $context['payment_intent_id'] === 'pi_456'
                        && $context['remote_ip'] === '127.0.0.1'
                        && $context['has_signature'] === true;
                })
            );

        $service = new WebhookLogService($fileLogger);
        $service->logReceived(self::SAMPLE_PAYLOAD, 't=123,v1=abc', '127.0.0.1');
    }

    public function testLogReceivedHandlesEmptyPayload(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'WEBHOOK_RECEIVED',
                $this->callback(function (array $context) {
                    return $context['event_id'] === 'unknown'
                        && $context['event_type'] === 'unknown';
                })
            );

        $service = new WebhookLogService($fileLogger);
        $service->logReceived('', '', '127.0.0.1');
    }

    public function testLogReceivedHandlesInvalidJson(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'WEBHOOK_RECEIVED',
                $this->callback(function (array $context) {
                    return $context['event_id'] === 'unknown';
                })
            );

        $service = new WebhookLogService($fileLogger);
        $service->logReceived('not json', 'sig', '127.0.0.1');
    }

    public function testLogResultLogsWithHttpCode(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'WEBHOOK_RESULT',
                $this->callback(function (array $context) {
                    return $context['result'] === 'SUCCESS: payment_intent.succeeded'
                        && $context['http_code'] === 200
                        && $context['event_type'] === 'payment_intent.succeeded';
                })
            );

        $service = new WebhookLogService($fileLogger);
        $service->logResult(self::SAMPLE_PAYLOAD, 'SUCCESS: payment_intent.succeeded', 200);
    }

    public function testLogReceivedHandlesFileLoggerException(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->method('log')
            ->willThrowException(new \Exception('Write failed'));

        $service = new WebhookLogService($fileLogger);

        // Should not throw
        $service->logReceived(self::SAMPLE_PAYLOAD, 'sig', '127.0.0.1');
    }
}
```

### Step 3: Implement WebhookLogService

```php
// src/Stripe/Service/WebhookLogService.php

<?php

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
```

### Step 4: Create WebhookFileLoggerFactory

```php
// src/Stripe/Service/Factory/WebhookFileLoggerFactory.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the webhook file logger.
 *
 * Sprint 16: Logs webhook requests/responses to file.
 * Logs to log/osc/stripe_webhooks.log
 *
 * @since 2.0.0
 */
final class WebhookFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_webhooks.log';

    /**
     * Create the webhook file logger.
     *
     * @return FileLoggerInterface
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'WEBHOOK');
    }
}
```

### Step 5: Refactor WebhookController

```php
// src/Stripe/Controller/Webhook/WebhookController.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;

/**
 * Webhook endpoint controller.
 *
 * Sprint 16: Refactored to use WebhookLogService for logging.
 * Controller only handles HTTP concerns (SRP).
 *
 * URL: /index.php?cl=stripe_webhook
 *
 * @since 2.0.0
 */
class WebhookController extends FrontendController
{
    private ?StripeWebhookProcessor $processor = null;
    private ?WebhookLogServiceInterface $webhookLogger = null;

    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        try {
            $this->processor = $container->get(StripeWebhookProcessor::class);
            $this->webhookLogger = $container->get(WebhookLogServiceInterface::class);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Webhook services not available', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function render(): string
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');

        $payload = file_get_contents('php://input');
        $rawSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $signature = is_string($rawSignature) ? $rawSignature : '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $remoteIp = is_string($remoteIp) ? $remoteIp : 'unknown';

        // Log received webhook
        $this->webhookLogger?->logReceived($payload ?: '', $signature, $remoteIp);

        // Validate input
        if (!is_string($payload) || $payload === '') {
            $this->webhookLogger?->logResult($payload ?: '', 'EMPTY_PAYLOAD', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Empty payload']);
            exit;
        }

        if ($signature === '') {
            $this->webhookLogger?->logResult($payload, 'MISSING_SIGNATURE', 400);
            http_response_code(400);
            echo json_encode(['error' => 'Missing signature header']);
            exit;
        }

        // Check processor availability
        if ($this->processor === null) {
            $this->webhookLogger?->logResult($payload, 'PROCESSOR_UNAVAILABLE', 500);
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processor unavailable']);
            exit;
        }

        // Create request object
        $request = new WebhookRequest(
            payload: $payload,
            signature: $signature,
            remoteIp: $remoteIp,
            receivedAt: new DateTimeImmutable()
        );

        // Process webhook
        $result = $this->processor->process($request);

        // Return response based on result
        if ($result->isFailure()) {
            $statusCode = $result->action === 'signature_invalid' ? 400 : 500;
            $this->webhookLogger?->logResult($payload, "FAILED: {$result->action}", $statusCode);
            http_response_code($statusCode);
            echo json_encode(['error' => $result->error ?? $result->action]);
            exit;
        }

        $this->webhookLogger?->logResult($payload, "SUCCESS: {$result->action}", 200);
        http_response_code(200);
        echo json_encode(['received' => true, 'action' => $result->action]);
        exit;

        // @phpstan-ignore-next-line - unreachable but required for return type
        return '';
    }

    // ALL INLINE LOGGING METHODS REMOVED (lines 127-293)
}
```

### Step 6: Update services.yaml

```yaml
# services.yaml

# Webhook file logger factory
OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory: ~

# Webhook file logger instance
stripe.webhook_file_logger:
    class: OxidEsales\PaymentComponent\Service\FileLoggerInterface
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory', 'create']

# WebhookLogService
OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\WebhookLogService
    arguments:
        $fileLogger: '@stripe.webhook_file_logger'
```

---

## Files to Create

| File | Description |
|------|-------------|
| `src/Stripe/Service/WebhookLogServiceInterface.php` | Interface |
| `src/Stripe/Service/WebhookLogService.php` | Implementation |
| `src/Stripe/Service/Factory/WebhookFileLoggerFactory.php` | Factory |
| `tests/Unit/Stripe/Service/WebhookLogServiceTest.php` | Tests |

## Files to Modify

| File | Action |
|------|--------|
| `src/Stripe/Controller/Webhook/WebhookController.php` | Remove ~150 lines, inject service |
| `services.yaml` | Register factory and service |

---

## Verification

```bash
# Run pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: No errors
# - PHPUnit: All tests pass including WebhookLogServiceTest
# - PHPCS: No style violations

# Verify webhook logging (manual test with Stripe CLI)
stripe trigger payment_intent.succeeded
# Check log/osc/stripe_webhooks.log
```

---

## Acceptance Criteria

- [ ] `WebhookLogServiceInterface` created
- [ ] `WebhookLogService` implements interface with `logReceived()` and `logResult()`
- [ ] `WebhookFileLoggerFactory` creates logger for `log/osc/stripe_webhooks.log`
- [ ] `WebhookController` reduced by ~150 lines
- [ ] `WebhookController` only handles HTTP concerns
- [ ] All inline logging methods removed from controller
- [ ] Unit tests pass for `WebhookLogService`
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Webhook logs appear in correct file (manual verification)
