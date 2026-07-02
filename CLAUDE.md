# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Stripe Payment Module for OXID eShop 7.4+ implementing a **Smart-Contract Architecture** for payment lifecycle management. Uses Stripe SDK v19+ with Stimulus.js for frontend.

**Module ID:** `oe_payments_stripe_wallet`
**Namespace:** `OxidEsales\Payments\Stripe\`
**Stripe SDK:** `stripe/stripe-php ^19.3`

## Core Development Principles

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **DevOps-first** - Pre-commit validation (PHPCS, PHPStan, PHPMD, PHPUnit) before every commit
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **No overengineering** - Implement exactly what's needed, no speculative abstractions
- **PSR-12** code style, **PHPStan level max** compliance

## Development Commands

### Installation
```bash
make install              # Install all dependencies (composer + npm)
composer install          # PHP dependencies only
npm install               # JS dependencies only
```

### Building JavaScript
```bash
npm run build             # Production build (minified)
npm run build:dev         # Development build (with source maps)
npm run watch             # Watch mode for development
```

### Testing

All tests run inside Docker from the project root (parent of extensions/stripe).

**Unit Tests:**
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

**Integration Tests:**
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration
```

**Single Test File:**
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Path/To/TestFile.php
```

**Single Test Method:**
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter testMethodName extensions/stripe/tests/Unit/Path/To/TestFile.php
```

**E2E Tests (Playwright):**
```bash
cd tests/e2e/playwright/playwright
npm install && npx playwright install chromium
npx playwright test                                          # All tests
npx playwright test tests/admin/stripe-tab-styles.spec.ts    # Single spec
npx playwright test --headed                                 # With browser UI
npx playwright test --project=admin-tests                    # Admin tests only
```

### Code Quality

**Pre-commit check (recommended):**
```bash
./bin/pre-commit-check.sh           # Unit tests + style checks
./bin/pre-commit-check.sh --full    # Unit + Integration tests
./bin/pre-commit-check.sh --no-phpunit  # Style checks only
```

**Individual checks:**
```bash
composer phpcs              # PHP CodeSniffer (PSR-12)
composer phpstan            # PHPStan static analysis (level max)
composer phpmd              # PHP Mess Detector
composer style              # All style checks
```

### OXID Module Commands
```bash
bin/oe-console oe:module:install extensions/stripe
bin/oe-console oe:module:activate oe_payments_stripe_wallet
bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
bin/oe-console oe:cache:clear
```

## Smart-Contract Architecture

The module implements a **contract-first payment pattern** where clicking "Place Order" creates a contract, not an order. The order is created early (during draft completion) so an order number exists before Stripe redirect.

**Contract Lifecycle:**
```
DRAFT → NOT_FINISHED → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
                                     ↓ (manual capture skips AUTHORIZED)
                        PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
```
Alternative endings: `CANCELLED`, `EXPIRED`, `FAILED`

**Key Innovation:**
- Traditional: User clicks "Place Order" → Order created → Payment → Order updated
- Smart-Contract: User clicks "Place Order" → Contract(DRAFT) → Order(NOT_FINISHED) → Stripe session → User pays → Contract advances → Order finalized

### Source Structure

```
src/Stripe/
├── Adapter/              # StripeAdapter, LazyStripeAdapter, OxidShopAdapter, Helper/
│   └── Helper/           # PaymentIntentHelper, RefundHelper, CheckoutHelper
├── Component/Widget/     # StripeCheckoutFooter
├── Controller/
│   ├── Admin/            # OrderRefund, OrderActionDispatcher, OrderRefundViewDataProvider,
│   │                     #   StripeConnect, ModuleConfiguration
│   ├── Webhook/          # WebhookController, Guards (HTTPS, IP, RateLimit, PayloadSize)
│   ├── PaymentController.php
│   └── StripeOrderController.php
├── Core/                 # StripeDefinitions, ViewConfig, Events (activate/deactivate)
├── EventSystem/
│   ├── Event/            # 8 Stripe-specific events (Capture, Refund, Cancel, Checkout, etc.)
│   └── Handler/          # 9 event handlers (ContractCreation, OrderCreation, Capture, Refund, etc.)
├── Model/                # Order (extension), Payment (extension)
├── Service/              # 33 service files — Capture, Refund, Checkout, Reconciliation, etc.
│   ├── Factory/          # StripeAdapterFactory
│   └── Result/           # CheckoutReturnResult
└── WebhookHandler/       # PaymentIntentSucceededHandler, ChargeRefundedHandler
```

