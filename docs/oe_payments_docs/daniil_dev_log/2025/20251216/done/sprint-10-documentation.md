# Sprint 10: Documentation Updates

**Status:** PENDING
**Priority:** LOW
**Estimated Effort:** 1 hour
**Depends On:** All previous sprints

---

## Objective

Update architecture documentation to reflect the new delayed/manual capture feature.

---

## Documentation Files to Update

### 1. Architecture Overview

**File:** `docs/payment-component/architecture/00-overview.md`

Add section on capture modes:

```markdown
## Capture Modes

The module supports two capture modes:

### Automatic Capture (Default)
- Payment is captured immediately upon authorization
- Funds are transferred to merchant instantly
- Contract flow: PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED

### Manual Capture
- Payment is only authorized (funds reserved)
- Merchant captures later (e.g., when shipping)
- Contract flow: PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
- Authorizations expire after 7 days if not captured

Configure via: Extensions → Modules → Stripe → Settings → Capture Mode
```

### 2. Contract State Machine

**File:** `docs/payment-component/architecture/01-architecture-layers.md`

Update state machine diagram:

```markdown
## Contract State Machine (Updated)

┌───────┐     ┌─────────┐     ┌────────────┐     ┌─────────────────┐
│ DRAFT │────▶│ PENDING │────▶│ AUTHORIZED │────▶│ READY_TO_COMMIT │
└───────┘     └─────────┘     └────────────┘     └─────────────────┘
                   │                │                      │
                   │                │                      ▼
                   │                ▼               ┌───────────┐
                   │           ┌─────────┐        │ COMMITTED │
                   │           │ EXPIRED │        └─────┬─────┘
                   │           └─────────┘              │
                   │                                     ▼
                   └───────────────────────────▶ ┌───────────┐
                     (automatic capture)          │ FULFILLED │
                                                  └───────────┘

### State Transitions

| From | To | Trigger |
|------|-----|---------|
| DRAFT | PENDING | Contract created, payment initiated |
| PENDING | AUTHORIZED | Payment authorized (manual capture) |
| PENDING | READY_TO_COMMIT | Payment captured (automatic capture) |
| AUTHORIZED | READY_TO_COMMIT | Manual capture executed |
| AUTHORIZED | EXPIRED | Authorization timeout (7 days) |
| READY_TO_COMMIT | COMMITTED | Order created |
| COMMITTED | FULFILLED | Order completed |
```

### 3. Capture & Refund Operations

**File:** `docs/payment-component/architecture/07-capture-refund-operations.md`

Add section on manual capture configuration:

```markdown
## Manual Capture Mode

### Configuration

Set capture mode in module settings:

```php
// metadata.php
['group' => 'STRIPE_GENERAL', 'name' => 'sStripeCaptureMode', 'type' => 'select', 'value' => 'automatic', 'constraints' => 'automatic|manual'],
```

### Event Flow for Manual Capture

1. User completes checkout → PaymentIntent status: `requires_capture`
2. Contract transitions: PENDING → AUTHORIZED
3. Admin clicks "Capture" → CaptureRequestedEvent emitted
4. StripeCaptureHandler calls Stripe API
5. PaymentIntent captured → PaymentCapturedEvent emitted
6. Contract transitions: AUTHORIZED → READY_TO_COMMIT
7. Order created, contract fulfilled

### Admin Backend

Orders with authorized payments display:
- Payment status: "Authorized (awaiting capture)"
- "Capture Full Amount" button
- "Capture Partial Amount" input
- Authorization expiration date
```

### 4. Module Configuration

**File:** `docs/payment-component/architecture/11-module-configuration.md` (new or update)

```markdown
# Module Configuration

## Capture Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `sStripeCaptureMode` | select | `automatic` | `automatic` = instant capture, `manual` = delayed capture |

### Automatic Mode
- Best for digital goods, instant delivery
- Funds transferred immediately
- Simpler order management

### Manual Mode
- Best for physical goods, delayed shipping
- Funds reserved but not captured
- Capture when ready to ship
- Supports partial captures
- Authorizations expire after 7 days

### Configuring via Admin

1. Go to Extensions → Modules → Stripe Payment Gateway
2. Click Settings tab
3. Find "Capture Mode" dropdown
4. Select "Automatic" or "Manual"
5. Save settings

### Configuring via CLI

```bash
# View current setting
bin/oe-console oe:module:configuration:show osc_stripe_wallet

# Set to manual capture
bin/oe-console oe:module:configuration:set osc_stripe_wallet sStripeCaptureMode manual
```
```

### 5. Service Catalog

**File:** `docs/payment-component/architecture/12-service-catalog.md`

Add new services:

```markdown
## Capture Services

### CaptureConfigurationService

**Location:** `src/Stripe/Service/CaptureConfigurationService.php`

Reads and validates capture mode configuration.

```php
interface CaptureConfigurationService
{
    public function getCaptureMode(): string;
    public function isAutomaticCapture(): bool;
    public function isManualCapture(): bool;
    public function getStripeCaptureMethod(): string;
}
```

### StripeCaptureHandler

**Location:** `src/Stripe/EventSystem/Handler/StripeCaptureHandler.php`

Handles `CaptureRequestedEvent` and executes capture via Stripe API.
```

---

## New Documentation Files

### 1. Manual Capture Guide

**File:** `docs/payment-component/guides/manual-capture-guide.md` (NEW)

```markdown
# Manual Capture Guide

## Overview

Manual capture allows merchants to authorize payments at checkout but capture funds later, typically when goods are shipped.

## When to Use Manual Capture

- Physical goods that ship later
- Pre-orders with delayed fulfillment
- Custom/made-to-order products
- Marketplace scenarios requiring seller confirmation

## When NOT to Use Manual Capture

- Digital goods delivered immediately
- Services rendered instantly
- High-volume automated processing

## Setup

1. Enable manual capture in module settings
2. Train staff on capture workflow
3. Set up shipping triggers (optional)

## Daily Operations

### Viewing Authorized Orders

1. Go to Administer Orders → Orders
2. Filter by payment status "Authorized"
3. Orders awaiting capture are highlighted

### Capturing a Payment

1. Open order details
2. Go to Stripe tab
3. Click "Capture Full Amount" or enter partial amount
4. Confirm the capture
5. Order status updates automatically

### Handling Expirations

- Authorizations expire after 7 days
- Set up alerts for expiring authorizations
- Consider auto-capture cron job for safety

## Troubleshooting

### Capture Failed
- Check Stripe dashboard for error details
- Verify card is still valid
- Contact customer if needed

### Authorization Expired
- Cannot capture expired authorizations
- Must request new payment from customer
- Consider extending authorization (business agreement needed)
```

---

## CLAUDE.md Update

**File:** `CLAUDE.md`

Add capture mode information:

```markdown
## Capture Modes

The module supports two capture modes configured via `sStripeCaptureMode`:

- **automatic** (default): Instant capture on authorization
- **manual**: Delayed capture, admin action required

### Contract States with Manual Capture

```
DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
```

The `AUTHORIZED` state is only used when manual capture is enabled.
```

---

## Acceptance Criteria

- [ ] Architecture overview updated with capture modes section
- [ ] State machine diagram includes AUTHORIZED state
- [ ] Capture operations doc updated with manual capture flow
- [ ] New service documentation added
- [ ] Manual capture guide created
- [ ] CLAUDE.md updated
- [ ] All documentation follows existing style
- [ ] No broken links
- [ ] Code examples are accurate

---

## Notes

- Keep documentation concise and practical
- Include code examples where helpful
- Update diagrams to match implementation
- Cross-reference related documentation
