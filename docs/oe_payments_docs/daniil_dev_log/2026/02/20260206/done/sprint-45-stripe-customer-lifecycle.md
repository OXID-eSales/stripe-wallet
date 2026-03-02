# Sprint 45: Stripe Customer Lifecycle — Email Prefill + Saved Cards

**Date:** 2026-02-06
**Status:** READY FOR IMPLEMENTATION
**Prerequisites:** Sprint 42 (idempotency) completed
**Branch:** `b-7.4.x`

---

## Goal

Pass a Stripe Customer ID (`customer` param) to Checkout Sessions so the checkout page shows:
- Prefilled email (read-only)
- Saved payment methods from previous purchases
- Prefilled billing address

This requires a full Customer lifecycle: create on first checkout, reuse on subsequent checkouts, persist the mapping in `oe_payments_customer`.

---

## What Exists (Infrastructure)

| Component | Status |
|-----------|--------|
| `oe_payments_customer` DB table | Created by migration, **unused** |
| `OXPAYMENTCUSTOMERID` column (VARCHAR 128) | For `cus_xxx` Stripe ID |
| `OXUSERID` column (UNIQUE) | 1:1 link to oxuser |
| `blStripeProvideCustomerEmailAddress` setting | Defined in metadata.php, **unused** |
| `shouldProvideCustomerEmail()` config accessor | Exists in ModuleConfigurationService |
| Admin translations (EN/DE) | Exist for the setting |
| `user` object in EventContext | Already passed by controller |
| Adapter vaulting methods | `createPaymentMethod()`, `listPaymentMethods()`, `deletePaymentMethod()` exist |
| `CreatePaymentRequest.customerId` | Parameter exists, passed to Stripe PaymentIntent |

| Component | Status |
|-----------|--------|
| PaymentCustomer model | **Does not exist** (removed Sprint 7) |
| PaymentCustomerRepository | **Does not exist** (removed Sprint 7) |
| Stripe Customer CRUD on adapter | **Does not exist** |
| `customer` param in checkout session | **Not passed** |
| `saved_payment_method_options` | **Not passed** |

---

## Architecture

### Customer Resolution Flow

```
User clicks "Place Order"
  │
  ├─ Controller: puts user in EventContext
  │
  ├─ StripeCheckoutSessionHandler:
  │     1. Extract OXID userId from context
  │     2. Call CustomerService.resolveStripeCustomerId(userId)
  │     3. Pass customerId to CheckoutSessionService
  │
  ├─ StripeCustomerService.resolveStripeCustomerId():
  │     1. customerRepo.findByUserId(userId)
  │     2. If found → return OXPAYMENTCUSTOMERID
  │     3. If not found:
  │        a. Get user email + name from OXID
  │        b. adapter.createStripeCustomer(email, name, metadata)
  │        c. Create PaymentCustomer record
  │        d. customerRepo.save(record)
  │        e. Return new cus_xxx ID
  │
  └─ CheckoutSessionService.createSession():
        params['customer'] = $customerId
        params['saved_payment_method_options'] = [
            'payment_method_save' => 'enabled'
        ]
```

### Layer Ownership

```
payment-component (provider-agnostic):
  ├── Contract/PaymentCustomer.php          — Entity model
  ├── Repository/PaymentCustomerRepositoryInterface.php
  └── Repository/DoctrinePaymentCustomerRepository.php

stripe (provider-specific):
  ├── Adapter/StripeAdapterInterface.php    — Add createStripeCustomer(), retrieveStripeCustomer()
  ├── Adapter/StripeAdapter.php             — Implement Stripe SDK calls
  ├── Adapter/IdempotentStripeAdapter.php   — Delegate new methods
  ├── Adapter/LazyStripeAdapter.php         — Delegate new methods
  ├── Service/StripeCustomerServiceInterface.php
  ├── Service/StripeCustomerService.php     — Resolve/create logic
  ├── EventSystem/Handler/StripeCheckoutSessionHandler.php — Wire customer
  └── Service/CheckoutSessionService.php    — Accept customerId param
```

---

## Step 0: PaymentCustomer Model (payment-component)

**New file:** `payment-component/src/Contract/PaymentCustomer.php`

Follows IdempotencyRecord pattern: extends `AbstractModel implements ModelInterface`.

