# Sprint 49: Custom ContractCondition Types for Agent-Specific Conditions

**Date:** 2026-02-09
**Status:** TODO
**Priority:** Medium
**Prerequisites:** Sprint 47 (MCP/ACP foundations)
**Principle:** Make the condition system extensible without breaking existing handlers. Provider modules register condition types at DI time — no runtime reflection, no open whitelist.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | OCP: extend condition types without modifying `ContractCondition` internals |
| DI | Condition type registry injected via services.yaml |
| LSP | New condition types behave identically to existing ones |
| DRY | Single registration point — no duplicate whitelist maintenance |
| No Overengineering | Registry pattern only — no condition strategy classes, no condition plugins |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

The current `ContractCondition::validateType()` method hardcodes 4 condition types. This sprint makes the whitelist **extensible** so that:

1. Agentic commerce can add `agent_identity_verified` and `agent_consent_confirmed` conditions
2. Future modules can register custom condition types without forking payment-component
3. Existing code continues to work without changes (backward-compatible)

### Current Problem

```php
// payment-component/src/Contract/ContractCondition.php (lines 107-119)
private function validateType(string $type): void
{
    $validTypes = [
        self::TYPE_PAYMENT_AUTHORIZED,  // 'payment_authorized'
        self::TYPE_FRAUD_CHECK,          // 'fraud_check'
        self::TYPE_COMPLIANCE_CHECK,     // 'compliance_check'
        self::TYPE_ADDRESS_VALIDATED,    // 'address_validated'
    ];

    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException("Invalid condition type: {$type}");
    }
}
```

Any custom type throws `InvalidArgumentException`. The workaround (storing conditions as metadata) loses type safety and condition lifecycle tracking.

### Solution: Condition Type Registry

Replace the hardcoded array with a `ConditionTypeRegistryInterface` injected via DI. The registry is populated at container build time from tagged services — same pattern as `payment.event_handler` and `payment.mcp_tool`.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  ContractCondition                                                │
│  └─ validateType() → $this->registry->isValid($type)            │
│                                                                   │
│  ConditionTypeRegistryInterface                                   │
│  └─ isValid(string $type): bool                                  │
│  └─ getRegisteredTypes(): array                                  │
│                                                                   │
│  ConditionTypeRegistry                                            │
│  └─ constructor(iterable $providers) — tagged_iterator            │
│  └─ merges all providers into single type set                    │
│                                                                   │
│  ConditionTypeProviderInterface                                   │
│  └─ getConditionTypes(): array<string>                           │
└──────────────────────────────────────────────────────────────────┘

 ┌─────────────────────────┐      ┌──────────────────────────────┐
 │ CoreConditionTypeProvider│      │ AgentConditionTypeProvider    │
 │ (payment-component)      │      │ (payment-component Mcp/)     │
 │                          │      │                              │
 │ payment_authorized       │      │ agent_identity_verified      │
 │ fraud_check              │      │ agent_consent_confirmed      │
 │ compliance_check         │      │                              │
 │ address_validated        │      │                              │
 └─────────────────────────┘      └──────────────────────────────┘
          ↓ tagged: payment.condition_type_provider ↓
 ┌──────────────────────────────────────────────────────────────┐
 │  ConditionTypeRegistry  (collects all via !tagged_iterator)   │
 └──────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `ConditionTypeRegistryInterface` | Yes | payment-component | Core domain extensibility |
| `ConditionTypeRegistry` | Yes | payment-component | Collects tagged providers |
| `ConditionTypeProviderInterface` | Yes | payment-component | Extension point contract |
| `CoreConditionTypeProvider` | Yes | payment-component | Built-in 4 types |
| `AgentConditionTypeProvider` | Yes | payment-component (Mcp/) | Agent-specific types |
| `ContractCondition` changes | Yes | payment-component | Uses registry instead of hardcoded array |

All changes are in payment-component. Stripe's `services.yaml` only needs to wire the registry and tag the providers.

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Contract/
├── ConditionTypeRegistryInterface.php
├── ConditionTypeRegistry.php
└── ConditionTypeProviderInterface.php

payment-component/src/Contract/Provider/
├── CoreConditionTypeProvider.php
└── AgentConditionTypeProvider.php
```

### A1. ConditionTypeProviderInterface

**File:** `payment-component/src/Contract/ConditionTypeProviderInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

interface ConditionTypeProviderInterface
{
    /**
     * Return condition types registered by this provider.
     *
     * @return array<string> Condition type strings (e.g., ['payment_authorized', 'fraud_check'])
     */
    public function getConditionTypes(): array;
}
```

### A2. ConditionTypeRegistryInterface

**File:** `payment-component/src/Contract/ConditionTypeRegistryInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

