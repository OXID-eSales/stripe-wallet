# Post-Cleanup Errors Analysis & TDD Solutions

**Date:** 2026-01-26
**Context:** After removing unused/deprecated classes (FraudScoringService, StockManagementService, etc.) and deprecated methods from StripeAdapterFactoryInterface

---

## Summary

After cleanup of unused code, pre-commit check (`--full`) reveals:
- **PHPStan errors:** 16
- **PHPUnit errors:** 85

**Root Cause:** Removed deprecated `getStripeClient()` method but consumers still reference direct SDK patterns.

---

## Issue 1: OrderRefund Controller Uses Direct SDK Pattern

### Location
`src/Stripe/Controller/Admin/OrderRefund.php` (lines 851, 855, 899, 901-903)

### Problem
The controller was updated to return `StripeAdapterInterface` instead of `StripeClient`, but still uses direct SDK calls:

```php
// Line 851 - BROKEN: StripeAdapterInterface has no ->paymentIntents property
$this->_oStripeApiOrder = $this->getStripeApiRequestModel()->paymentIntents->retrieve($transId);

// Line 899 - BROKEN: StripeAdapterInterface has no ->charges property
$this->_oStripeApiCharge = $this->getStripeApiRequestModel()->charges->retrieve($sLastChargeId);
```

### What Was Removed (Commit HEAD~2)
```php
// StripeAdapterFactoryInterface - REMOVED:
public function getStripeClient(): StripeClient;

// StripeAdapterFactory - REMOVED:
public function getStripeClient(): StripeClient { ... }
```

### TDD Solution

**Step 1: Write failing test**
```php
// tests/Unit/Stripe/Controller/Admin/OrderRefundTest.php
public function testGetStripeApiOrderUsesAdapterRetrievePaymentIntent(): void
{
    $adapter = $this->createMock(StripeAdapterInterface::class);
    $adapter->expects($this->once())
        ->method('retrievePaymentIntent')
        ->with('pi_test123', ['expand' => ['latest_charge']])
        ->willReturn($this->createPaymentIntentMock());

    // ... test setup
    $result = $controller->getStripeApiOrder();
    $this->assertInstanceOf(PaymentIntent::class, $result);
}

public function testGetStripeApiOrderLastChargeUsesAdapterRetrieveCharge(): void
{
    // Need to add retrieveCharge() to StripeAdapterInterface
    $adapter = $this->createMock(StripeAdapterInterface::class);
    $adapter->expects($this->once())
        ->method('retrieveCharge')
        ->with('ch_test123')
        ->willReturn($this->createChargeMock());

    // ... test
}
```

**Step 2: Add missing method to interface**
```php
// src/Stripe/Adapter/StripeAdapterInterface.php
/**
 * Retrieve a Stripe Charge.
 *
 * @param string $chargeId Stripe Charge ID (ch_xxx)
 * @return Charge Stripe Charge object
 */
public function retrieveCharge(string $chargeId): Charge;
```

**Step 3: Implement in adapter**
```php
// src/Stripe/Adapter/StripeAdapter.php
public function retrieveCharge(string $chargeId): Charge
{
    return $this->client->charges->retrieve($chargeId);
}
```

**Step 4: Update controller**
```php
// src/Stripe/Controller/Admin/OrderRefund.php

// Line 851 - FIX:
$this->_oStripeApiOrder = $this->getStripeApiRequestModel()
    ->retrievePaymentIntent($transId, ['latest_charge']);

// Line 899 - FIX:
$this->_oStripeApiCharge = $this->getStripeApiRequestModel()
    ->retrieveCharge($sLastChargeId);
```

---

## Issue 2: RequestLogService References Non-Existent RequestLog Class

### Location
`src/Stripe/Service/RequestLogService.php` (line 115)

### Problem
```php
// Line 115 - BROKEN: Class does not exist
return oxNew(RequestLog::class);
```

The service was created as a facade for a legacy `RequestLog` model that either:
1. Was removed during cleanup
2. Never existed (planned but not implemented)

### PHPStan Errors
- Line 50: `Call to method logRequest() on object`
- Line 73: `Call to method logExceptionResponse() on object`
- Line 115: `Class RequestLog not found`

### TDD Solution - Use File-Based Logging (NOT Database)

**IMPORTANT:** We use file-based logging, NOT database storage. Follow the pattern from `EventFileLoggerFactory`.

**Step 1: Create RequestFileLoggerFactory**
```php
// src/Stripe/Service/Factory/RequestFileLoggerFactory.php
namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the request logging file logger.
 *
 * Logs to log/osc/stripe_requests.log for API request/response tracking.
 */
final class RequestFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_requests.log';

    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }
        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'REQUEST');
    }
}
```

