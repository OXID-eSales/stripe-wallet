# Comparative Analysis: MCP/ACP Package Extraction

**Date:** 2026-02-09
**Author:** Architecture review
**Status:** Decision required before Sprint 47 implementation

---

## Question

Should MCP/ACP/UCP functionality be extracted into a separate `mcp-component` package?

Three architectural options are evaluated:

| Option | Structure |
|--------|-----------|
| **A: Current** | MCP lives inside `payment-component` + `stripe` (as planned in Sprints 47-54) |
| **B: Extracted, depends on PC** | `mcp-component` depends on `payment-component`; `stripe` optionally depends on `mcp-component` |
| **C: Extracted, PC + stripe are deps** | `mcp-component` depends on both `payment-component` and `stripe` |

---

## Current Dependency Graph (Option A)

```
┌──────────────────────────────────────────┐
│  stripe (oxid-esales/stripe-wallet)       │
│  "require": {                             │
│      "oxid-esales/payment-component": "*" │
│      "stripe/stripe-php": "^18.0"         │
│  }                                        │
│                                           │
│  src/Stripe/Mcp/                          │
│  ├── Controller/ (McpController, UCP..)   │
│  ├── Service/ (StripeAcpCheckoutService)  │
│  ├── Handler/ (SptTokenUsedHandler)       │
│  └── Http/ (CurlHttpClient)              │
└────────────────┬─────────────────────────┘
                 │ depends on
┌────────────────▼─────────────────────────┐
│  payment-component                        │
│  (oxid-esales/payment-component)          │
│                                           │
│  src/                                     │
│  ├── Contract/ (PaymentContract, etc.)    │
│  ├── EventSystem/ (Dispatcher, Handlers)  │
│  ├── Repository/ (ContractRepo, etc.)     │
│  ├── Service/ (ContractService, etc.)     │
│  ├── Webhook/ (Processor, Handlers)       │
│  └── Mcp/ ← NEW (Sprints 47-54)          │
│      ├── McpServer, McpToolInterface      │
│      ├── Auth/ (McpAuthGuard, OAuth)      │
│      ├── Acp/ (Checkout, Formatter, Tools)│
│      ├── Ucp/ (Profile, Negotiation)      │
│      ├── Notification/ (AgentNotification)│
│      ├── Http/ (HttpClientInterface)      │
│      └── Handler/ (McpRequestHandler)     │
└──────────────────────────────────────────┘
```

---

## Dependency Audit: What Does MCP Code Actually Need?

### Classes that TIGHTLY couple to payment-component core

These MCP classes import payment-component domain types (`Contract`, `EventSystem`, `Repository`, `Service`). They cannot exist without them.

| MCP Class | Depends On (from payment-component core) |
|-----------|----------------------------------------|
| `AbstractAcpCheckoutService` | `PaymentContractInterface`, `ContractServiceInterface`, `ContractRepositoryInterface`, `EventDispatcherInterface`, `EventContext` |
| `AcpResponseFormatter` | `PaymentContractInterface`, `BasketSnapshot` |
| `UcpResponseFormatter` | `PaymentContractInterface`, `BasketSnapshot` |
| `AgentNotificationHandler` | `HandlerInterface`, `ContractCommittedEvent`, `ContractFulfilledEvent`, `ContractCancelledEvent`, `PaymentContractInterface` |
| `AgentCallbackRegistry` | `ContractRepositoryInterface` |
| `McpRequestHandler` | `HandlerInterface`, `EventContext` |
| `ConditionTypeRegistry` (S49) | `ContractCondition` |
| All 6 ACP Tools | `AcpCheckoutServiceInterface` (which wraps contract operations) |

**Count: ~15 classes tightly coupled** — these represent ~65% of the total MCP code in payment-component.

### Classes that are protocol-only (no payment-component core deps)

These classes could work independently — they deal with protocol mechanics, not payment domain logic.