```php
class PaymentCustomer extends AbstractModel implements ModelInterface
{
    private string $userId;
    private ?string $paymentCustomerId;    // cus_xxx
    private ?string $defaultPaymentMethod; // pm_xxx
    private ?string $savedPaymentMethods;  // JSON
    private bool $billingAgreement;
    private ?DateTimeImmutable $lastPaymentDate;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    // Constructor: id, userId, createdAt, updatedAt
    // Getters/setters for all fields
    // toArray() / fromArray()
}
```

Maps to `oe_payments_customer`:
| Property | Column |
|----------|--------|
| `$id` (parent) | `OXID` |
| `$userId` | `OXUSERID` |
| `$paymentCustomerId` | `OXPAYMENTCUSTOMERID` |
| `$defaultPaymentMethod` | `OXDEFAULTPAYMENTMETHOD` |
| `$savedPaymentMethods` | `OXSAVEDPAYMENTMETHODS` |
| `$billingAgreement` | `OXBILLINGAGREEMENT` |
| `$lastPaymentDate` | `OXLASTPAYMENTDATE` |
| `$createdAt` | `OXCREATED` |
| `$updatedAt` | `OXUPDATED` |

**Test:** `payment-component/tests/Unit/Contract/PaymentCustomerTest.php`
- Construction, getters, setters, toArray(), fromArray()

---

## Step 1: PaymentCustomerRepository (payment-component)

**New file:** `payment-component/src/Repository/PaymentCustomerRepositoryInterface.php`

```php
interface PaymentCustomerRepositoryInterface
{
    public function save(PaymentCustomer $customer): void;
    public function findByUserId(string $userId): ?PaymentCustomer;
    public function findByPaymentCustomerId(string $paymentCustomerId): ?PaymentCustomer;
}
```

**New file:** `payment-component/src/Repository/DoctrinePaymentCustomerRepository.php`

Follows DoctrineIdempotencyRepository pattern:
- `save()`: check exists by OXID → update or insert
- `findByUserId()`: SELECT WHERE OXUSERID = :userId
- `findByPaymentCustomerId()`: SELECT WHERE OXPAYMENTCUSTOMERID = :id
- `prepareData()` / `hydrateRecord()` private helpers

**Test:** `payment-component/tests/Unit/Repository/DoctrinePaymentCustomerRepositoryTest.php`
- save insert, save update, findByUserId found/not found, findByPaymentCustomerId

---

## Step 2: Stripe Customer Methods on Adapter

**Modify:** `stripe/src/Stripe/Adapter/StripeAdapterInterface.php`

Add two Stripe-specific methods:

```php
/**
 * Create a Stripe Customer object.
 *
 * @param array<string, mixed> $params Customer creation params (email, name, metadata, etc.)
 * @return \Stripe\Customer Created customer
 */
public function createStripeCustomer(array $params): \Stripe\Customer;

/**
 * Retrieve a Stripe Customer object.
 *
 * @param string $customerId Stripe Customer ID (cus_xxx)
 * @return \Stripe\Customer Retrieved customer
 */
public function retrieveStripeCustomer(string $customerId): \Stripe\Customer;
```

**Modify:** `stripe/src/Stripe/Adapter/StripeAdapter.php`

Implement both methods:

```php
public function createStripeCustomer(array $params): \Stripe\Customer
{
    return $this->stripeClient->customers->create($params);
}

public function retrieveStripeCustomer(string $customerId): \Stripe\Customer
{
    return $this->stripeClient->customers->retrieve($customerId, []);
}
```

**Modify:** `stripe/src/Stripe/Adapter/IdempotentStripeAdapter.php` — delegate both methods to `$this->inner`
**Modify:** `stripe/src/Stripe/Adapter/LazyStripeAdapter.php` — delegate both methods

**Tests:** Update existing adapter unit tests to cover delegation.

---

## Step 3: StripeCustomerService

**New file:** `stripe/src/Stripe/Service/StripeCustomerServiceInterface.php`

```php
interface StripeCustomerServiceInterface
{
    /**
     * Resolve or create a Stripe Customer for the given OXID user.
     *
     * Returns the Stripe Customer ID (cus_xxx).
     * Creates a new Stripe Customer + DB record if none exists.
     */
    public function resolveStripeCustomerId(string $userId, string $email, string $name): string;
}
```

**New file:** `stripe/src/Stripe/Service/StripeCustomerService.php`

