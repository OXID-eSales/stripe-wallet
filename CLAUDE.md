# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Stripe Payment Module for OXID eShop 7.4+ implementing a **Smart-Contract Architecture** for payment lifecycle management. Uses Stripe SDK v18+ with Stimulus.js for frontend.

**Module ID:** `osc_stripe_wallet`
**Namespace:** `OxidSolutionCatalysts\Payments\`

## Core Development Principles

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance

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

**Unit Tests (isolated, no shop bootstrap needed):**
```bash
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

**Integration Tests (requires shop bootstrap):**
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php
```

**Single Unit Test File:**
```bash
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Path/To/TestFile.php"
```

**Single Integration Test File:**
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  /var/www/test-module/tests/Integration/Path/To/TestFile.php
```

**E2E Tests (Playwright):**
```bash
cd tests/e2e/playwright
npm install
npx playwright test
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
composer phpstan            # PHPStan static analysis (level 6)
composer phpmd              # PHP Mess Detector
composer style              # All style checks
```

### OXID Module Commands
```bash
bin/oe-console oe:module:install extensions/stripe
bin/oe-console oe:module:activate osc_stripe_wallet
bin/oe-console oe:module:deactivate osc_stripe_wallet
bin/oe-console oe:module:uninstall osc_stripe_wallet
```

## Smart-Contract Architecture

The module implements a **contract-first payment pattern** where clicking "Place Order" creates a contract, not an order. The order is created only when the contract is fulfilled.

**Contract Lifecycle:** `DRAFT → PENDING → COMMITTED → FULFILLED`

**Key Innovation:**
- Traditional: User clicks "Place Order" → Order created → Payment → Order updated
- Smart-Contract: User clicks "Place Order" → Contract created → Conditions resolved → Order created

### Architecture Layers

```
┌─────────────────────────────────────────────────────────────┐
│  PRESENTATION LAYER - Controllers (thin, emit events only)  │
└────────────────────────────┬────────────────────────────────┘
                             │ emits events
┌────────────────────────────▼────────────────────────────────┐
│  EVENT LAYER - Domain Events, EventDispatcher (PSR-14)      │
└────────────────────────────┬────────────────────────────────┘
                             │ triggers
┌────────────────────────────▼────────────────────────────────┐
│  EVENT HANDLERS - Business Logic, Contract Lifecycle        │
└────────────────────────────┬────────────────────────────────┘
                             │ uses
┌────────────────────────────▼────────────────────────────────┐
│  CONTRACT DOMAIN - PaymentContract (Aggregate Root)         │
└────────────────────────────┬────────────────────────────────┘
                             │ uses
┌────────────────────────────▼────────────────────────────────┐
│  SERVICE LAYER - ContractService, PaymentService            │
└────────────────────────────┬────────────────────────────────┘
                             │ uses
┌────────────────────────────▼────────────────────────────────┐
│  SDK-ADAPTER LAYER - PaymentAdapterInterface (provider-agnostic) │
└────────────────────────────┬────────────────────────────────┘
                             │ persists
┌────────────────────────────▼────────────────────────────────┐
│  DATA ACCESS LAYER - Repositories (Doctrine DBAL)           │
└─────────────────────────────────────────────────────────────┘
```

### Source Structure

```
src/
├── Component/          # Provider-agnostic payment components (100% reusable)
│   ├── Adapter/        # PaymentAdapterInterface and DTOs
│   ├── Contract/       # PaymentContract, ContractCondition entities
│   ├── Controller/     # Base webhook controllers
│   ├── EventSystem/    # EventDispatcher, EventListenerProvider
│   ├── Repository/     # ContractRepository, TransactionRepository
│   └── Service/        # CheckoutOrchestrator, ContractService
├── Stripe/             # Stripe-specific implementation (~30% code)
│   ├── Adapter/        # StripeAdapter implements PaymentAdapterInterface
│   ├── Controller/     # Stripe webhook/payment controllers
│   ├── Handler/        # Stripe webhook event handlers
│   ├── Repository/     # Stripe-specific repositories
│   └── Service/        # StripePaymentService, StripeCheckoutService
└── Watch/              # PaymentWatch monitoring subsystem
```

### Key Domain Models

**PaymentContract (Aggregate Root):**
- States: `DRAFT`, `PENDING`, `READY_TO_COMMIT`, `COMMITTED`, `FULFILLED`, `CANCELLED`, `EXPIRED`
- Manages conditions: `payment_authorized`, `fraud_check`, `stock_reserved`
- Links to oxorder only after commitment (OXORDERID NULL until committed)

**BasketSnapshot (Value Object):**
- Immutable copy of basket at contract creation
- Stored as JSON in `osc_payment_contract.OXBASKETDATA`

### Database Schema

Component tables with FK references to OXID core (NO ALTER TABLE on core):
- `osc_payment_contract` - Contract lifecycle, basket snapshot, conditions
- `osc_payment_transaction` - Transaction tracking with OXCONTRACTID FK
- `osc_payment_order_state` - Payment state with OXCONTRACTID FK

## Documentation

Key architecture documents in `docs/payment-component/`:
- `00-overview.md` - Smart-contract architecture overview
- `01-architecture-layers.md` - Event-driven layer architecture
- `02-database-and-models.md` - Contract-aware database schema
- `03-building-payment-modules.md` - How to build provider modules
- `04-sdk-adapter-layer.md` - Provider abstraction architecture
- `05-webhooks.md` - Webhook processing with contract integration

Development history in `docs/payment-component/dev_history/`

## Code Style Rules

- **No else expressions** - Use early returns
- **Explicit imports** - No inline `\Exception`, use `use` statements
- **Null safety** - Check for null before using nullable values
- **Small methods** - Target 15-25 lines, extract helpers for long methods
- **PHPStan annotations** - Use `@phpstan-ignore-next-line` for safe database type casts

## Testing Strategy

**Test Structure (AAA):** Arrange-Act-Assert

**Unit Tests:** Pure domain logic, no database required
```php
$contract = new PaymentContract($shopId, $userId, $basketSnapshot);
$contract->addCondition(new ContractCondition('payment_authorized'));
$contract->fulfillCondition('payment_authorized');
$this->assertTrue($contract->areAllConditionsFulfilled());
```

**Integration Tests:** Use `e2e_` prefix for test data that persists for inspection.

## Configuration

Module settings in `metadata.php`:
- `sStripeMode` - live/test mode toggle
- `sStripeTestToken`/`sStripeLiveToken` - API secret keys
- `sStripeTestPk`/`sStripeLivePk` - Publishable keys
- `sStripeWebhookEndpointSecret` - Webhook signing secret