| MCP Class | Dependencies |
|-----------|-------------|
| `McpServer` | Only `McpToolInterface`, `AgentContext` (own types) |
| `McpAuthGuard` | Only `AgentContext`, `AuthResult` (own types) |
| `AgentContext` | None (pure value object) |
| `AuthResult` | `AgentContext` |
| `JwtTokenValidator` | `TokenValidationResult` (own type) |
| `UcpProfile` | `UcpCapability` (own type) |
| `UcpCapabilityNegotiationService` | `UcpCapability` (own type) |
| `UcpRequestValidator` | None |
| `HttpClientInterface` | None (pure interface) |
| `HttpClientResponse` | None (pure value object) |
| `AgentNotificationPayload` | None (pure value object) |
| `AgentNotificationResult` | None (pure value object) |
| `ProtectedResourceMetadata` | None (pure value object) |
| `CatalogSyncResult` | None (pure value object) |
| `ProductFeedGeneratorInterface` | None (pure interface) |
| `ProductFieldMapperInterface` | None (pure interface) |

**Count: ~16 classes standalone** — these represent ~35% of the total MCP code.

### Stripe-side MCP classes

These live in the stripe module and depend on both payment-component MCP types and stripe-specific types.

| Stripe MCP Class | MCP Dependencies | Stripe Dependencies |
|-----------------|-----------------|-------------------|
| `McpController` | `McpAuthGuardInterface`, `McpRequestReceivedEvent`, `EventContext` | OXID controller registration |
| `StripeAcpCheckoutService` | Extends `AbstractAcpCheckoutService` | `StripeAdapterInterface`, `SptPaymentService`, `ShopAdapterInterface` |
| `SptPaymentService` | `PaymentContractInterface` | `StripeAdapterInterface` |
| `CurlHttpClient` | `HttpClientInterface`, `HttpClientResponse` | None |
| `UcpCheckoutController` | `McpAuthGuardInterface`, `UcpRequestValidator`, `UcpResponseFormatterInterface`, `EventContext` | OXID controller |
| `SptTokenUsedHandler` | `ContractServiceInterface` | `WebhookEventHandlerInterface` |
| `HostedAcpOrderHandler` | `ContractServiceInterface`, `ShopOrderServiceInterface` | `WebhookEventHandlerInterface` |
| `ProductFeedController` | `McpAuthGuardInterface`, `EventContext` | OXID controller |
| `OxidProductFieldMapper` | `ProductFieldMapperInterface`, `ShopAdapterInterface` | OXID article model |

---

## Option A: MCP Inside payment-component + stripe (Current Plan)

```
stripe ──depends──→ payment-component (includes src/Mcp/)
```

### Advantages

| # | Advantage | Weight |
|---|-----------|--------|
| 1 | **Zero new packages** — no new composer.json, no new CI pipeline, no new versioning | High |
| 2 | **No dependency wiring complexity** — services.yaml stays two-way (stripe wires PC interfaces) | High |
| 3 | **Refactoring freedom** — MCP code can use any PC type without cross-package concerns | High |
| 4 | **Single test suite** — payment-component tests cover both domain and MCP in one run | Medium |
| 5 | **Faster iteration** — change MCP interface + domain model in one commit | High |
| 6 | **Natural cohesion** — MCP/ACP is *how agents talk to the payment system*, not a separate system | High |

### Disadvantages

| # | Disadvantage | Weight |
|---|-------------|--------|
| 1 | **payment-component grows** — adds ~52 new files (~2,400 lines) to an existing package | Medium |
| 2 | **All-or-nothing** — shops that don't want MCP still get it in their autoloader | Low |
| 3 | **Mixed concerns** — payment domain + protocol handling in same package | Medium |
| 4 | **Harder to reuse MCP server outside payments** — MCP server infrastructure (JSON-RPC router, auth) is useful beyond payments (inventory MCP, CRM MCP) | Low |

### Risk

Low. This is the simplest architecture. The main risk is that payment-component becomes bloated over time if more protocols are added beyond MCP/ACP/UCP.

---

## Option B: Separate mcp-component, depends on payment-component

