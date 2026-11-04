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

**Unit Tests, standalone (no shop, no database — what CI runs):**
```bash
composer install                       # inside the module
vendor/bin/phpunit -c tests/phpunit-unit.xml
```
`tests/bootstrap-unit.php` supplies what only an activated module in a booted shop would
otherwise provide: the `*_parent` classes, the shop's global functions and an in-memory `Config`.
Add a stub there when a new extension class or shop seam enters the unit suite.

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
├── Adapter/              # StripeAdapter (+ sub-interfaces), StripeClientFactory, Dto/, Helper/
├── Admin/                # Stripe's panel in payment-base's admin Payment tab (panel provider, money gateway, history)
├── ClientConfig/         # Config the OPC / headless clients read
├── Command/              # Backfill, idempotency prune, stale-checkout sweep
├── Component/Widget/     # StripeCheckoutFooter
├── Controller/
│   ├── Admin/            # ModuleConfiguration (extension), StripeConnect, OrderRefundViewDataProvider
│   ├── Webhook/          # WebhookController (guards come from payment-base)
│   ├── PaymentController.php, StripeOrderController.php, ControllerRequestHelper.php
├── Core/                 # StripeDefinitions, ViewConfig, Events (activate/deactivate), WebhookEndpointKeys
├── EventSystem/          # Event/ (Stripe requests, URL-build events), Handler/ (contract creation, session, capture, refund, cancel)
├── GraphQL/              # Storefront checkout mutations and the admin API (both optional packages)
├── Logging/, Mcp/, Model/ # Throwable summaries / PII keys, MCP-ACP checkout, Order + Payment extensions
├── PaymentHandler/       # StripePaymentHandler (payment-base handler), HeadlessCheckoutContext
├── Service/              # Capture, Refund, Checkout session/return, customer, in-flight guard, StripePaidStatusProvider, ...
│   ├── Factory/          # StripeAdapterFactory, file logger factories
│   └── Result/           # Checkout results
└── Webhook/              # StripeWebhookProcessor, event parser, Handler/ (one class per Stripe event type)
```

**Note:** Everything provider-agnostic lives in payment-base (`oxid-esales/payment-base`): contracts, repositories, event
dispatcher, the shop/session adapters, user-data validation, OXPAID reconciliation (`oe:payments:reconcile-oxpaid`),
contract termination, the module-configuration base class. Stripe uses them through dependency injection; it keeps no copy.

### Admin Panel (Stripe Tab)

Stripe's panel (`Admin/StripePaymentPanelProvider`) renders in payment-base's order Payment tab:
- **Payment details** - contract, order, payment type, transaction id (links to the Stripe Dashboard), captured and refunded amounts
- **Transaction history** - built from the Stripe API (`StripeTransactionHistoryBuilder`), so it covers Dashboard actions too
- **Capture / refund / cancel authorization** - partial amounts allowed; run through payment-base's money runner via `StripeMoneyGateway`
- **OXPAID reconciliation** - `oe:payments:reconcile-oxpaid --provider=stripe` (payment-base) asks `StripePaidStatusProvider`

Display comes from the Stripe API; the audit log is `oe_payments_transaction`, written on authorization, capture and refund.

### Key Domain Models

**PaymentContract (Aggregate Root):** (from payment-base)
- States: `DRAFT`, `NOT_FINISHED`, `PENDING`, `AUTHORIZED`, `READY_TO_COMMIT`, `COMMITTED`, `FULFILLED`, `CANCELLED`, `EXPIRED`, `FAILED`
- Manages conditions: `payment_authorized`, `fraud_check`, `stock_reserved`
- Links to oxorder only after commitment (OXORDERID NULL until committed)

**BasketSnapshot (Value Object):** (from payment-base)
- Immutable copy of basket at contract creation
- Stored as JSON in `oe_payments_contract.OXBASKETDATA`

### Database Schema

**Important:** All database tables are created by payment-base. Stripe has NO migrations.

Tables (created by payment-base):
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

**Test Structure (AAA):** Arrange-Act-Assert

**Testable Subclass Pattern:** OXID admin controllers don't support constructor DI. Use testable subclasses:
```php
final class TestableStripeCheckoutFooter extends StripeCheckoutFooter {
    public function __construct(private readonly array $viewParams, private readonly ModuleConfigurationServiceInterface $config) {
        // Skip OXID bootstrap
    }
    public function getViewParameter($name): mixed { return $this->viewParams[$name] ?? null; }
    protected function getServiceFromContainer(string $serviceName): object { return $this->config; }
}
```

**Required collaborators:** no `= null` constructor defaults, no `?? new X()`; `tests/Unit/Stripe/Smoke/RequiredCollaboratorsTest.php` scans `src/` and holds the (short, justified) allow-list.

**PHPStan baseline:** `tests/PhpStan/phpstan-baseline.neon` — OXID virtual parent classes and untyped OXID return values.
**PHPMD baseline:** `tests/PhpMd/phpmd.baseline.xml` — interface-driven adapter complexity only.

## Configuration

Module settings in `metadata.php` / YAML:
- `sStripeMode` - live/test mode toggle
- `sStripeTestToken`/`sStripeLiveToken` - API secret keys
- `sStripeTestPk`/`sStripeLivePk` - Publishable keys
- Webhook signing secret and endpoint id: per mode in the module's oxconfig variables (`Core/WebhookEndpointKeys`); `sStripeWebhookEndpointSecret` is the legacy, mode-agnostic setting
- `sStripeCaptureMode` - automatic/manual capture mode

**Webhook URL:** `https://{shopUrl}/index.php?cl=StripeWebhookController`

