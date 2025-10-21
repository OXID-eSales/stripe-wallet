# TDD Strategy - Complete Index

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

---

## Overview

This document provides a complete index to the TDD Strategy documentation, which has been split into 8 focused parts for better maintainability and navigation. Each part covers specific aspects of the testing strategy for the event-driven payment component.

**Original Document:** The content was previously in a single 3752-line file (`09-tdd-strategy.md`) and has been split for easier navigation and maintenance.

---

## Document Series

### [Part 1: Overview & Payment Security](09-01-tdd-overview.md)
**Lines 1-230 of original document**

**Contents:**
- Test Organization Overview
- Priority Classification (P0/P1/P2/P3)
- Development Priority Matrix
- **Block 1: Payment Security & Money Handling** (P0)
  - Transaction Integrity (P0-A)
  - Idempotency System (P0-B)
  - Order State Machine (P0-C)
  - Webhook Signature Verification (P0-D)
- **Block 2: Data Persistence & Integrity** (P0) - Introduction
  - Repository Layer (P0-E) - Overview
  - Transaction History & Audit Trail (P0-F) - Overview

**Key Topics:**
- Critical security requirements
- Money handling test scenarios
- Double-capture prevention
- Idempotency key validation
- Webhook security

---

### [Part 2: Data Persistence & Integrity](09-02-tdd-data-persistence.md)
**Lines 231-330 of original document**

**Contents:**
- **Block 2: Data Persistence & Integrity** (P0) - Detailed
  - Repository Layer (P0-E) - Detailed implementation
  - Component table structure (no OXID core table extensions)
  - Transaction History & Audit Trail (P0-F) - Detailed implementation
  - Immutable transaction log design

**Key Topics:**
- Component-owned tables with FK references
- Foreign key constraints and cascade deletes
- Unique constraints for 1:1 relationships
- Immutable audit trail design
- Transaction linking (refund → capture → authorization)

---

### [Part 3: Event System & Business Logic](09-03-tdd-event-system.md)
**Lines 331-530 of original document**

**Contents:**
- **Block 3: Event System & Business Logic** (P1)
  - Event Layer (P1-A)
  - Event Handlers (P1-B)
  - Domain Layer (P1-C) - OXID 7.4+ Architecture
- **Block 4: Service Layer** (P1)
  - Payment Service (P1-D)
  - Module Settings & Configuration (P1-E)

**Key Topics:**
- Event immutability
- EventContext caching
- Event dispatcher integration
- Payment capture/refund handlers
- Domain models without class extensions
- Service layer orchestration

---

### [Part 4: Provider Integration & SDK-Adapter Layer](09-04-tdd-provider-integration.md)
**Lines 531-900 of original document**

**Contents:**
- **Block 5: Provider Integration** (P2)
  - Request Factories (P2-A)
  - Error Mapping (P2-B)
- **Block 5.5: SDK-Adapter Layer** (P2)
  - Adapter Interface & Request/Response Objects (P2-A+)
  - Provider Adapters (P2-B) - Stripe, Unzer, PayPal
  - Adapter Factory (P2-C)
  - Integration with PaymentService (P2-D)

**Key Topics:**
- Component vs Provider test separation
- `PaymentAdapterInterface` design
- Request/Response DTOs
- Provider SDK integration testing
- Adapter factory pattern
- Mocking adapter interface in component tests

---

### [Part 5: Two-Step Authorization Flow & Webhook Processing](09-05-tdd-authorization-flow.md)
**Lines 901-1400 of original document**

**Contents:**
- **Block 5.6: Two-Step Authorization Flow** (P0)
  - Authorization Service (P0-F)
  - Reauthorization Service (P0-G)
- **Block 5.7: Idempotency Management** (P0)
  - Idempotency Service (P0-H)
  - Integration with PaymentService (P0-I)
- **Block 5.8: Vaulting/Tokenization** (P1)
  - Vaulting Service (P1-A)
  - Integration with PaymentService (P1-B)
- **Block 5.9: 3D Secure/SCA Verification** (P1)
  - SCA Validator Service (P1-C)
  - Integration with PaymentService (P1-D)
- **Block 5.10: Partial Refund & Calculation** (P2)
  - Refund Service (P2-F)
  - Integration with PaymentService (P2-G)
- **Block 6: API Layer & Controllers** (P2)
  - Controllers (P2-E)
- **Block 7: User Interface & Experience** (P3)
  - E2E Checkout Flows (P3-A)
- Implementation Roadmap (Phase 1-4)

**Key Topics:**
- Authorization without capture
- Delayed capture and void
- Authorization expiration tracking
- Reauthorization (PayPal 29 days, Stripe/Unzer 7 days)
- Idempotency key management
- Saved payment methods (vaulting)
- 3D Secure / SCA flow
- Partial refund validation
- Multi-phase implementation plan

---

### [Part 6: Checkout Frontend & Admin Features](09-06-tdd-checkout-frontend.md)
**Lines 1401-2200 of original document**

**Contents:**
- Security-First Testing Checklist
- Critical Test Coverage Requirements
- Overview of TDD Strategy
- Test Pyramid Strategy introduction
- **Unit Tests (60%)** - Detailed
  - Event Layer tests
  - Domain Layer tests
  - Service Layer tests
  - Event Handler tests
  - Factory Layer tests
  - Repository Layer unit tests
- Unit Test Best Practices