```
stripe ──depends──→ mcp-component ──depends──→ payment-component
  │                    │
  └── optional ────────┘
```

Or more precisely:

```
┌────────────────────────────────────────┐
│  stripe (oxid-esales/stripe-wallet)     │
│  "require": {                           │
│      "oxid-esales/payment-component"    │
│      "oxid-esales/mcp-component"        │  ← optional, via "suggest"
│      "stripe/stripe-php"                │
│  }                                      │
│  src/Stripe/Mcp/ (same as now)          │
└───────┬──────────────┬─────────────────┘
        │              │
        ▼              ▼
┌───────────────┐  ┌───────────────────────┐
│ mcp-component │  │ payment-component      │
│ "require": {  │  │ (no MCP awareness)     │
│   "oxid-      │  │ src/Contract/          │
│    esales/    │──→│ src/EventSystem/       │
│    payment-   │  │ src/Repository/        │
│    component" │  │ src/Service/           │
│ }             │  │ src/Webhook/           │
│ src/Mcp/      │  └───────────────────────┘
│ src/Acp/      │
│ src/Ucp/      │
│ src/Http/     │
│ src/Notification/ │
└───────────────┘
```

### Advantages

| # | Advantage | Weight |
|---|-----------|--------|
| 1 | **Clean separation** — payment-component stays focused on contracts/events/repos | Medium |
| 2 | **Optional for merchants** — shops that don't need AI commerce skip the package | Low |
| 3 | **Independent versioning** — MCP spec evolves faster than payment contracts; `mcp-component v2.0` doesn't force `payment-component v2.0` | Medium |
| 4 | **Reusable MCP server** — other OXID modules could depend on mcp-component for non-payment MCP servers | Low |
| 5 | **Cleaner composer.json** — each package lists only its actual dependencies | Low |

### Disadvantages

| # | Disadvantage | Weight |
|---|-------------|--------|
| 1 | **Three packages to maintain** — 3 composer.json, 3 CI pipelines, 3 release cycles, 3 changelogs | **Critical** |
| 2 | **Version matrix hell** — mcp-component v1.2 requires payment-component >=1.5 <2.0, stripe requires mcp-component >=1.0... | **Critical** |
| 3 | **Tight coupling disguised as separation** — 65% of mcp-component classes import payment-component types; this is not a clean boundary | **Critical** |
| 4 | **services.yaml three-way wiring** — stripe must wire interfaces from BOTH packages; handler tags span packages; tool registration crosses boundaries | High |
| 5 | **Cross-package refactoring pain** — changing `PaymentContractInterface` requires updating mcp-component simultaneously, but they're separate repos with separate PRs/reviews/releases | High |
| 6 | **Testing complexity** — mcp-component tests need payment-component mocks; integration tests need all three packages | High |
| 7 | **Developer onboarding cost** — "clone 3 repos, symlink 3 paths, run 3 composers" vs "clone 2 repos" | Medium |
| 8 | **Circular knowledge** — mcp-component needs to "know" about contract lifecycle events (`ContractCommittedEvent`, etc.) to register notification handlers | High |

### The Tight Coupling Problem (Detail)

The fundamental issue: `AbstractAcpCheckoutService` is the heart of MCP/ACP, and its constructor is:

```php
public function __construct(
    protected readonly ContractServiceInterface $contractService,        // payment-component
    protected readonly ContractRepositoryInterface $contractRepository,  // payment-component
    protected readonly EventDispatcherInterface $eventDispatcher,        // payment-component
    protected readonly AcpResponseFormatterInterface $formatter          // mcp-component
)
```

This class **is** payment-component. Moving it to a separate package doesn't make it less coupled — it just makes the coupling cross a package boundary, which is worse.

Similarly, `AgentNotificationHandler` subscribes to:
- `ContractCommittedEvent` (payment-component)
- `ContractFulfilledEvent` (payment-component)
- `ContractCancelledEvent` (payment-component)
- `ContractFailedEvent` (payment-component)

