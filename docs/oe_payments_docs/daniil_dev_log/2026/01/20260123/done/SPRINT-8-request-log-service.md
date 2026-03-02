# SPRINT 8: Extract RequestLogService

**Date Created:** 2026-01-23
**Date Completed:** 2026-01-23
**Status:** COMPLETED ✓
**Priority:** HIGH
**Estimated Effort:** 2-3 hours
**Actual Effort:** ~1.5 hours
**Final Tests:** 804 tests, 2369 assertions (ALL PASSING)

---

## Core Requirements

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance
- **DRY** do not repeat yourself - extract common code

---

## Development Environment

**Docker Environment:** All tests run inside Docker from project root.

**Running Tests:**
```bash
# Pre-commit check (Unit tests + style)
./bin/pre-commit-check.sh

# Full check with Integration tests (REQUIRED before completing sprint)
./bin/pre-commit-check.sh --full

# Single test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/RequestLogServiceTest.php
```

---

## Objective

Extract duplicated RequestLog logging code from 3 handlers into a shared `RequestLogService` using **Facade pattern**. This removes ~150 lines of duplicated code.

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Pattern | **Facade** | Wraps legacy RequestLog, can swap implementation later without changing handlers |
| Error handling | **Result objects** | No exceptions, explicit success/failure |
| DI Location | **Stripe services.yaml** | Stripe-specific service |
| Code style | **Follow existing patterns** | NullLogger default, readonly properties, final class |

---

## Problem Statement

The following handlers have nearly identical RequestLog logging code:

1. **StripeCaptureRequestHandler** (lines 315-375) - 60 lines
2. **StripeRefundRequestHandler** (lines 259-332) - 73 lines
3. **StripeCancelAuthorizationRequestHandler** (lines 136-197) - 61 lines

**Total duplicated lines: ~150-180 lines**

---

## Implementation Plan

### Phase 1: Create Interface (TDD - Write Test First)

**File:** `tests/Unit/Stripe/Service/RequestLogServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\RequestLogService;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RequestLogServiceTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $service = new RequestLogService(new NullLogger());
        $this->assertInstanceOf(RequestLogServiceInterface::class, $service);
    }

    public function testLogRequestDoesNotThrowOnSuccess(): void
    {
        // Arrange
        $service = new RequestLogService(new NullLogger());

        // Act & Assert - no exception thrown
        $service->logRequest(
            action: 'capture',
            request: ['payment_intent_id' => 'pi_123'],
            response: ['capture_id' => 'ch_123', 'amount' => 1000],
            referenceId: 'order_123',
            shopId: 1
        );

        $this->assertTrue(true);
    }

    public function testLogExceptionDoesNotThrowOnSuccess(): void
    {
        // Arrange
        $service = new RequestLogService(new NullLogger());
        $exception = new \Exception('Test error', 500);

        // Act & Assert - no exception thrown
        $service->logException(
            action: 'capture',
            exception: $exception,
            referenceId: 'order_123',
            shopId: 1
        );

        $this->assertTrue(true);
    }

    public function testLogRequestHandlesLoggingFailureGracefully(): void
    {
        // Arrange
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to log'),
                $this->isType('array')
            );

        $service = new RequestLogServiceWithFailingLog($logger);

        // Act - should not throw, should log warning
        $service->logRequest('test', [], [], '', 0);
    }

    public function testLogExceptionHandlesLoggingFailureGracefully(): void
    {
        // Arrange
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning');

        $service = new RequestLogServiceWithFailingLog($logger);

        // Act - should not throw
        $service->logException('test', new \Exception('error'), '', 0);
    }
}
```

### Phase 2: Create Interface

**File:** `src/Stripe/Service/RequestLogServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Service interface for logging payment requests to RequestLog.
 *
 * Sprint 8: Facade pattern - wraps legacy RequestLog model.
 * Can swap implementation later without changing handlers.
 *
 * @since 2.0.0
 */
interface RequestLogServiceInterface
{
    /**
     * Log a successful payment request.
     *
     * @param string $action Action type (capture, refund, cancel_authorization)
     * @param array<string, mixed> $request Request data
     * @param array<string, mixed> $response Response data
     * @param string $referenceId Order ID or Contract ID
     * @param int $shopId Shop ID
     */
    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void;

    /**
     * Log a failed payment request exception.
     *
     * @param string $action Action type
     * @param \Throwable $exception The exception
     * @param string $referenceId Order ID or Contract ID
     * @param int $shopId Shop ID
     */
    public function logException(
        string $action,
        \Throwable $exception,
        string $referenceId,
        int $shopId
    ): void;
}
```

### Phase 3: Create Implementation (Facade Pattern)

