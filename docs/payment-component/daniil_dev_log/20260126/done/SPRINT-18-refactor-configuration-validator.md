# SPRINT-18: Refactor ConfigurationValidator to Use Adapter

**Priority:** MEDIUM
**Estimated Effort:** 1h
**Impact:** Eliminate direct SDK usage
**Decision:** Test configured key only - no arbitrary key testing (confirmed)

---

## Problem Statement

`ConfigurationValidator::testConnection()` creates `StripeClient` directly:

```php
public function testConnection(string $secretKey): bool
{
    try {
        // VIOLATION: Direct StripeClient instantiation
        $stripe = new StripeClient($secretKey);
        $stripe->balance->retrieve();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}
```

This violates the adapter architecture - all Stripe SDK calls should go through `StripeAdapter`.

---

## Requirements

### R1: Add `testConnection()` to StripeAdapterInterface
- Method tests API connectivity
- Returns boolean
- Used for configuration validation

### R2: Implement in StripeAdapter
- Call `balance->retrieve()` to verify credentials
- Return true on success, false on exception

### R3: Refactor ConfigurationValidator
- Inject `StripeAdapterFactoryInterface`
- Use adapter's `testConnection()` instead of direct SDK
- Remove `$secretKey` parameter from `testConnection()`

### R4: All tests must pass
- Unit tests for new adapter method
- Update `ConfigurationValidatorTest`
- PHPStan level 6
- PHPCS PSR-12

---

## TDD Implementation

### Step 1: Write test for StripeAdapter::testConnection()

```php
// tests/Unit/Stripe/Adapter/StripeAdapterTest.php

public function testTestConnectionReturnsTrueOnSuccess(): void
{
    $balanceService = $this->createMock(\Stripe\Service\BalanceService::class);
    $balanceService->expects($this->once())
        ->method('retrieve')
        ->willReturn(new \Stripe\Balance());

    $client = $this->createMock(StripeClient::class);
    $client->balance = $balanceService;

    $adapter = new StripeAdapter($client);

    $this->assertTrue($adapter->testConnection());
}

public function testTestConnectionReturnsFalseOnApiError(): void
{
    $balanceService = $this->createMock(\Stripe\Service\BalanceService::class);
    $balanceService->method('retrieve')
        ->willThrowException(new \Stripe\Exception\AuthenticationException('Invalid key'));

    $client = $this->createMock(StripeClient::class);
    $client->balance = $balanceService;

    $adapter = new StripeAdapter($client);

    $this->assertFalse($adapter->testConnection());
}

public function testTestConnectionReturnsFalseOnNetworkError(): void
{
    $balanceService = $this->createMock(\Stripe\Service\BalanceService::class);
    $balanceService->method('retrieve')
        ->willThrowException(new \Stripe\Exception\ApiConnectionException('Network error'));

    $client = $this->createMock(StripeClient::class);
    $client->balance = $balanceService;

    $adapter = new StripeAdapter($client);

    $this->assertFalse($adapter->testConnection());
}
```

### Step 2: Add method to StripeAdapterInterface

```php
// src/Stripe/Adapter/StripeAdapterInterface.php

/**
 * Test API connectivity by retrieving account balance.
 *
 * Used by ConfigurationValidator to verify API keys are valid.
 * This method catches all exceptions and returns false instead of throwing.
 *
 * @return bool True if API connection successful, false otherwise
 */
public function testConnection(): bool;
```

### Step 3: Implement in StripeAdapter

```php
// src/Stripe/Adapter/StripeAdapter.php

/**
 * @inheritDoc
 */
public function testConnection(): bool
{
    try {
        $this->stripeClient->balance->retrieve();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
```

### Step 4: Write test for refactored ConfigurationValidator

```php
// tests/Unit/Stripe/Service/ConfigurationValidatorTest.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;

class ConfigurationValidatorTest extends TestCase
{
    public function testTestConnectionReturnsTrueWhenAdapterSucceeds(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('testConnection')
            ->willReturn(true);

        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->expects($this->once())
            ->method('getStripeAdapter')
            ->willReturn($adapter);

        $validator = new ConfigurationValidator($factory);

        $this->assertTrue($validator->testConnection());
    }

    public function testTestConnectionReturnsFalseWhenAdapterFails(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->method('testConnection')
            ->willReturn(false);

        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')
            ->willReturn($adapter);

        $validator = new ConfigurationValidator($factory);

        $this->assertFalse($validator->testConnection());
    }

    public function testTestConnectionReturnsFalseWhenFactoryThrows(): void
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')
            ->willThrowException(new \RuntimeException('Not configured'));

        $validator = new ConfigurationValidator($factory);

        $this->assertFalse($validator->testConnection());
    }

    public function testValidateConfigurationValidatesSecretKeyFormat(): void
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $validator = new ConfigurationValidator($factory);

        // Test mode with test key - valid
        $errors = $validator->validateConfiguration(true, 'sk_test_abc', 'whsec_xyz');
        $this->assertArrayNotHasKey('secretKey', $errors);

        // Test mode with live key - invalid
        $errors = $validator->validateConfiguration(true, 'sk_live_abc', 'whsec_xyz');
        $this->assertArrayHasKey('secretKey', $errors);

        // Live mode with live key - valid
        $errors = $validator->validateConfiguration(false, 'sk_live_abc', 'whsec_xyz');
        $this->assertArrayNotHasKey('secretKey', $errors);
    }

    public function testValidateConfigurationValidatesWebhookSecret(): void
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $validator = new ConfigurationValidator($factory);

        // Valid webhook secret
        $errors = $validator->validateConfiguration(true, 'sk_test_abc', 'whsec_xyz');
        $this->assertArrayNotHasKey('webhookSecret', $errors);

        // Invalid webhook secret
        $errors = $validator->validateConfiguration(true, 'sk_test_abc', 'invalid');
        $this->assertArrayHasKey('webhookSecret', $errors);

        // Empty webhook secret
        $errors = $validator->validateConfiguration(true, 'sk_test_abc', '');
        $this->assertArrayHasKey('webhookSecret', $errors);
    }
}
```

