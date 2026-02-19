# Stripe SDK v18 - Webhook Endpoint Management Reference

## Overview

Stripe allows programmatic management of webhook endpoints via the API. This is useful for:
- Auto-registering endpoints during module installation
- Validating that the correct URL is configured
- Updating endpoints when shop URL changes
- Listing all registered endpoints for diagnostics

---

## API Endpoints

| Operation | HTTP Method | Endpoint |
|-----------|-------------|----------|
| Create | POST | `/v1/webhook_endpoints` |
| Update | POST | `/v1/webhook_endpoints/:id` |
| Retrieve | GET | `/v1/webhook_endpoints/:id` |
| List | GET | `/v1/webhook_endpoints` |
| Delete | DELETE | `/v1/webhook_endpoints/:id` |

---

## PHP SDK Examples

### Initialize Client

```php
use Stripe\StripeClient;

$stripe = new StripeClient('sk_test_...');
```

### Create Webhook Endpoint

```php
$webhookEndpoint = $stripe->webhookEndpoints->create([
    'url' => 'https://shop.example.com/index.php?cl=stripe_webhook',
    'enabled_events' => [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.captured',
        'charge.refunded',
        'charge.dispute.created',
        'checkout.session.completed',
    ],
    'api_version' => '2023-10-16',  // Optional: lock to specific API version
    'description' => 'OXID eShop Stripe Payment Module',
    'metadata' => [
        'shop_id' => '1',
        'module_version' => '1.0.0',
    ],
]);

// Response object properties:
// $webhookEndpoint->id          = 'we_1Mr5jULkdIwHu7ix1ibLTM0x'
// $webhookEndpoint->secret      = 'whsec_...' (IMPORTANT: Store this!)
// $webhookEndpoint->url         = 'https://...'
// $webhookEndpoint->status      = 'enabled'
// $webhookEndpoint->created     = 1680000000
// $webhookEndpoint->livemode    = false
// $webhookEndpoint->enabled_events = ['payment_intent.succeeded', ...]
```

### Update Webhook Endpoint

```php
$webhookEndpoint = $stripe->webhookEndpoints->update(
    'we_1Mr5jULkdIwHu7ix1ibLTM0x',
    [
        'url' => 'https://new-domain.com/index.php?cl=stripe_webhook',
        'enabled_events' => [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'charge.refunded',
        ],
        'disabled' => false,  // Enable/disable webhook
        'description' => 'Updated description',
        'metadata' => ['updated_at' => date('Y-m-d')],
    ]
);
```

### List All Webhook Endpoints

```php
$endpoints = $stripe->webhookEndpoints->all(['limit' => 50]);

foreach ($endpoints->data as $endpoint) {
    echo sprintf(
        "%s: %s [%s]\n",
        $endpoint->id,
        $endpoint->url,
        $endpoint->status
    );
}

// Example output:
// we_1Mr5jU...: https://shop.example.com/index.php?cl=stripe_webhook [enabled]
// we_1Abc12...: https://old-domain.com/webhook [disabled]
```

### Retrieve Single Endpoint

```php
$endpoint = $stripe->webhookEndpoints->retrieve('we_1Mr5jULkdIwHu7ix1ibLTM0x');

echo $endpoint->url;
echo $endpoint->status;
```

### Delete Webhook Endpoint

```php
$stripe->webhookEndpoints->delete('we_1Mr5jULkdIwHu7ix1ibLTM0x');
```

---

## Webhook Endpoint Object Structure

```json
{
    "id": "we_1Mr5jULkdIwHu7ix1ibLTM0x",
    "object": "webhook_endpoint",
    "api_version": "2023-10-16",
    "application": null,
    "created": 1680000000,
    "description": "OXID eShop webhook",
    "enabled_events": [
        "payment_intent.succeeded",
        "payment_intent.payment_failed"
    ],
    "livemode": false,
    "metadata": {
        "shop_id": "1"
    },
    "secret": "whsec_...",
    "status": "enabled",
    "url": "https://shop.example.com/index.php?cl=stripe_webhook"
}
```

---

## Event Types Reference

### Payment Intent Events

| Event | When Fired |
|-------|-----------|
| `payment_intent.created` | PaymentIntent created |
| `payment_intent.succeeded` | Payment successful |
| `payment_intent.payment_failed` | Payment failed |
| `payment_intent.canceled` | Payment canceled |
| `payment_intent.processing` | Payment processing |
| `payment_intent.requires_action` | Customer action needed (3DS) |

