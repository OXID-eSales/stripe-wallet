# Sprint 9: Integration Tests

**Status:** PENDING
**Priority:** MEDIUM
**Estimated Effort:** 2 hours
**Depends On:** Sprints 1-8

---

## Objective

Create integration tests that verify the delayed capture flow works end-to-end with real Stripe API calls (using test mode).

---

## Integration Test Scenarios

### 1. Manual Capture Flow (Happy Path)

Test the complete flow:
1. Create checkout session with `capture_method: 'manual'`
2. Verify PaymentIntent has `requires_capture` status
3. Execute capture via API
4. Verify PaymentIntent status changes to `succeeded`
5. Verify contract state transitions correctly

### 2. Webhook Processing

Test webhook handling:
1. Create authorized contract
2. Simulate `charge.captured` webhook
3. Verify contract transitions to READY_TO_COMMIT

### 3. Partial Capture

Test partial capture functionality:
1. Authorize €100
2. Capture €60
3. Verify remaining uncaptured amount

---

## Test Files to Create

### 1. ManualCaptureIntegrationTest.php

**File:** `tests/Integration/Stripe/ManualCaptureIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use OxidSolutionCatalysts\Payments\Stripe\Service\CaptureConfigurationService;
use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;
use Stripe\PaymentIntent;

/**
 * @group integration
 * @group stripe
 * @group manual-capture
 */
class ManualCaptureIntegrationTest extends IntegrationTestCase
{
    private StripeAdapter $stripeAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfStripeNotConfigured();
        $this->stripeAdapter = $this->getContainer()->get(StripeAdapter::class);
    }

    public function testCreatePaymentIntentWithManualCapture(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            orderId: 'test-order-' . uniqid(),
            shopId: 1,
            amount: 99.99,
            currency: 'EUR',
            directCapture: false, // Manual capture
            metadata: ['test' => 'manual_capture_integration']
        );

        // Act
        $response = $this->stripeAdapter->createPayment($request);

        // Assert
        $this->assertNotEmpty($response->providerPaymentId);
        $this->assertEquals('requires_payment_method', $response->status);

        // Verify on Stripe side
        $paymentIntent = $this->retrievePaymentIntent($response->providerPaymentId);
        $this->assertEquals('manual', $paymentIntent->capture_method);
    }

    public function testCaptureAuthorizedPaymentIntent(): void
    {
        // Arrange - Create and confirm PaymentIntent with test card
        $paymentIntentId = $this->createAndConfirmPaymentIntent(9999, 'manual');

        // Verify it requires capture
        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        $this->assertEquals('requires_capture', $paymentIntent->status);

        // Act - Capture the payment
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $paymentIntentId,
            amount: null, // Full amount
            metadata: ['test' => 'capture_integration']
        );

        $captureResponse = $this->stripeAdapter->capturePayment($captureRequest);

        // Assert
        $this->assertEquals('succeeded', $captureResponse->status);
        $this->assertEquals(99.99, $captureResponse->amount);

        // Verify on Stripe side
        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertTrue($paymentIntent->charges->data[0]->captured);
    }

    public function testPartialCapture(): void
    {
        // Arrange - Create PaymentIntent for €100
        $paymentIntentId = $this->createAndConfirmPaymentIntent(10000, 'manual');

        // Act - Capture €60
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $paymentIntentId,
            amount: 60.00,
            metadata: ['test' => 'partial_capture']
        );

        $captureResponse = $this->stripeAdapter->capturePayment($captureRequest);

        // Assert
        $this->assertEquals('succeeded', $captureResponse->status);
        $this->assertEquals(60.00, $captureResponse->amount);

        // Verify uncaptured amount (€40 released back to customer)
        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        $this->assertEquals(6000, $paymentIntent->amount_received);
    }

    public function testAutomaticCaptureComparison(): void
    {
        // Arrange - Create PaymentIntent with automatic capture
        $paymentIntentId = $this->createAndConfirmPaymentIntent(9999, 'automatic');

        // Assert - Should be succeeded immediately (no requires_capture state)
        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertTrue($paymentIntent->charges->data[0]->captured);
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    private function createAndConfirmPaymentIntent(
        int $amountInCents,
        string $captureMethod
    ): string {
        $stripeClient = $this->getStripeClient();

        // Create PaymentIntent
        $paymentIntent = $stripeClient->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => 'eur',
            'capture_method' => $captureMethod,
            'payment_method' => 'pm_card_visa', // Test card
            'confirm' => true,
            'return_url' => 'https://example.com/return',
        ]);

        return $paymentIntent->id;
    }

    private function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
    }

    private function skipIfStripeNotConfigured(): void
    {
        $apiKey = $this->getConfigService()->getStripeSecretKey();
        if (empty($apiKey) || !str_starts_with($apiKey, 'sk_test_')) {
            $this->markTestSkipped('Stripe test API key not configured');
        }
    }
}
```

### 2. WebhookChargeCapturedIntegrationTest.php