**Key Topics:**
- Complete checkout flow E2E tests
- Admin capture/refund operations
- Domain model unit tests (Order, PaymentTransaction, Basket)
- Service layer unit tests (PaymentService, OrderManager)
- Event handler unit tests (PaymentCaptureHandler, PaymentRefundHandler)
- AAA pattern (Arrange-Act-Assert)
- Test naming conventions

---

### [Part 7: Test Pyramid Strategy](09-07-tdd-test-pyramid.md)
**Lines 2201-2900 of original document**

**Contents:**
- **Integration Tests (30%)** - Detailed
  - Repository integration tests
  - Event flow integration tests
  - Webhook integration tests
  - TestContainers setup
  - WireMock for API mocking
- **E2E Tests (10%)** - Detailed
  - Complete checkout flows
  - GraphQL API E2E tests
  - Codeception/Playwright setup
- **Test Data & Fixtures**
  - Unit test fixtures (Builders)
  - Integration test fixtures (Factories)
  - E2E test fixtures (Seeders)

**Key Topics:**
- 60% unit / 30% integration / 10% E2E distribution
- Real database with TestContainers
- Transaction-based test isolation
- Builder pattern for unit test data
- Factory pattern for integration test data
- Database seeders for E2E scenarios
- OrderBuilder, BasketBuilder, UserBuilder examples
- Database cleanup strategies

---

### [Part 8: Mocking Strategy, Coverage Goals, CI/CD, Best Practices](09-08-tdd-mocking-coverage.md)
**Lines 2901-3752 of original document**

**Contents:**
- **Mocking Strategy**
  - Mock external APIs (Provider APIs)
  - Mock internal services
  - Mock repositories
  - WireMock configuration
- **Coverage Goals**
  - Component coverage: 95%+
  - Provider coverage: 90%+
  - Coverage by layer
  - Generating coverage reports
- **CI/CD Pipeline**
  - GitHub Actions workflow
  - Separate component and provider test jobs
  - E2E test job
  - Coverage threshold enforcement
- **Best Practices**
  - Test naming conventions
  - AAA pattern
  - One assertion focus
  - Data providers
  - Test isolation
  - Meaningful assertion messages
  - Cleanup in tearDown()
  - Skip slow tests in development

**Key Topics:**
- Mocking Stripe/Unzer/PayPal SDKs
- EventDispatcher mocking
- Logger mocking
- Component vs Provider coverage targets
- PHPUnit coverage reports
- Codecov integration
- Test suite execution time optimization
- Test independence and parallelization

---

## Quick Reference

### By Priority Level

**P0 (Critical):**
- [Part 1: Payment Security](09-01-tdd-overview.md) - Transaction integrity, idempotency, order state machine, webhook verification
- [Part 2: Data Persistence](09-02-tdd-data-persistence.md) - Repository layer, audit trail
- [Part 5: Authorization Flow](09-05-tdd-authorization-flow.md) - Two-step authorization, idempotency management

**P1 (High):**
- [Part 3: Event System](09-03-tdd-event-system.md) - Event layer, handlers, domain layer, service layer
- [Part 5: Vaulting & 3DS](09-05-tdd-authorization-flow.md) - Saved payment methods, 3D Secure

**P2 (Medium):**
- [Part 4: Provider Integration](09-04-tdd-provider-integration.md) - SDK-Adapter layer
- [Part 5: Refunds](09-05-tdd-authorization-flow.md) - Partial refund calculation

**P3 (Low):**
- [Part 6: Frontend](09-06-tdd-checkout-frontend.md) - E2E checkout flows

### By Test Type

**Unit Tests:**
- [Part 6: Unit Tests](09-06-tdd-checkout-frontend.md) - Event, domain, service, handler, factory unit tests
- [Part 7: Builders](09-07-tdd-test-pyramid.md) - Builder pattern for unit test fixtures

**Integration Tests:**
- [Part 7: Integration Tests](09-07-tdd-test-pyramid.md) - Repository, event flow, webhook integration tests
- [Part 7: Factories](09-07-tdd-test-pyramid.md) - Factory pattern for integration test fixtures

**E2E Tests:**
- [Part 7: E2E Tests](09-07-tdd-test-pyramid.md) - Complete checkout flows, GraphQL API tests
- [Part 7: Seeders](09-07-tdd-test-pyramid.md) - Database seeders for E2E scenarios

### By Component

**Component Tests (Provider-Agnostic):**
- [Part 1: Overview](09-01-tdd-overview.md) - Test organization principles
- [Part 2: Data Persistence](09-02-tdd-data-persistence.md) - Repository testing
- [Part 3: Event System](09-03-tdd-event-system.md) - Event and service testing
- [Part 4: Adapter Interface](09-04-tdd-provider-integration.md) - Mock adapter interface
- [Test Organization Doc](09-test-organization.md) - Complete separation strategy

**Provider Tests (SDK Integration):**
- [Part 4: Provider Adapters](09-04-tdd-provider-integration.md) - Stripe, Unzer, PayPal adapter testing
- [Test Organization Doc](09-test-organization.md) - Provider test suite structure

---

## Related Documentation

- **[09-test-organization.md](09-test-organization.md)** - Detailed test organization strategy (component vs provider)
- **[puml/10-tdd-strategy.puml](puml/10-tdd-strategy.puml)** - Visual diagram of TDD strategy

---

## Navigation Tips

1. **Start with Part 1** if you're new to the TDD strategy
2. **Use this index** to jump to specific topics
3. **Follow the "Related Documentation" links** at the bottom of each part to navigate sequentially
4. **Refer to the Quick Reference** section above to find topics by priority, test type, or component

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