```php
class StripeCustomerService implements StripeCustomerServiceInterface
{
    public function __construct(
        private readonly PaymentCustomerRepositoryInterface $customerRepository,
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly LoggerInterface $logger
    ) {}

    public function resolveStripeCustomerId(string $userId, string $email, string $name): string
    {
        // 1. Look up existing mapping
        $existing = $this->customerRepository->findByUserId($userId);
        if ($existing !== null && $existing->getPaymentCustomerId() !== null) {
            return $existing->getPaymentCustomerId();
        }

        // 2. Create Stripe Customer
        $adapter = $this->adapterFactory->getStripeAdapter();
        $stripeCustomer = $adapter->createStripeCustomer([
            'email' => $email,
            'name' => $name,
            'metadata' => ['oxid_user_id' => $userId],
        ]);

        // 3. Persist mapping
        $now = new DateTimeImmutable();
        if ($existing !== null) {
            // Record exists but has no payment customer ID — update it
            $existing->setPaymentCustomerId($stripeCustomer->id);
            $existing->setUpdatedAt($now);
            $this->customerRepository->save($existing);
        } else {
            // Create new record
            $record = new PaymentCustomer(
                bin2hex(random_bytes(16)),
                $userId,
                $now,
                $now
            );
            $record->setPaymentCustomerId($stripeCustomer->id);
            $this->customerRepository->save($record);
        }

        return $stripeCustomer->id;
    }
}
```

**Test:** `stripe/tests/Unit/Stripe/Service/StripeCustomerServiceTest.php`
- Returns existing customer ID when found
- Creates Stripe Customer + DB record when not found
- Updates existing record when it has no payment customer ID
- Logs appropriately

---

## Step 4: Wire Customer into Checkout Session

### 4a. Modify CheckoutSessionServiceInterface + CheckoutSessionService

Add `?string $stripeCustomerId = null` parameter to `createSession()`.

```php
public function createSession(
    string $contractId,
    BasketSnapshot $basketSnapshot,
    string $successUrl,
    string $cancelUrl,
    string $shopId = '1',
    string $captureMode = 'automatic',
    ?string $orderId = null,
    ?string $orderNumber = null,
    ?string $stripeCustomerId = null   // NEW
): CheckoutSessionResult;
```

In `CheckoutSessionService::createSession()`, after building `$params`:

```php
if ($stripeCustomerId !== null) {
    $params['customer'] = $stripeCustomerId;
    $params['saved_payment_method_options'] = [
        'payment_method_save' => 'enabled',
    ];
}
```

### 4b. Modify StripeCheckoutSessionHandler

Inject `StripeCustomerServiceInterface` and `ModuleConfigurationService`.

Before calling `createSession()`:

```php
$stripeCustomerId = null;

if ($this->config->shouldProvideCustomerEmail()) {
    $user = $context->getUser();
    if ($user !== null) {
        $email = $user->getFieldData('oxusername');
        $firstName = $user->getFieldData('oxfname') ?? '';
        $lastName = $user->getFieldData('oxlname') ?? '';
        $name = trim($firstName . ' ' . $lastName);
        $userId = $context->get('userId', '');

        if (is_string($email) && $email !== '' && is_string($userId) && $userId !== '') {
            $stripeCustomerId = $this->customerService->resolveStripeCustomerId($userId, $email, $name);
        }
    }
}
```

Pass `$stripeCustomerId` to `createSession()`.

### 4c. Update services.yaml

```yaml
# Payment Customer Repository
OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface:
  class: OxidEsales\PaymentComponent\Repository\DoctrinePaymentCustomerRepository
  arguments:
    $connection: '@doctrine.dbal.connection'
  public: false

# Stripe Customer Service
OxidEsales\Payments\Stripe\Service\StripeCustomerServiceInterface:
  class: OxidEsales\Payments\Stripe\Service\StripeCustomerService
  arguments:
    $customerRepository: '@OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface'
    $adapterFactory: '@OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface'
    $logger: '@oxid_esales.monolog.logger'
  public: false
```

Update `StripeCheckoutSessionHandler` DI to add `$customerService` and `$config`.

---

## Step 5: Integration Tests

**New file:** `stripe/tests/Integration/Repository/DoctrinePaymentCustomerRepositoryTest.php`
- Real DB: save → findByUserId round-trip
- Real DB: findByPaymentCustomerId
- Real DB: unique constraint on OXUSERID
- Real DB: update existing record

