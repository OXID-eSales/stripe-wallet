# Architecture Audit Report — 2026-02-18

## Scope

Compared the architecture documentation (`docs/architecture/`) against the actual implementation to find:
- Deviations from documented architecture
- Dead code
- Direct access bypassing the event system
- Security concerns

---

## CRITICAL: Direct Stripe API Calls Bypassing Adapter Layer

### Finding 1: `StripeProductCatalogSyncService` — hardcoded API calls

**File:** `src/Stripe/Mcp/Service/StripeProductCatalogSyncService.php`
**Severity:** CRITICAL

This service makes **direct HTTP calls** to Stripe API endpoints, bypassing both the event system AND the `StripeAdapter`:

```php
// Line 36-44: Direct POST to hardcoded Stripe URL
$response = $this->httpClient->post(
    'https://api.stripe.com/v1/products/import',      // ← HARDCODED
    $feedContent,
    ['Authorization' => 'Bearer ' . $this->stripeApiKey],  // ← RAW API KEY
    30
);

// Line 96-103: Another direct POST
$response = $this->httpClient->post(
    'https://api.stripe.com/v1/orders/' . $orderId . '/fulfillment',  // ← HARDCODED
    $body,
    ['Authorization' => 'Bearer ' . $this->stripeApiKey],  // ← RAW API KEY
    10
);
```

**Architecture violation:**
- `docs/architecture/03-provider-abstraction.md` states ALL Stripe interactions go through `PaymentAdapterInterface` → `StripeAdapter`
- This service bypasses that entirely with raw HTTP calls
- No event dispatched (no `ProductCatalogSyncEvent`)
- No idempotency, no logging through event system, no webhook tracking
- Hardcoded Stripe API URLs — brittle if API changes
- Raw API key passed as constructor string — not through `ModuleConfigurationService`

**Recommendation:** Create `HostedCommerceAdapterInterface` methods on the adapter, or dispatch events through the event system.

---

## HIGH: Security — API Key Prefix Leaked in JSON Response

### Finding 2: `StripeOrderController::createCheckoutSession()` — debug info in production response

**File:** `src/Stripe/Controller/StripeOrderController.php:147-158`
**Severity:** HIGH (security)

The `createCheckoutSession()` AJAX endpoint returns debug information including API key prefixes in the JSON response:

```php
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    'url' => $context->get('checkoutUrl'),
    'contract_id' => $context->get('contractId'),
    // Debug info (remove in production)      ← COMMENT SAYS REMOVE!
    '_debug' => [
        'pk_prefix' => substr($publishableKey, 0, 20),  // ← 20 chars of publishable key
        'sk_prefix' => $secretKeyPrefix,                  // ← 12 chars of SECRET key
        'testMode' => $config->isTestMode(),
        'keysValid' => $validator->validateKeyPair(),
    ],
]);
```

Also logs the secret key prefix to the application log (line 136):
```php
$secretKeyPrefix = substr($config->getToken(), 0, 12) . '...';
```

**Risk:** Even partial secret key exposure helps attackers narrow brute-force attempts. The comment `// Debug info (remove in production)` confirms this was meant to be temporary.

**Recommendation:** Remove the `_debug` block entirely. Log to file logger only, never return key material in HTTP responses.

---

## MEDIUM: Registry Direct Access in Event Handler

### Finding 3: `AcpContextResolverHandler` — `Registry::getSession()` in handler

**File:** `src/Stripe/Mcp/Handler/AcpContextResolverHandler.php:221, 232`
**Severity:** MEDIUM

The handler uses `Registry::getSession()` directly instead of injected `SessionAdapterInterface`:

```php
protected function setSession(User $user, Basket $basket): void
{
    $session = Registry::getSession();   // ← Should be injected
    $session->setBasket($basket);
    $session->setUser($user);
}

protected function getSessionId(): string
{
    return Registry::getSession()->getId();  // ← Should be injected
}
```

**Architecture violation:** `docs/architecture/01-architecture-layers.md` states handlers use dependency injection. Other handlers (e.g., `StripeCheckoutReturnHandler`) correctly inject `SessionAdapterInterface`.

**Mitigating factor:** Methods are `protected` for testable subclass pattern override. But the production code still hits Registry.

**Recommendation:** Inject `SessionAdapterInterface` via constructor, consistent with `StripeCheckoutReturnHandler`.

---

## MEDIUM: ContainerFactory Service Locator in MCP Controllers

### Finding 4: Three MCP controllers use `ContainerFactory` instead of DI

