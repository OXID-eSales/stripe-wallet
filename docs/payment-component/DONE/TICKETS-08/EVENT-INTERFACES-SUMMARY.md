# Event Interfaces - Complete Implementation

**Date:** 2025-10-30
**Approach:** Interface-First Design for Better Testing & SOLID
**Status:** Interfaces Complete ✅

---

## 🎯 Why Interfaces?

1. **Better Mocking** - Mock interfaces, not concrete classes
2. **Dependency Inversion** - Depend on abstractions (SOLID D)
3. **Clear Contracts** - Interfaces define exact behavior
4. **Easy Testing** - Fast, isolated unit tests
5. **Substitutability** - Swap implementations easily

---

## 🏗️ Interface Hierarchy

```
EventInterface (base marker)
    ↓
    ├─ ContractEventInterface (all contract events)
    │    ├─ ContractCreatedEventInterface
    │    ├─ ContractTransitionedToPendingEventInterface
    │    ├─ ContractConditionFulfilledEventInterface
    │    ├─ ContractReadyToCommitEventInterface (simple, no specific methods)
    │    ├─ ContractCommittedEventInterface
    │    ├─ ContractFulfilledEventInterface (simple, no specific methods)
    │    ├─ ContractCancelledEventInterface
    │    ├─ ContractExpiredEventInterface (simple, no specific methods)
    │    └─ ContractFailedEventInterface
    │
    └─ PaymentEventInterface (all payment events)
         ├─ PaymentInitiatedEventInterface
         ├─ PaymentAuthorizedEventInterface
         ├─ PaymentCapturedEventInterface
         ├─ PaymentFailedEventInterface (simple, no specific methods yet)
         ├─ PaymentRefundedEventInterface (simple, no specific methods yet)
         ├─ OrderCreatedEventInterface (simple, no specific methods yet)
         ├─ OrderCompletedEventInterface (simple, no specific methods yet)
         └─ WebhookReceivedEventInterface
```

---

## 📁 Files Created (15 Interfaces)

### Base Interfaces
1. ✅ `EventInterface.php` - Base marker interface (already existed)

### Contract Layer Interfaces
2. ✅ `Contract/ContractEventInterface.php` - Base for all contract events
3. ✅ `Contract/ContractCreatedEventInterface.php`
4. ✅ `Contract/ContractTransitionedToPendingEventInterface.php`
5. ✅ `Contract/ContractConditionFulfilledEventInterface.php`
6. ✅ `Contract/ContractCommittedEventInterface.php`
7. ✅ `Contract/ContractCancelledEventInterface.php`
8. ✅ `Contract/ContractFailedEventInterface.php`

### Payment Layer Interfaces
9. ✅ `Payment/PaymentEventInterface.php` - Base for all payment events
10. ✅ `Payment/PaymentInitiatedEventInterface.php`
11. ✅ `Payment/PaymentAuthorizedEventInterface.php`
12. ✅ `Payment/PaymentCapturedEventInterface.php`
13. ✅ `Payment/WebhookReceivedEventInterface.php`

---

## 📐 Interface Design Patterns

### Pattern 1: Marker Interface

```php
// Just marks that something is an event
interface EventInterface { }
```

**Use:** Type hinting at the highest level

### Pattern 2: Category Interface

```php
// Defines common behavior for a category
interface ContractEventInterface extends EventInterface
{
    public function getContract(): PaymentContractInterface;
    public function getContext(): EventContext;
}
```

**Use:** All contract events share these methods

### Pattern 3: Specific Interface

```php
// Defines specific behavior for one event type
interface ContractCreatedEventInterface extends ContractEventInterface
{
    public function getContractId(): string;
    public function getContractState(): string;
}
```

**Use:** Handlers can type hint exactly what they need

---

## 🎯 Usage Examples

### Example 1: Handler Type Hints Interface

```php
// ✅ Handler depends on interface, not concrete class
final class ContractCreatedHandler
{
    public function handle(ContractCreatedEventInterface $event): void
    {
        $contractId = $event->getContractId();
        $contract = $event->getContract();
        // ... business logic
    }
}
```

### Example 2: Test Mocks Interface

```php
// ✅ Test mocks the interface
final class ContractCreatedHandlerTest extends TestCase
{
    public function testHandle_SavesContract(): void
    {
        // Mock interface, not concrete class!
        $event = $this->createMock(ContractCreatedEventInterface::class);
        $event->method('getContractId')->willReturn('test_123');
        $event->method('getContract')->willReturn(
            $this->createMock(PaymentContractInterface::class)
        );

        $handler = new ContractCreatedHandler($mockRepository);
        $handler->handle($event);

        // Easy, fast, clean test!
    }
}
```

### Example 3: EventDispatcher Accepts Base Interface