**Step 2: Refactor RequestLogService to use FileLoggerInterface**
```php
// src/Stripe/Service/RequestLogService.php
namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RequestLogService implements RequestLogServiceInterface
{
    public function __construct(
        private readonly FileLoggerInterface $fileLogger,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $this->fileLogger->log($action, [
                'reference_id' => $referenceId,
                'shop_id' => $shopId,
                'request' => $request,
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log request', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function logException(
        string $action,
        \Throwable $exception,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $this->fileLogger->log($action . '_EXCEPTION', [
                'reference_id' => $referenceId,
                'shop_id' => $shopId,
                'error_code' => $exception->getCode() ?: 500,
                'error_message' => $exception->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log exception', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

**Step 3: Register in services.yaml**
```yaml
OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory: ~

OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\RequestLogService
    arguments:
        $fileLogger: '@request_file_logger'
        $logger: '@logger'

request_file_logger:
    class: OxidEsales\PaymentComponent\Service\FileLoggerInterface
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory', 'create']
```

---

## Issue 3: StripeCustomerService Has Unused Property

### Location
`src/Stripe/Service/StripeCustomerService.php` (line 58)

### Problem
```php
private ?StripeClient $stripe = null;  // Written in doInitialize() but never read
```

The class was partially cleaned - methods using `$stripe` were removed but the property and initializer remained.

### TDD Solution

**Option A: Remove the class entirely** (if not used)

Check usage:
```bash
grep -r "StripeCustomerService" src/ --include="*.php"
```

If only self-references, remove the class.

**Option B: Complete the implementation** (if needed)

Add methods that use the property, or remove the property if initialization is not needed.

---

## Issue 4: Factory Type Issues

### Location
- `src/Stripe/Service/Factory/EventFileLoggerFactory.php` (line 34)
- `src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php` (line 34)

### Problem
```php
// PHPStan: Parameter #1 $string of function rtrim expects string, mixed given.
$logDir = rtrim($this->config->getShopPath(), '/');  // getShopPath() returns mixed
```

### TDD Solution

Add type assertion:
```php
$shopPath = $this->config->getShopPath();
if (!is_string($shopPath)) {
    throw new RuntimeException('Shop path must be configured');
}
$logDir = rtrim($shopPath, '/');
```

Or use PHPStan annotation if safe:
```php
/** @var string $shopPath */
$shopPath = $this->config->getShopPath();
```

---

## Issue 5: WebhookController Has Inline Logging - Must Use Centralized System

### Location
`src/Stripe/Controller/Webhook/WebhookController.php` (lines 127-293)

### Problem
The WebhookController has ~170 lines of inline logging code that duplicates functionality:

```php
// Inline methods that should use centralized logging:
private function logRawWebhookRequest($payload, string $sigHeader): void { ... }
private function getWebhookLogFilePath(): ?string { ... }
private function parseWebhookEventInfo($payload): array { ... }
private function extractStringField(array $data, string $key): string { ... }
private function extractPaymentIntentId(array $data): string { ... }
private function formatWebhookLogEntry(array $eventInfo, string $sigHeader, $payload): string { ... }
private function logWebhookResult($payload, string $result, int $httpCode): void { ... }
```

This violates:
- **DRY** - Duplicates FileLogger functionality
- **SRP** - Controller handles both HTTP and logging concerns
- **Consistency** - Different log format than other Stripe logs

### TDD Solution - Refactor to Use Centralized Logging

**Step 1: Create WebhookFileLoggerFactory**
```php
// src/Stripe/Service/Factory/WebhookFileLoggerFactory.php
namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the webhook file logger.
 *
 * Logs to log/osc/stripe_webhooks.log for webhook request/response tracking.
 */
final class WebhookFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_webhooks.log';

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

**Step 2: Create WebhookLogService**
```php
// src/Stripe/Service/WebhookLogService.php
namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Centralized webhook logging service.
 *
 * Provides consistent logging for all webhook operations.
 */
final class WebhookLogService implements WebhookLogServiceInterface
{
    public function __construct(
        private readonly FileLoggerInterface $fileLogger
    ) {}

    public function logReceived(string $payload, string $signature, string $remoteIp): void
    {
        $eventInfo = $this->parseEventInfo($payload);

        $this->fileLogger->log('WEBHOOK_RECEIVED', [
            'event_id' => $eventInfo['eventId'],
            'event_type' => $eventInfo['eventType'],
            'payment_intent_id' => $eventInfo['paymentIntentId'],
            'remote_ip' => $remoteIp,
            'payload_size' => strlen($payload),
            'has_signature' => $signature !== '',
        ]);
    }

    public function logResult(string $payload, string $result, int $httpCode): void
    {
        $eventInfo = $this->parseEventInfo($payload);

        $this->fileLogger->log('WEBHOOK_RESULT', [
            'result' => $result,
            'http_code' => $httpCode,
            'event_type' => $eventInfo['eventType'],
            'event_id' => $eventInfo['eventId'],
        ]);
    }

    /**
     * @return array{eventId: string, eventType: string, paymentIntentId: string}
     */
    private function parseEventInfo(string $payload): array
    {
        $result = ['eventId' => 'unknown', 'eventType' => 'unknown', 'paymentIntentId' => 'unknown'];

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return $result;
        }

        $result['eventId'] = is_string($data['id'] ?? null) ? $data['id'] : 'unknown';
        $result['eventType'] = is_string($data['type'] ?? null) ? $data['type'] : 'unknown';

        // Extract payment intent ID from nested structure
        $object = $data['data']['object'] ?? [];
        if (is_array($object)) {
            $id = $object['id'] ?? $object['payment_intent'] ?? null;
            $result['paymentIntentId'] = is_string($id) ? $id : 'unknown';
        }

        return $result;
    }
}
```

