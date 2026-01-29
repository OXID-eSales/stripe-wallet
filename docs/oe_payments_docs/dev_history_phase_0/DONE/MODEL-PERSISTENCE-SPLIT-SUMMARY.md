# Model Persistence Split Summary

**Date:** 2025-10-31
**Status:** ✅ COMPLETE

---

## 🎯 Objective

Split domain models into persistent and non-persistent categories to clearly identify:
1. **Persistent Models** - Models with database tables requiring repositories
2. **Non-Persistent Models** - Value objects embedded in persistent models

---

## 📊 Analysis Results

### Persistent Models (Have Database Tables)

| Model | Type | Database Table | Repository | Location |
|-------|------|---------------|------------|----------|
| PaymentContract | Aggregate Root | `osc_payments_contracts` | DoctrineContractRepository | `src/Component/Contract/PaymentContract.php` |
| ContractCondition | Entity (Child) | `osc_payments_contract_conditions` | Via aggregate | `src/Component/Contract/ContractCondition.php` |
| WebhookLog | Entity (Independent) | `osc_payments_webhooklogs` | WebhookLogRepository | `src/Component/Webhook/WebhookLog.php` |

### Non-Persistent Models (Embedded Value Objects)

| Model | Type | Storage Method | Located In | Location |
|-------|------|---------------|------------|----------|
| BasketSnapshot | Value Object | JSON in `OXBASKET` column | `osc_payments_contracts` | `src/Component/Contract/BasketSnapshot.php` |
| ContractState | Value Object | String in `OXSTATE` column | `osc_payments_contracts` | `src/Component/Contract/ContractState.php` |

---

## 📁 Directory Structure Created

```
src/Component/Model/
├── ModelInterface.php          # Common interface
├── AbstractModel.php           # Common base class
├── Persistent/                 # Directory for persistent models (CREATED)
└── ValueObject/                # Directory for non-persistent value objects (CREATED)
```

**Note:** Directories are prepared but currently empty. Existing models remain in their original domain directories (`Contract/`, `Webhook/`) to avoid breaking changes.

---

## 🗄️ Database Schema Overview

### Table: osc_payments_contracts
**Maps to:** PaymentContract aggregate root

```sql
OXID (PK)                    -- PaymentContract.$id
OXSHOPID                     -- PaymentContract.$shopId
OXUSERID                     -- PaymentContract.$userId
OXORDERID                    -- PaymentContract.$orderId
OXSTATE (VARCHAR)            -- ContractState.$value (serialized)
OXBASKET (TEXT/JSON)         -- BasketSnapshot.toArray() (serialized)
OXPROVIDER                   -- PaymentContract.$provider
OXPROVIDERORDERID            -- PaymentContract.$providerOrderId
OXPROVIDERREDIRECTURL        -- PaymentContract.$providerRedirectUrl
OXEXPIRESAT                  -- PaymentContract.$expiresAt
OXCREATED                    -- PaymentContract.$createdAt
OXTIMESTAMP                  -- PaymentContract.$updatedAt
OXFULFILLEDAT                -- PaymentContract.$fulfilledAt
```

### Table: osc_payments_contract_conditions
**Maps to:** ContractCondition entity (child of PaymentContract aggregate)

```sql
OXID (PK)                    -- Auto-generated
OXCONTRACTID (FK)            -- References osc_payments_contracts.OXID
OXTYPE                       -- ContractCondition.$type
OXSTATUS                     -- ContractCondition.$status
OXDATA (TEXT/JSON)           -- ContractCondition.$data (serialized)
OXFULFILLEDAT                -- ContractCondition.$fulfilledAt
OXFAILUREREASON              -- ContractCondition.$failureReason
```

### Table: osc_payments_webhooklogs
**Maps to:** WebhookLog entity (independent)

