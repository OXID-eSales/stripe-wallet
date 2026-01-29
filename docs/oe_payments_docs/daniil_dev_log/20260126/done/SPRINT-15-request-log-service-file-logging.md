# SPRINT-15: RequestLogService File-Based Logging

**Priority:** HIGH
**Estimated Effort:** 1-2h
**Blocking:** Logging broken, PHPStan errors
**Decision:** Keep specific methods `logRequest()` and `logException()` (confirmed)

---

## Problem Statement

`RequestLogService` references a non-existent `RequestLog` class:

```php
// Line 115 - BROKEN: Class does not exist
return oxNew(RequestLog::class);
```

**PHPStan Errors:**
- Line 50: `Call to method logRequest() on object`
- Line 73: `Call to method logExceptionResponse() on object`
- Line 115: `Class OxidEsales\Payments\Stripe\Service\RequestLog not found`

---

## Requirements

### R1: Use file-based logging (NOT database)
- Follow pattern from `EventFileLoggerFactory`
- Use `FileLoggerInterface` from payment-component
- Log to `log/osc/stripe_requests.log`

### R2: Create RequestFileLoggerFactory
- Factory creates `FileLogger` instance
- Consistent with existing factory pattern

### R3: Refactor RequestLogService
- Inject `FileLoggerInterface` instead of creating `RequestLog`
- Simplify to use `FileLoggerInterface::log()` method
- Remove broken `oxNew(RequestLog::class)` call

### R4: Update services.yaml
- Register `RequestFileLoggerFactory`
- Wire `FileLoggerInterface` to `RequestLogService`

### R5: All tests must pass
- Update existing `RequestLogServiceTest`
- PHPStan level 6
- PHPCS PSR-12

---

## TDD Implementation

### Step 1: Write test for RequestFileLoggerFactory

```php
// tests/Unit/Stripe/Service/Factory/RequestFileLoggerFactoryTest.php

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory;
use PHPUnit\Framework\TestCase;

class RequestFileLoggerFactoryTest extends TestCase
{
    public function testCreateReturnsFileLoggerInterface(): void
    {
        // Note: This test requires mocking Registry::getConfig()
        // or use integration test approach
        $this->markTestSkipped('Requires OXID Registry mock');
    }
}
```

### Step 2: Create RequestFileLoggerFactory

```php
// src/Stripe/Service/Factory/RequestFileLoggerFactory.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the request logging file logger.
 *
 * Sprint 15: Logs API requests/responses to file instead of database.
 * Logs to log/osc/stripe_requests.log
 *
 * @since 2.0.0
 */
final class RequestFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_requests.log';

    /**
     * Create the request file logger.
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

        return new FileLogger($logFilePath, 'REQUEST');
    }
}
```

### Step 3: Write test for refactored RequestLogService

```php
// tests/Unit/Stripe/Service/RequestLogServiceTest.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestLogServiceTest extends TestCase
{
    public function testLogRequestDelegatesToFileLogger(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'CAPTURE',
                $this->callback(function (array $context) {
                    return $context['reference_id'] === 'pi_test123'
                        && $context['shop_id'] === 1
                        && isset($context['request'])
                        && isset($context['response']);
                })
            );

        $service = new RequestLogService($fileLogger);

        $service->logRequest(
            action: 'CAPTURE',
            request: ['amount' => 1000],
            response: ['status' => 'succeeded'],
            referenceId: 'pi_test123',
            shopId: 1
        );
    }

    public function testLogExceptionDelegatesToFileLogger(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'CAPTURE_EXCEPTION',
                $this->callback(function (array $context) {
                    return $context['reference_id'] === 'pi_test123'
                        && $context['error_code'] === 500
                        && $context['error_message'] === 'Test error';
                })
            );

        $service = new RequestLogService($fileLogger);

        $service->logException(
            action: 'CAPTURE',
            exception: new \Exception('Test error'),
            referenceId: 'pi_test123',
            shopId: 1
        );
    }

    public function testLogRequestHandlesFileLoggerException(): void
    {
        $fileLogger = $this->createMock(FileLoggerInterface::class);
        $fileLogger->method('log')
            ->willThrowException(new \Exception('Write failed'));

        $fallbackLogger = $this->createMock(LoggerInterface::class);
        $fallbackLogger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to log request',
                $this->arrayHasKey('error')
            );

        $service = new RequestLogService($fileLogger, $fallbackLogger);

        // Should not throw, just log warning
        $service->logRequest('TEST', [], [], 'ref', 1);
    }
}
```

### Step 4: Refactor RequestLogService

```php
// src/Stripe/Service/RequestLogService.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for logging payment API requests to file.
 *
 * Sprint 15: Refactored to use FileLoggerInterface instead of database model.
 * Logs to log/osc/stripe_requests.log via RequestFileLoggerFactory.
 *
 * @since 2.0.0
 */
final class RequestLogService implements RequestLogServiceInterface
{
    private readonly LoggerInterface $fallbackLogger;

    public function __construct(
        private readonly FileLoggerInterface $fileLogger,
        ?LoggerInterface $fallbackLogger = null
    ) {
        $this->fallbackLogger = $fallbackLogger ?? new NullLogger();
    }

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
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->fallbackLogger->warning('Failed to log request', [
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
            $this->fileLogger->log($action . '_EXCEPTION', [
                'reference_id' => $referenceId,
                'shop_id' => $shopId,
                'error_code' => $exception->getCode() ?: 500,
                'error_message' => $exception->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->fallbackLogger->warning('Failed to log exception', [
                'action' => $action,
                'reference_id' => $referenceId,
                'original_error' => $exception->getMessage(),
                'log_error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Step 5: Update services.yaml

```yaml
# services.yaml

# Request file logger factory
OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory: ~

# Request file logger instance (created by factory)
stripe.request_file_logger:
    class: OxidEsales\PaymentComponent\Service\FileLoggerInterface
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory', 'create']

# RequestLogService with file logger
OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\RequestLogService
    arguments:
        $fileLogger: '@stripe.request_file_logger'
        $fallbackLogger: '@logger'
```

---

## Files to Create

| File | Description |
|------|-------------|
| `src/Stripe/Service/Factory/RequestFileLoggerFactory.php` | Factory for file logger |
| `tests/Unit/Stripe/Service/Factory/RequestFileLoggerFactoryTest.php` | Factory test |

## Files to Modify

| File | Action |
|------|--------|
| `src/Stripe/Service/RequestLogService.php` | Refactor to use FileLoggerInterface |
| `tests/Unit/Stripe/Service/RequestLogServiceTest.php` | Update tests |
| `services.yaml` | Register factory and wire dependencies |

---

## Verification

```bash
# Run pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: No errors for RequestLogService.php
# - PHPUnit: All tests pass including RequestLogServiceTest
# - PHPCS: No style violations

# Verify log file is created (manual test)
# After running a payment operation, check:
# source/log/osc/stripe_requests.log
```

---

## Acceptance Criteria

- [ ] `RequestFileLoggerFactory` created and returns `FileLoggerInterface`
- [ ] `RequestLogService` refactored to use `FileLoggerInterface`
- [ ] No more `oxNew(RequestLog::class)` calls
- [ ] `services.yaml` updated with factory and wiring
- [ ] Unit tests pass for `RequestLogService`
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Log entries appear in `log/osc/stripe_requests.log` (manual verification)