**Step 3: Refactor WebhookController**
```php
// src/Stripe/Controller/Webhook/WebhookController.php
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
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Use centralized logging
        $this->webhookLogger?->logReceived($payload ?: '', (string)$signature, (string)$remoteIp);

        // ... validation and processing ...

        $this->webhookLogger?->logResult($payload ?: '', "SUCCESS: {$result->action}", 200);

        // ... response handling ...
    }

    // DELETE all inline logging methods (lines 127-293)
}
```

**Step 4: Register in services.yaml**
```yaml
OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory: ~

webhook_file_logger:
    class: OxidEsales\PaymentComponent\Service\FileLoggerInterface
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory', 'create']

OxidEsales\Payments\Stripe\Service\WebhookLogServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\WebhookLogService
    arguments:
        $fileLogger: '@webhook_file_logger'
```

### Benefits of Refactoring
- **~150 lines removed** from controller
- **Consistent log format** across all Stripe logging
- **Testable** - WebhookLogService can be unit tested
- **Reusable** - Other controllers can use same service
- **SRP compliant** - Controller only handles HTTP concerns

---

## Priority Order

| Priority | Issue | Effort | Impact |
|----------|-------|--------|--------|
| **1** | OrderRefund adapter methods | 2h | HIGH - Blocks admin refund UI |
| **2** | RequestLogService file-based logging | 1-2h | MEDIUM - Logging broken |
| **3** | WebhookController centralized logging | 2-3h | MEDIUM - Code quality/consistency |
| **4** | StripeCustomerService cleanup | 30min | LOW - Just PHPStan warning |
| **5** | Factory type issues | 15min | LOW - Just PHPStan warnings |

---

## Files to Modify

### Must Fix (Blocking)
1. `src/Stripe/Adapter/StripeAdapterInterface.php` - Add `retrieveCharge()` method
2. `src/Stripe/Adapter/StripeAdapter.php` - Implement `retrieveCharge()`
3. `src/Stripe/Controller/Admin/OrderRefund.php` - Use adapter methods
4. `src/Stripe/Service/RequestLogService.php` - Refactor to use FileLoggerInterface

### Should Fix (Code Quality)
5. `src/Stripe/Service/Factory/RequestFileLoggerFactory.php` - CREATE
6. `src/Stripe/Service/Factory/WebhookFileLoggerFactory.php` - CREATE
7. `src/Stripe/Service/WebhookLogService.php` - CREATE
8. `src/Stripe/Service/WebhookLogServiceInterface.php` - CREATE
9. `src/Stripe/Controller/Webhook/WebhookController.php` - Refactor to use WebhookLogService

### Cleanup (PHPStan)
10. `src/Stripe/Service/StripeCustomerService.php` - Remove unused property or class
11. `src/Stripe/Service/Factory/EventFileLoggerFactory.php` - Type assertion
12. `src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php` - Type assertion

### Tests to Create/Update
13. `tests/Unit/Stripe/Controller/Admin/OrderRefundTest.php` - If exists
14. `tests/Unit/Stripe/Adapter/StripeAdapterTest.php` - Add retrieveCharge test
15. `tests/Unit/Stripe/Service/RequestLogServiceTest.php` - Update for FileLogger
16. `tests/Unit/Stripe/Service/WebhookLogServiceTest.php` - CREATE

---

## Centralized Logging Architecture

After refactoring, Stripe logging will follow this consistent pattern:

```
┌─────────────────────────────────────────────────────────────────┐
│                    STRIPE LOGGING SYSTEM                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────┐ │
│  │ EventFileLogger │    │ RequestFileLogger│    │WebhookLogger│ │
│  │ Factory         │    │ Factory          │    │ Factory     │ │
│  └────────┬────────┘    └────────┬─────────┘    └──────┬──────┘ │
│           │                      │                      │        │
│           ▼                      ▼                      ▼        │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │              FileLogger (payment-component)                  ││
│  │              implements FileLoggerInterface                  ││
│  └─────────────────────────────────────────────────────────────┘│
│                              │                                   │
│                              ▼                                   │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                    LOG FILES                                 ││
│  │  log/osc/stripe_events.log                                   ││
│  │  log/osc/stripe_requests.log                                 ││
│  │  log/osc/stripe_webhooks.log                                 ││
│  │  log/osc/stripe_reconciliation.log                           ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

---

## Verification

After fixes:
```bash
./bin/pre-commit-check.sh --full
```

Expected result: All checks pass (matching CI state from 2026-01-23)