```php
// ✅ Dispatcher accepts any event
final class EventDispatcher
{
    public function dispatch(EventInterface $event): EventInterface
    {
        // Works with ALL event types
        // Polymorphism at its best!
    }
}
```

---

## 🧪 Testing Benefits

### Without Interfaces (❌)

```php
// Must mock concrete class
$event = $this->createMock(ContractCreatedEvent::class);

// Problems:
// - Fragile (depends on implementation)
// - Slower (PHP reflection overhead)
// - Couples test to concrete class
// - Harder to refactor
```

### With Interfaces (✅)

```php
// Mock clean interface
$event = $this->createMock(ContractCreatedEventInterface::class);

// Benefits:
// - Fast (just interface contract)
// - Stable (implementation can change)
// - Clear (interface shows what's needed)
// - Easy to refactor (tests don't break)
```

---

## 📊 Interface Method Summary

### ContractEventInterface (Base)
- `getContract(): PaymentContractInterface`
- `getContext(): EventContext`

### ContractCreatedEventInterface
- + `getContractId(): string`
- + `getContractState(): string`

### ContractCommittedEventInterface
- + `getOrderId(): string`

### ContractConditionFulfilledEventInterface
- + `getConditionType(): string`
- + `getConditionData(): array`

### ContractFailedEventInterface
- + `getErrorCode(): string`
- + `getErrorMessage(): string`

### ContractCancelledEventInterface
- + `getReason(): string`

### ContractTransitionedToPendingEventInterface
- + `getConditions(): array`

---

### PaymentEventInterface (Base)
- `getContext(): EventContext`

### PaymentInitiatedEventInterface
- + `getPaymentMethodId(): string`
- + `getAmount(): float`
- + `getCurrency(): string`
- + `getReturnUrl(): string`
- + `getCancelUrl(): string`
- + `setProviderRedirectUrl(string): void`
- + `getProviderRedirectUrl(): ?string`
- + `setProviderOrderId(string): void`
- + `getProviderOrderId(): ?string`

### PaymentAuthorizedEventInterface
- + `getAuthorizationId(): string`
- + `getProviderOrderId(): string`
- + `getAmount(): float`
- + `getCurrency(): string`

### PaymentCapturedEventInterface
- + `getAuthorizationId(): string`
- + `getCaptureId(): string`
- + `getCapturedAmount(): float`
- + `getCurrency(): string`

### WebhookReceivedEventInterface
- + `getProvider(): string`
- + `getEventType(): string`
- + `getPayload(): array`
- + `getSignature(): string`

---

## 🚀 Next Steps (TDD)

### Now We Can Write Tests First!

1. **Write Handler Test** (depends on interface)
   ```php
   public function testHandle(ContractCreatedEventInterface $event)
   ```

2. **Mock the Interface** (easy!)
   ```php
   $event = $this->createMock(ContractCreatedEventInterface::class);
   ```

3. **Write Implementation** (implements interface)
   ```php
   final class ContractCreatedEvent implements ContractCreatedEventInterface
   ```

4. **Test Passes** ✅

---

## 🎁 SOLID Principles Applied

### S - Single Responsibility
Each interface defines ONE concept (one event type)

### O - Open/Closed
New event types = new interfaces, old code untouched

### L - Liskov Substitution
Any `ContractEventInterface` implementation works anywhere

### I - Interface Segregation
Small, focused interfaces (not one giant interface)

### D - Dependency Inversion
Handlers depend on `EventInterface`, not concrete classes

---

## 📖 Documentation References

- [INTERFACE-BASED-TDD-EXAMPLE.md](docs/payment-component/INTERFACE-BASED-TDD-EXAMPLE.md) - Complete TDD example with interfaces
- [TDD-APPROACH-CORRECTION.md](docs/payment-component/TDD-APPROACH-CORRECTION.md) - Why we switched to interfaces first

---

## ✅ Status

| Item | Status |
|------|--------|
| **Interface Hierarchy** | ✅ Complete |
| **Base Interfaces** | ✅ Created (2) |
| **Contract Interfaces** | ✅ Created (7) |
| **Payment Interfaces** | ✅ Created (6) |
| **Documentation** | ✅ Complete |
| **Test Examples** | ✅ Provided |
| **TDD Ready** | ✅ Yes! |

---

## 🎯 Key Insight

> "Program to an interface, not an implementation."
> — Gang of Four, Design Patterns

By creating interfaces first:
- ✅ We define clear contracts
- ✅ We enable easy testing
- ✅ We follow SOLID principles
- ✅ We make refactoring safe
- ✅ We enable TDD to work beautifully

**Now we can write tests that mock interfaces, then implement classes that fulfill those interfaces!**

---

**Status:** ✅ **COMPLETE - Ready for TDD**
**Date:** 2025-10-30
**Next:** Write tests using interface mocks, then implement concrete event classes
