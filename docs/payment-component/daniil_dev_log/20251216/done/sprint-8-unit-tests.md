# Sprint 8: Unit Tests

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 3 hours
**Depends On:** All previous sprints

---

## Objective

Write comprehensive unit tests for all new code created in Sprints 1-7, following TDD principles.

---

## Test Files to Create/Update

### 1. ContractStateTest.php (Update)

**File:** `tests/Unit/Component/Contract/ContractStateTest.php`

```php
// Add tests for AUTHORIZED state

public function testAuthorizedStateCanBeCreated(): void
{
    $state = ContractState::authorized();

    $this->assertTrue($state->isAuthorized());
    $this->assertEquals('authorized', $state->getValue());
}

public function testAuthorizedStateIsNotTerminal(): void
{
    $state = ContractState::authorized();

    $this->assertFalse($state->isTerminal());
}

public function testAuthorizedStateEquality(): void
{
    $state1 = ContractState::authorized();
    $state2 = ContractState::authorized();
    $state3 = ContractState::pending();

    $this->assertTrue($state1->equals($state2));
    $this->assertFalse($state1->equals($state3));
}
```

### 2. PaymentContractTest.php (Update)

**File:** `tests/Unit/Component/Contract/PaymentContractTest.php`

```php
// Add tests for authorize() and captureAuthorization() methods

public function testAuthorizeTransitionsFromPendingToAuthorized(): void
{
    $contract = $this->createContract();
    $contract->transitionToPending();

    $contract->authorize();

    $this->assertTrue($contract->getState()->isAuthorized());
}

public function testAuthorizeThrowsExceptionForNonPendingContract(): void
{
    $contract = $this->createContract(); // In DRAFT state

    $this->expectException(InvalidStateTransitionException::class);
    $contract->authorize();
}

public function testCaptureAuthorizationTransitionsToReadyToCommit(): void
{
    $contract = $this->createContract();
    $contract->transitionToPending();
    $contract->authorize();

    $contract->captureAuthorization();

    $this->assertTrue($contract->getState()->isReadyToCommit());
}

public function testCaptureAuthorizationThrowsForNonAuthorizedContract(): void
{
    $contract = $this->createContract();
    $contract->transitionToPending();

    $this->expectException(InvalidStateTransitionException::class);
    $contract->captureAuthorization();
}
```

### 3. CaptureConfigurationServiceTest.php (New)

**File:** `tests/Unit/Stripe/Service/CaptureConfigurationServiceTest.php`

Full test class as described in Sprint 2.

### 4. CaptureRequestedEventTest.php (New)

**File:** `tests/Unit/Component/EventSystem/Event/Payment/CaptureRequestedEventTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\CaptureRequestedEvent;
use PHPUnit\Framework\TestCase;

class CaptureRequestedEventTest extends TestCase
{
    public function testEventConstructorSetsProperties(): void
    {
        $event = new CaptureRequestedEvent(
            contractId: 'contract-123',
            amount: 99.99,
            triggeredBy: 'admin',
            idempotencyKey: 'key-123',
            reason: 'Manual capture'
        );

        $this->assertEquals('contract-123', $event->getContractId());
        $this->assertEquals(99.99, $event->getAmount());
        $this->assertEquals('admin', $event->getTriggeredBy());
        $this->assertEquals('key-123', $event->getIdempotencyKey());
        $this->assertEquals('Manual capture', $event->getReason());
    }

    public function testEventNameIsCorrect(): void
    {
        $event = new CaptureRequestedEvent(contractId: 'contract-123');

        $this->assertEquals('payment.capture.requested', $event->getEventName());
    }

    public function testAmountIsNullableForFullCapture(): void
    {
        $event = new CaptureRequestedEvent(contractId: 'contract-123');

        $this->assertNull($event->getAmount());
    }

    public function testDefaultTriggeredBy(): void
    {
        $event = new CaptureRequestedEvent(contractId: 'contract-123');

        $this->assertEquals('admin', $event->getTriggeredBy());
    }
}
```

### 5. ContractAuthorizedEventTest.php (New)