**Files:**
- `src/Stripe/Mcp/Controller/McpController.php:30`
- `src/Stripe/Mcp/Controller/UcpCheckoutController.php:34`
- `src/Stripe/Mcp/Controller/UcpProfileController.php:25`

```php
public function init(): void
{
    $container = ContainerFactory::getInstance()->getContainer();
    $this->authGuard = $container->get(McpAuthGuardInterface::class);
    $this->eventDispatcher = $container->get(EventDispatcherInterface::class);
    // ...
}
```

**Architecture violation:** Service Locator antipattern. `docs/architecture/01-architecture-layers.md` prescribes dependency injection.

**Mitigating factor:** OXID controllers are framework-managed and don't support constructor injection natively. This is a known OXID limitation.

**Recommendation:** Accept for controllers, but document as OXID constraint. Never use in handlers/services.

---

## MEDIUM: Controller Contains Business Logic

### Finding 5: `StripeOrderController::createCheckoutSession()` — config validation in controller

**File:** `src/Stripe/Controller/StripeOrderController.php:90-97`
**Severity:** MEDIUM

The controller performs API key validation before dispatching the event:

```php
// 0. Validate API key configuration
$config = $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class);
$validator = $this->getServiceFromContainer(ConfigurationValidatorInterface::class);
$keyValidationError = $validator->getKeyValidationError();
if ($keyValidationError !== null) {
    throw new \RuntimeException('Stripe configuration error: ' . $keyValidationError);
}
```

**Architecture deviation:** The documented pattern is: validate input → create EventContext → dispatch event → return result. Config validation is business logic that should be in a handler. The other controller methods (`executeStripePayment`, `checkoutSuccess`, `stripeReturn`) correctly follow the thin-controller pattern.

**Recommendation:** Move config validation into a handler (e.g., `ConfigurationValidationHandler` at priority 200) or into `StripeCheckoutSessionHandler`.

---

## LOW: Duplicate `sess_challenge` Setting

### Finding 6: `sess_challenge` set twice in `checkoutSuccess()` flow

**File:** `src/Stripe/Controller/StripeOrderController.php:206, 278`

```php
// Line 206: In checkoutSuccess() directly
if ($orderId = $context->get('orderId')) {
    $this->setSessionVariable('sess_challenge', $orderId);
    ...
}

// Line 278: In processContextResults() — called at line 203
if ($context->get('orderId') !== null) {
    $this->setSessionVariable('sess_challenge', $context->get('orderId'));
}
```

`processContextResults()` is called BEFORE the explicit set in `checkoutSuccess()`. So `sess_challenge` is set twice with the same value.

**Recommendation:** Remove the duplicate from `processContextResults()` or from `checkoutSuccess()`.

---

## LOW: StripeOrderController::getUser() May Clash with Parent

### Finding 7: `getUser()` override may behave differently than parent

**File:** `src/Stripe/Controller/StripeOrderController.php:458-462`

```php
public function getUser(): ?\OxidEsales\Eshop\Application\Model\User
{
    $basket = $this->getBasketFromSession();
    return $basket->getBasketUser();
}
```

This overrides `OrderController::getUser()` which loads from session/database. The basket user may differ from the logged-in user (especially in ACP flow). This works but could cause subtle bugs if parent methods expect the original `getUser()` behavior.

---

## NO ISSUES — Positive Findings

| Area | Status |
|------|--------|
| **Event handler chain** | All 9 handlers properly registered, correct priorities |
| **Adapter centralization** | Only `StripeAdapter` calls Stripe SDK (except Finding 1) |
| **Webhook processing** | Correctly uses Template Method + event dispatching |
| **Contract state machine** | No `setState()` anywhere — only named transition methods |
| **Dead code** | None found — all classes, methods, imports are used |
| **Handler business logic** | All handlers delegate to services, services delegate to adapters |
| **Test coverage** | 1114 tests, all passing |

---

## Summary

| # | Finding | Severity | Category |
|---|---------|----------|----------|
| 1 | `StripeProductCatalogSyncService` bypasses adapter + events | **CRITICAL** | Architecture violation |
| 2 | API key prefixes leaked in JSON response | **HIGH** | Security |
| 3 | `AcpContextResolverHandler` uses `Registry::` in handler | **MEDIUM** | DI violation |
| 4 | MCP controllers use `ContainerFactory` service locator | **MEDIUM** | DI violation (OXID constraint) |
| 5 | Config validation in controller, not handler | **MEDIUM** | Thin-controller violation |
| 6 | `sess_challenge` set twice | **LOW** | Dead/duplicate code |
| 7 | `getUser()` override may clash with parent | **LOW** | Potential bug |
