# Sprint 1: Implementation Tickets Index

**Sprint Goal:** Build reusable payment component foundation + Stripe implementation

**Total Story Points:** 37 points

---

## Quick Navigation

- [Sprint Overview](SPRINT-1-overview.md) - Architecture, database schema, and shared configuration
- [Original Combined File](IMPLEMENTATION-TICKETS-SPRINT-1.md) - Full 3559-line original document

---

## Sprint 1 Tickets

### Foundation Tickets (P0 - Blocker)

| # | Ticket | Priority | Story Points | Status | File |
|---|--------|----------|--------------|--------|------|
| 1 | Project Setup with Component/Stripe Structure | P0 - Blocker | 8 pts (2 days) | Ready | [TICKET-001](SPRINT-1-TICKET-01-project-setup.md) |
| 2 | Component Event Layer (Domain Events + Context) | P0 - Critical | 8 pts (2 days) | Ready | [TICKET-002](SPRINT-1-TICKET-02-event-layer.md) |

**Foundation Subtotal:** 16 points

### Core Component Tickets (P1 - High)

| # | Ticket | Priority | Story Points | Status | File |
|---|--------|----------|--------------|--------|------|
| 3 | Component Models (PaymentTransaction + Component-Level Models) | P1 - High | 8 pts (2 days) | Ready | [TICKET-003](SPRINT-1-TICKET-03-component-models.md) |
| 4 | Component Repositories (Data Access Layer) | P1 - High | 5 pts (1.5 days) | Ready | [TICKET-004](SPRINT-1-TICKET-04-repositories.md) |
| 5 | SDK-Adapter Layer (Provider Abstraction) | P1 - High | 8 pts (2 days) | Ready | [TICKET-005](SPRINT-1-TICKET-05-sdk-adapter.md) |

**Core Component Subtotal:** 21 points

---

## Ticket Summaries

### TICKET-001: Project Setup with Component/Stripe Structure
**Priority:** P0 - Blocker | **Story Points:** 8 | [View Details](SPRINT-1-TICKET-01-project-setup.md)

Set up the OXID module with complete directory structure separating Component (reusable) and Stripe (provider-specific) with TDD infrastructure.

**Key Deliverables:**
- OXID module structure with dual PSR-4 namespaces
- PHPUnit 9+ with 3 test suites
- Database migration files (4 tables)
- PHPStan level 6+ configuration
- GitHub Actions CI/CD workflow

**Dependencies:** None (foundation ticket)

---

### TICKET-002: Component Event Layer
**Priority:** P0 - Critical | **Story Points:** 8 | [View Details](SPRINT-1-TICKET-02-event-layer.md)

Implement the reusable event layer in `src/Component/Event/` with domain events, EventContext, and event dispatcher.

**Key Deliverables:**
- EventContext for request data caching
- 8 domain events for payment lifecycle
- PSR-14 event dispatcher wrapper
- 100% test coverage

**Dependencies:** TICKET-001

---

### TICKET-003: Component Models
**Priority:** P1 - High | **Story Points:** 8 | [View Details](SPRINT-1-TICKET-03-component-models.md)

Implement domain models in `src/Component/Model/` that reference OXID core tables via foreign keys without extending them.

**Key Deliverables:**
- PaymentTransaction model
- PaymentOrderState model
- PaymentCustomer model
- PaymentBasketSnapshot model
- PaymentOrderStates constants
- 100% test coverage

**Dependencies:** TICKET-001, TICKET-002

---

### TICKET-004: Component Repositories
**Priority:** P1 - High | **Story Points:** 5 | [View Details](SPRINT-1-TICKET-04-repositories.md)

Implement repository pattern in `src/Component/Repository/` for PaymentTransaction and Order data access.

**Key Deliverables:**
- PaymentTransactionRepository with CRUD operations
- OrderRepository for OXID order access
- Repository interfaces in `src/Component/Contract/`
- Integration tests with real database

**Dependencies:** TICKET-001, TICKET-003

---

### TICKET-005: SDK-Adapter Layer
**Priority:** P1 - High | **Story Points:** 8 | [View Details](SPRINT-1-TICKET-05-sdk-adapter.md)