```sql
OXID (PK)                    -- WebhookLog.$id
OXEVENTID (UNIQUE)           -- WebhookLog.$eventId
OXEVENTTYPE                  -- WebhookLog.$eventType
OXCONTRACTID                 -- WebhookLog.$contractId
OXSTATUS                     -- WebhookLog.$status
OXRECEIVEDAT                 -- WebhookLog.$receivedAt
OXERROR                      -- WebhookLog.$error
```

---

## 🔄 Persistence Patterns

### 1. Aggregate Persistence (PaymentContract)

PaymentContract is an aggregate root that controls the persistence of its children:

**Save Operation:**
```php
$contract = new PaymentContract($shopId, $userId, $basket);
$contract->addCondition(new ContractCondition('payment_authorized'));
$contract->transitionToPending();

$repository->save($contract);

// Results in:
// 1. INSERT into osc_payments_contracts (1 row)
//    - OXSTATE = 'pending' (ContractState serialized)
//    - OXBASKET = '{"items":[...],"totalGross":199.98,...}' (BasketSnapshot JSON)
// 2. INSERT into osc_payments_contract_conditions (1 row per condition)
```

**Load Operation:**
```php
$contract = $repository->findById('contract_123');

// Results in:
// 1. SELECT from osc_payments_contracts WHERE OXID = 'contract_123'
// 2. SELECT from osc_payments_contract_conditions WHERE OXCONTRACTID = 'contract_123'
// 3. Deserialize OXSTATE string → ContractState::fromValue('pending')
// 4. Deserialize OXBASKET JSON → BasketSnapshot::fromArray([...])
// 5. Reconstruct ContractCondition[] from rows
// 6. Return fully reconstructed aggregate
```

### 2. Value Object Serialization

**BasketSnapshot → JSON:**
```php
// When saving:
$json = json_encode($contract->getBasketSnapshot()->toArray());
// Stored in OXBASKET column

// When loading:
$data = json_decode($row['OXBASKET'], true);
$basketSnapshot = BasketSnapshot::fromArray($data);
```

**ContractState → String:**
```php
// When saving:
$stateString = $contract->getState()->getValue(); // 'pending'
// Stored in OXSTATE column

// When loading:
$state = ContractState::fromValue($row['OXSTATE']); // ContractState object
```

### 3. Independent Entity Persistence (WebhookLog)

WebhookLog has its own table and repository:

```php
$log = new WebhookLog($eventId, $receivedAt, $status);
$log->setEventType('payment_intent.succeeded');
$log->setContractId('contract_123');

$webhookLogRepository->save($log);

// Results in:
// INSERT into osc_payments_webhooklogs (1 row)
```

---

## 🏛️ Repository Boundaries

### Repositories That Exist

**✅ ContractRepository (DoctrineContractRepository)**
- **Purpose:** Manages PaymentContract aggregate
- **Responsibility:** Save/load entire aggregate including child ContractCondition entities
- **Serialization:** Handles ContractState and BasketSnapshot serialization

**✅ WebhookLogRepository**
- **Purpose:** Manages WebhookLog entities
- **Responsibility:** Simple CRUD operations for webhook logs
- **Already Implemented:** Yes

### Repositories That Do NOT Exist

**❌ ContractConditionRepository**
- **Reason:** ContractCondition is part of PaymentContract aggregate
- **Access:** Through PaymentContract aggregate root only
- **Persistence:** Managed by ContractRepository as part of aggregate

**❌ BasketSnapshotRepository**
- **Reason:** Value object, no separate table
- **Storage:** Serialized as JSON in parent PaymentContract
- **Access:** Through PaymentContract only

**❌ ContractStateRepository**
- **Reason:** Value object, no separate table
- **Storage:** Serialized as string in parent PaymentContract
- **Access:** Through PaymentContract only

---

## 📝 Documentation Updates

### Files Updated

1. **MODELS-ARCHITECTURE.md** (300+ lines added)
   - Added complete persistence architecture section
   - Added persistence architecture diagram (ASCII art)
   - Added persistence strategy summary table
   - Added DDD persistence patterns section
   - Added persistence flow examples
   - Updated model hierarchy with persistence annotations
   - Updated implementation status table with persistence info