**File:** `tests/Unit/Component/EventSystem/Event/Contract/ContractAuthorizedEventTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractAuthorizedEvent;
use PHPUnit\Framework\TestCase;

class ContractAuthorizedEventTest extends TestCase
{
    public function testEventConstructorSetsProperties(): void
    {
        $contract = $this->createMock(PaymentContract::class);

        $event = new ContractAuthorizedEvent(
            contract: $contract,
            providerPaymentId: 'pi_123',
            authorizedAmount: 99.99
        );

        $this->assertSame($contract, $event->getContract());
        $this->assertEquals('pi_123', $event->getProviderPaymentId());
        $this->assertEquals(99.99, $event->getAuthorizedAmount());
    }

    public function testEventNameIsCorrect(): void
    {
        $contract = $this->createMock(PaymentContract::class);

        $event = new ContractAuthorizedEvent(
            contract: $contract,
            providerPaymentId: 'pi_123',
            authorizedAmount: 99.99
        );

        $this->assertEquals('contract.authorized', $event->getEventName());
    }
}
```

### 6. StripeCaptureHandlerTest.php (New)

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeCaptureHandlerTest.php`

Full test class as described in Sprint 4.

### 7. ChargeCapturedWebhookHandlerTest.php (New)

**File:** `tests/Unit/Stripe/Webhook/Handler/ChargeCapturedWebhookHandlerTest.php`

Full test class as described in Sprint 7.

### 8. CheckoutSessionServiceTest.php (Update)

**File:** `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php`

Add tests for `capture_method` parameter passing:

```php
public function testCreateSessionPassesManualCaptureMethod(): void
{
    $captureConfig = $this->createMock(CaptureConfigurationService::class);
    $captureConfig->method('getStripeCaptureMethod')->willReturn('manual');

    // Assert Stripe API receives capture_method: 'manual'
}

public function testCreateSessionPassesAutomaticCaptureMethod(): void
{
    $captureConfig = $this->createMock(CaptureConfigurationService::class);
    $captureConfig->method('getStripeCaptureMethod')->willReturn('automatic');

    // Assert Stripe API receives capture_method: 'automatic'
}
```

---

## Test Coverage Goals

| Component | Target Coverage |
|-----------|----------------|
| ContractState | 100% |
| PaymentContract (authorize methods) | 100% |
| CaptureConfigurationService | 100% |
| CaptureRequestedEvent | 100% |
| ContractAuthorizedEvent | 100% |
| StripeCaptureHandler | 90%+ |
| ChargeCapturedWebhookHandler | 90%+ |

---

## Test Data Builders

Create reusable test data builders:

**File:** `tests/Support/ContractTestBuilder.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Support;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;

class ContractTestBuilder
{
    private string $id = 'contract-test-123';
    private string $state = 'draft';
    private array $metadata = [];

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function inPendingState(): self
    {
        $this->state = 'pending';
        return $this;
    }

    public function inAuthorizedState(): self
    {
        $this->state = 'authorized';
        return $this;
    }

    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function build(): PaymentContract
    {
        $contract = new PaymentContract(
            shopId: 1,
            userId: 'user-test-123',
            basketSnapshot: $this->createBasketSnapshot()
        );

        // Set state
        if ($this->state === 'pending') {
            $contract->transitionToPending();
        } elseif ($this->state === 'authorized') {
            $contract->transitionToPending();
            $contract->authorize();
        }

        // Set metadata
        foreach ($this->metadata as $key => $value) {
            $contract->setMetadataValue($key, $value);
        }

        return $contract;
    }

    private function createBasketSnapshot(): BasketSnapshot
    {
        return new BasketSnapshot(
            items: [],
            totalAmount: 99.99,
            currency: 'EUR',
            paymentMethodId: 'osc_stripe_card'
        );
    }
}
```

---

## Test Commands

```bash
# Run all new tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "CaptureConfigurationServiceTest|CaptureRequestedEventTest|ContractAuthorizedEventTest|StripeCaptureHandlerTest|ChargeCapturedWebhookHandlerTest"

# Run with coverage
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --coverage-html coverage/ \
  tests/Unit/

# Run full unit test suite
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

---

## Acceptance Criteria

- [ ] All new classes have corresponding test files
- [ ] Test coverage > 90% for new code
- [ ] All tests follow AAA pattern (Arrange-Act-Assert)
- [ ] Test data builders created for common scenarios
- [ ] No false-positive tests (`assertTrue(true)`)
- [ ] Tests run in isolation (no global state)
- [ ] All tests pass
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Notes

- Write tests BEFORE implementation (TDD)
- Use meaningful test method names that describe behavior
- Avoid over-mocking - test behavior, not implementation
- Create test helpers for common setup patterns