Implement SDK-Adapter layer in `src/Component/Adapter/` that provides a unified, provider-agnostic interface for payment provider SDK integration.

**Key Deliverables:**
- PaymentAdapterInterface (provider-agnostic contract)
- Request/Response DTOs (normalized data structures)
- StripeAdapter (Stripe SDK implementation)
- AdapterFactory (configuration-driven adapter creation)
- Unified exception handling
- 100% unit test coverage

**Dependencies:** TICKET-001, TICKET-002

---

## Dependency Graph

```
TICKET-001 (Project Setup)
    ↓
    ├─→ TICKET-002 (Event Layer)
    │       ↓
    │       ├─→ TICKET-003 (Models)
    │       │       ↓
    │       │       └─→ TICKET-004 (Repositories)
    │       │
    │       └─→ TICKET-005 (SDK-Adapter Layer)
    │
    └─→ (All tickets depend on Project Setup)
```

---

## Sprint Progress Tracker

### Story Points Breakdown

| Priority | Tickets | Story Points | Percentage |
|----------|---------|--------------|------------|
| P0 (Blocker/Critical) | 2 | 16 pts | 43% |
| P1 (High) | 3 | 21 pts | 57% |
| **Total** | **5** | **37 pts** | **100%** |

### Estimated Timeline

- **Sprint Duration:** 2 weeks (10 working days)
- **Average Velocity:** ~18-20 points per week
- **Team Size:** 1-2 developers

**Timeline:**
- Week 1: TICKET-001 (2 days) + TICKET-002 (2 days) + Start TICKET-003 (1 day)
- Week 2: Complete TICKET-003 (1 day) + TICKET-004 (1.5 days) + TICKET-005 (2 days) + Buffer (0.5 days)

---

## Key Architecture Decisions

1. **Component/Provider Separation**: Clean separation enables future extraction to Composer package
2. **No OXID Core Extensions**: Use foreign keys instead of extending oxorder/oxuser tables
3. **Event-Driven Architecture**: Loose coupling between Component and Stripe layers
4. **TDD Approach**: Write tests first, implement minimal code to pass
5. **SDK-Adapter Pattern**: Unified interface for multiple payment providers

---

## Testing Strategy

**Test Organization:**
- **Component Tests** (`tests/Component/`): Provider-agnostic, mock `PaymentAdapterInterface`, 95%+ coverage
- **Provider Tests** (`tests/Stripe/`): Stripe-specific, mock/real Stripe SDK, 90%+ coverage
- **Separate Test Suites**: Independent execution with different coverage requirements

**Test Suites:**
```bash
# Run component tests only (fast, no external dependencies)
vendor/bin/phpunit --testsuite=Component

# Run Stripe adapter tests only
vendor/bin/phpunit --testsuite=Stripe

# Run all tests
vendor/bin/phpunit
```

---

## Additional Resources

- [Test Organization Strategy](10-test-organization.md)
- [TDD Strategy](09-tdd-strategy.md)
- [Sprint Overview](SPRINT-1-overview.md) - Complete architecture and configuration details

---

## Files Created

This sprint documentation is split into the following focused files:

1. **SPRINT-1-index.md** (this file) - Navigation hub and quick reference
2. **SPRINT-1-overview.md** - Architecture, database schema, composer config, OXID metadata
3. **SPRINT-1-TICKET-01-project-setup.md** - Project setup and infrastructure (8 pts)
4. **SPRINT-1-TICKET-02-event-layer.md** - Event-driven foundation (8 pts)
5. **SPRINT-1-TICKET-03-component-models.md** - Domain models (8 pts)
6. **SPRINT-1-TICKET-04-repositories.md** - Data access layer (5 pts)
7. **SPRINT-1-TICKET-05-sdk-adapter.md** - Provider abstraction (8 pts)

**Original File:** `IMPLEMENTATION-TICKETS-SPRINT-1.md` (3559 lines) - Retained for reference

---

[Back to Sprint Overview](SPRINT-1-overview.md)