**Note:** Provider-agnostic components (Contract, Repository, EventSystem, etc.) are in the separate `payment-component` package. Stripe uses them via dependency injection.

### Admin Panel (Stripe Tab)

The admin order detail page has a **Stripe tab** (`OrderRefund` controller) with:
- **Payment Details** card — Contract ID, Order ID, Payment Type, Transaction ID (links to Stripe Dashboard), Factual Captured Amount, Refunded Amount
- **Transaction History** table — fetched from Stripe API (source of truth), shows authorization/capture/refund with colored badges
- **Capture form** — supports partial capture with amount input (releases remainder to customer)
- **Refund form** — supports partial refund with amount input (validates against remaining refundable)
- **Cancel Authorization** — for uncaptured manual-capture orders
- **OXPAID Reconciliation** — auto-heals OXPAID when Stripe shows succeeded but OXPAID is 0000

**Transaction Storage Strategy (B+):**
- **Display**: Stripe API (`getStripeTransactionHistory()`) — always fresh, covers Dashboard actions
- **Audit log**: DB (`oe_payments_transaction`) — recorded on auth/capture/refund events
- **Self-healing**: `reconcilePaymentState()` on admin view

### Key Domain Models

**PaymentContract (Aggregate Root):** (from payment-component)
- States: `DRAFT`, `NOT_FINISHED`, `PENDING`, `AUTHORIZED`, `READY_TO_COMMIT`, `COMMITTED`, `FULFILLED`, `CANCELLED`, `EXPIRED`, `FAILED`
- Manages conditions: `payment_authorized`, `fraud_check`, `stock_reserved`
- Links to oxorder only after commitment (OXORDERID NULL until committed)

**BasketSnapshot (Value Object):** (from payment-component)
- Immutable copy of basket at contract creation
- Stored as JSON in `oe_payments_contract.OXBASKETDATA`

### Database Schema

**Important:** All database tables are created by `payment-component`. Stripe has NO migrations.

Tables (created by payment-component):
- `oe_payments_contract` - Contract lifecycle, basket snapshot, capture/refund tracking
- `oe_payments_transaction` - Transaction audit log (authorization, capture, refund records)
- `oe_payments_customer` - Customer payment data (vaulting)
- `oe_payments_idempotency` - Duplicate charge prevention
- `oe_payments_sessions` - Session state management
- `oe_payments_webhooklogs` - Webhook event logs

## Documentation

Architecture documents in `docs/architecture/`:
- `00-overview.md` - Smart-contract architecture overview
- `01-architecture-layers.md` - 7-layer event-driven architecture
- `02-event-system.md` - PSR-14 event dispatcher, handler priorities
- `03-provider-abstraction.md` - Adapter pattern, DTO contracts
- `04-webhook-processing.md` - Webhook flow, idempotency, signature verification

Developer guides in `docs/for_developer/`:
- `01-module-principles.md` - Contract-first model, boundary rules, extension hooks
- `02-payment-component-dependency.md` - Interface mappings, DI wiring, database schema
- `03-extending-the-stripe-module.md` - 6 extension patterns with code examples

Development history in `docs/oe_payments_docs/daniil_dev_log/`

## Code Style Rules

