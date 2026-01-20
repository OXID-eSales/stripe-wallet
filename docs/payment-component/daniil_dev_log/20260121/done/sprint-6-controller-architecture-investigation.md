# Sprint 6: Remove Unused Component Controllers

**Date:** 2026-01-21 (carried from 2026-01-20)
**Priority:** Low
**Estimated Effort:** 1-2 hours
**Type:** Code Removal
**Status:** READY TO START

---

## Q&A Decisions (2026-01-21)

| # | Question | Decision |
|---|----------|----------|
| Q1 | Architectural approach | **A) Remove component controllers** - OXID has its own controller hierarchy |
| Q2 | Investigation needed | **A) Remove + document** - Controllers are empty stubs, skip investigation |

---

## Confirmed Implementation Approach

1. **Remove all component controller files** (empty stubs, never used)
2. **Update services.yaml** - remove controller registrations
3. **Document the pattern** - providers should extend OXID controllers directly
4. **No traits needed** - not enough shared logic to justify

---

## Core Development Principles

All code in this sprint MUST follow:

| Principle | Requirement |
|-----------|-------------|
| **TDD-First** | Write failing tests BEFORE implementation. Red → Green → Refactor |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **Liskov Substitution** | Subtypes must be substitutable for their base types |
| **Dependency Injection** | Depend on abstractions, not concretions. Inject dependencies via constructor |
| **DRY** | Don't Repeat Yourself. Extract common logic to shared methods/classes |
| **Clean Code** | Meaningful names, small functions (15-25 lines), early returns (no else), single responsibility per method |
| **No Over-Engineering** | Only add what's needed now. No speculative features or premature abstractions |

### Testing Commands

Run from `payment-component/` or `stripe/` directory:

```bash
# Quick check (unit tests + style checks)
./bin/pre-commit-check.sh

# Full check (unit tests + integration tests + style checks)
./bin/pre-commit-check.sh --full
```

---

## Executive Summary

The component has unused controller infrastructure:
- `AbstractController.php`
- `BaseController.php` (empty stub)
- `BaseControllerInterface.php`
- `Webhook/WebhookController.php` + Interface

Stripe extends OXID controllers directly and has its own `StripeWebhookController`.

**Question:** Should the component provide abstract controllers that provider modules extend, or is direct OXID extension the correct pattern?

---

## Current State Analysis

### Component's Controller Infrastructure

**AbstractController:**
```php
// payment-component/src/Controller/AbstractController.php
abstract class AbstractController
{
    // Common controller logic (if any)
}
```

**BaseController (Empty):**
```php
// payment-component/src/Controller/BaseController.php
class BaseController
{
    // Empty stub
}
```

**WebhookController:**
```php
// payment-component/src/Controller/Webhook/WebhookController.php
class WebhookController implements WebhookControllerInterface
{
    public function __construct(
        private readonly WebhookProcessorInterface $processor
    ) {}

    public function handleWebhook(Request $request): Response
    {
        // Generic webhook handling
    }
}
```

### Stripe's Controllers

**StripeWebhookController:**
```php
// stripe/src/Stripe/Controller/StripeWebhookController.php
class StripeWebhookController extends FrontendController  // OXID's FrontendController
{
    public function stripeWebhook(): void
    {
        // Stripe-specific webhook handling
        // Uses WebhookProcessingService directly
    }
}
```

**StripeCheckoutController:**
```php
// stripe/src/Stripe/Controller/CheckoutController.php
class CheckoutController extends FrontendController  // OXID's FrontendController
{
    // Checkout session handling
}
```

**PaymentController:**
```php
// stripe/src/Stripe/Controller/PaymentController.php
class PaymentController extends FrontendController  // OXID's FrontendController
{
    // Payment return/cancel handling
}
```

---

## Investigation Questions

### Question 1: Should Components Provide Abstract Controllers?

**Arguments FOR:**
- Standardizes controller patterns across providers
- Reuses common logic (error handling, logging, security)
- Ensures consistent webhook handling
- Easier to maintain and test

**Arguments AGAINST:**
- OXID has its own controller hierarchy (`FrontendController`, `AdminController`)
- Providers need to integrate with OXID's request/response cycle
- Controller logic is often highly provider-specific
- May over-abstract and add unnecessary complexity

### Question 2: What Common Logic Could Be Extracted?

Potential reusable controller logic:
1. **Error handling** - Standard error responses
2. **Logging** - Request/response logging
3. **Security** - CSRF protection, rate limiting
4. **Response formatting** - JSON responses, redirects
5. **Event dispatching** - Dispatch events before/after action

### Question 3: How Do Other OXID Modules Handle This?

Research needed:
- How do other OXID payment modules structure controllers?
- Does OXID recommend a pattern for module controllers?
- Are there OXID best practices for webhook handling?

---

## Analysis Tasks

### Task 1: Review Component Controllers