This handler cannot function without intimate knowledge of the contract lifecycle. Putting it in a separate package creates a false boundary.

### Risk

High. The 3-package version matrix is the biggest risk. A breaking change in payment-component's `PaymentContractInterface` would require:
1. Release payment-component v2.0
2. Update mcp-component to match → release mcp-component v2.0
3. Update stripe to match → release stripe v2.0

This is a waterfall release chain for what should be a single atomic change.

---

## Option C: mcp-component depends on BOTH payment-component AND stripe

```
┌──────────────────────────────────┐
│  mcp-component                    │
│  "require": {                     │
│      "oxid-esales/payment-comp"   │
│      "oxid-esales/stripe-wallet"  │
│  }                                │
│  src/ (ALL MCP code, both sides)  │
└───────┬──────────────┬───────────┘
        │              │
        ▼              ▼
┌───────────────┐  ┌───────────────┐
│ payment-comp  │  │ stripe        │
│ (no MCP)      │  │ (no MCP)      │
└───────────────┘  └───────────────┘
```

### Advantages

| # | Advantage | Weight |
|---|-----------|--------|
| 1 | **payment-component stays pure** — zero MCP awareness | Medium |
| 2 | **stripe stays focused** — only payment processing, no protocol handling | Medium |
| 3 | **All MCP code in one package** — clear ownership of the MCP layer | Medium |
| 4 | **Opt-in** — install mcp-component to enable AI commerce | Low |

### Disadvantages

| # | Disadvantage | Weight |
|---|-------------|--------|
| 1 | **Inverted dependency — fundamentally wrong** — mcp-component would depend on stripe, but it should be the other way around (stripe is the concrete provider, MCP is the protocol layer) | **Fatal** |
| 2 | **Kills multi-provider** — an Unzer module can't use MCP without pulling in stripe as a dependency | **Fatal** |
| 3 | **Violates DIP** — high-level protocol depends on low-level provider implementation | **Fatal** |
| 4 | **Diamond dependency** — if someone wants Unzer + MCP and Stripe + MCP, they get both payment providers as transitive dependencies | **Fatal** |
| 5 | **All disadvantages of Option B** — 3 packages, version matrix, CI complexity | Critical |
| 6 | **Breaks the boundary rule** — "Could this work with PayPal?" fails for everything in mcp-component | High |

### Risk

**Fatal.** This option violates the Dependency Inversion Principle at the architectural level. The protocol layer (MCP/ACP/UCP) should not depend on a specific payment provider (Stripe). This would prevent any other provider module from using MCP.

---

## Quantitative Comparison

### Package Complexity

| Metric | Option A (Current) | Option B (Extract) | Option C (Inverted) |
|--------|-------------------|-------------------|-------------------|
| Packages to maintain | 2 | 3 | 3 |
| composer.json files | 2 | 3 | 3 |
| CI pipelines | 2 | 3 | 3 |
| Release coordination | Simple (2-way) | Complex (3-way chain) | Complex (inverted) |
| services.yaml wiring | 1 file (stripe) | 1 file but 3-way refs | 1 file but inverted |
| PHPUnit configs | 2 | 3 | 3 |
| Developer repos to clone | 2 | 3 | 3 |

### Dependency Graph Quality

| Metric | Option A | Option B | Option C |
|--------|----------|----------|----------|
| Direction | Clean ↓ | Clean ↓ | Inverted ↑ |
| Boundary violations | 0 | 0 (artificial) | Fatal |
| Cross-package imports | 0 | ~15 classes cross boundary | ~25 classes cross boundary |
| Circular dependencies | 0 | 0 | Potential |
| Multi-provider support | Yes | Yes | **No** |

### Cohesion Analysis

How much of mcp-component's code actually uses payment-component types?

```
Option B split:

mcp-component classes:     ~37 classes
  ├── Tightly coupled to PC: ~21 classes (57%)
  ├── Protocol-only:          ~16 classes (43%)
  └── Need stripe types:       0 classes (0%)

Conclusion: 57% of mcp-component would be "payment-component code
in disguise" — living in a separate package but importing all the
same types. This is **artificial separation**.
```