- **No else expressions** - Use early returns
- **Explicit imports** - No inline `\Exception`, use `use` statements
- **Null safety** - Check for null before using nullable values
- **Small methods** - Target 15-25 lines, extract helpers for long methods
- **PHPStan annotations** - Use `@phpstan-ignore-next-line` only for OXID core issues (oxNew, Registry, virtual parent classes)
- **Never suppress static analysis** - Fix the underlying code; suppress only for OXID core patterns

## Testing Strategy

**Test counts:** ~99 test files, 822+ unit tests, 18 integration tests

**Test Structure (AAA):** Arrange-Act-Assert

**Testable Subclass Pattern:** OXID admin controllers don't support constructor DI. Use testable subclasses:
```php
class TestableOrderRefundForVisibility extends OrderRefund {
    public function __construct(?Order $order = null, ?ViewDataProvider $vdp = null) {
        // Skip OXID admin bootstrap
    }
    public function getOrder(): ?Order { return $this->testOrder; }
    protected function getViewDataProvider(): ViewDataProvider { return $this->testVdp; }
}
```

**Final class mocking:** `StripeOrderApiService`, `OrderActionDispatcher`, `CaptureService` are `final`. Use real instances with mocked dependencies, not `createMock()`.

**PHPStan baseline:** `tests/PhpStan/phpstan-baseline.neon` — OXID virtual parent class errors only.
**PHPMD baseline:** `tests/PhpMd/phpmd.baseline.xml` — interface-driven adapter complexity only.

## Configuration

Module settings in `metadata.php` / YAML:
- `sStripeMode` - live/test mode toggle
- `sStripeTestToken`/`sStripeLiveToken` - API secret keys
- `sStripeTestPk`/`sStripeLivePk` - Publishable keys
- `sStripeWebhookEndpointSecret` - Webhook signing secret
- `sStripeCaptureMode` - automatic/manual capture mode
- `blStripeRemoveByBillingCountry` - Filter by billing country
- `blStripeRemoveByBasketCurrency` - Filter by basket currency

**Webhook URL:** `https://{shopUrl}/index.php?cl=StripeWebhookController`

## Event System

### Stripe-Specific Events (`src/Stripe/EventSystem/Event/`)
- `StripeCheckoutSessionRequestEvent` - Initiates Stripe Checkout
- `StripeCheckoutReturnEvent` - Customer returns from Stripe
- `StripeCaptureRequestEvent` - Admin capture action (supports partial via `?float amount`)
- `StripeRefundRequestEvent` - Admin refund action (supports partial via `?float amount`)
- `StripeCancelAuthorizationRequestEvent` - Admin cancel authorization
- `StripePaymentExecuteEvent` - Payment execution
- `StripePaymentReturnEvent` - Payment return
- `Stripe3DSRequiredEvent` - 3D Secure required

### Contract Events (from payment-component)
- `ContractCreatedEvent`, `ContractTransitionedToPendingEvent`
- `ContractReadyToCommitEvent`, `ContractCommittedEvent`, `ContractFulfilledEvent`
- `ContractConditionFulfilledEvent`, `ContractCancelledEvent`, `ContractExpiredEvent`

### Payment Events (from payment-component)
- `PaymentAuthorizedEvent`, `PaymentCapturedEvent`
- `PaymentRefundedEvent`, `PaymentFailedEvent`
- `OrderCreatedEvent`, `OrderCompletedEvent`

Handlers are registered via `payment.event_handler` tag in `services.yaml` and auto-collected by `EventListenerProvider`.

## OXID 7.4 Specifics

- **Admin template blocks** (`oxtplblocks`): Not confirmed working for admin Twig templates in this OXID version. Use module-owned templates (Stripe tab) for custom UI.
- **Controller routing:** `?cl=ControllerKey` where key is from `metadata.php` `controllers` array.
- **Class extensions:** Registered in `metadata.php` `extend` array, resolved via virtual parent classes (`Order_parent`).
- **DI container:** `services.yaml` (1039 lines), cleared via `oe:cache:clear`.
- **Twig template cache:** Must be cleared manually (`rm -rf source/tmp/*`) after template changes.
