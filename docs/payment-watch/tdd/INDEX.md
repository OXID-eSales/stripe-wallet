# PaymentWatch - TDD Documentation Index

**Complete Guide to Test-Driven Development for PaymentWatch**

Version: 1.0.0
Date: 2025-11-11

---

## 📚 Documentation Structure

This TDD guide is split into focused documents for easier navigation and maintenance.

### Core Documents

| Document | Description | Lines | Focus |
|----------|-------------|-------|-------|
| **[00-overview.md](00-overview.md)** | TDD philosophy, SOLID principles, test organization | ~450 | Foundation |
| **[01-phase1-domain.md](01-phase1-domain.md)** | Domain Layer: Value Objects (RED-GREEN-REFACTOR) | ~620 | Implementation |
| **[02-phase2-infrastructure.md](02-phase2-infrastructure.md)** | Infrastructure Layer: Operator Strategies | ~540 | Implementation |
| **[03-phase3-application.md](03-phase3-application.md)** | Application Layer: Security-focused Services | ~460 | Implementation |
| **[04-best-practices.md](04-best-practices.md)** | TDD best practices, clean code, testing commands | ~480 | Reference |
| **[05-phase5-6-integration.md](05-phase5-6-integration.md)** | Controller & E2E Integration with real cURL tests | ~950 | Integration |

**Total:** ~3,500 lines split into 6 manageable documents

---

## 🚀 Quick Start Guide

### For First-Time Readers

1. **Start here:** [00-overview.md](00-overview.md)
   - Understand TDD philosophy
   - Learn SOLID principles
   - Review test organization

2. **Begin implementation:** [01-phase1-domain.md](01-phase1-domain.md)
   - Follow RED-GREEN-REFACTOR for Value Objects
   - Build immutable domain layer

3. **Add strategies:** [02-phase2-infrastructure.md](02-phase2-infrastructure.md)
   - Implement operator strategies
   - Apply Open/Closed Principle

4. **Secure services:** [03-phase3-application.md](03-phase3-application.md)
   - Build security-critical validators
   - Prevent SQL injection and timing attacks

5. **Integration tests:** [05-phase5-6-integration.md](05-phase5-6-integration.md)
   - Controller implementation
   - Real cURL E2E tests

6. **Maintain quality:** [04-best-practices.md](04-best-practices.md)
   - Apply clean code principles
   - Use testing commands reference

### For Experienced TDD Practitioners

- **Jump to:** [01-phase1-domain.md](01-phase1-domain.md) and start coding
- **Reference:** [04-best-practices.md](04-best-practices.md) for testing commands
- **Security focus:** [03-phase3-application.md](03-phase3-application.md) for critical validators

---

## 📁 Test Directory Structure

```
/home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe/tests/
├── Unit/
│   └── Watch/
│       ├── ValueObject/        ← Phase 1
│       │   ├── AssumptionRequestTest.php
│       │   ├── AssumptionResponseTest.php
│       │   └── AuthConfigTest.php
│       ├── Strategy/           ← Phase 2
│       │   ├── EqualityOperatorTest.php
│       │   ├── ComparisonOperatorTest.php
│       │   ├── LikeOperatorTest.php
│       │   └── NullCheckOperatorTest.php
│       └── Service/            ← Phase 3
│           ├── RequestValidatorTest.php
│           ├── IpValidatorTest.php
│           └── ApiKeyValidatorTest.php
├── Integration/
│   └── Watch/
│       ├── Controller/
│       ├── Database/
│       └── EndToEnd/
└── Acceptance/
    └── Watch/
```

---

## 🎯 Implementation Phases

### ✅ Phase 1: Domain Layer (Value Objects)
**Status:** Documented in [01-phase1-domain.md](01-phase1-domain.md)

- AssumptionRequest
- AssumptionResponse
- AuthConfig

**Test Directory:** `tests/Unit/Watch/ValueObject/`

---

### ✅ Phase 2: Infrastructure Layer (Strategies)
**Status:** Documented in [02-phase2-infrastructure.md](02-phase2-infrastructure.md)

- OperatorStrategyInterface
- EqualityOperator, ComparisonOperator, LikeOperator, NullCheckOperator
- OperatorStrategyFactory (future)

**Test Directory:** `tests/Unit/Watch/Strategy/`

---

### ✅ Phase 3: Application Layer (Services)
**Status:** Documented in [03-phase3-application.md](03-phase3-application.md)

- RequestValidator 🔒 (SQL injection prevention)
- ApiKeyValidator 🔒 (timing attack prevention)
- IpValidator (CIDR support)

**Test Directory:** `tests/Unit/Watch/Service/`

---

### 🔄 Phase 4: Infrastructure Completion
**Status:** To be implemented

- QueryBuilder
- SqlSanitizer
- AuditLogger

**Test Directory:** `tests/Unit/Watch/Infrastructure/`

---

### ✅ Phase 5 & 6: Controller & Integration Tests
**Status:** Documented in [05-phase5-6-integration.md](05-phase5-6-integration.md)

- AssumptionController (HTTP endpoint)
- **Real cURL integration tests** 🌐
- E2E payment flow tests
- Database integration tests
- Security validation tests
- Performance tests

**Test Directories:**
- `tests/Integration/Watch/Controller/`
- `tests/Integration/Watch/EndToEnd/`
- `tests/Acceptance/Watch/`