interface ConditionTypeRegistryInterface
{
    /**
     * Check if a condition type is registered and valid.
     */
    public function isValid(string $type): bool;

    /**
     * Get all registered condition types.
     *
     * @return array<string>
     */
    public function getRegisteredTypes(): array;
}
```

### A3. ConditionTypeRegistry

**File:** `payment-component/src/Contract/ConditionTypeRegistry.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

class ConditionTypeRegistry implements ConditionTypeRegistryInterface
{
    /** @var array<string, true> */
    private array $types = [];

    /**
     * @param iterable<ConditionTypeProviderInterface> $providers Collected via !tagged_iterator
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            foreach ($provider->getConditionTypes() as $type) {
                $this->types[$type] = true;
            }
        }
    }

    public function isValid(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function getRegisteredTypes(): array
    {
        return array_keys($this->types);
    }
}
```

### A4. CoreConditionTypeProvider

**File:** `payment-component/src/Contract/Provider/CoreConditionTypeProvider.php`

Provides the original 4 types. Backward-compatible — existing code keeps working.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;

class CoreConditionTypeProvider implements ConditionTypeProviderInterface
{
    public function getConditionTypes(): array
    {
        return [
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            ContractCondition::TYPE_FRAUD_CHECK,
            ContractCondition::TYPE_COMPLIANCE_CHECK,
            ContractCondition::TYPE_ADDRESS_VALIDATED,
        ];
    }
}
```

### A5. AgentConditionTypeProvider

**File:** `payment-component/src/Contract/Provider/AgentConditionTypeProvider.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;

class AgentConditionTypeProvider implements ConditionTypeProviderInterface
{
    /**
     * Agent identity verified: confirms the AI agent is authorized to act on buyer's behalf.
     */
    public const TYPE_AGENT_IDENTITY_VERIFIED = 'agent_identity_verified';

    /**
     * Agent consent confirmed: buyer has approved the specific purchase through the agent.
     */
    public const TYPE_AGENT_CONSENT_CONFIRMED = 'agent_consent_confirmed';

    public function getConditionTypes(): array
    {
        return [
            self::TYPE_AGENT_IDENTITY_VERIFIED,
            self::TYPE_AGENT_CONSENT_CONFIRMED,
        ];
    }
}
```

### A6. ContractCondition Modification

**File:** `payment-component/src/Contract/ContractCondition.php`

**Changes:**
1. Inject `ConditionTypeRegistryInterface` via static setter (for backward compatibility with factory methods)
2. Replace hardcoded `$validTypes` array with registry call
3. Keep constants and factory methods unchanged

```php
// BEFORE (lines 107-119):
private function validateType(string $type): void
{
    $validTypes = [
        self::TYPE_PAYMENT_AUTHORIZED,
        self::TYPE_FRAUD_CHECK,
        self::TYPE_COMPLIANCE_CHECK,
        self::TYPE_ADDRESS_VALIDATED,
    ];

    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException("Invalid condition type: {$type}");
    }
}

// AFTER:
private static ?ConditionTypeRegistryInterface $registry = null;

/**
 * Inject the registry at boot time (called from DI container).
 * When null, falls back to hardcoded types for backward compatibility.
 */
public static function setConditionTypeRegistry(?ConditionTypeRegistryInterface $registry): void
{
    self::$registry = $registry;
}

private function validateType(string $type): void
{
    if (self::$registry !== null) {
        if (!self::$registry->isValid($type)) {
            throw new InvalidArgumentException("Invalid condition type: {$type}");
        }
        return;
    }

    // Fallback: hardcoded types (backward compatibility when no DI container)
    $validTypes = [
        self::TYPE_PAYMENT_AUTHORIZED,
        self::TYPE_FRAUD_CHECK,
        self::TYPE_COMPLIANCE_CHECK,
        self::TYPE_ADDRESS_VALIDATED,
    ];

    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException("Invalid condition type: {$type}");
    }
}
```

### A7. New Factory Methods on ContractCondition

```php
// Add to ContractCondition class:
public static function agentIdentityVerified(): self
{
    return new self(AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED);
}

public static function agentConsentConfirmed(): self
{
    return new self(AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED);
}
```

### A8. Registry Boot Service

**File:** `payment-component/src/Contract/ConditionTypeRegistryBootService.php`

Called at DI container boot to inject the registry into the static `ContractCondition`.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract;

class ConditionTypeRegistryBootService
{
    public function __construct(ConditionTypeRegistryInterface $registry)
    {
        ContractCondition::setConditionTypeRegistry($registry);
    }
}
```

