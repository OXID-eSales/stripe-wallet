# TDD Strategy for Event-Driven Payment Component - Part 1 of 8: Overview

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Status:** Implementation Guide
**Visual Diagram:** [puml/10-tdd-strategy.puml](puml/10-tdd-strategy.puml)
**Test Organization:** [09-test-organization.md](09-test-organization.md) - Component vs Provider Test Split

**Part of Series:**
- **Part 1** (This document): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

## Important: Test Organization

This TDD strategy document covers testing approaches for both **component tests** (provider-agnostic) and **provider tests** (provider-specific SDK integration). For detailed information about test separation, directory structures, and testing boundaries, see:

**[09-test-organization.md](09-test-organization.md) - Test Organization: Component vs Provider Tests**

**Key Principles:**
- **Component Tests**: Mock `PaymentAdapterInterface`, test business logic without provider SDK dependencies
- **Provider Tests**: Mock or use real provider SDKs, test adapter implementations
- **Separate Test Suites**: Independent execution with different coverage requirements
- **Component Coverage**: 95%+ (fast tests, no external dependencies)
- **Provider Coverage**: 90%+ (slower tests, real SDK integration)

---

## Table of Contents

1. [🔴 Critical Priority Blocks](#-critical-priority-blocks)
2. [Development Priority Matrix](#development-priority-matrix)
3. [Overview](#overview)
4. [Test Pyramid Strategy](#test-pyramid-strategy)
5. [Unit Tests (60%)](#unit-tests-60)
6. [Integration Tests (30%)](#integration-tests-30)
7. [E2E Tests (10%)](#e2e-tests-10)
8. [Test Data & Fixtures](#test-data--fixtures)
9. [Mocking Strategy](#mocking-strategy)
10. [Coverage Goals](#coverage-goals)
11. [CI/CD Pipeline](#cicd-pipeline)
12. [Best Practices](#best-practices)

---

## 🔴 Critical Priority Blocks

### Priority Classification

**🔴 CRITICAL (P0)** - Must implement FIRST. Security, money handling, data integrity
**🟠 HIGH (P1)** - Core business logic. Required for system functionality
**🟡 MEDIUM (P2)** - Important features. Enhance reliability and user experience
**🟢 LOW (P3)** - Nice to have. Can be implemented later

---

## Development Priority Matrix

### Block 1: Payment Security & Money Handling 🔴 CRITICAL (P0)

**Why Critical:** Direct impact on financial transactions, PCI compliance, fraud prevention

#### 1.1 Transaction Integrity (P0-A)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration + E2E
- **Components:**
  - `PaymentTransaction` model - Track all money movements
  - `PaymentService::trackTransaction()` - Persist transactions atomically
  - `PaymentService::capturePayment()` - Ensure money capture
  - `PaymentService::refundPayment()` - Handle refunds correctly
  - Amount calculations and currency conversions

**Critical Test Scenarios:**
```php
// tests/Component/Unit/Service/PaymentService_Transaction_CRITICAL_Test.php

✅ testTransactionAtomicity_NoPartialCaptures()
✅ testAmountPrecision_NoCentsLost()
✅ testDoubleCaptureViaIdempotencyKey_OnlyOneCharge()
✅ testRefundExceedsCapturedAmount_MustFail()
✅ testConcurrentCaptures_OnlyOneSucceeds()
✅ testTransactionRollback_OnProviderFailure()
✅ testCurrencyConversion_NoRoundingErrors()
```

**Implementation Order:**
1. Write tests for double-capture prevention (idempotency)
2. Implement transaction tracking with database constraints
3. Test atomic transaction rollback on errors
4. Implement amount validation (refunds ≤ captured amount)
5. Test concurrent access scenarios
6. Implement currency precision (no rounding errors)

---

#### 1.2 Idempotency System (P0-B)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration + E2E
- **Components:**
  - Idempotency key validation
  - Duplicate request detection
  - State consistency checks

**Critical Test Scenarios:**
```php
✅ testSameIdempotencyKey_ReturnsCachedResult()
✅ testNetworkRetry_NoDoubleCharge()
✅ testWebhookRedelivery_ProcessedOnce()
✅ testIdempotencyKeyExpiration_After24Hours()
✅ testConcurrentRequestsSameKey_OnlyOneProcessed()
```

**Implementation Order:**
1. Add `idempotency_key` column to `osc_transaction` table
2. Write tests for duplicate detection
3. Implement unique constraint on (order_id, idempotency_key, transaction_type)
4. Test webhook redelivery scenarios
5. Implement idempotency key expiration (24-48 hours)

---

#### 1.3 Order State Machine (P0-C)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - Order state transitions
  - State validation
  - Prevent invalid state jumps

**Critical Test Scenarios:**
```php
✅ testCannotCaptureUnauthorizedOrder_ThrowsException()
✅ testCannotRefundUncapturedOrder_ThrowsException()
✅ testStateTransitionValidation_EnforcesSequence()
✅ testConcurrentStateChanges_LastWriteWins()
✅ testOrderFinalization_ImmutableAfterComplete()
```

**Implementation Order:**
1. Define all valid state transitions
2. Write tests for invalid state transitions (must throw exceptions)
3. Implement state machine with validation
4. Test concurrent state changes
5. Implement order immutability after completion

---

#### 1.4 Webhook Signature Verification (P0-D)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - Signature verification
  - Replay attack prevention
  - Timestamp validation

**Critical Test Scenarios:**
```php
✅ testInvalidSignature_RejectsWebhook()
✅ testExpiredTimestamp_RejectsWebhook()
✅ testReplayAttack_DetectsAndRejects()
✅ testMalformedPayload_RejectsWebhook()
✅ testSignatureAlgorithmMismatch_RejectsWebhook()
```

**Implementation Order:**
1. Write tests for signature verification (HMAC-SHA256)
2. Implement signature validation
3. Test timestamp expiration (reject webhooks > 5 minutes old)
4. Implement replay attack detection (store webhook IDs)
5. Test malformed payload handling

---

### Block 2: Data Persistence & Integrity 🔴 CRITICAL (P0)

**Note:** Detailed coverage of Block 2 continues in [Part 2: Data Persistence & Integrity](09-02-tdd-data-persistence.md).

#### 2.1 Repository Layer (P0-E)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentTransactionRepository` - CRUD operations
  - `PaymentOrderStateRepository` - Order state management
  - `PaymentCustomerRepository` - Customer data management
  - Transaction queries
  - Database constraints (FK references)
  - Data consistency

**Critical Test Scenarios:**
```php
✅ testSaveTransaction_EnforcesRequiredFields()
✅ testGetTransactionsByOrderId_ReturnsChronological()
✅ testConcurrentOrderUpdate_VersionControl()
✅ testOrphanedTransaction_ForeignKeyPrevents()
✅ testDatabaseConstraints_EnforcedAtDBLevel()
✅ testPaymentOrderState_UniqueConstraintEnforced()
✅ testForeignKeyOnDeleteCascade_WorksCorrectly()
```

**Implementation Order:**
1. Create component tables with FK constraints (NOT ALTER TABLE on oxorder)
2. Write tests for required fields validation
3. Implement repositories with proper error handling
4. Test foreign key constraints (ON DELETE CASCADE)
5. Test 1:1 relationships with UNIQUE constraints
6. Test concurrent access with pessimistic locking
7. Implement cleanup of abandoned orders

---

#### 2.2 Transaction History & Audit Trail (P0-F)
- **Coverage Required:** 100%
- **Test Types:** Integration
- **Components:**
  - All transaction types (auth, capture, refund)
  - Immutable audit log
  - Reconciliation support

**Critical Test Scenarios:**
```php
✅ testTransactionHistory_ImmutableAfterCreation()
✅ testMultipleTransactionsPerOrder_AllTracked()
✅ testRefundLinksToOriginalCapture_AuditTrail()
✅ testTransactionTimestamps_AccurateToMillisecond()
```

**Implementation Order:**
1. Design transaction table schema (immutable records)
2. Write tests for transaction types (auth, capture, refund)
3. Implement transaction creation (insert-only, no updates)
4. Test transaction linking (refund → capture → authorization)
5. Implement reconciliation queries

---

## Related Documentation

- **[Part 2: Data Persistence & Integrity](09-02-tdd-data-persistence.md)** - Detailed repository layer and audit trail testing
- **[Part 3: Event System & Business Logic](09-03-tdd-event-system.md)** - Event layer and service layer testing
- **[Part 4: Provider Integration](09-04-tdd-provider-integration.md)** - SDK-Adapter layer testing
- **[Part 5: Authorization Flow](09-05-tdd-authorization-flow.md)** - Two-step authorization and webhook processing
- **[Part 6: Checkout Frontend](09-06-tdd-checkout-frontend.md)** - E2E checkout and admin feature testing
- **[Part 7: Test Pyramid Strategy](09-07-tdd-test-pyramid.md)** - Unit, integration, and E2E test organization
- **[Part 8: Mocking & Coverage](09-08-tdd-mocking-coverage.md)** - Mocking strategies, coverage goals, and CI/CD

**Test Organization:** [09-test-organization.md](09-test-organization.md)
**Visual Diagram:** [puml/10-tdd-strategy.puml](puml/10-tdd-strategy.puml)

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