**New file:** `stripe/tests/Integration/Stripe/Service/StripeCustomerServiceTest.php`
- Real DB + mocked adapter: resolveStripeCustomerId creates record on first call
- Real DB + mocked adapter: resolveStripeCustomerId returns cached ID on second call
- Real DB + mocked adapter: adapter only called once for same user

---

## Step 6: Validation

```bash
./bin/pre-commit-check.sh --full
```

Expected: ALL CHECKS PASSED (880+ tests)

---

## Files Summary

| File | Action | Package |
|------|--------|---------|
| `payment-component/src/Contract/PaymentCustomer.php` | CREATE | payment-component |
| `payment-component/src/Repository/PaymentCustomerRepositoryInterface.php` | CREATE | payment-component |
| `payment-component/src/Repository/DoctrinePaymentCustomerRepository.php` | CREATE | payment-component |
| `stripe/src/Stripe/Service/StripeCustomerServiceInterface.php` | CREATE | stripe |
| `stripe/src/Stripe/Service/StripeCustomerService.php` | CREATE | stripe |
| `stripe/src/Stripe/Adapter/StripeAdapterInterface.php` | MODIFY | stripe |
| `stripe/src/Stripe/Adapter/StripeAdapter.php` | MODIFY | stripe |
| `stripe/src/Stripe/Adapter/IdempotentStripeAdapter.php` | MODIFY | stripe |
| `stripe/src/Stripe/Adapter/LazyStripeAdapter.php` | MODIFY | stripe |
| `stripe/src/Stripe/Service/CheckoutSessionServiceInterface.php` | MODIFY | stripe |
| `stripe/src/Stripe/Service/CheckoutSessionService.php` | MODIFY | stripe |
| `stripe/src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` | MODIFY | stripe |
| `stripe/services.yaml` | MODIFY | stripe |

### Test Files

| File | Action | Tests |
|------|--------|-------|
| `payment-component/tests/Unit/Contract/PaymentCustomerTest.php` | CREATE | ~8 |
| `payment-component/tests/Unit/Repository/DoctrinePaymentCustomerRepositoryTest.php` | CREATE | ~6 |
| `stripe/tests/Unit/Stripe/Service/StripeCustomerServiceTest.php` | CREATE | ~5 |
| `stripe/tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php` | MODIFY | +2 |
| `stripe/tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php` | MODIFY | +3 |
| `stripe/tests/Integration/Repository/DoctrinePaymentCustomerRepositoryTest.php` | CREATE | ~5 |
| `stripe/tests/Integration/Stripe/Service/StripeCustomerServiceTest.php` | CREATE | ~4 |

---

## Stripe API Caveats

1. **`customer` and `customer_email` are mutually exclusive** — never pass both. We use `customer` only.
2. **Email is read-only** when Customer has email on file — customer cannot change it on Stripe page.
3. **Saved cards require `allow_redisplay: always`** — `saved_payment_method_options.payment_method_save: 'enabled'` shows a "Save for future" checkbox. If checked, card gets `allow_redisplay: always` and appears in future sessions.
4. **Pre-filled card data expires in 30 minutes** — only affects saved card display, not email.
5. **Max 50 saved cards** displayed per customer.
6. **Feature is gated** by `blStripeProvideCustomerEmailAddress` admin setting (default: OFF).

---

## Principles

| Principle | Application |
|-----------|-------------|
| TDD | Write failing tests first for each step |
| SRP | StripeCustomerService handles only customer resolution; CheckoutSessionService only builds params |
| OCP | Adding customer to checkout flow extends handler without modifying core session creation logic |
| LSP | PaymentCustomer extends AbstractModel; StripeCustomerService implements interface |
| ISP | PaymentCustomerRepositoryInterface has exactly 3 methods (save, findByUserId, findByPaymentCustomerId) |
| DIP | Handler depends on StripeCustomerServiceInterface abstraction, not concrete |
| DRY | Single resolveStripeCustomerId() method used by all checkout paths |

---

## Risks

| Risk | Mitigation |
|------|------------|
| Stripe API failure during customer creation | Try/catch in resolveStripeCustomerId — fall back to no customer (session works without it) |
| Duplicate Stripe Customers for same OXID user | UNIQUE index on OXUSERID prevents DB duplicates; service checks before creating |
| Stale customer data (email changed in OXID) | Future: update Stripe Customer on OXID user profile change (out of scope) |
| Performance (+1 DB query + optional API call per checkout) | DB query is indexed (UK_USER); API call only on first checkout per user |