---

## Part B: stripe Module Changes

### B1. services.yaml Additions

```yaml
# === Condition Type Registry ===

OxidEsales\PaymentComponent\Contract\ConditionTypeRegistryInterface:
    class: OxidEsales\PaymentComponent\Contract\ConditionTypeRegistry
    arguments:
        $providers: !tagged_iterator payment.condition_type_provider

# Core condition types (4 built-in)
OxidEsales\PaymentComponent\Contract\Provider\CoreConditionTypeProvider:
    tags: [{ name: payment.condition_type_provider }]

# Agent condition types (2 new)
OxidEsales\PaymentComponent\Contract\Provider\AgentConditionTypeProvider:
    tags: [{ name: payment.condition_type_provider }]

# Boot service — injects registry into ContractCondition at container init
OxidEsales\PaymentComponent\Contract\ConditionTypeRegistryBootService:
    tags: [{ name: kernel.event_subscriber }]
```

### B2. Handler Usage Example

In `StripeAcpCheckoutService.createCheckout()`, agent conditions can now be added:

```php
// After contract creation in ACP flow:
$contract->addCondition(ContractCondition::agentIdentityVerified());
$contract->addCondition(ContractCondition::agentConsentConfirmed());

// Later, when agent identity is validated:
$contract->fulfillCondition(AgentConditionTypeProvider::TYPE_AGENT_IDENTITY_VERIFIED);

// When buyer confirms via agent:
$contract->fulfillCondition(AgentConditionTypeProvider::TYPE_AGENT_CONSENT_CONFIRMED);
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `src/Contract/ConditionTypeProviderInterface.php` | Extension point | ~15 |
| 2 | payment-component | `src/Contract/ConditionTypeRegistryInterface.php` | Registry contract | ~18 |
| 3 | payment-component | `src/Contract/ConditionTypeRegistry.php` | Collects providers | ~30 |
| 4 | payment-component | `src/Contract/Provider/CoreConditionTypeProvider.php` | Built-in 4 types | ~20 |
| 5 | payment-component | `src/Contract/Provider/AgentConditionTypeProvider.php` | Agent types | ~25 |
| 6 | payment-component | `src/Contract/ConditionTypeRegistryBootService.php` | DI boot injection | ~15 |
| 7 | payment-component | `src/Contract/ContractCondition.php` | **Modified** — registry injection | ~+25 |
| | | **Total new** | | **~148** |

---

## TDD Approach

### Step 1: ConditionTypeRegistry Tests
Test that registry collects types from multiple providers. Test `isValid()` for known and unknown types. Test empty registry.

### Step 2: CoreConditionTypeProvider Tests
Test returns exactly 4 types. Test all types match `ContractCondition` constants.

### Step 3: AgentConditionTypeProvider Tests
Test returns exactly 2 types. Test type string values.

### Step 4: ContractCondition Integration Tests
Test that with registry injected, custom types are accepted. Test that without registry (null), fallback to hardcoded types. Test that invalid types still throw `InvalidArgumentException`.

### Step 5: Factory Method Tests
Test `ContractCondition::agentIdentityVerified()` creates correct type. Test `ContractCondition::agentConsentConfirmed()` creates correct type.

### Step 6: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `ConditionTypeRegistry` collects types from all tagged providers
- [ ] `CoreConditionTypeProvider` provides all 4 existing types
- [ ] `AgentConditionTypeProvider` provides `agent_identity_verified` and `agent_consent_confirmed`
- [ ] `ContractCondition` accepts custom types when registry is injected
- [ ] `ContractCondition` falls back to hardcoded types when registry is null (backward compat)
- [ ] `ContractCondition` still rejects unknown types (throws `InvalidArgumentException`)
- [ ] Factory methods `agentIdentityVerified()` and `agentConsentConfirmed()` work
- [ ] Existing tests for `ContractCondition` continue to pass unchanged
- [ ] A third-party module can register custom types by tagging a provider
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. `ContractCondition::agentIdentityVerified()` creates a valid condition
2. `ContractCondition::agentConsentConfirmed()` creates a valid condition
3. A custom provider can register `my_custom_check` without modifying payment-component
4. All existing condition types (`payment_authorized`, `fraud_check`, etc.) work unchanged
5. `ConditionTypeRegistry::getRegisteredTypes()` returns all 6 types (4 core + 2 agent)
6. Contracts with agent conditions follow the same lifecycle as contracts with core conditions
