# PlantUML Diagrams: Stripe Payment Component Architecture

This directory contains PlantUML diagrams documenting the Stripe Payment Component architecture, from button click to thank you page.

## How to View Diagrams

### Option 1: PlantUML Server (Recommended)
Visit [plantuml.com/plantuml](http://www.plantuml.com/plantuml/uml/) and paste the `.puml` file contents.

### Option 2: VS Code Extension
Install the "PlantUML" extension and preview diagrams directly in VS Code.

### Option 3: Local PlantUML
```bash
# Install PlantUML
brew install plantuml  # macOS
apt install plantuml   # Ubuntu

# Generate PNG
plantuml filename.puml
```

### Option 4: Docker
```bash
docker run -v $(pwd):/data plantuml/plantuml:latest /data/*.puml
```

---

## Diagram Index

### 1. Complete Checkout Sequence (`01-complete-checkout-sequence.puml`)
**Full sequence diagram** showing the entire flow from button click to thank you page.

**Key elements:**
- Customer interaction with browser
- Controller → EventDispatcher → Handlers chain
- Contract creation and persistence
- Stripe API calls
- Order finalization
- Address hash restoration (bug fix)

---

### 2. Contract State Machine (`02-contract-state-machine.puml`)
**State diagram** for PaymentContract lifecycle.

**States:**
- `DRAFT` → Initial state when contract created
- `PENDING` → Awaiting condition fulfillment
- `READY_TO_COMMIT` → All conditions met, ready for order
- `COMMITTED` → Order created
- `FULFILLED` → Terminal success state
- `CANCELLED`, `EXPIRED`, `FAILED` → Terminal error states

---

### 3. Class Hierarchy (`03-class-hierarchy.puml`)
**Class diagram** showing inheritance and interfaces.

**Key hierarchies:**
- OXID Controllers: `FrontendController` → `OrderController` → `StripeOrderController`
- OXID Models: `Order` → `Stripe\Model\Order` (chain extension)
- Handler Interface implementations
- Event Interface implementations
- Contract domain classes

---

### 4. Event Flow (`04-event-flow.puml`)
**Activity diagram** with swimlanes showing event dispatch flow.

**Swimlanes:**
- Controller layer
- EventDispatcher
- Handlers (by priority)
- External systems

---

### 5. Dependency Injection (`05-dependency-injection.puml`)
**Component diagram** showing Symfony DI container configuration.

**Key concepts:**
- Interface → Implementation bindings
- Tagged handler collection (`payment.handler` tag)
- Service dependencies
- Factory pattern for Stripe client

---

### 6. Data Flow Waterfall (`06-data-flow-waterfall.puml`)
**Detailed sequence diagram** with data payloads.

**Shows:**
- HTTP request/response data
- EventContext contents at each stage
- Contract metadata storage
- Stripe API request/response bodies
- Database operations

---

### 7. Address Validation Bug Fix (`07-address-validation-fix.puml`)
**Activity diagram** documenting the two-part bug fix.

**Problem:** OXID reads address hash from REQUEST, but Stripe returns via GET redirect (no form data).

**Solution:**
1. Store hash in contract metadata before Stripe redirect
2. Fix metadata hydration in DoctrineContractRepository
3. Restore hash from contract to session after return
4. Override `validateDeliveryAddress()` in Stripe Order model

---

### 8. Condition Fulfillment System (`08-condition-fulfillment.puml`)
**Class + Sequence diagrams** for contract conditions.

**Shows:**
- ContractCondition types (payment_authorized, fraud_check)
- Fulfillment flow
- Auto-transition logic when all conditions met

---

### 9. Test Architecture (`09-test-architecture.puml`)
**Component + Mind map diagrams** for test coverage.

**Test categories:**
- Contract Domain tests
- Repository tests (including MetadataPersistenceTest bug fix)
- Event System tests
- Handler tests
- Model tests (including OrderAddressValidationTest bug fix)
- Controller tests

---

### 10. Component Overview (`10-component-overview.puml`)
**High-level component diagram** showing module architecture.

**Layers:**
- OXID Shop Core (purple)
- Stripe Module (yellow)
- Component Layer - reusable (blue)
- Stripe-Specific - integration (orange)
- External Services (red)

---

### 11. Customer Journey (`11-button-to-thankyou-journey.puml`)
**Customer-focused sequence diagram** with timing.

**Steps:**
1. Click button → JavaScript handles
2. Create checkout session → Contract saved
3. Redirect to Stripe
4. Customer pays
5. Return to shop → Order created
6. Thank you page displayed

---

## Architecture Summary

### Contract-First Pattern
Orders are created **AFTER** payment confirmation, not before. This prevents:
- Abandoned orders in database
- Payment failures after order creation
- Inconsistent state between OXID and Stripe

### Event-Driven Design
All business logic is in event handlers:
- Loose coupling between components
- Easy to add/remove handlers
- Priority-based execution order
- Testable in isolation

### Key Data Flows

```
Button Click
    │
    ▼
StripeCheckoutSessionRequestEvent
    │
    ├─► StripeContractCreationHandler (priority: 100)
    │       Creates PaymentContract (DRAFT)
    │       Stores delivery_address_hash in metadata
    │
    └─► StripeCheckoutSessionHandler (priority: 0)
            Creates Stripe Checkout Session
            References contract_id in metadata
    │
    ▼
[Customer pays on Stripe]
    │
    ▼
StripeCheckoutReturnEvent
    │
    └─► StripeCheckoutReturnHandler
            Verifies payment_status === 'paid'
            Loads contract from DB
            Restores delivery_address_hash to session ⚠️
            Dispatches PaymentAuthorizedEvent
    │
    ▼
PaymentAuthorizedEvent
    │
    └─► PaymentAuthorizedEventHandler
            Fulfills payment_authorized condition
            Contract: DRAFT → PENDING → READY_TO_COMMIT
            Dispatches ContractReadyToCommitEvent
    │
    ▼
ContractReadyToCommitEvent
    │
    └─► StripeOrderCreationHandler
            Creates OXID Order (validateDeliveryAddress passes!)
            Contract: READY_TO_COMMIT → COMMITTED
    │
    ▼
Thank You Page
```

---

## Bug Fix Documentation

### Bug 1: Metadata Not Persisting
- **File:** `DoctrineContractRepository.php`
- **Problem:** `setContractPrivateProperties()` didn't restore metadata
- **Fix:** Added `hydrateContractMetadata()` method

### Bug 2: Address Hash Validation
- **File:** `Stripe/Model/Order.php`
- **Problem:** OXID reads from REQUEST, empty on GET redirect
- **Fix:** Override `validateDeliveryAddress()` to read from SESSION for Stripe payments

See `07-address-validation-fix.puml` for detailed flow.

---

## Files Modified (Summary)

| File | Change |
|------|--------|
| `DoctrineContractRepository.php` | Added `hydrateContractMetadata()` |
| `Stripe/Model/Order.php` | Added `validateDeliveryAddress()` override |
| `StripeContractCreationHandler.php` | Stores address hash in metadata |
| `StripeCheckoutReturnHandler.php` | Restores address hash from contract |
| `phpstan-bootstrap.php` | Added `Order_parent` class alias |
| `phpstan.neon` | Added ignore patterns for OXID chain extension |

---

## Generated: 2025-12-01