### Step 5: Refactor ConfigurationValidator

```php
// src/Stripe/Service/ConfigurationValidator.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\ServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Validator for Stripe API configuration.
 *
 * Sprint 18: Refactored to use adapter instead of direct SDK.
 *
 * @since 2.0.0
 */
class ConfigurationValidator implements ServiceInterface
{
    private const TEST_KEY_PREFIX = 'sk_test_';
    private const LIVE_KEY_PREFIX = 'sk_live_';
    private const WEBHOOK_SECRET_PREFIX = 'whsec_';

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory
    ) {
    }

    /**
     * Validate complete module configuration.
     *
     * @param bool $isTestMode Whether the module is in test mode
     * @param string $secretKey The Stripe secret key to validate
     * @param string $webhookSecret The webhook secret to validate
     * @return array<string, string> Array of validation errors (empty if valid)
     */
    public function validateConfiguration(
        bool $isTestMode,
        string $secretKey,
        string $webhookSecret
    ): array {
        $errors = [];

        // Validate secret key
        if (empty($secretKey)) {
            $errors['secretKey'] = 'Secret key is required';
        } elseif (!$this->validateApiKeyFormat($secretKey, $isTestMode)) {
            $errors['secretKey'] = $isTestMode
                ? 'Test mode requires test key (sk_test_...)'
                : 'Live mode requires live key (sk_live_...)';
        }

        // Validate webhook secret
        if (empty($webhookSecret)) {
            $errors['webhookSecret'] = 'Webhook secret is required';
        } elseif (
            !str_starts_with($webhookSecret, self::WEBHOOK_SECRET_PREFIX) ||
            strlen($webhookSecret) <= strlen(self::WEBHOOK_SECRET_PREFIX)
        ) {
            $errors['webhookSecret'] = 'Invalid webhook secret format (must start with whsec_)';
        }

        return $errors;
    }

    /**
     * Validate API key format matches the expected mode.
     *
     * @param string $apiKey The API key to validate
     * @param bool $isTestMode Whether test mode is enabled
     * @return bool True if format is valid for the mode
     */
    public function validateApiKeyFormat(string $apiKey, bool $isTestMode): bool
    {
        $expectedPrefix = $isTestMode ? self::TEST_KEY_PREFIX : self::LIVE_KEY_PREFIX;
        return str_starts_with($apiKey, $expectedPrefix);
    }

    /**
     * Test connection to Stripe API using configured credentials.
     *
     * Uses the adapter to verify API connectivity instead of creating
     * a StripeClient directly.
     *
     * @return bool True if connection successful, false otherwise
     */
    public function testConnection(): bool
    {
        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
            return $adapter->testConnection();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

### Step 6: Update services.yaml

```yaml
# services.yaml

OxidEsales\Payments\Stripe\Service\ConfigurationValidator:
    arguments:
        $adapterFactory: '@OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface'
```

---

## Files to Modify

| File | Action |
|------|--------|
| `src/Stripe/Adapter/StripeAdapterInterface.php` | Add `testConnection(): bool` |
| `src/Stripe/Adapter/StripeAdapter.php` | Implement `testConnection()` |
| `src/Stripe/Service/ConfigurationValidator.php` | Inject factory, use adapter |
| `tests/Unit/Stripe/Adapter/StripeAdapterTest.php` | Add tests for `testConnection()` |
| `tests/Unit/Stripe/Service/ConfigurationValidatorTest.php` | Update tests |
| `services.yaml` | Update `ConfigurationValidator` registration |

---

## Verification

```bash
# Verify no direct StripeClient usage in ConfigurationValidator
grep "new StripeClient" src/Stripe/Service/ConfigurationValidator.php
# Expected: No results

# Run pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: No errors
# - PHPUnit: All tests pass
# - PHPCS: No style violations
```

---

## Acceptance Criteria

- [ ] `StripeAdapterInterface` has `testConnection(): bool` method
- [ ] `StripeAdapter` implements `testConnection()` with exception handling
- [ ] `ConfigurationValidator` injects `StripeAdapterFactoryInterface`
- [ ] `ConfigurationValidator::testConnection()` uses adapter (no direct SDK)
- [ ] No `new StripeClient` in `ConfigurationValidator.php`
- [ ] Unit tests pass for `testConnection()` in both classes
- [ ] `./bin/pre-commit-check.sh --full` passes