**File:** `tests/Integration/Stripe/WebhookChargeCapturedIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\ChargeCapturedWebhookHandler;
use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;
use Stripe\Event as StripeEvent;

/**
 * @group integration
 * @group stripe
 * @group webhook
 */
class WebhookChargeCapturedIntegrationTest extends IntegrationTestCase
{
    public function testChargeCapturedWebhookTransitionsContract(): void
    {
        // Arrange - Create contract in AUTHORIZED state
        $contract = $this->createAuthorizedContract();
        $contract->setMetadataValue('provider_payment_id', 'pi_test_' . uniqid());
        $this->contractRepository->save($contract);

        // Build webhook event
        $webhookPayload = $this->buildChargeCapturedPayload(
            chargeId: 'ch_test_' . uniqid(),
            paymentIntentId: $contract->getMetadataValue('provider_payment_id'),
            amountCaptured: 9999
        );

        $event = StripeEvent::constructFrom($webhookPayload);

        // Act
        $handler = $this->getContainer()->get(ChargeCapturedWebhookHandler::class);
        $handler->handle($event);

        // Assert
        $updatedContract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($updatedContract->getState()->isReadyToCommit());
    }

    private function createAuthorizedContract(): PaymentContract
    {
        $contract = new PaymentContract(
            shopId: 1,
            userId: 'test-user-' . uniqid(),
            basketSnapshot: $this->createTestBasketSnapshot()
        );

        $contract->transitionToPending();
        $contract->authorize();

        $this->contractRepository->save($contract);

        return $contract;
    }

    private function buildChargeCapturedPayload(
        string $chargeId,
        string $paymentIntentId,
        int $amountCaptured
    ): array {
        return [
            'id' => 'evt_test_' . uniqid(),
            'type' => 'charge.captured',
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'payment_intent' => $paymentIntentId,
                    'amount' => $amountCaptured,
                    'amount_captured' => $amountCaptured,
                    'captured' => true,
                    'currency' => 'eur',
                    'metadata' => [],
                ],
            ],
        ];
    }
}
```

### 3. CaptureConfigurationIntegrationTest.php

**File:** `tests/Integration/Stripe/CaptureConfigurationIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use OxidSolutionCatalysts\Payments\Stripe\Service\CaptureConfigurationService;
use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;

/**
 * @group integration
 * @group configuration
 */
class CaptureConfigurationIntegrationTest extends IntegrationTestCase
{
    public function testCaptureModeCanBeReadFromModuleConfig(): void
    {
        // Arrange - Set module config (requires active module)
        $this->skipIfModuleNotActivated();

        // Act
        $service = $this->getContainer()->get(CaptureConfigurationService::class);
        $captureMode = $service->getCaptureMode();

        // Assert
        $this->assertContains($captureMode, ['automatic', 'manual']);
    }

    public function testDefaultCaptureModeIsAutomatic(): void
    {
        $this->skipIfModuleNotActivated();

        // Default config should be automatic
        $service = $this->getContainer()->get(CaptureConfigurationService::class);

        // Note: This assumes default config hasn't been changed
        $this->assertEquals('automatic', $service->getCaptureMode());
    }

    private function skipIfModuleNotActivated(): void
    {
        // Check if module is activated
        // Skip if not
    }
}
```

---

## E2E Test Plan (Playwright)

For full end-to-end testing with browser:

**File:** `tests/e2e/playwright/tests/manual-capture.spec.ts`

```typescript
import { test, expect } from '@playwright/test';

test.describe('Manual Capture Flow', () => {
  test('admin can capture authorized payment', async ({ page }) => {
    // 1. Create order with manual capture (may need to set config first)
    // 2. Complete Stripe checkout with test card
    // 3. Verify order shows "Authorized" status
    // 4. Go to admin backend
    // 5. Navigate to order detail
    // 6. Click "Capture Payment" button
    // 7. Verify success message
    // 8. Verify order status updated
  });
});
```

---

## Test Environment Setup

### Required Configuration

```yaml
# test environment variables
STRIPE_TEST_SECRET_KEY: sk_test_...
STRIPE_TEST_PUBLISHABLE_KEY: pk_test_...
```

### Test Database Setup

Integration tests should use a separate test database or transactions with rollback.

```php
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();
        parent::tearDown();
    }
}
```

---

## Test Commands

```bash
# Run all integration tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration

# Run manual capture integration tests only
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --group manual-capture

# Run with verbose output
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Integration -v
```

---

## Acceptance Criteria

- [ ] Manual capture integration test passes with real Stripe API
- [ ] Partial capture test passes
- [ ] Webhook handler integration test passes
- [ ] Configuration service integration test passes
- [ ] All tests use test mode API keys only
- [ ] Tests clean up after themselves (no leftover test data)
- [ ] Tests can run in CI environment
- [ ] PHPStan level 6 passes

---

## Notes

- Integration tests require valid Stripe test mode API keys
- Tests create real objects in Stripe (PaymentIntents, etc.)
- Use unique order IDs to avoid conflicts
- Consider rate limiting when running many tests
- Stripe CLI can be used to forward webhooks for local testing