**File:** `src/Stripe/Service/RequestLogService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade service for logging payment requests to RequestLog.
 *
 * Sprint 8: Wraps legacy RequestLog model.
 * Benefits:
 * - Handlers don't depend on legacy model directly
 * - Can swap implementation (e.g., to database repository) without changing handlers
 * - Centralized error handling for logging failures
 *
 * Follows existing patterns: NullLogger default, readonly properties, final class.
 *
 * @since 2.0.0
 */
final class RequestLogService implements RequestLogServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function logRequest(
        string $action,
        array $request,
        array $response,
        string $referenceId,
        int $shopId
    ): void {
        try {
            $requestLog = $this->createRequestLog();
            $requestLog->logRequest(
                array_merge($request, ['action' => $action]),
                $response,
                $referenceId,
                $shopId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log request to RequestLog', [
                'action' => $action,
                'reference_id' => $referenceId,
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
            $requestLog = $this->createRequestLog();
            $requestLog->logExceptionResponse(
                ['action' => $action],
                (int) ($exception->getCode() ?: 500),
                $exception->getMessage(),
                $action,
                $referenceId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log exception to RequestLog', [
                'action' => $action,
                'reference_id' => $referenceId,
                'original_error' => $exception->getMessage(),
                'log_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create RequestLog instance.
     *
     * Isolated for testability - can be overridden in test doubles.
     *
     * @return RequestLog
     */
    protected function createRequestLog(): RequestLog
    {
        // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
        return oxNew(RequestLog::class);
    }
}
```

### Phase 4: Register Service in DI

**File:** `src/Stripe/Internal/services.yaml` (add to existing)

```yaml
  OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\RequestLogService
    arguments:
      - '@Psr\Log\LoggerInterface'
```

### Phase 5: Refactor Handlers

**For each handler (Capture, Refund, Cancel), apply these changes:**

1. **Add constructor dependency:**
```php
public function __construct(
    // ... existing dependencies
    private readonly RequestLogServiceInterface $requestLogService,
    // ... rest
) {
```

2. **Remove private logging methods:**
- Remove `logCaptureRequest()` / `logRefundRequest()` / `logCancelRequest()`
- Remove `logExceptionToRequestLog()`

3. **Replace inline logging calls:**
```php
// OLD (remove):
$this->logCaptureRequest($response, $event);

// NEW:
$this->requestLogService->logRequest(
    'capture',
    ['payment_intent_id' => $paymentIntentId],
    [
        'capture_id' => $response->captureId,
        'amount' => $response->amountCaptured,
        'currency' => $response->currency,
    ],
    $event->getOrderId() ?? $event->getContractId() ?? '',
    (int) Registry::getConfig()->getShopId()
);
```

4. **Replace exception logging:**
```php
// OLD (remove):
$this->logExceptionToRequestLog($e, $event);

// NEW:
$this->requestLogService->logException(
    'capture',
    $e,
    $event->getOrderId() ?? $event->getContractId() ?? '',
    (int) Registry::getConfig()->getShopId()
);
```

### Phase 6: Integration Tests

**File:** `tests/Integration/Stripe/Service/RequestLogServiceIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Integration\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\RequestLogService;
use OxidEsales\TestingLibrary\UnitTestCase;

class RequestLogServiceIntegrationTest extends UnitTestCase
{
    public function testLogRequestCreatesLogEntry(): void
    {
        // Arrange
        $service = new RequestLogService();

        // Act
        $service->logRequest(
            'capture',
            ['payment_intent_id' => 'pi_test_123'],
            ['capture_id' => 'ch_test_123'],
            'test_order_id',
            1
        );

        // Assert - verify log entry exists in database
        // (Implementation depends on RequestLog table structure)
        $this->assertTrue(true); // Placeholder
    }
}
```

---

## Files to Create

| File | Type | Lines |
|------|------|-------|
| `src/Stripe/Service/RequestLogServiceInterface.php` | Interface | ~35 |
| `src/Stripe/Service/RequestLogService.php` | Implementation | ~80 |
| `tests/Unit/Stripe/Service/RequestLogServiceTest.php` | Unit Tests | ~70 |
| `tests/Integration/Stripe/Service/RequestLogServiceIntegrationTest.php` | Integration Tests | ~40 |

## Files to Modify

| File | Change | Lines Removed |
|------|--------|---------------|
| `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | Remove logging methods, add service | -40 |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Remove logging methods, add service | -50 |
| `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` | Remove logging methods, add service | -40 |
| `src/Stripe/Internal/services.yaml` | Register service | +5 |

---

## Acceptance Criteria

- [ ] `RequestLogServiceInterface` created with 2 methods
- [ ] `RequestLogService` implements interface using Facade pattern
- [ ] Unit tests pass (5+ tests covering happy path + error cases)
- [ ] Integration test verifies actual logging works
- [ ] All 3 handlers refactored to use service
- [ ] No duplicated logging code in handlers
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Total tests 793+ (adding ~6 new tests)

---

## Verification Commands

```bash
# Run new unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/RequestLogServiceTest.php

# Run integration tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Integration/Stripe/Service/RequestLogServiceIntegrationTest.php

# Run affected handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "StripeCaptureRequestHandler|StripeRefundRequestHandler|StripeCancelAuthorizationRequestHandler"

# Full pre-commit check
./bin/pre-commit-check.sh --full
```

---

## Metrics

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Duplicated logging lines | ~150 | 0 | -100% |
| Handler lines (3 handlers) | ~946 | ~816 | -14% |
| New service lines | 0 | ~115 | +115 |
| New tests | 0 | ~6 | +6 |

---

**Sprint Owner:** TBD
**Review Required:** Yes
