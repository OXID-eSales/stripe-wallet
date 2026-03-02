# Sprint 67–70 — Security Remediation (STRP-99)

**Date:** 2026-02-24
**Branch:** `b-7.4.x-security-STRP-99`
**Type:** TDD-first implementation (RED → GREEN → REFACTOR)
**Related findings:** H3, M6, H5, M9, H7, H6, M2, M3
**Approach:** SOLID, DI, Clean Code — one sub-sprint per finding

---

## Table of Contents

1. [Pre-Sprint: Already-Secure Findings](#pre-sprint-already-secure-findings)
2. [Sprint 67a — H3: Contract Token Validation](#sprint-67a)
3. [Sprint 67b — M6: Webhook HTTPS Guard](#sprint-67b)
4. [Sprint 68a — H5: State Machine Guard on fromArray()](#sprint-68a)
5. [Sprint 68b — M9: Address Hash HMAC Binding](#sprint-68b)
6. [Sprint 69a — H7: Webhook Payload PII Redaction](#sprint-69a)
7. [Sprint 69b — H6: Basket Snapshot PII Whitelist](#sprint-69b)
8. [Sprint 70a — M2: Dev Mode Domain Matching Fix](#sprint-70a)
9. [Sprint 70b — M3: Restrictive File Permissions](#sprint-70b)
10. [Execution Schedule & Dependency Graph](#execution-schedule)

---

## Pre-Sprint: Already-Secure Findings

Before coding, 4 findings can be marked DONE — the codebase already handles them securely. Update `status.md` only; no code changes needed.

| Finding | Why Already Secure | Evidence |
|---------|-------------------|----------|
| **H9** (Webhook Signature) | `StripeWebhookProcessor::parseAndValidateRequest()` calls `Webhook::constructEvent()` with mandatory secret. `SignatureVerificationException` → fail-closed. | `src/Stripe/Webhook/StripeWebhookProcessor.php:64-85` |
| **H10** (Client Secret in Session) | Client secret passed through `EventContext` (in-memory) to template param. NOT stored in `$_SESSION`. | `StripePaymentStatusHandler.php:152`, `StripeOrderController.php:259` |
| **M1** (XSS in Admin) | Twig auto-escaping ON by default. No `|raw` filter on error messages. `{{ oView.getErrorMessage() }}` is auto-escaped. | `views/twig/admin/stripe_order_refund.html.twig:102` |
| **M4** (DateTime Parsing) | All production code uses `new DateTimeImmutable('@' . $timestamp)` (safe, integer unix timestamp) or `new DateTimeImmutable()` (current time). No unvalidated string→DateTime in production paths. | Grep confirms: only test files have string-based DateTime. |

**Updated totals after pre-sprint:** 18/28 DONE, 10 PLANNED → after Sprints 67–70: 26/28 DONE.

---

<a name="sprint-67a"></a>
## Sprint 67a — H3: Contract Token Validation in Controller (TDD)

### Finding

**H3 — Contract Tokens Not Validated (CVSS 5.5, HIGH)**

`ContractTokenService` exists and is fully tested (20 unit tests pass). It generates HMAC-signed tokens for contract IDs in return URLs. However, `StripeOrderController::checkoutSuccess()` (line 175) **never calls `validateToken()`**. The URL parameters `contract_id` and `contract_token` pass through completely unvalidated into the `EventContext` and on to event handlers.

### Why This Matters

An attacker who intercepts or guesses a `contract_id` can craft a return URL with any `contract_id` value. Without token validation, the controller will happily dispatch a `StripeCheckoutReturnEvent` with the forged contract ID. The event handlers then operate on the wrong contract — potentially completing a different user's payment or linking an order to the wrong session.

The `ContractTokenService` was built specifically to prevent this (it signs contract IDs with HMAC-SHA256 derived from the Stripe API secret), but the last mile — actually **checking** the token in the controller — was never wired in.

### Current Code (Vulnerable)

**File:** `src/Stripe/Controller/StripeOrderController.php:164-211`

```php
public function checkoutSuccess(): string
{
    // 1. Validate checkout session ID
    $sessionId = $this->getCheckoutSessionIdFromRequest();
    if ($sessionId === null) {
        Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
        return 'payment';
    }

    // 2. Get contract_id and contract_token from URL (passed in return URL)
    $contractId = Registry::getRequest()->getRequestParameter('contract_id');
    $contractToken = Registry::getRequest()->getRequestParameter('contract_token');

    // 3. Validate contract_id from URL matches session     ← ONLY check
    $sessionContractId = $this->getContractIdFromSession();
    if (
        is_string($contractId)
        && is_string($sessionContractId)
        && $contractId !== $sessionContractId
    ) {
        Registry::getUtilsView()->addErrorToDisplay('Payment verification failed');
        return 'payment';
    }

    // 4. Create context with URL parameters                ← TOKEN NEVER VALIDATED
    $context = new EventContext([
        'checkoutSessionId' => $sessionId,
        'contract_id' => $contractId,
        'contract_token' => $contractToken,      // ← passed through blindly
        'contractId' => $sessionContractId,
    ]);

    // 4. Dispatch event - HANDLERS DO THE WORK
    $event = new StripeCheckoutReturnEvent($context);
    $this->getEventDispatcher()->dispatch($event);
    // ...
}
```

The session-based contract ID check (step 3) provides partial protection but is bypassable — if the user's session has expired, `$sessionContractId` is null, and the check is skipped entirely.

### Proposed Fix

Add `ContractTokenService::validateToken()` call **before** any business logic or event dispatching. Extract `Registry::getRequest()` calls into overridable protected methods for testability (same pattern as existing `getCheckoutSessionIdFromRequest()`). Extract `Registry::getUtilsView()->addErrorToDisplay()` into an overridable `addErrorToDisplay()` method.

**File to modify:** `src/Stripe/Controller/StripeOrderController.php`

#### Changes

1. **Add import:** `use OxidEsales\Payments\Stripe\Service\ContractTokenService;`

2. **Add 3 new protected methods** (data accessors + token validation):

```php
protected function getContractIdFromRequest(): ?string
{
    $value = Registry::getRequest()->getRequestParameter('contract_id');
    return is_string($value) ? $value : null;
}

protected function getContractTokenFromRequest(): ?string
{
    $value = Registry::getRequest()->getRequestParameter('contract_token');
    return is_string($value) ? $value : null;
}

protected function validateContractToken(?string $contractId, ?string $contractToken): bool
{
    if ($contractId === null || $contractToken === null) {
        return false;
    }
    return $this->getContractTokenService()->validateToken($contractToken, $contractId);
}

protected function getContractTokenService(): ContractTokenService
{
    return $this->getServiceFromContainer(ContractTokenService::class);
}
```

3. **Add `addErrorToDisplay()` wrapper** for testability:

```php
protected function addErrorToDisplay(string $message): void
{
    Registry::getUtilsView()->addErrorToDisplay($message);
}
```

4. **Rewrite `checkoutSuccess()`** with token validation FIRST:

```php
public function checkoutSuccess(): string
{
    // 1. Validate checkout session ID
    $sessionId = $this->getCheckoutSessionIdFromRequest();
    if ($sessionId === null) {
        $this->addErrorToDisplay('Payment information missing');
        return 'payment';
    }

    // 2. Get contract_id and contract_token from URL
    $contractId = $this->getContractIdFromRequest();
    $contractToken = $this->getContractTokenFromRequest();

    // 3. Sprint 67a (H3): Validate contract token BEFORE any business logic
    if (!is_string($contractId) || !is_string($contractToken)) {
        $this->addErrorToDisplay('Payment verification failed');
        return 'payment';
    }

    if (!$this->validateContractToken($contractId, $contractToken)) {
        $this->addErrorToDisplay('Payment verification failed');
        return 'payment';
    }

    // 4. Validate contract_id from URL matches session
    $sessionContractId = $this->getContractIdFromSession();
    if (is_string($sessionContractId) && $contractId !== $sessionContractId) {
        $this->addErrorToDisplay('Payment verification failed');
        return 'payment';
    }

    // 5. Create context with validated URL parameters
    $context = new EventContext([
        'checkoutSessionId' => $sessionId,
        'contract_id' => $contractId,
        'contract_token' => $contractToken,
        'contractId' => $sessionContractId,
    ]);

    // 6. Dispatch event
    $event = new StripeCheckoutReturnEvent($context);
    $this->getEventDispatcher()->dispatch($event);

    // 7. Process results
    $this->processContextResults($context);

    if ($orderId = $context->get('orderId')) {
        $this->setSessionVariable('sess_challenge', $orderId);
        $this->clearStripeSessionVariables();
    }

    return $context->get('redirectTarget') ?? 'payment';
}
```

### Tests

**File:** `tests/Unit/Stripe/Controller/StripeOrderControllerTokenTest.php`

Uses **testable subclass pattern** (same as existing `StripeOrderControllerCsrfTest.php`). The subclass overrides all framework-dependent methods: `getCheckoutSessionIdFromRequest()`, `getContractIdFromRequest()`, `getContractTokenFromRequest()`, `getContractIdFromSession()`, `validateContractToken()`, `addErrorToDisplay()`, `getEventDispatcher()`, `processContextResults()`, session methods, etc.

| # | Test Name | Scenario | Expected |
|---|-----------|----------|----------|
| 1 | `checkoutSuccessRejectsInvalidContractToken` | Valid session ID, valid contract ID, invalid token, `validateContractToken` returns false | Returns `'payment'`, error message contains "Payment verification failed", event NOT dispatched |
| 2 | `checkoutSuccessAcceptsValidContractToken` | Valid session ID, valid contract ID, valid token, `validateContractToken` returns true | Event IS dispatched (event dispatcher called) |
| 3 | `checkoutSuccessRejectsMissingContractToken` | Valid session ID, valid contract ID, token is null | Returns `'payment'`, error "Payment verification failed" |
| 4 | `checkoutSuccessRejectsMissingContractId` | Valid session ID, contract ID is null, token present | Returns `'payment'`, error "Payment verification failed" |
| 5 | `checkoutSuccessValidatesTokenBeforeSessionCheck` | Valid session ID, valid contract ID, invalid token, session contract ID is DIFFERENT | Returns `'payment'` with token error (not session mismatch error), proving token check runs FIRST |

**Expected:** 5 tests, ~10 assertions

### Testable Subclass Design

```php
class TestableStripeOrderControllerForToken extends StripeOrderController
{
    private ?string $checkoutSessionId = null;
    private ?string $contractIdFromRequest = null;
    private ?string $contractTokenFromRequest = null;
    private ?string $contractIdFromSession = null;
    private bool $tokenValidationResult = false;
    private bool $eventDispatched = false;
    private ?string $lastError = null;

    public function __construct() { /* skip OXID bootstrap */ }

    // Setters for test control
    public function setCheckoutSessionId(?string $id): void { ... }
    public function setContractIdFromRequest(?string $id): void { ... }
    public function setContractTokenFromRequest(?string $token): void { ... }
    public function setContractIdFromSession(?string $id): void { ... }
    public function setTokenValidationResult(bool $result): void { ... }

    // Test inspection
    public function wasEventDispatched(): bool { return $this->eventDispatched; }
    public function getLastError(): ?string { return $this->lastError; }

    // Overrides
    protected function getCheckoutSessionIdFromRequest(): ?string { return $this->checkoutSessionId; }
    protected function getContractIdFromRequest(): ?string { return $this->contractIdFromRequest; }
    protected function getContractTokenFromRequest(): ?string { return $this->contractTokenFromRequest; }
    protected function getContractIdFromSession(): ?string { return $this->contractIdFromSession; }
    protected function validateContractToken(?string $c, ?string $t): bool { ... }
    protected function addErrorToDisplay(string $msg): void { $this->lastError = $msg; }
    protected function getEventDispatcher(): EventDispatcherInterface {
        $this->eventDispatched = true;
        return /* anonymous impl that returns event */;
    }
    protected function processContextResults(EventContext $ctx): void { /* noop */ }
    // ... session, tpl, log methods as noop
}
```

### SOLID Compliance

| Principle | Application |
|-----------|------------|
| **S** | `validateContractToken()` delegates to `ContractTokenService` — controller doesn't know HMAC details |
| **O** | Existing CSRF tests unaffected — new subclass, new test file |
| **L** | `ContractTokenService` implements `TokenServiceInterface` — substitutable |
| **I** | New methods are small, focused (one request param each) |
| **D** | Controller depends on `ContractTokenService` via DI container, not direct instantiation |

---

<a name="sprint-67b"></a>
## Sprint 67b — M6: Webhook HTTPS Enforcement Guard (TDD)

### Finding

**M6 — No HTTPS Enforcement on Webhook Endpoint (CVSS 3.5, MEDIUM)**

`ModuleConfigurationService::getWebhookUrl()` uses whatever shop URL is configured — it could be HTTP. The webhook controller has no check for transport layer security. If the shop is misconfigured or a reverse proxy terminates TLS incorrectly, webhook payloads (containing payment data) travel in plaintext.

### Why This Matters

Stripe's webhook payloads contain payment intent IDs, amounts, customer references, and event metadata. Over HTTP, a network attacker (MITM) can:
1. **Read** payment data in transit
2. **Replay** captured payloads to trigger duplicate order fulfillment
3. **Modify** payload data (amounts, status) before it reaches the application

Stripe's signature verification protects against (3) but not (1) or (2). HTTPS is the defense for all three.

### Design Decision: Guard Chain (OCP)

Instead of modifying the webhook controller directly, we add a new guard to the existing `WebhookGuardChain` from Sprint 64. This follows the **Open/Closed Principle** — the controller and existing guards are unchanged; we only add a new implementation.

The HTTPS guard is the **cheapest possible check** (reads 1-2 server variables), so it goes at position 0 in the chain — before payload size, rate limiting, and IP allowlist.

```
┌─────────────────────────────────────────┐
│  WebhookGuardChain                      │
│                                         │
│  0. WebhookHttpsGuard      ← NEW (M6)  │  O(1) — check $_SERVER['HTTPS']
│  1. WebhookPayloadSizeGuard            │  O(1) — strlen
│  2. WebhookRateLimitGuard              │  O(1) — APCu
│  3. WebhookIpAllowlistGuard           │  O(n) — CIDR loop
└─────────────────────────────────────────┘
```

### Implementation

**New file:** `src/Stripe/Controller/Webhook/WebhookHttpsGuard.php`

```php
final class WebhookHttpsGuard implements WebhookRequestGuardInterface
{
    public function __construct(
        private readonly bool $allowInsecureLoopback = false,
    ) {}

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        if ($this->isSecureConnection()) {
            return null;  // pass — HTTPS confirmed
        }

        if ($this->allowInsecureLoopback && $this->isLoopback($remoteIp)) {
            return null;  // pass — dev convenience
        }

        return new WebhookGuardResult('insecure_connection', 400, 'HTTPS required for webhook endpoints');
    }

    private function isSecureConnection(): bool
    {
        // Direct HTTPS
        if (($this->getServerVar('HTTPS') ?? '') === 'on') {
            return true;
        }
        // Behind reverse proxy (nginx, load balancer, CDN)
        if ($this->getServerVar('HTTP_X_FORWARDED_PROTO') === 'https') {
            return true;
        }
        return false;
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    protected function getServerVar(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
```

**Why `getServerVar()` is `protected`:** Allows testable subclass to override `$_SERVER` access without modifying global state. Same pattern as other guards.

**Why HTTP 400 (not 403):** 403 implies the client is identified but unauthorized. 400 communicates that the request itself is malformed — it arrived over the wrong transport. Stripe will retry on non-2xx, but a persistent 400 signals a configuration issue that the shop operator needs to fix.

### Services.yaml Change

**File:** `services.yaml` (guard chain section, ~line 988)

```yaml
  # Sprint 67b (M6): HTTPS enforcement guard
  OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard:
    arguments:
      $allowInsecureLoopback: true   # Allow HTTP on 127.0.0.1 for dev

  # Guard chain: ordered cheapest → most expensive
  OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRequestGuardInterface:
    class: OxidEsales\Payments\Stripe\Controller\Webhook\WebhookGuardChain
    public: true
    arguments:
      - - '@OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard'         # ← NEW, position 0
        - '@OxidEsales\Payments\Stripe\Controller\Webhook\WebhookPayloadSizeGuard'
        - '@OxidEsales\Payments\Stripe\Controller\Webhook\WebhookRateLimitGuard'
        - '@OxidEsales\Payments\Stripe\Controller\Webhook\WebhookIpAllowlistGuard'
```

### Tests

**File:** `tests/Unit/Stripe/Controller/Webhook/WebhookHttpsGuardTest.php`

Uses **testable subclass** that overrides `getServerVar()` to control `$_SERVER` values without polluting global state.

| # | Test Name | Server Vars | RemoteIp | allowInsecureLoopback | Expected |
|---|-----------|-------------|----------|----------------------|----------|
| 1 | `guardAllowsHttpsRequest` | `HTTPS=on` | `54.187.174.169` | false | `null` (pass) |
| 2 | `guardRejectsHttpRequest` | `{}` (empty) | `54.187.174.169` | false | 400, reason=`insecure_connection` |
| 3 | `guardAcceptsXForwardedProtoHttps` | `HTTP_X_FORWARDED_PROTO=https` | `54.187.174.169` | false | `null` (pass) |
| 4 | `guardRejectsXForwardedProtoHttp` | `HTTP_X_FORWARDED_PROTO=http` | `54.187.174.169` | false | 400 |
| 5 | `guardAllowsLocalhostWhenInsecureLoopbackEnabled` | `{}` | `127.0.0.1` | true | `null` (pass) |
| 6 | `guardRejectsLocalhostWhenInsecureLoopbackDisabled` | `{}` | `127.0.0.1` | false | 400 |

**Expected:** 6 tests, ~10 assertions

---

<a name="sprint-68a"></a>
## Sprint 68a — H5: State Machine Guard on fromArray() (TDD)

### Finding

**H5 — State Machine Bypass via fromArray() (CVSS 5.0, HIGH)**

`PaymentContract::fromArray()` (line 503-529) assigns the state directly via `$contract->state = self::extractState($data)`. This bypasses all transition guards (e.g., you can't go from `draft` to `fulfilled` via the public API — `fulfill()` requires `committed` state). The concern is: what if `fromArray()` receives invalid or inconsistent data?

### Actual Risk Assessment

After reading the code carefully, the risk is **narrower** than the audit suggests:

1. **`fromArray()` is for DB hydration** — it's called from repositories to reconstruct objects from database rows. The DB is the source of truth, so it MUST accept any valid state.
2. **`ContractState::fromValue()` already validates** against `VALID_STATES` array — garbage strings like `'hacked'` are rejected with `InvalidArgumentException`.
3. **The real gap:** `fromValue()` calls the constructor, which checks `in_array($value, VALID_STATES, true)`. But an empty string `''` is not in `VALID_STATES`, so it's already rejected. However, the error message doesn't distinguish empty from invalid.

**What we CAN improve:**
- Add explicit empty-string rejection in `fromValue()` with a clear error message
- Add **state/condition consistency validation** — detect impossible combinations (e.g., state=`fulfilled` but conditions not all fulfilled) and log a warning. This is defensive, not blocking — the DB is source of truth, but inconsistency indicates a bug or data corruption.
- Add comprehensive tests that document the security boundary

### Current Code

**File:** `payment-component/src/Contract/ContractState.php:92-95`

```php
public static function fromValue(string $value): self
{
    return new self($value);   // delegates to constructor which checks VALID_STATES
}
```

**File:** `payment-component/src/Contract/ContractState.php:33-39`

```php
private function __construct(string $value)
{
    if (!in_array($value, self::VALID_STATES, true)) {
        throw new InvalidArgumentException("Invalid contract state: {$value}");
    }
    $this->value = $value;
}
```

**File:** `payment-component/src/Contract/PaymentContract.php:575-581`

```php
private static function extractState(array $data): ContractState
{
    if (!isset($data['state']) || !is_string($data['state'])) {
        throw new InvalidArgumentException('state must be a string');
    }
    return ContractState::fromValue($data['state']);
}
```

### Proposed Changes

#### 1. `ContractState::fromValue()` — explicit empty-string guard

```php
public static function fromValue(string $value): self
{
    if ($value === '') {
        throw new InvalidArgumentException("Invalid contract state: empty string");
    }
    return new self($value);
}
```

**Why add this when the constructor already rejects?** Defense in depth + clarity. The constructor error says "Invalid contract state: " (with nothing after the colon for empty string). The explicit check gives a clear message and documents the intent.

#### 2. `PaymentContract::fromArray()` — state/condition consistency warning

Add a private static method that checks for impossible combinations and logs a warning (does NOT throw — the DB is authoritative):

```php
private static function validateStateConsistency(
    ContractState $state,
    array $conditions
): void {
    // Fulfilled state requires all conditions fulfilled
    if ($state->isFulfilled() && !empty($conditions)) {
        $unfulfilled = array_filter(
            $conditions,
            fn(ContractCondition $c): bool => !$c->isFulfilled()
        );
        if (!empty($unfulfilled)) {
            trigger_error(
                sprintf(
                    'PaymentContract state/condition inconsistency: state=%s but %d conditions unfulfilled',
                    $state->getValue(),
                    count($unfulfilled)
                ),
                E_USER_WARNING
            );
        }
    }
}
```

Call after `extractConditions()` in `fromArray()`:

```php
$contract->conditions = self::extractConditions($data);
self::validateStateConsistency($contract->state, $contract->conditions);
```

### Tests

**File:** `payment-component/tests/Unit/Contract/PaymentContractFromArrayGuardTest.php`

| # | Test Name | Input | Expected |
|---|-----------|-------|----------|
| 1 | `fromArrayRejectsInvalidState` | `state='hacked'` | `InvalidArgumentException` |
| 2 | `fromArrayAcceptsAllValidStates` | iterate all 10 valid states | No exception for any |
| 3 | `fromArrayRejectsEmptyState` | `state=''` | `InvalidArgumentException` with "empty string" |
| 4 | `fromArrayRejectsNonStringState` | `state` key missing | `InvalidArgumentException` with "must be a string" |
| 5 | `fromArrayPreservesStateWithConditions` | state=`fulfilled`, all conditions fulfilled | Contract state is `fulfilled`, no warnings |
| 6 | `fromArrayDetectsInconsistentStateConditions` | state=`fulfilled`, 1 condition unfulfilled | `E_USER_WARNING` triggered (captured by PHPUnit expectWarning) |

**Expected:** 6 tests, ~12 assertions

### Why NOT Block Inconsistent fromArray()?

The database is the authoritative source. If a contract is `fulfilled` in the DB but conditions aren't all fulfilled, this means:
- A bug set the state directly (we've now prevented this via transition guards)
- A migration or manual DB fix left inconsistency

**Blocking** (throwing) would prevent the application from loading existing contracts — a data-driven outage. **Warning** (logging) alerts developers without breaking the app.

---

<a name="sprint-68b"></a>
## Sprint 68b — M9: Address Hash HMAC Binding (TDD)

### Finding

**M9 — Address Hash Not HMAC-Protected (MEDIUM)**

`ContractMetadataService::computeAddressHashFromBasket()` (line 136-153) uses OXID's `getEncodedDeliveryAddress()` which produces an MD5 hash without a server-side secret. An attacker with DB write access (SQL injection, compromised admin) can forge a matching MD5 hash for a modified delivery address.

### Why This Matters

The delivery address hash is used to detect if the customer changed their address between contract creation and order fulfillment. If an attacker can forge the hash:
- They can change the delivery address after payment authorization
- The address validation in `DeliveryAddressHashService::restoreHashForValidation()` would pass because the forged hash matches the new address
- This is a checkout manipulation attack

### Current Flow

```
Contract Creation:
  basket user → getEncodedDeliveryAddress() → MD5 hash → contract metadata

Order Fulfillment:
  contract metadata → MD5 hash → inject into $_REQUEST → OXID validateDeliveryAddress()
```

### Proposed Flow (HMAC-Protected)

```
Contract Creation:
  basket user → getEncodedDeliveryAddress() → MD5 hash
  MD5 hash → HMAC-SHA256(MD5, server_secret) → contract metadata (hash + hmac)

Order Fulfillment:
  contract metadata → MD5 hash + stored HMAC
  recalculate HMAC-SHA256(MD5, server_secret) → compare with stored HMAC
  if match → inject MD5 into $_REQUEST → OXID validateDeliveryAddress()
  if no match → reject (address tampered)
```

### Implementation

#### New Service: `AddressHmacService`

**File:** `payment-component/src/Service/AddressHmacService.php`

```php
final class AddressHmacService
{
    private const ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $secret
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Address HMAC secret must not be empty');
        }
    }

    public function sign(string $addressHash): string
    {
        if ($addressHash === '') {
            return '';
        }
        return hash_hmac(self::ALGORITHM, $addressHash, $this->secret);
    }

    public function verify(string $addressHash, string $hmac): bool
    {
        if ($addressHash === '' || $hmac === '') {
            return false;
        }
        return hash_equals($this->sign($addressHash), $hmac);
    }
}
```

**Why a separate service (SRP)?** The HMAC signing concern is distinct from metadata storage and from `$_REQUEST` injection. Each service has one reason to change.

**Where does the secret come from?** Injected via DI. In `services.yaml`, derived from the Stripe API secret (same approach as `ContractTokenService`). This avoids requiring a new environment variable.

#### Modify: `ContractMetadataService`

Inject `AddressHmacService` via constructor. In `storeDeliveryAddressMetadata()`, after storing the hash, also store the HMAC:

```php
if (!empty($addressHash)) {
    $contract->setMetadata('delivery_address_hash', $addressHash);
    $contract->setMetadata('delivery_address_hmac', $this->addressHmacService->sign($addressHash));
}
```

Add a new method for HMAC-verified hash retrieval:

```php
public function getVerifiedDeliveryAddressHash(PaymentContractInterface $contract): ?string
{
    $hash = $this->getDeliveryAddressHash($contract);
    $hmac = $contract->getMetadata('delivery_address_hmac');

    if ($hash === null || !is_string($hmac)) {
        return $hash;  // backwards compat: contracts created before HMAC
    }

    if (!$this->addressHmacService->verify($hash, $hmac)) {
        return null;  // tampered — reject
    }

    return $hash;
}
```

#### Modify: `DeliveryAddressHashService`

No changes needed. The service operates on whatever hash it receives. The HMAC verification happens in `ContractMetadataService` before the hash reaches `DeliveryAddressHashService`.

### Tests

**File:** `payment-component/tests/Unit/Service/AddressHmacServiceTest.php`

| # | Test Name | Scenario | Expected |
|---|-----------|----------|----------|
| 1 | `hmacDiffersFromPlainMd5` | sign("abc123") | Result !== "abc123" |
| 2 | `hmacRequiresSecret` | sign with secret "A" vs sign with secret "B" | Different HMACs |
| 3 | `hmacVerifiesSuccessfully` | sign("hash") then verify("hash", hmac) | `true` |
| 4 | `hmacRejectsTamperedHash` | sign("hash") then verify("tampered", hmac) | `false` |
| 5 | `hmacRejectsEmptyHash` | verify("", hmac) | `false` |

**Expected:** 5 tests, ~10 assertions

---

<a name="sprint-69a"></a>
## Sprint 69a — H7: Webhook Payload PII Redaction (TDD)

### Finding

**H7 — PII in Webhook Logs (CVSS 4.5, HIGH)**

`AbstractWebhookProcessor::logWebhookReceived()` (line 108-119) calls `$log->setPayload($event->data)` — this stores the **full** Stripe webhook payload into the `oe_payments_webhooklogs` table. Stripe payloads contain PII:

- `customer_details.email` — customer email
- `customer_details.name` — customer name
- `shipping.address` — full shipping address
- `billing_details` — billing name + address
- `payment_method_details.card.last4` — card last 4 digits
- `receipt_email` — receipt email address

This violates **GDPR Article 5(1)(c)** — data minimization. We store more personal data than necessary for the purpose (webhook audit logging).

### Current Code

```php
protected function logWebhookReceived(WebhookEvent $event, WebhookRequest $request): void
{
    $log = new WebhookLog($event->id, $request->receivedAt, 'received');
    $log->setEventType($event->type);
    $log->setProvider($this->getProviderName());
    $log->setPayload($event->data);       // ← FULL payload, including PII
    $this->logRepository->save($log);
}
```

### Design: WebhookPayloadSanitizer (SRP)

Create a dedicated sanitizer that strips PII paths while preserving operational data needed for debugging (event ID, type, amounts, currency, object IDs).

**Why not filter in the repository?** The repository is a persistence concern — it shouldn't know about PII. The sanitizer is a data-protection concern (SRP).

**Why not filter in the WebhookLog model?** The model is a data structure — it shouldn't have filtering logic (SRP).

**Why recursive stripping?** Stripe payloads have nested objects (`data.object.customer_details.email`). A flat key list would miss nested structures.

### Implementation

**New file:** `payment-component/src/Webhook/WebhookPayloadSanitizer.php`

```php
final class WebhookPayloadSanitizer
{
    /**
     * Top-level and nested keys that contain PII.
     * These are stripped recursively from the payload.
     */
    private const PII_KEYS = [
        'customer_details',
        'customer_email',
        'customer_name',
        'shipping',
        'billing_details',
        'receipt_email',
        'metadata',           // may contain arbitrary merchant data including PII
    ];

    /**
     * Keys within nested objects that contain PII.
     * Stripped from any level of nesting.
     */
    private const PII_NESTED_KEYS = [
        'email',
        'name',
        'phone',
        'address',
        'last4',
        'exp_month',
        'exp_year',
    ];

    public function sanitize(array $payload): array
    {
        return $this->stripRecursive($payload);
    }

    private function stripRecursive(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::PII_KEYS, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            if (in_array($key, self::PII_NESTED_KEYS, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->stripRecursive($value);
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
```

#### Modify: `AbstractWebhookProcessor`

**Note:** The current code doesn't call `logWebhookReceived()` in `process()` — it was removed when `claimEvent()` was added (Sprint 64g). The payload is set in the log repository via `claimEvent()`. However, if a subclass calls `logWebhookReceived()` directly, the PII issue remains. We inject the sanitizer and apply it there.

Add `WebhookPayloadSanitizer` to the constructor:

```php
public function __construct(
    protected readonly WebhookLogRepositoryInterface $logRepository,
    protected readonly LoggerInterface $logger,
    protected readonly WebhookPayloadSanitizer $sanitizer = new WebhookPayloadSanitizer()
) {}
```

In `logWebhookReceived()`:

```php
$log->setPayload($this->sanitizer->sanitize($event->data));
```

### Tests

**File:** `payment-component/tests/Unit/Webhook/WebhookPayloadSanitizerTest.php`

| # | Test Name | Input | Expected |
|---|-----------|-------|----------|
| 1 | `sanitizerPreservesEventId` | `['id' => 'evt_123']` | `id` kept |
| 2 | `sanitizerPreservesEventType` | `['type' => 'checkout.session.completed']` | `type` kept |
| 3 | `sanitizerPreservesObjectId` | `['data' => ['object' => ['id' => 'cs_123']]]` | `data.object.id` kept |
| 4 | `sanitizerPreservesAmounts` | `['amount_total' => 2500]` | `amount_total` kept |
| 5 | `sanitizerPreservesCurrency` | `['currency' => 'eur']` | `currency` kept |
| 6 | `sanitizerStripsCustomerEmail` | `['customer_details' => ['email' => 'a@b.com']]` | `customer_details` = `[REDACTED]` |
| 7 | `sanitizerStripsCustomerName` | `['customer_details' => ['name' => 'John']]` | `customer_details` = `[REDACTED]` |
| 8 | `sanitizerStripsShippingAddress` | `['shipping' => ['address' => [...]]]` | `shipping` = `[REDACTED]` |
| 9 | `sanitizerStripsNestedCardDetails` | `['payment_method_details' => ['card' => ['last4' => '4242']]]` | `last4` = `[REDACTED]` |
| 10 | `sanitizerHandlesNestedObjects` | deeply nested PII | PII stripped at all levels |
| 11 | `sanitizerHandlesEmptyPayload` | `[]` | `[]` |
| 12 | `sanitizerIsDeterministic` | same input twice | identical output |

**Expected:** 12 tests, ~20 assertions

---

<a name="sprint-69b"></a>
## Sprint 69b — H6: Basket Snapshot PII Whitelist (TDD)

### Finding

**H6 — PII in Basket Snapshot (CVSS 4.0, HIGH)**

`BasketSnapshot::fromArray()` accepts the `items` array without filtering fields. If OXID basket items contain buyer-specific data (gift messages, personalization fields, customer notes), it all gets persisted as JSON in `oe_payments_contract.OXBASKETDATA`.

### Why This Matters

The contract table is long-lived (audit trail, reconciliation). Under GDPR, data minimization requires that we store only what's needed. Basket items need: product ID, title, quantity, price, VAT. They do NOT need: gift messages, personalization text, customer notes, or any other arbitrary fields.

### Current Code

**File:** `payment-component/src/Contract/BasketSnapshot.php:69-80`

```php
private static function extractItems(array $data): array
{
    if (!isset($data['items'])) {
        return [];
    }
    if (!is_array($data['items'])) {
        return [];
    }
    /** @var array<int, array<string, mixed>> $items */
    $items = $data['items'];
    return $items;     // ← NO FILTERING — all fields pass through
}
```

### Proposed Fix

Add a whitelist-based sanitizer method. Only explicitly allowed fields survive:

```php
private const ITEM_WHITELIST = ['artnum', 'title', 'quantity', 'price', 'vat', 'amount'];

private static function sanitizeItems(array $items): array
{
    return array_map(function (array $item): array {
        return array_intersect_key($item, array_flip(self::ITEM_WHITELIST));
    }, $items);
}
```

Call from `extractItems()`:

```php
private static function extractItems(array $data): array
{
    if (!isset($data['items']) || !is_array($data['items'])) {
        return [];
    }
    /** @var array<int, array<string, mixed>> $items */
    $items = $data['items'];
    return self::sanitizeItems($items);
}
```

**Why whitelist (not blacklist)?** Blacklisting is fragile — new PII fields added by OXID plugins would pass through. Whitelisting ensures only known-safe fields persist. If a new essential field is needed, it's added to the constant explicitly.

**Why not filter discounts too?** Discounts are system-generated (voucher codes, campaign rules) — they don't contain user-provided PII. If this changes, the same pattern can be applied.

### Tests

**File:** `payment-component/tests/Unit/Contract/BasketSnapshotSanitizationTest.php`

| # | Test Name | Input Item Fields | Expected |
|---|-----------|-------------------|----------|
| 1 | `snapshotKeepsProductId` | `artnum=ABC123` | `artnum` present |
| 2 | `snapshotKeepsTitle` | `title=Widget` | `title` present |
| 3 | `snapshotKeepsQuantity` | `quantity=2` | `quantity` present |
| 4 | `snapshotKeepsPrice` | `price=19.99` | `price` present |
| 5 | `snapshotKeepsVat` | `vat=19.0` | `vat` present |
| 6 | `snapshotStripsUnknownItemFields` | `artnum=X, unknownField=secret` | `unknownField` absent |
| 7 | `snapshotStripsGiftMessage` | `artnum=X, giftMessage=Happy birthday` | `giftMessage` absent |
| 8 | `snapshotStripsPersonalization` | `artnum=X, personalization=Custom text` | `personalization` absent |
| 9 | `sanitizeItemsIsDeterministic` | same input twice | identical output |

**Expected:** 9 tests, ~15 assertions

---

<a name="sprint-70a"></a>
## Sprint 70a — M2: Dev Mode Domain Matching Fix (TDD)

### Finding

**M2 — Dev Mode Domain Matching Has False Positives (MEDIUM)**

`ViewConfig::isStripeDevelopmentMode()` (line 67-76) uses `strpos($serverName, $domain)` to check if the server name matches a dev domain. This has false positives:

```php
$devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
foreach ($devDomains as $domain) {
    if (strpos($serverName, $domain) !== false) { return true; }
}
```

**Problem examples:**
- `attacker.localhost.com` matches `localhost` → dev mode enabled on attacker's domain
- `evil-site.local.attacker.com` matches `.local` → dev mode enabled
- `my.test.phishing.com` matches `.test` → dev mode enabled

Dev mode enables: unminified JS (larger attack surface for XSS), debug logging (may leak internal paths), timestamp-based cache busting (may bypass CDN protections).

### Current Code

**File:** `src/Stripe/Core/ViewConfig.php:66-76`

```php
// Check if we're on localhost/development domain
$serverName = $_SERVER['SERVER_NAME'] ?? '';
if (!is_string($serverName)) {
    $serverName = '';
}
$devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
foreach ($devDomains as $domain) {
    if (strpos($serverName, $domain) !== false) {
        return true;
    }
}
```

### Proposed Fix

Replace `strpos()` with **strict domain suffix matching**. A server name matches a dev domain if:
1. It equals the domain exactly (e.g., `localhost === localhost`)
2. It ends with the domain (e.g., `shop.local` ends with `.local`)

```php
$devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
foreach ($devDomains as $domain) {
    if ($serverName === $domain || str_ends_with($serverName, $domain)) {
        return true;
    }
}
```

**Why `str_ends_with()` is correct:**
- `localhost` → exact match ✓
- `shop.local` → ends with `.local` ✓
- `myshop.dev` → ends with `.dev` ✓
- `attacker.localhost.com` → does NOT end with `localhost` ✗
- `evil.test.attacker.com` → does NOT end with `.test` ✗

**Why PHP 8.0+ `str_ends_with()` is safe:** Project requires PHP 8.2+ (per `composer.json`).

### Testability

The current code reads `$_SERVER['SERVER_NAME']` directly. To test without polluting global state, we extract server name access into an overridable protected method:

```php
protected function getServerName(): string
{
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    return is_string($serverName) ? $serverName : '';
}
```

### Tests

**File:** `tests/Unit/Stripe/Core/ViewConfigDevModeTest.php`

Uses testable subclass that overrides `getServerName()` and `getEnvVar()` and `getConfigParam()`.

| # | Test Name | Server Name | Env Var | Expected |
|---|-----------|-------------|---------|----------|
| 1 | `devModeDetectsLocalhost` | `localhost` | — | `true` |
| 2 | `devModeDetectsDotLocal` | `shop.local` | — | `true` |
| 3 | `devModeRejectsPartialMatch` | `attacker.localhost.com` | — | `false` |
| 4 | `devModeRejectsSubdomainTrick` | `evil.test.attacker.com` | — | `false` |
| 5 | `devModeAcceptsEnvVariable` | `production.shop.com` | `STRIPE_DEV_MODE=1` | `true` |
| 6 | `devModeDefaultsFalseInProduction` | `production.shop.com` | — | `false` |

**Expected:** 6 tests, ~8 assertions

---

<a name="sprint-70b"></a>
## Sprint 70b — M3: Restrictive File Permissions (TDD)

### Finding

**M3 — File Permissions Too Permissive (CVSS 3.0, MEDIUM)**

`FileLogger::ensureDirectoryExists()` (line 41-46) creates log directories with `0755`:

```php
private function ensureDirectoryExists(): void
{
    $logDir = dirname($this->logFilePath);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
}
```

`0755` means **world-readable** (`rwxr-xr-x`). On shared hosting or misconfigured servers, any user on the system can read log files. Log files may contain contract IDs, payment intent IDs, error details, and debugging info — all sensitive in a PCI DSS context.

### Proposed Fix

Change `0755` to `0750` (`rwxr-x---`):
- **Owner (7):** Full access — the web server process writes logs
- **Group (5):** Read + execute — ops/admin group can read logs for monitoring
- **Other (0):** No access — other system users cannot read

```php
mkdir($logDir, 0750, true);
```

**Why not `0700`?** Ops teams need to read logs for monitoring (Prometheus exporters, log collectors). `0750` allows the web server's group to read without opening to the world.

### Tests

**File:** `payment-component/tests/Unit/Service/FileLoggerPermissionsTest.php`

Testing file permissions requires actual filesystem operations. We use a temporary directory in `sys_get_temp_dir()` and clean up in `tearDown()`.

| # | Test Name | Scenario | Expected |
|---|-----------|----------|----------|
| 1 | `logDirectoryCreatedWithRestrictivePermissions` | Log to non-existent dir | Dir perms are `0750` |
| 2 | `logFileNotWorldReadable` | Write a log entry | File perms exclude world-read (`($perms & 0004) === 0`) |
| 3 | `existingDirectoryNotModified` | Dir already exists with 0755 | Dir perms unchanged (still 0755) — we don't modify existing dirs |

**Expected:** 3 tests, ~5 assertions

**Note on test 3:** We intentionally do NOT chmod existing directories. If an admin set 0755 deliberately (e.g., for a log collector), we respect that. We only set 0750 on newly created directories.

---

<a name="execution-schedule"></a>
## Execution Schedule

### Summary Table

| Sprint | Sub | Finding | Severity | Theme | New Tests | New Files | Modified Files |
|--------|-----|---------|----------|-------|-----------|-----------|----------------|
| 67 | a | H3 | HIGH | Token validation | 5 | 1 (test) | 1 (controller) |
| 67 | b | M6 | MEDIUM | HTTPS guard | 6 | 2 (guard + test) | 1 (services.yaml) |
| 68 | a | H5 | HIGH | State machine | 6 | 1 (test) | 2 (PaymentContract, ContractState) |
| 68 | b | M9 | MEDIUM | Address HMAC | 5 | 2 (service + test) | 2 (MetadataService, services.yaml) |
| 69 | a | H7 | HIGH | Webhook PII | 12 | 2 (sanitizer + test) | 1 (AbstractWebhookProcessor) |
| 69 | b | H6 | HIGH | Basket PII | 9 | 1 (test) | 1 (BasketSnapshot) |
| 70 | a | M2 | MEDIUM | Dev mode | 6 | 1 (test) | 1 (ViewConfig) |
| 70 | b | M3 | MEDIUM | File perms | 3 | 1 (test) | 1 (FileLogger) |
| **Total** | | **8 findings** | | | **~52** | **~11** | **~10** |

### Dependency Graph

```
67a (H3 token)  ─── independent
67b (M6 HTTPS)  ─── depends on 64a guard chain infrastructure (already done)
68a (H5 state)  ─── independent (payment-component)
68b (M9 HMAC)   ─── independent (payment-component)
69a (H7 webhook PII) ─── independent (payment-component)
69b (H6 basket PII)  ─── independent (payment-component)
70a (M2 dev mode)     ─── independent
70b (M3 file perms)   ─── independent
```

All sub-sprints are **independent** — can be executed in any order.

### Verification After Each Sprint

```bash
# Unit tests (stripe)
docker compose exec -T php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Unit tests (payment-component)
docker compose exec -T php php vendor/bin/phpunit \
  -c extensions/payment-component/tests/phpunit.xml --testsuite Unit

# Static analysis
docker compose exec -w /var/www/extensions/stripe -T php \
  php vendor/bin/phpstan analyse --level=max -c tests/PhpStan/phpstan.neon src/

# Full pre-commit
docker compose exec -w /var/www/extensions/stripe -T php \
  ./bin/pre-commit-check.sh --full
```

### Post-Sprint 70 Status Update

After all sprints complete:
- **Findings:** 26/28 DONE (remaining: L1-L4 low-severity)
- **PCI DSS:** All HIGH blockers resolved (H3, H5 done)
- **GDPR:** Data minimization enforced (H6 basket, H7 webhook)
- **BSI TR-03116-4:** HTTPS + HMAC enforced (M6, M9)
- **OWASP Top 10:** H3, M1, M2 resolved

### SOLID Compliance Summary

| Principle | Application |
|-----------|------------|
| **S** | `WebhookPayloadSanitizer` — one job (strip PII). `AddressHmacService` — one job (sign/verify). `WebhookHttpsGuard` — one job (check TLS). |
| **O** | HTTPS guard added to existing chain without modifying controller or other guards. Basket whitelist added without changing snapshot serialization. |
| **L** | All guards implement `WebhookRequestGuardInterface` — chain treats them uniformly. `ContractTokenService` implements `TokenServiceInterface`. |
| **I** | `AddressHmacService` has 2 methods (sign, verify). `WebhookPayloadSanitizer` has 1 method (sanitize). Guard interface has 1 method (check). |
| **D** | Controller depends on `ContractTokenService` via DI. `ContractMetadataService` depends on injected `AddressHmacService`. `AbstractWebhookProcessor` receives sanitizer via constructor. |
