# Database Documentation - Index

**Version:** 4.0.0
**Last Updated:** 2025-10-23

---

## 📚 Main Documentation

### ⭐ **[02-database-and-models.md](02-database-and-models.md)** - UNIFIED DATABASE DOCUMENTATION

**This is the PRIMARY and MOST COMPLETE database documentation.**

**Version:** 4.0.0 (Contract-Aware Schema)
**Content (1149 lines):**
- ✅ Executive Summary
- ✅ Architecture Principles (Contract-First + Master-Detail)
- ✅ **Contract Schema** (NEW - oe_payments_contract)
- ✅ **Master-Detail Pattern** (Transaction tables)
- ✅ **Complete Database Tables** (All 12 tables with SQL)
- ✅ **Domain Models** (PaymentContract, ContractCondition, etc.)
- ✅ **Value Objects** (BasketSnapshot)
- ✅ **Repository Pattern** (Data access layer)
- ✅ **Migration Scripts** (PHP for OXID 7.4+)
- ✅ **Query Examples** (Common patterns)
- ✅ **Provider-Specific Handling** (Stripe, PayPal, Amazon Pay, etc.)

**Why this is the best document:**
- Most comprehensive (1149 lines)
- Already unified (smart contracts + master-detail)
- Includes domain models AND SQL
- Includes repositories AND queries
- Production-ready migration scripts
- Complete provider integration guidance

---

## 🎯 Supporting Documentation

### Implementation Guides

1. **[IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md](IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md)**
   - PHP migration classes for OXID 7.4
   - MigrationRunner helper
   - TDD approach for migrations
   - Smart contract migration tests

2. **[IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md](IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md)**
   - Complete domain models with TDD tests
   - PaymentContract (Aggregate Root)
   - ContractCondition (Entity)
   - BasketSnapshot (Value Object)
   - 7 Domain Event classes
   - Full unit test coverage

### Visual Diagrams

1. **[puml/01-01-database-schema.puml](puml/01-01-database-schema.puml)**
   - Complete ER diagram
   - Contract-first architecture visualization
   - Master-detail pattern illustration
   - All tables and relationships

---

## 🗺️ Navigation Guide

### For Quick Start:
1. **Read:** [02-database-and-models.md](02-database-and-models.md) (Main unified doc)
2. **Visualize:** [puml/01-01-database-schema.puml](puml/01-01-database-schema.puml)
3. **Implement:** [IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md](IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md)

### For Deep Dive:
1. **Architecture:** [02-database-and-models.md](02-database-and-models.md) - Sections 1-4
2. **Tables:** [02-database-and-models.md](02-database-and-models.md) - Section 5
3. **Models:** [02-database-and-models.md](02-database-and-models.md) - Sections 6-7
4. **Implementation:** [IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md](IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md)

### For Specific Topics:

| Topic | Document | Section |
|-------|----------|---------|
| **Contract-First Pattern** | [02-database-and-models.md](02-database-and-models.md) | Section 3: Contract Schema |
| **Master-Detail Pattern** | [02-database-and-models.md](02-database-and-models.md) | Section 4 |
| **All Table Definitions** | [02-database-and-models.md](02-database-and-models.md) | Section 5 |
| **Domain Models** | [02-database-and-models.md](02-database-and-models.md) | Section 6 |
| **Repositories** | [02-database-and-models.md](02-database-and-models.md) | Section 8 |
| **Queries** | [02-database-and-models.md](02-database-and-models.md) | Section 10 |
| **Migration PHP** | [IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md](IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md) | All |
| **Model Tests** | [IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md](IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md) | All |

---

## 🚫 Deprecated Files

The following files are **DEPRECATED** and should NOT be used:

- ❌ `02-01-database-and-models.md.DEPRECATED` (Version 3.0.0 - old normalized pattern only)
- ❌ `02-02-database-and-models-smart-contracts.md.DEPRECATED` (Version 1.0.0 - smart contracts only)
- ❌ `DATABASE-SCHEMA.md.REDUNDANT` (duplicate of 02-database-and-models.md)
- ❌ `DATABASE-SCHEMA-PART-1-TABLES.md.REFERENCE` (subset of 02-database-and-models.md)
- ❌ `DATABASE-README.md.OLD` (outdated navigation)

**Why deprecated:**
- Overlapping content
- Partial coverage
- Superseded by unified version 4.0.0

---

## 📊 Architecture Quick Reference

### Contract-First Flow

```
User clicks "Place Order"
  ↓
Contract created (OXID='contract-123', OXORDERID=NULL)
  ↓
Conditions added (payment_authorized, fraud_check, inventory_reserved)
  ↓
All conditions fulfilled → Contract: READY_TO_COMMIT
  ↓
Order created (OXID='order-123')
  ↓
Contract linked (OXORDERID='order-123') → Contract: COMMITTED
  ↓
Payment captured → Contract: FULFILLED
```

### Core Tables

| Table | Purpose | Key Feature |
|-------|---------|-------------|
| **oe_payments_contract** | Payment lifecycle | OXORDERID = NULL until committed |
| **oe_payments_transaction** | Transaction master | Links to contract via OXCONTRACTID |
| **oe_payments_order_state** | Order payment state | Links to contract for audit trail |

### Performance Benefits

| Metric | Improvement |
|--------|-------------|
| Row size | **6x smaller** (250 vs 1500 bytes) |
| Query speed | **3-6x faster** |
| Storage | **60-70% reduction** |
| Cache efficiency | **6x more rows in cache** |

---

## 🔄 Document Versions

| Version | Date | Description |
|---------|------|-------------|
| **4.0.0** | 2025-10-22 | ✅ **CURRENT** - Unified contract-aware schema |
| 3.0.0 | 2025-10-16 | ❌ DEPRECATED - Normalized pattern only |
| 1.0.0 | 2025-10-20 | ❌ DEPRECATED - Smart contracts only |

---

## ✅ Answer: Is `02-database-and-models.md` still relevant?

**YES! It is the PRIMARY and MOST COMPLETE database documentation.**

- ✅ Version 4.0.0 (latest)
- ✅ 1149 lines (most comprehensive)
- ✅ Already unified (contract + master-detail)
- ✅ Includes everything: tables, models, repos, queries, migrations
- ✅ Production-ready

**Use this file as your single source of truth for database architecture.**

---

**Status:** ✅ Documentation Index Complete
**Primary Doc:** [02-database-and-models.md](02-database-and-models.md)
**Last Updated:** 2025-10-23