### The "Could This Work With PayPal?" Test

| Component | Option A | Option B | Option C |
|-----------|----------|----------|----------|
| McpServer + auth | PayPal module wires it via services.yaml | PayPal requires mcp-component (adds dependency) | PayPal gets Stripe as transitive dependency |
| AcpCheckoutService | PayPal extends AbstractAcpCheckoutService | Same, but from mcp-component | PayPal can't use it without Stripe |
| AcpResponseFormatter | Works — reads PaymentContractInterface | Works — reads PaymentContractInterface | Works but pulls Stripe |
| Tools | Work — delegate to service interface | Work — delegate to service interface | Work but pull Stripe |

---

## When Would Extraction Make Sense?

Extraction to a separate package would be justified if ANY of these were true:

| Condition | Current Reality | Justified? |
|-----------|----------------|-----------|
| MCP code has few dependencies on payment-component | 57% of classes import PC types | **No** |
| MCP evolves on a fundamentally different release cadence | MCP and payment contracts evolve together | **No** |
| Multiple OXID modules need MCP server (not just payment) | Only payment modules need it today | **No** |
| The package would have >50 dependents | Only 1-3 payment provider modules | **No** |
| payment-component is too large (>500 files) | ~60 files currently, ~112 after MCP | **No** |
| Different teams own MCP vs payment-component | Same team | **No** |

### Future Threshold

Revisit this decision when:
- **payment-component exceeds ~200 files** — growth pressure justifies split
- **3+ provider modules** (Stripe, Unzer, PayPal) all use MCP — the shared infrastructure justifies its own package
- **Non-payment MCP servers emerge** — CRM, inventory, or logistics modules want `McpServer` without payment-component
- **MCP spec breaking changes** outpace payment-component releases — independent versioning becomes valuable

---

## Recommendation

### **Option A: Keep MCP inside payment-component + stripe**

**Rationale:**

1. **57% tight coupling** — more than half of the MCP code is essentially payment-component domain code with MCP serialization. Extracting it creates an artificial boundary that adds complexity without reducing coupling.

2. **2 packages > 3 packages** — every additional package multiplies maintenance: CI, releases, version constraints, developer setup, documentation, issue trackers.

3. **The boundary rule already works** — "Could this work with PayPal?" is answered by the two-module split (payment-component vs stripe). MCP adds a protocol layer on top of the same domain — it doesn't introduce a new domain.

4. **Premature optimization** — with only one provider module (stripe), there's no evidence that MCP extraction would benefit anyone. The theoretical "reusable MCP server" scenario has zero current demand.

5. **Namespace provides separation without packages** — `OxidEsales\PaymentComponent\Mcp\` is a clean namespace boundary within payment-component. A developer knows exactly where MCP code lives. Moving it to a separate package adds dependency management overhead for the same logical organization.

### Implementation Note

To keep the door open for future extraction, follow these practices during Sprint 47-54:

- **Keep `Mcp\` as a self-contained namespace** — no MCP class should live outside `src/Mcp/`
- **MCP classes should import from payment-component root interfaces** — `PaymentContractInterface`, not `PaymentContract` concrete class
- **No circular imports** — payment-component core must never import from `Mcp\` namespace

This ensures that if extraction is needed later, it's a file-move operation + composer.json update, not a refactoring project.

---

## Summary Matrix

| Criterion | Option A | Option B | Option C |
|-----------|:--------:|:--------:|:--------:|
| Implementation complexity | Low | High | High |
| Maintenance burden | Low | High | High |
| Multi-provider support | Yes | Yes | **No** |
| DIP compliance | Yes | Yes | **No** |
| Boundary cleanliness | Good | Artificial | Broken |
| Future extractability | Preserved | N/A | N/A |
| Version management | Simple | Complex | Complex |
| Developer experience | Good | Degraded | Degraded |
| **Verdict** | **Recommended** | Premature | Rejected |
