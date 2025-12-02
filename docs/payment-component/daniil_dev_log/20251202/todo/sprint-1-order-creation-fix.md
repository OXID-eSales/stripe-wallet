# Sprint 1: Order Creation - Session Restoration via URL Hash

**Priority:** CRITICAL
**Status:** IN PROGRESS
**Estimated Effort:** 4-6 hours

---

## Architecture Principles

### 1. Liskov Substitution Principle (LSP)

All classes MUST follow LSP:
- Subtypes must be substitutable for their base types
- Interfaces define contracts that ALL implementations must honor
- No implementation should require type checking (`instanceof`) to work correctly

```php
// GOOD: Any implementation works identically
interface SecurityValidatorInterface {
    public function validate(PaymentContractInterface $contract, array $context): SecurityValidationResultInterface;
}

// BAD: Requires knowing the concrete type
if ($validator instanceof StripeSecurityValidator) {
    $validator->validateStripeSpecific($contract);
}
```

### 2. Component vs Stripe Separation

**`src/Component/`** = Framework layer (provider-agnostic)
- Contains interfaces, base classes, and reusable logic
- Can be used by ANY payment provider (PayPal, Klarna, etc.)
- Examples: `PaymentContractInterface`, `SecurityValidatorInterface`, `TokenServiceInterface`

**`src/Stripe/`** = Provider-specific layer
- Implements Component interfaces for Stripe
- Contains Stripe API calls, Stripe-specific logic
- MUST NOT contain logic reusable by other providers

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        ARCHITECTURE LAYERS                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  src/Component/ (FRAMEWORK - Provider Agnostic)                             │
│  ├── Contract/                                                              │
│  │   ├── PaymentContractInterface.php                                       │
│  │   └── SecurityValidationResultInterface.php   ◄── NEW                    │
│  ├── Service/                                                               │
│  │   ├── TokenServiceInterface.php               ◄── NEW                    │
│  │   └── ReturnSecurityValidatorInterface.php    ◄── NEW                    │
│  └── Repository/                                                            │
│                                                                             │
│  src/Stripe/ (PROVIDER SPECIFIC)                                            │
│  ├── Service/                                                               │
│  │   ├── ContractTokenService.php        implements TokenServiceInterface   │
│  │   ├── ReturnSessionSecurityService.php implements ReturnSecurityValidator│
│  │   └── Result/                                                            │
│  │       └── SecurityValidationResult.php implements SecurityValidationResult│
│  └── EventSystem/Handler/                                                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3. Implementation Checklist

Before creating any class, ask:
- [ ] Is this logic specific to Stripe, or could PayPal/Klarna use it too?
- [ ] If reusable → Create interface in Component, implementation in Stripe
- [ ] Does the class depend only on interfaces, not concrete classes?
- [ ] Can any implementation of the interface be substituted without breaking?

---

## Problem Statement

Order creation fails with `order_state: 7` (invalid_delivery_address) when customer returns from Stripe checkout.

**Key Insight from Daniil:**
> `$_REQUEST['sDeliveryAddressMD5']` will ALWAYS be empty on return. You need to add hashes to the return URL. The user may return in incognito mode or new browser window - we cannot rely on session.

---

## Solution Architecture

### The Challenge

When user returns from Stripe:
1. Session may be different (new browser window, incognito)
2. `$_REQUEST` parameters are empty (GET redirect has no POST data)
3. We need to **identify the user AND restore all session data**

### Security Considerations

The return URL hash must enable:
1. **User identification** - match returning user to original session
2. **Session restoration** - restore basket, delivery address, payment data
3. **Fraud prevention** - scoring to verify same user (IP, country, timing)