2. **TICKET-10-DATABASE-MODELS-STATUS.md**
   - Added persistence split section
   - Added persistence strategy table
   - Referenced new persistence documentation

3. **MODEL-PERSISTENCE-SPLIT-SUMMARY.md** (this file)
   - Complete summary of persistence split analysis
   - Database schema overview
   - Persistence patterns documentation
   - Repository boundaries clarification

---

## ✅ Verification

### Tests
```
✅ 50 tests passing
✅ 138 assertions
✅ 0 failures
✅ 0 errors
```

### Code Quality
- ✅ All models unchanged (no breaking changes)
- ✅ Directory structure prepared for future use
- ✅ Documentation comprehensive and accurate

---

## 🎨 Key Insights

### 1. DDD Aggregate Pattern

PaymentContract follows proper DDD aggregate pattern:
- **Aggregate Root:** PaymentContract controls all access
- **Consistency Boundary:** Changes to conditions go through aggregate root
- **Transaction Boundary:** Entire aggregate saved/loaded as unit
- **Repository:** Only aggregate root has repository

### 2. Value Object Embedding

Value objects are properly embedded without separate persistence:
- **BasketSnapshot:** Complex value object serialized as JSON
- **ContractState:** Simple value object serialized as string
- **Benefits:** No unnecessary tables, faster queries, natural boundaries

### 3. Clear Persistence Boundaries

The split makes it clear:
- **3 Persistent Models** requiring database storage and repositories
- **2 Non-Persistent Models** existing only as embedded data
- **Repository Design:** Only aggregate roots and independent entities

---

## 🔮 Future Considerations

### If Moving Models to Persistence Directories

If in the future models are moved to persistence-based directories:

**Option A: Move by Persistence Type**
```
src/Component/Model/
├── Persistent/
│   ├── PaymentContract.php
│   ├── ContractCondition.php
│   └── WebhookLog.php
└── ValueObject/
    ├── BasketSnapshot.php
    └── ContractState.php
```

**Option B: Keep Domain Organization (Current)**
```
src/Component/
├── Model/
│   ├── ModelInterface.php
│   ├── AbstractModel.php
│   ├── Persistent/      (empty, prepared)
│   └── ValueObject/     (empty, prepared)
├── Contract/
│   ├── PaymentContract.php      (PERSISTENT)
│   ├── ContractCondition.php    (PERSISTENT)
│   ├── BasketSnapshot.php       (NON-PERSISTENT)
│   └── ContractState.php        (NON-PERSISTENT)
└── Webhook/
    └── WebhookLog.php           (PERSISTENT)
```

**Recommendation:** Option B (current) is better because:
- ✅ Maintains domain boundaries (DDD)
- ✅ No breaking changes to imports
- ✅ Persistence is implementation detail
- ✅ Documentation clearly identifies persistence type

---

## 📊 Impact Summary

### Changes Made
- **Directories Created:** 2 (Persistent/, ValueObject/)
- **Documentation Updated:** 3 files
- **New Documentation:** 1 file (this summary)
- **Code Changes:** 0 (no model code modified)
- **Tests:** All passing (50 tests, 138 assertions)

### Benefits Achieved
- ✅ Clear persistence boundaries documented
- ✅ Repository design clarified
- ✅ DDD patterns properly documented
- ✅ Serialization strategy documented
- ✅ Future developers have clear guidance

---

## 📚 Related Documentation

- [MODELS-ARCHITECTURE.md](MODELS-ARCHITECTURE.md) - Complete model architecture with persistence diagrams
- [TICKET-10-DATABASE-MODELS-STATUS.md](TICKET-10-DATABASE-MODELS-STATUS.md) - Implementation status
- [MODEL-CLEANUP-SUMMARY.md](MODEL-CLEANUP-SUMMARY.md) - Structure cleanup details
- Migration file: `migration/data/Version20251031140000.php` - Database schema

---

**Status:** ✅ COMPLETE
**Version:** 1.0
**Last Updated:** 2025-10-31
