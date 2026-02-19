# Sprint 2: Add Event System Logging

**Date:** 2025-12-17
**Status:** DONE
**Branch:** b-7.4.x-code-review-STRP-75

---

## Problem Statement

Debugging production issues in the event-driven payment flow was difficult because there was no visibility into:
- Which handlers were being invoked
- What data was being passed through the event system
- Where the flow was failing or behaving unexpectedly

---

## Solution

Implemented a dedicated event file logger that writes to `log/osc/stripe_events.log` with:
- Timestamps
- Handler names
- Event data (JSON formatted)
- Flow progression markers

---

## Implementation

### 1. EventFileLoggerFactory

Created a factory to produce file loggers for the event system:

```php
// src/Stripe/Service/Factory/EventFileLoggerFactory.php
final class EventFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_events.log';

    public function create(): FileLoggerInterface
    {
        $shopDir = $this->getShopDir();
        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;
        return new FileLogger($logFilePath, 'EVENT');
    }
}
```

### 2. Service Registration

Added the logger to `services.yaml`:

```yaml
OxidSolutionCatalysts\Payments\Stripe\Service\Factory\EventFileLoggerFactory:
  public: false

stripe.events.file_logger:
  class: OxidSolutionCatalysts\Payments\Component\Service\FileLoggerInterface
  factory: ['@OxidSolutionCatalysts\Payments\Stripe\Service\Factory\EventFileLoggerFactory', 'create']
  public: false
```

### 3. Handler Instrumentation

Added logging to event handlers via constructor injection:

```php
public function __construct(
    // ... other dependencies
    private readonly ?FileLoggerInterface $eventLogger = null
) {
}

private function logEvent(string $message, array $context = []): void
{
    if ($this->eventLogger !== null) {
        $this->eventLogger->log($message, $context);
    }
}
```

---

## Files Created

| File | Purpose |
|------|---------|
| `src/Stripe/Service/Factory/EventFileLoggerFactory.php` | Factory for event system logger |

## Files Modified

| File | Changes |
|------|---------|
| `services.yaml` | Added logger factory and service |
| `StripeCheckoutReturnHandler.php` | Added event logging |
| `PaymentAuthorizedEventHandler.php` | Added event logging |
| `StripeOrderCreationHandler.php` | Added event logging |

---

## Log Output Example

```
[2025-12-17 10:57:24] EVENT StripeCheckoutReturnHandler::handle() START
[2025-12-17 10:57:24] EVENT Step 1: Extract parameters {"sessionId":"cs_test_xxx"}
[2025-12-17 10:57:25] EVENT Step 2: Validating return with service...
[2025-12-17 10:57:25] EVENT Step 2b: Validation result {"successful":true,"paymentStatus":"unpaid"}
[2025-12-17 10:57:25] EVENT PaymentAuthorizedEventHandler::handle() START
[2025-12-17 10:57:25] EVENT StripeOrderCreationHandler: Order created {"orderId":"xxx","orderNumber":56}
[2025-12-17 10:57:25] EVENT StripeCheckoutReturnHandler::handle() END {"redirectTarget":"thankyou"}
```

---

## Benefits

1. **Debugging**: Easy to trace event flow in production
2. **Non-intrusive**: Optional logger, null-safe implementation
3. **Structured**: JSON context for easy parsing
4. **Separate file**: Doesn't pollute main OXID logs

---

## Usage

View event logs:
```bash
cat source/log/osc/stripe_events.log
tail -f source/log/osc/stripe_events.log  # Live monitoring
```