## Event System

### Stripe-Specific Events (`src/Stripe/EventSystem/Event/`)
- `StripeCheckoutSessionRequestEvent` - Initiates Stripe Checkout
- `StripeSuccessUrlBuildEvent`, `StripeCancelUrlBuildEvent` - Let handlers (OPC modal) rewrite the return URLs
- `StripeCaptureRequestEvent` - Admin capture action (supports partial via `?float amount`)
- `StripeRefundRequestEvent` - Admin refund action (supports partial via `?float amount`)
- `StripeCancelAuthorizationRequestEvent` - Admin cancel authorization

### Contract Events (from payment-base)
- `ContractCreatedEvent`, `ContractTransitionedToPendingEvent`
- `ContractReadyToCommitEvent`, `ContractCommittedEvent`, `ContractFulfilledEvent`
- `ContractConditionFulfilledEvent`, `ContractCancelledEvent`, `ContractExpiredEvent`

### Payment Events (from payment-base)
- `PaymentAuthorizedEvent`, `PaymentCapturedEvent`
- `PaymentRefundedEvent`, `PaymentFailedEvent`
- `OrderCreatedEvent`, `OrderCompletedEvent`

Handlers are registered via `payment.event_handler` tag in `services.yaml` and auto-collected by `EventListenerProvider`.

## OXID 7.4 Specifics

- **Admin template blocks** (`oxtplblocks`): Not confirmed working for admin Twig templates in this OXID version. Use module-owned templates (Stripe tab) for custom UI.
- **Controller routing:** `?cl=ControllerKey` where key is from `metadata.php` `controllers` array.
- **Class extensions:** Registered in `metadata.php` `extend` array, resolved via virtual parent classes (`Order_parent`).
- **DI container:** `services.yaml`, cleared via `oe:cache:clear`.
- **Twig template cache:** Must be cleared manually (`rm -rf source/tmp/*`) after template changes.

## Dead-code gate

`composer dead-code` runs shipmonk/dead-code-detector over `src/` (`phpstan-dead-code.neon`; payment-base is scanned so the
interface methods it calls count as used) and `composer dead-assets` checks language keys, templates and assets. CI runs both
(job `dead-code`). A finding is fixed by deleting the code. The framework callers the detector cannot see (GraphQLite
attributes, metadata.php, the shop's class extensions, Twig, the service container, payment-base's duck typing) are named in
`tests/PhpStan/DeadCode/OxidEntryPointUsageProvider.php`. What was kept knowingly is in `tests/PhpStan/dead-code-baseline-*.neon`
(owner decision / consumer API / unreviewed backlog); nothing may be added there, only removed.
