# Direct StripeClient Usage Analysis

**Date:** 2026-01-26
**Goal:** Eliminate all direct Stripe SDK calls - all calls must go through adapter

---

## Architecture Goal

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         CORRECT ARCHITECTURE                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────┐    ┌─────────────────────────┐    ┌────────────────┐  │
│  │   Service    │───▶│ StripeAdapterInterface  │───▶│  StripeAdapter │  │
│  │  (Business)  │    │ (payment-component)     │    │   (Stripe)     │  │
│  └──────────────┘    └─────────────────────────┘    └───────┬────────┘  │
│                                                              │           │
│                                                              ▼           │
│                                                     ┌────────────────┐  │
│                                                     │  StripeClient  │  │
│                                                     │  (Stripe SDK)  │  │
│                                                     └────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                         WRONG ARCHITECTURE                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌──────────────┐                               ┌────────────────┐      │
│  │   Service    │──────────────────────────────▶│  StripeClient  │      │
│  │  (Business)  │   DIRECT CALL - VIOLATION!    │  (Stripe SDK)  │      │
│  └──────────────┘                               └────────────────┘      │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## StripeAdapterFactory Status

**Is StripeAdapterFactory unused?** NO - it's properly wired in services.yaml:

```yaml
# services.yaml (lines 164-180)
OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactoryInterface:
    class: OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactory

OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface:
    alias: OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactoryInterface

OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactory:
    arguments:
        $configurationService: '@OxidEsales\Payments\Stripe\Service\ModuleConfigurationService'
        $clientFactory: '@OxidEsales\Payments\Stripe\Adapter\StripeClientFactory'

OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface:
    factory: ['@OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface', 'getStripeAdapter']
```

**Services using StripeAdapterFactoryInterface (correct):**
- Line 311: CheckoutSessionService
- Line 380: CheckoutReturnService
- Line 483: RefundService
- Line 539: OxpaidReconciliationService
- Line 781: StripeRadarFraudCheckService
- Line 859: StripePaymentStatusHandler

---

## Direct StripeClient Violations

### Violation 1: StripeCustomerService

**File:** `src/Stripe/Service/StripeCustomerService.php:86`

```php
protected function doInitialize(): void
{
    $secretKey = $this->config->getToken();
    // VIOLATION: Direct StripeClient instantiation
    $this->stripe = new StripeClient($secretKey);
}
```

**Status:** Class is essentially empty (all methods removed), only has unused `$stripe` property.

**Solution:** DELETE the class entirely (already identified in Issue 3 of previous report).

---

### Violation 2: ConfigurationValidator

**File:** `src/Stripe/Service/ConfigurationValidator.php:101`

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

**Analysis:** This is a configuration validation service that tests if API keys work. It intentionally creates a StripeClient to verify credentials.

**Options:**

**Option A: Add testConnection() to StripeAdapterInterface** (Recommended)
```php
// StripeAdapterInterface.php
/**
 * Test API connectivity.
 * @return bool True if connection successful
 */
public function testConnection(): bool;

// StripeAdapter.php
public function testConnection(): bool
{
    try {
        $this->stripeClient->balance->retrieve();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}
```

Then refactor ConfigurationValidator:
```php
public function __construct(
    private readonly StripeAdapterFactoryInterface $adapterFactory
) {}

public function testConnection(): bool
{
    try {
        $adapter = $this->adapterFactory->getStripeAdapter();
        return $adapter->testConnection();
    } catch (\Exception $e) {
        return false;
    }
}
```

**Option B: Accept this as infrastructure code**
The ConfigurationValidator is used during admin configuration before the adapter can be created. It needs to test arbitrary keys, not the configured key.

If we accept this, add a PHPStan ignore annotation:
```php
/**
 * @phpstan-ignore-next-line Infrastructure code for key validation
 */
$stripe = new StripeClient($secretKey);
```

---

### Acceptable: StripeClientFactory

**File:** `src/Stripe/Adapter/StripeClientFactory.php:44`

```php
public function create(): ?StripeClient
{
    return !empty($this->secretKey) ? new StripeClient([
        'api_key' => $this->secretKey,
        'stripe_version' => '2024-11-20.acacia',
    ]) : null;
}
```

**Status:** ACCEPTABLE - This is the single point where StripeClient is created for injection into StripeAdapter. This is the correct pattern.

---

## Inheritance Hierarchy

**Current (Correct):**
```
PaymentAdapterFactoryInterface (payment-component)
         ▲
         │ implements
PaymentAdapterFactory (payment-component, abstract)
         ▲
         │ extends
StripeAdapterFactory (stripe module)
         │ implements
         ▼
StripeAdapterFactoryInterface (stripe module)
```

**Adapter:**
```
PaymentAdapterInterface (payment-component)
         ▲
         │ extends
StripeAdapterInterface (stripe module)
         ▲
         │ implements
StripeAdapter (stripe module)
         │
         │ uses
         ▼
StripeClient (Stripe SDK) ← Only place SDK is used
```

---

## Summary of Required Changes

| Priority | File | Action | Effort |
|----------|------|--------|--------|
| **HIGH** | `StripeCustomerService.php` | DELETE entirely | 5min |
| **MEDIUM** | `ConfigurationValidator.php` | Refactor to use adapter OR accept as infrastructure | 30min |
| **NONE** | `StripeClientFactory.php` | No change - correct pattern | - |
| **NONE** | `StripeAdapterFactory.php` | No change - properly used | - |

---

## TDD Solution for ConfigurationValidator (Option A)

**Step 1: Add interface method**
```php
// src/Stripe/Adapter/StripeAdapterInterface.php

/**
 * Test API connectivity by retrieving account balance.
 *
 * Used by ConfigurationValidator to verify API keys are valid.
 *
 * @return bool True if API connection successful
 */
public function testConnection(): bool;
```

**Step 2: Write failing test**
```php
// tests/Unit/Stripe/Adapter/StripeAdapterTest.php

public function testTestConnectionReturnsTrue(): void
{
    $client = $this->createMock(StripeClient::class);
    $balanceService = $this->createMock(\Stripe\Service\BalanceService::class);
    $balanceService->method('retrieve')->willReturn(new \Stripe\Balance());

    $client->balance = $balanceService;

    $adapter = new StripeAdapter($client);

    $this->assertTrue($adapter->testConnection());
}

public function testTestConnectionReturnsFalseOnException(): void
{
    $client = $this->createMock(StripeClient::class);
    $balanceService = $this->createMock(\Stripe\Service\BalanceService::class);
    $balanceService->method('retrieve')->willThrowException(new \Exception('Invalid key'));

    $client->balance = $balanceService;

    $adapter = new StripeAdapter($client);

    $this->assertFalse($adapter->testConnection());
}
```

**Step 3: Implement**
```php
// src/Stripe/Adapter/StripeAdapter.php

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

**Step 4: Refactor ConfigurationValidator**
```php
// src/Stripe/Service/ConfigurationValidator.php

public function __construct(
    private readonly StripeAdapterFactoryInterface $adapterFactory
) {}

public function testConnection(): bool
{
    try {
        return $this->adapterFactory->getStripeAdapter()->testConnection();
    } catch (\Throwable $e) {
        return false;
    }
}

// REMOVE the old testConnection(string $secretKey) method
```

---

## Verification

After changes:
```bash
# Check for direct StripeClient usage (should only show StripeClientFactory)
grep -r "new StripeClient" src/

# Expected output:
# src/Stripe/Adapter/StripeClientFactory.php:44:        return !empty($this->secretKey) ? new StripeClient([

# Run tests
./bin/pre-commit-check.sh --full
```
