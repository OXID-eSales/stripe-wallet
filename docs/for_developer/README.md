# Developer Documentation

**Module:** Stripe Payment Module for OXID eShop 7.4+
**Module ID:** `oe_payments_stripe_wallet`
**Namespace:** `OxidEsales\Payments\Stripe\`

---

## Two Modules, One System

```
┌────────────────────────────────────────────────────────┐
│                    stripe module                       │
│  Namespace: OxidEsales\Payments\Stripe\               │
│  Role: Stripe-specific controllers, adapters, handlers │
│                                                        │
│  Implements interfaces defined by payment-component    │
│                         ↓ depends on                   │
├────────────────────────────────────────────────────────┤
│                  payment-component                     │
│  Namespace: OxidEsales\PaymentComponent\               │
│  Role: Provider-agnostic contracts, events, repos      │
│                                                        │
│  Defines: Interfaces, Domain Models, Event System      │
│  Owns: All 6 database tables, all migrations           │
└────────────────────────────────────────────────────────┘
```

The **stripe** module never touches the database schema directly. All tables and migrations belong to **payment-component**. Stripe implements interfaces, registers event handlers, and wires everything via `services.yaml`.

---

## Directory Structure (stripe module)

```
src/Stripe/
├── Adapter/            # StripeAdapter, OxidShopAdapter, OxidSessionAdapter
├── Command/            # CLI commands (reconciliation)
├── Controller/         # Payment, Order, Webhook, Admin controllers
├── Core/               # Events (onActivate), ViewConfig, StripeDefinitions
├── EventSystem/
│   ├── Event/          # Stripe-specific events (8 event classes)
│   └── Handler/        # Stripe event handlers (9 handler classes)
├── Model/              # OXID model extensions (Order, Payment)
├── Service/            # Business logic services (checkout, capture, refund)
├── Webhook/            # Webhook parsing and processing
└── WebhookHandler/     # Individual webhook event handlers
```

---

## Document Index

| # | Document | What It Covers |
|---|----------|---------------|
| 1 | [Module Principles](01-module-principles.md) | Contract-first model, event-driven architecture, handler registration, design patterns |
| 2 | [Payment-Component Dependency](02-payment-component-dependency.md) | What payment-component provides, interface mappings, services.yaml wiring, database schema |
| 3 | [Extending the Stripe Module](03-extending-the-stripe-module.md) | 6 extension patterns with code examples, full subscription/booking examples, testing, pitfalls |

---

## Prerequisites

- PHP 8.2+ with Composer
- OXID eShop 7.4+ with module system
- Familiarity with SOLID principles and dependency injection
- Understanding of Symfony DI container and service tagging
- Basic knowledge of Stripe API concepts (PaymentIntents, Checkout Sessions)

---

## Related Documentation

- **Architecture reference:** [`docs/architecture/`](../architecture/) — detailed system design (5 documents)
- **Merchant documentation:** [`docs/for_merchant/`](../for_merchant/) — configuration and usage
- **OXID module development:** [OXID eShop Module Docs](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/)
