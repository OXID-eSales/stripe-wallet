# Sprint 7: Remove Unused PaymentCustomer Repository

**Date:** 2026-01-20
**Priority:** Low
**Estimated Effort:** 30 minutes
**Risk Level:** Very Low (code is confirmed unused, no DB table exists)

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

Remove the `PaymentCustomerRepository` interface and implementation. These were created for a **customer vaulting feature** (storing payment methods for returning customers) that was **never implemented**.

---

## Evidence of Non-Usage

### 1. No Database Table Exists

The repository references a table that doesn't exist:
- No migration creates `oe_payments_customer` table
- No schema definition exists

### 2. Not Registered in services.yaml

```bash
$ grep -r "PaymentCustomerRepository" stripe/services.yaml
# No matches
```

### 3. Not Referenced Anywhere

```bash
$ grep -r "PaymentCustomerRepository" stripe/src/
# No matches

$ grep -r "PaymentCustomerRepository" payment-component/src/
# Only self-references in the files themselves
```

### 4. Vaulting Feature Not Implemented

The repository was intended to support:
- Storing customer payment methods (cards, bank accounts)
- Retrieving saved payment methods for returning customers
- Managing payment method lifecycle (add, update, delete)

None of this functionality exists in the current implementation.

---

## Files to Remove

### Source Files
```
payment-component/src/Repository/PaymentCustomerRepositoryInterface.php
payment-component/src/Repository/DoctrinePaymentCustomerRepository.php
```

### Test Files (if any)
```
payment-component/tests/Unit/Repository/PaymentCustomerRepositoryTest.php
payment-component/tests/Unit/Repository/DoctrinePaymentCustomerRepositoryTest.php
```

---

## Implementation Steps

### Step 1: Verify No References

```bash
# Double-check no references exist
grep -r "PaymentCustomerRepository" payment-component/
grep -r "PaymentCustomer" payment-component/src/
grep -r "PaymentCustomer" stripe/src/
```

### Step 2: Remove Test Files

```bash
rm payment-component/tests/Unit/Repository/PaymentCustomerRepositoryTest.php 2>/dev/null
rm payment-component/tests/Unit/Repository/DoctrinePaymentCustomerRepositoryTest.php 2>/dev/null
```

### Step 3: Remove Source Files

```bash
rm payment-component/src/Repository/PaymentCustomerRepositoryInterface.php
rm payment-component/src/Repository/DoctrinePaymentCustomerRepository.php
```

### Step 4: Verify

```bash
# Run PHPStan
composer phpstan

# Run tests
composer test-unit

# Check for broken references
grep -r "PaymentCustomerRepository" payment-component/
```

---

## Future Considerations

### If Vaulting Is Needed Later

When implementing customer vaulting:

1. **Create migration** for `oe_payments_customer` table:
   ```sql
   CREATE TABLE oe_payments_customer (
       OXID CHAR(32) NOT NULL PRIMARY KEY,
       OXUSERID CHAR(32) NOT NULL,
       OXPROVIDER VARCHAR(50) NOT NULL,
       OXPROVIDERID VARCHAR(255) NOT NULL,
       OXDEFAULT TINYINT(1) DEFAULT 0,
       OXCREATED DATETIME NOT NULL,
       OXUPDATED DATETIME NULL,

       INDEX idx_user (OXUSERID),
       INDEX idx_provider (OXPROVIDER, OXPROVIDERID),
       FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID)
   );
   ```

2. **Design repository interface** with proper methods:
   ```php
   interface PaymentCustomerRepositoryInterface
   {
       public function findByUserId(string $userId): array;
       public function findByProviderCustomerId(string $provider, string $providerId): ?PaymentCustomer;
       public function save(PaymentCustomer $customer): void;
       public function delete(string $id): void;
       public function setDefault(string $userId, string $customerId): void;
   }
   ```

3. **Implement with TDD** following Sprint 2 pattern

---

## Verification Checklist

- [ ] No references to `PaymentCustomerRepository` in payment-component/src/
- [ ] No references to `PaymentCustomerRepository` in stripe/src/
- [ ] No references in services.yaml
- [ ] PHPStan passes
- [ ] Unit tests pass

---

## Impact Assessment

| Metric | Change |
|--------|--------|
| Files removed | 2-4 (source + tests) |
| Lines of code | ~50-100 |
| Risk | None - code never executed, no DB table |

---

## Post-Removal: Update Sprint 1

After removal, update Sprint 1 to mark this as completed.

---

## References

- Sprint 1: Overall code analysis
- Vaulting architecture: Not documented (feature was planned but not designed)