### Proposed Approach

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  BEFORE REDIRECT TO STRIPE                                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Create PaymentContract with ALL session data:                           │
│     - delivery_address_hash (MD5)                                           │
│     - delivery_address_id                                                   │
│     - user_id                                                               │
│     - session_id                                                            │
│     - user_ip                                                               │
│     - user_agent                                                            │
│     - country_code                                                          │
│     - basket_hash (to verify basket hasn't changed)                         │
│                                                                             │
│  2. Generate secure return token:                                           │
│     - HMAC-SHA256(contract_id + secret)                                     │
│     - Or use contract_id directly (already unique)                          │
│                                                                             │
│  3. Build return URL with token:                                            │
│     success_url = shop.com/order?fnc=checkoutSuccess                        │
│                   &session_id={CHECKOUT_SESSION_ID}                         │
│                   &contract_token=xxx                                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  ON RETURN FROM STRIPE                                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Extract contract_token from GET parameters                              │
│                                                                             │
│  2. Load contract from database                                             │
│                                                                             │
│  3. Security scoring:                                                       │
│     - Check IP matches (or same country)                                    │
│     - Check user agent similarity                                           │
│     - Check timing (return within reasonable time)                          │
│     - Log any mismatches for review                                         │
│                                                                             │
│  4. Restore session data from contract:                                     │
│     - Set $_REQUEST['sDeliveryAddressMD5'] = stored_hash                    │
│     - Set session delivery address ID                                       │
│     - Restore user login if needed                                          │
│                                                                             │
│  5. Proceed with order creation                                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Current Code Analysis

### Where Return URL is Built

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php:72-73`

```php
$successUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl = $shopUrl . 'index.php?cl=payment';
```

**Issue:** No contract token in URL. Uses Stripe's `{CHECKOUT_SESSION_ID}` placeholder only.

### Where Session Data is Stored

**File:** `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php`

Already stores some metadata:
```php
$contract->setMetadata('delivery_address_hash', $addressHash);
$contract->setMetadata('delivery_address_id', $session->getVariable('deladrid'));
```

### Where Return is Handled

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

Currently tries to restore from session (doesn't work):
```php
$session->setVariable('sDeliveryAddressMD5', $hash);
```

---

## Implementation Tasks

### Task 1: Enhance Contract Metadata Storage

**File:** `StripeContractCreationHandler.php`

Store additional data needed for session restoration:

```php
// Store user identification data
$contract->setMetadata('user_id', $user->getId());
$contract->setMetadata('session_id', $session->getId());
$contract->setMetadata('user_ip', $_SERVER['REMOTE_ADDR'] ?? '');
$contract->setMetadata('user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
$contract->setMetadata('country_code', $this->detectCountry());
$contract->setMetadata('created_timestamp', time());

// Store delivery address data
$contract->setMetadata('delivery_address_hash', $addressHash);
$contract->setMetadata('delivery_address_id', $session->getVariable('deladrid'));

// Store basket verification hash
$contract->setMetadata('basket_hash', $this->computeBasketHash($basket));
```

### Task 2: Add Contract Token to Return URL

**File:** `StripeCheckoutSessionHandler.php`

```php
// Generate contract token for URL
$contractToken = $this->generateContractToken($contract->getId());

// Build URLs with contract token
$successUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
    . '&session_id={CHECKOUT_SESSION_ID}'
    . '&contract_token=' . urlencode($contractToken);
```

### Task 3: Implement Security Scoring

**New File:** `src/Stripe/Service/ReturnSessionSecurityService.php`

```php
class ReturnSessionSecurityService
{
    public function validateReturn(PaymentContractInterface $contract): SecurityResult
    {
        $score = 100;
        $warnings = [];

        // Check IP
        $storedIp = $contract->getMetadata('user_ip');
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($storedIp !== $currentIp) {
            $score -= 20;
            $warnings[] = 'IP changed';

            // But same country is OK
            if ($this->sameCountry($storedIp, $currentIp)) {
                $score += 10;
            }
        }

        // Check timing
        $createdAt = $contract->getMetadata('created_timestamp');
        $elapsed = time() - $createdAt;
        if ($elapsed > 3600) { // More than 1 hour
            $score -= 30;
            $warnings[] = 'Long delay: ' . $elapsed . 's';
        }

        // Check user agent similarity
        // ... etc

        return new SecurityResult($score, $warnings, $score >= 50);
    }
}
```

### Task 4: Update Return Handler

**File:** `StripeCheckoutReturnHandler.php`

```php
public function handle(object $event): void
{
    // 1. Get contract token from GET
    $contractToken = $_GET['contract_token'] ?? '';

    // 2. Validate token and load contract
    $contract = $this->loadContractFromToken($contractToken);

    // 3. Security scoring
    $securityResult = $this->securityService->validateReturn($contract);
    if (!$securityResult->isAllowed()) {
        $this->logger->warning('Suspicious return', [
            'contract_id' => $contract->getId(),
            'score' => $securityResult->getScore(),
            'warnings' => $securityResult->getWarnings(),
        ]);
        // Optionally: require re-authentication
    }

    // 4. Restore session data - INCLUDING $_REQUEST injection
    $this->restoreSessionData($contract);
}

private function restoreSessionData(PaymentContractInterface $contract): void
{
    // Restore to SESSION
    $session = Registry::getSession();
    $session->setVariable('sDeliveryAddressMD5', $contract->getMetadata('delivery_address_hash'));
    $session->setVariable('deladrid', $contract->getMetadata('delivery_address_id'));

    // CRITICAL: Also inject into $_REQUEST for OXID's validation
    $_REQUEST['sDeliveryAddressMD5'] = $contract->getMetadata('delivery_address_hash');
}
```

---

## Frontend Considerations

**Question for Daniil:** Is the return URL created in frontend JavaScript or backend PHP?

Current flow appears to be:
1. Frontend calls controller to create checkout session
2. Backend (`StripeCheckoutSessionHandler`) builds URLs
3. Stripe redirects to our URL

If frontend builds URLs, we need to:
- Generate contract token in backend
- Pass token to frontend
- Frontend includes token in URLs

---

## Test Plan

### Unit Tests

```php
// Test metadata storage
public function testContractStoresAllRequiredMetadata(): void
{
    // Arrange
    $handler = new StripeContractCreationHandler(...);

    // Act
    $contract = $handler->createContract($context);

    // Assert
    $this->assertNotEmpty($contract->getMetadata('delivery_address_hash'));
    $this->assertNotEmpty($contract->getMetadata('user_ip'));
    $this->assertNotEmpty($contract->getMetadata('session_id'));
}

// Test security scoring
public function testSecurityScoringDetectsIpChange(): void
{
    // Arrange
    $contract = $this->createContractWithIp('1.2.3.4');
    $_SERVER['REMOTE_ADDR'] = '5.6.7.8';

    // Act
    $result = $this->securityService->validateReturn($contract);

    // Assert
    $this->assertLessThan(100, $result->getScore());
    $this->assertContains('IP changed', $result->getWarnings());
}

// Test session restoration
public function testSessionDataRestoredFromContract(): void
{
    // Arrange
    $contract = new PaymentContract(...);
    $contract->setMetadata('delivery_address_hash', 'abc123');

    // Act
    $handler->restoreSessionData($contract);

    // Assert
    $this->assertEquals('abc123', $_REQUEST['sDeliveryAddressMD5']);
}
```

### Integration Test

```php
public function testFullCheckoutFlowWithSessionRestoration(): void
{
    // Simulate complete flow: checkout -> stripe -> return -> order
}
```

---

## Acceptance Criteria

1. [ ] Contract stores all data needed for session restoration
2. [ ] Return URL contains contract token
3. [ ] Security scoring logs suspicious returns
4. [ ] `$_REQUEST['sDeliveryAddressMD5']` is set on return
5. [ ] Order creation succeeds (no state 7 error)
6. [ ] All existing tests pass
7. [ ] New tests cover security scenarios

---

## Open Questions

1. **Frontend vs Backend URL building** - Need to verify where URLs are constructed
2. **Token format** - Use contract_id directly or HMAC signature?
3. **Failed scoring threshold** - What score requires re-authentication?
4. **Logging level** - How much to log for fraud analysis?

---

**Created:** 2025-12-02
**Last Updated:** 2025-12-02