```bash
# Check what's in the component controllers
cat payment-component/src/Controller/AbstractController.php
cat payment-component/src/Controller/BaseController.php
cat payment-component/src/Controller/Webhook/WebhookController.php
```

### Task 2: Review Stripe Controllers

```bash
# List all Stripe controllers
ls -la stripe/src/Stripe/Controller/

# Check what they extend
grep -r "extends" stripe/src/Stripe/Controller/
```

### Task 3: Identify Common Patterns

Compare Stripe controllers to find common logic that could be extracted:
- Error handling
- Logging
- Security checks
- Response formatting

### Task 4: Research OXID Patterns

- Check OXID documentation for module controller best practices
- Review other OXID modules (PayPal, Klarna) for patterns

---

## Architectural Options

### Option A: Remove Component Controllers (Simplest)

If analysis shows controllers should be provider-specific:

1. Remove all component controllers
2. Document that providers should extend OXID controllers directly
3. Provide utility classes/traits for common logic if needed

**Pros:**
- Simplest solution
- Aligns with current Stripe implementation
- No unnecessary abstraction

**Cons:**
- No standardization across providers
- Duplicated error handling logic

### Option B: Abstract Controller with Traits (Recommended if reuse found)

If common logic is identified:

```php
// Component provides traits for common functionality
trait WebhookControllerTrait
{
    protected function logWebhookReceived(string $eventId): void { ... }
    protected function validateWebhookSignature(): bool { ... }
    protected function respondWithError(string $message, int $code = 400): Response { ... }
    protected function respondWithSuccess(): Response { ... }
}

// Stripe uses traits with OXID controller
class StripeWebhookController extends FrontendController
{
    use WebhookControllerTrait;

    public function stripeWebhook(): void
    {
        $this->logWebhookReceived($eventId);
        // Stripe-specific logic
    }
}
```

**Pros:**
- Reuses common logic
- Doesn't force inheritance
- Works with OXID's controller hierarchy

**Cons:**
- Traits can be harder to test
- May not be needed if logic is simple

### Option C: Abstract Controller Hierarchy

If strong standardization is needed:

```php
// Component's abstract webhook controller
abstract class AbstractWebhookController
{
    abstract protected function getWebhookSecret(): string;
    abstract protected function parseEvent(string $payload): WebhookEvent;
    abstract protected function processEvent(WebhookEvent $event): WebhookResult;

    final public function handleWebhook(Request $request): Response
    {
        // 1. Validate signature
        // 2. Parse event
        // 3. Process event
        // 4. Return response
    }
}

// OXID bridge for Stripe
class StripeWebhookController extends FrontendController
{
    private AbstractWebhookController $webhookHandler;

    public function stripeWebhook(): void
    {
        $request = $this->buildRequest();
        $response = $this->webhookHandler->handleWebhook($request);
        $this->sendResponse($response);
    }
}
```

**Pros:**
- Strongest standardization
- Clear contract for providers

**Cons:**
- Additional layer of abstraction
- May conflict with OXID patterns

---

## Recommendation Process

1. **Complete investigation tasks**
2. **Quantify common logic** - If <20 lines could be shared, Option A is best
3. **Check OXID conventions** - Follow existing patterns
4. **Make decision** based on findings

---

## Files Involved

### Component Controllers (Under Investigation)
```
payment-component/src/Controller/AbstractController.php
payment-component/src/Controller/BaseController.php
payment-component/src/Controller/BaseControllerInterface.php
payment-component/src/Controller/Webhook/WebhookController.php
payment-component/src/Controller/Webhook/WebhookControllerInterface.php
```

### Stripe Controllers (For Comparison)
```
stripe/src/Stripe/Controller/StripeWebhookController.php
stripe/src/Stripe/Controller/CheckoutController.php
stripe/src/Stripe/Controller/PaymentController.php
stripe/src/Stripe/Controller/Admin/OrderCapture.php
stripe/src/Stripe/Controller/Admin/OrderRefund.php
```

---

## Decision Matrix

| Criteria | Option A (Remove) | Option B (Traits) | Option C (Abstract) |
|----------|-------------------|-------------------|---------------------|
| Simplicity | High | Medium | Low |
| Code reuse | None | Medium | High |
| OXID compatibility | High | High | Medium |
| Testing | Easy | Medium | Harder |
| Maintenance | Low | Medium | Higher |

---

## Definition of Done

- [ ] Investigation tasks completed
- [ ] Common logic quantified (lines of code)
- [ ] OXID patterns researched
- [ ] Architectural decision made and documented
- [ ] If Option A: Controllers removed, documented why
- [ ] If Option B/C: Refactoring plan created

---

## References

- Sprint 1: Overall code analysis
- Sprint 5: Webhook infrastructure (related)
- OXID Controller docs: (link to OXID documentation)
- Architecture: `architecture/01-architecture-layers.md`
