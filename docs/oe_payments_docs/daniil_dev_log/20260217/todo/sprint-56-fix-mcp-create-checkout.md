# Sprint 56: Fix MCP create_checkout "User ID is required" Error

**Priority:** Critical
**Status:** TODO
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Problem

When an AI agent calls `create_checkout` via MCP with valid buyer data, the tool fails with:

```json
{
  "jsonrpc": "2.0",
  "id": 5,
  "error": {
    "code": -32000,
    "message": "Tool execution failed",
    "data": {
      "exception_class": "InvalidArgumentException",
      "exception_message": "User ID is required",
      "tool_name": "create_checkout"
    }
  }
}
```

The error persists even when buyer email, first_name, last_name, and phone_number are all provided.

### Reproduction

Agent input:
```json
{
  "items": [{"id": "22e135eb03a3aa69198ae30762ee785c", "quantity": 1}],
  "buyer": {
    "email": "playwright.user@oxid-esales.dev",
    "first_name": "Marc",
    "last_name": "Muster",
    "phone_number": "+491234567890"
  },
  "fulfillment_address": {
    "line_one": "Hugo Boss str. 23",
    "city": "Frankfurt",
    "country": "DE",
    "postal_code": "68386"
  }
}
```

---

## Root Cause Analysis

The error is thrown at **`ContractCreationHandler.php:78`** (payment-component):

```php
$userId = $context->get('userId');
if (!is_string($userId) || $userId === '') {
    throw new InvalidArgumentException('User ID is required');
}
```

This means `userId` was **not set on the EventContext** by the time `StripeContractCreationHandler` (priority 100) runs.

### Handler Chain

| Priority | Handler | Role | Status |
|----------|---------|------|--------|
| **200** | `AcpContextResolverHandler` | Resolve buyer email → OXID User → set `userId` on context | **Suspect** |
| **100** | `StripeContractCreationHandler` | Validate `userId`, create PaymentContract | **Throws error** |
| **0** | `StripeCheckoutSessionHandler` | Create Stripe Checkout Session | Never reached |

### Possible Failure Points in AcpContextResolverHandler

1. **Handler not invoked** — Event listener registration issue; the handler is tagged in services.yaml but the `EventListenerProvider` may not be resolving tagged handlers at runtime (DI compilation issue)

2. **Guard skips handler** — Line 55: `if ($context->get('source') !== 'acp') return;` — if `source` is not set or has different value, the handler exits silently

3. **oxNew(User) fails in MCP context** — The handler calls `oxNew(User::class)` which requires the OXID framework to be fully bootstrapped. In MCP controller context (HTTP to `/mcp/`), the OXID session and registry may not be initialized the same way as in a normal shop request

4. **User::getIdByUserName() returns unexpected value** — If the user lookup returns a non-string or empty result, and `createGuestUser()` also fails silently (e.g., DB permission issue, missing table), `$user->getId()` at line 77 could return null/empty

5. **Exception in resolveUser() swallowed** — If `AcpContextResolverHandler::handle()` throws an exception, the event dispatcher may catch it and continue to the next handler, leaving `userId` unset

---

## Investigation Steps

### Step 1: Verify handler registration
```bash
# Check if the handler is actually loaded by the DI container
docker compose exec php php -r "
    require '/var/www/source/bootstrap.php';
    \$container = \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()->getContainer();
    var_dump(\$container->has('OxidEsales\\\Payments\\\Stripe\\\Mcp\\\Handler\\\AcpContextResolverHandler'));
"
```

### Step 2: Check event dispatcher handler resolution
Verify that the `EventListenerProvider` collects tagged `payment.event_handler` services and maps them to the correct event class (`StripeCheckoutSessionRequestEvent`).

### Step 3: Add debug logging
Temporarily add logging at the top of `AcpContextResolverHandler::handle()` to confirm it's being called:
```php
$this->logEvent('AcpContextResolverHandler::handle() ENTRY', [
    'eventClass' => get_class($event),
    'source' => $context->get('source'),
    'hasUserId' => $context->has('userId'),
]);
```

### Step 4: Test OXID bootstrap in MCP context
Verify that `oxNew(User::class)` works inside the McpController request lifecycle:
```php
$user = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
var_dump($user->getIdByUserName('playwright.user@oxid-esales.dev'));
```

### Step 5: Check event dispatcher exception handling
If the dispatcher catches exceptions from handlers, AcpContextResolverHandler could fail silently. Check `EventDispatcher::dispatch()` for try/catch blocks.

---

## Likely Fix Areas

### Fix A: Event dispatcher not routing to handler
If the `EventListenerProvider` doesn't correctly resolve tagged services for `StripeCheckoutSessionRequestEvent`, the handler never runs. Fix: verify DI tag resolution or add explicit listener registration.

### Fix B: OXID framework not bootstrapped in MCP context
The `McpController` may not initialize the OXID session/registry properly. `oxNew()`, `Registry::getSession()`, `User::getIdByUserName()` all depend on a fully bootstrapped OXID environment. Fix: ensure McpController or a middleware initializes OXID session before dispatching events.

### Fix C: Exception in handler swallowed by dispatcher
If the event dispatcher wraps handler calls in try/catch, any exception from `resolveUser()` (e.g., DB connection, missing table) would be silently caught. Fix: either re-throw or log exceptions in the dispatcher, or add explicit error handling in `AcpContextResolverHandler::handle()`.

### Fix D: User creation fails
If `$user->save()` in `createGuestUser()` fails (missing required fields for DB — e.g., country, salutation), `$user->getId()` returns empty string. Fix: ensure guest user has all required DB fields populated.

---

## Acceptance Criteria

1. `create_checkout` succeeds with valid buyer email + items + address
2. New user is created if email doesn't exist in oxuser table
3. Existing user is loaded if email already exists
4. Contract is created in DRAFT state with basket snapshot
5. Stripe Checkout Session URL is returned to the agent
6. Error messages are actionable (not generic "User ID is required")
7. All existing unit tests pass (1099+)
8. Integration test added for the full MCP create_checkout flow
9. All quality gates pass (PHPCS, PHPStan, PHPMD)

---

## Files to Investigate/Modify

| File | Action |
|------|--------|
| `stripe/src/Stripe/Mcp/Handler/AcpContextResolverHandler.php` | Debug, possibly fix user resolution |
| `stripe/src/Stripe/Controller/McpController.php` | Verify OXID bootstrap |
| `payment-component/src/EventSystem/EventDispatcher.php` | Check exception handling |
| `payment-component/src/EventSystem/EventListenerProvider.php` | Verify handler routing |
| `payment-component/src/EventSystem/Handler/ContractCreationHandler.php` | Improve error message |
| `stripe/services.yaml` | Verify handler tag registration |
| `stripe/tests/Integration/Mcp/` | Add create_checkout integration test |

---

## Estimated Scope

- **Investigation:** 1-2 hours (debug logging, reproduce in Docker)
- **Fix:** Depends on root cause (likely 1-3 files, 20-50 lines)
- **Tests:** 1 new integration test + possibly 2-3 unit test updates
- **Quality gates:** PHPCS + PHPStan + PHPMD must pass