**Key Features:**
- ✅ Real HTTP requests via cURL
- ✅ Actual database queries (not mocked)
- ✅ Complete payment flow simulation
- ✅ 15+ integration tests
- ✅ Security & performance validation

---

## 🎨 Visual Reference

### Architecture Diagrams

| Diagram | Purpose | Reference |
|---------|---------|-----------|
| [../puml/01-architecture-overview.puml](../puml/01-architecture-overview.puml) | System architecture with layers | All phases |
| [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml) | SOLID class design | All phases |
| [../puml/03-sequence-assumption-flow.puml](../puml/03-sequence-assumption-flow.puml) | Happy path request flow | Phase 5 |
| [../puml/04-sequence-error-flows.puml](../puml/04-sequence-error-flows.puml) | Error handling patterns | Phase 5 |
| [../puml/05-state-machine-request.puml](../puml/05-state-machine-request.puml) | Request state machine | Phase 5 |
| [../puml/06-component-dependencies.puml](../puml/06-component-dependencies.puml) | Dependency graph (Onion) | All phases |

---

## 🔧 Testing Commands Quick Reference

### 🐳 Docker Environment (REQUIRED)

**ALWAYS run tests in Docker container:**

```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php
```

### Run Specific Phase
```bash
# Phase 1: Value Objects
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/ValueObject/

# Phase 2: Strategies
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/Strategy/

# Phase 3: Services
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/Service/
```

### Run Security Tests Only
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group security
```

### Optional Alias
```bash
alias phpunit-watch='docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php'

# Then use:
phpunit-watch tests/Unit/Watch/ValueObject/
phpunit-watch --group security
phpunit-watch --testdox
```

**Full reference:** [04-best-practices.md](04-best-practices.md#testing-commands-reference)

---

## 📖 Key Concepts by Document

### 00-overview.md
- RED-GREEN-REFACTOR cycle
- SOLID principles (all 5)
- Test pyramid (80% unit, 15% integration, 5% acceptance)
- Test organization strategy

### 01-phase1-domain.md
- Value Object pattern
- Immutability with `readonly`
- Zero dependencies approach
- Complete RED-GREEN-REFACTOR examples

### 02-phase2-infrastructure.md
- Strategy Pattern (Open/Closed)
- Operator abstraction
- Interface-based design
- SQL condition building

### 03-phase3-application.md
- SQL injection prevention 🔒
- Timing attack prevention 🔒
- CIDR range validation
- Security-first testing

### 04-best-practices.md
- Test naming conventions
- Arrange-Act-Assert pattern
- Clean code principles
- Refactoring guidelines
- Testing commands

### 05-phase5-6-integration.md
- Controller implementation
- Real cURL HTTP requests 🌐
- E2E payment flow testing
- Database integration (not mocked)
- Security & performance validation
- Complete scenario simulation

---

## 🛡️ Security Focus

Security-critical components are marked with 🔒:

- **RequestValidator** 🔒 - SQL injection prevention
- **ApiKeyValidator** 🔒 - Timing attack prevention
- **AuthenticationService** 🔒 - Combined IP + key validation

**Security tests:** Run with `vendor/bin/phpunit --group security`

---

## 📝 Document Maintenance

### When to Update

- **00-overview.md**: When adding new phases or changing philosophy
- **01/02/03-phase-*.md**: When implementation examples change
- **04-best-practices.md**: When discovering new patterns or anti-patterns
- **INDEX.md**: When adding/removing documents or restructuring

### Version History

- **v1.0.0** (2025-11-11): Initial split from monolithic document
  - Split 2,136 lines into 5 focused documents
  - Updated test paths to `/tests/Unit/Watch/` structure
  - Updated namespace to `OxidSolutionCatalysts\Payments\Watch\`

---

## 🔗 Related Documentation

- **[../README.md](../README.md)** - PaymentWatch main documentation
- **[../01-implementation-guide.md](../01-implementation-guide.md)** - Implementation details
- **[../02-test-scenarios.md](../02-test-scenarios.md)** - E2E test scenarios
- **[../puml/](../puml/)** - PlantUML architecture diagrams

---

## 🎓 Learning Path

### Beginner TDD
1. Read 00-overview.md (philosophy)
2. Follow 01-phase1-domain.md step-by-step
3. Practice RED-GREEN-REFACTOR with Value Objects

### Intermediate TDD
1. Review SOLID principles in 00-overview.md
2. Implement Phase 2 (strategies)
3. Learn refactoring patterns from 04-best-practices.md

### Advanced TDD
1. Focus on security in 03-phase3-application.md
2. Apply all SOLID principles
3. Build remaining phases independently

---

## 📞 Getting Help

- **Stuck on tests?** Check [04-best-practices.md](04-best-practices.md) for common pitfalls
- **Architecture questions?** Review visual diagrams in `../puml/`
- **Security concerns?** See security-focused tests in [03-phase3-application.md](03-phase3-application.md)

---

## ✅ Completion Checklist

Track your progress through the phases:

- [ ] Phase 1: Domain Layer (Value Objects)
- [ ] Phase 2: Infrastructure (Operator Strategies)
- [ ] Phase 3: Application (Security Services)
- [ ] Phase 4: Infrastructure (QueryBuilder, SqlSanitizer, AuditLogger)
- [ ] Phase 5 & 6: Controller & E2E Integration Tests (with real cURL)

---

**Happy Test-Driven Development!** 🧪✅