### Charge Events

| Event | When Fired |
|-------|-----------|
| `charge.succeeded` | Charge succeeded |
| `charge.failed` | Charge failed |
| `charge.captured` | Manual capture completed |
| `charge.refunded` | Refund issued |
| `charge.dispute.created` | Dispute/chargeback opened |
| `charge.dispute.closed` | Dispute resolved |

### Checkout Session Events

| Event | When Fired |
|-------|-----------|
| `checkout.session.completed` | Checkout completed |
| `checkout.session.expired` | Session expired |
| `checkout.session.async_payment_succeeded` | Async payment succeeded |
| `checkout.session.async_payment_failed` | Async payment failed |

---

## Signature Verification

### Generate Signature (Test Helper)

```php
function generateTestSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}
```

### Verify Signature (Stripe SDK)

```php
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

try {
    $event = Webhook::constructEvent(
        $payload,
        $_SERVER['HTTP_STRIPE_SIGNATURE'],
        $webhookSecret,
        300  // tolerance in seconds (default: 300 = 5 minutes)
    );

    // Process $event
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}
```

---

## Best Practices

### 1. Store Webhook Secret Securely

```php
// Good: Environment variable or encrypted config
$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');

// Bad: Hardcoded
$webhookSecret = 'whsec_abc123';
```

### 2. Use Idempotency

```php
// Check if event already processed
$eventId = $event->id;
$exists = $this->webhookLogRepository->findByEventId($eventId);

if ($exists) {
    return; // Already processed - safe to skip
}

// Process event
$this->processEvent($event);

// Log as processed
$this->webhookLogRepository->logEvent($eventId, $event->type);
```

### 3. Return 200 Quickly

```php
// Acknowledge receipt immediately
http_response_code(200);
echo json_encode(['received' => true]);

// Process asynchronously if needed
$this->queue->dispatch(new ProcessWebhookJob($event));
```

### 4. Handle Retries

Stripe retries failed webhooks (non-2xx responses) with exponential backoff:
- 1st retry: ~1 hour
- 2nd retry: ~2 hours
- 3rd retry: ~4 hours
- Max retries: 72 hours

### 5. Validate Event Types

```php
$allowedEvents = [
    'payment_intent.succeeded',
    'payment_intent.payment_failed',
    'charge.refunded',
];

if (!in_array($event->type, $allowedEvents)) {
    // Acknowledge but ignore
    return ['received' => true];
}
```

---

## URL Requirements

1. **HTTPS Required** (in live mode)
2. **Publicly Accessible** - No localhost in live mode
3. **No Authentication** - Webhook URLs cannot require login
4. **Fast Response** - Must respond within 20 seconds

### Testing Locally

```bash
# Use Stripe CLI to forward webhooks to localhost
stripe listen --forward-to http://localhost:8080/index.php?cl=stripe_webhook

# Output:
# Ready! Your webhook signing secret is whsec_...
```

---

## Error Handling

```php
try {
    $endpoint = $stripe->webhookEndpoints->create([...]);
} catch (\Stripe\Exception\InvalidRequestException $e) {
    // Invalid parameters
    echo "Invalid request: " . $e->getMessage();
} catch (\Stripe\Exception\AuthenticationException $e) {
    // Invalid API key
    echo "Authentication failed: " . $e->getMessage();
} catch (\Stripe\Exception\RateLimitException $e) {
    // Too many requests
    echo "Rate limited: " . $e->getMessage();
} catch (\Stripe\Exception\ApiConnectionException $e) {
    // Network error
    echo "Connection failed: " . $e->getMessage();
} catch (\Stripe\Exception\ApiErrorException $e) {
    // Generic API error
    echo "API error: " . $e->getMessage();
}
```

---

## References

- [Stripe Webhooks Documentation](https://docs.stripe.com/webhooks)
- [Webhook Endpoints API](https://docs.stripe.com/api/webhook_endpoints)
- [PHP SDK - Create Endpoint](https://docs.stripe.com/api/webhook_endpoints/create?lang=php)
- [PHP SDK - Update Endpoint](https://docs.stripe.com/api/webhook_endpoints/update?lang=php)
- [Webhook Quickstart](https://docs.stripe.com/webhooks/quickstart)
- [Stripe CLI](https://docs.stripe.com/stripe-cli)
