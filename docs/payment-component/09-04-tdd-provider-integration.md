# TDD Strategy - Part 4 of 8: Provider Integration & SDK-Adapter Layer

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- **Part 4** (This document): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

- **Coverage Required:** 95%
- **Test Types:** Unit (mock adapter) + Integration (real adapter with mocked SDK)
- **Components:**
  - `PaymentService` using `PaymentAdapterInterface`
  - Provider-agnostic business logic
  - Error handling via adapter exceptions

**Test Scenarios (Unit Tests - Mock Adapter):**
```php
// tests/Component/Unit/Service/PaymentServiceWithAdapterTest.php

✅ testInitiatePayment_UsesAdapter()
✅ testInitiatePayment_CreatesAdapterRequest()
✅ testInitiatePayment_HandlesAdapterResponse()
✅ testInitiatePayment_TracksTransaction()
✅ testInitiatePayment_HandlesAdapterException()
✅ testInitiatePayment_MapsAdapterErrorToServiceError()
✅ testCapturePayment_UsesAdapter()
✅ testRefundPayment_UsesAdapter()
```

**Example Test:**
```php
<?php
// tests/Component/Unit/Service/PaymentServiceWithAdapterTest.php

use PaymentComponent\Service\PaymentService;
use PaymentComponent\Adapter\PaymentAdapterInterface;
use PaymentComponent\Adapter\Request\CreatePaymentRequest;
use PaymentComponent\Adapter\Response\PaymentResponse;
use PaymentComponent\Adapter\Exception\PaymentAdapterException;
use Mockery;

class PaymentServiceWithAdapterTest extends TestCase
{
    public function testInitiatePayment_UsesAdapter(): void
    {
        // Arrange
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepo = Mockery::mock(PaymentTransactionRepository::class);

        $order = OrderBuilder::new()->build();

        // Expect adapter to be called with correct request
        $adapterMock
            ->shouldReceive('createPayment')
            ->once()
            ->with(Mockery::on(function (CreatePaymentRequest $request) use ($order) {
                return $request->getAmount() === $order->getTotalAmount()
                    && $request->getCurrency() === $order->getCurrency()
                    && $request->getOrderId() === $order->getId();
            }))
            ->andReturn(new PaymentResponse(
                providerPaymentId: 'pi_123',
                status: 'authorized',
                amount: 99.99,
                currency: 'EUR'
            ));

        $transactionRepo
            ->shouldReceive('save')
            ->once();

        $service = new PaymentService($adapterMock, $transactionRepo);

        // Act
        $response = $service->initiatePayment($order, 'card');

        // Assert
        $this->assertEquals('pi_123', $response->getProviderPaymentId());
        $this->assertEquals('authorized', $response->getStatus());
    }

    public function testInitiatePayment_HandlesAdapterException(): void
    {
        // Arrange
        $adapterMock = Mockery::mock(PaymentAdapterInterface::class);
        $transactionRepo = Mockery::mock(PaymentTransactionRepository::class);

        $order = OrderBuilder::new()->build();

        $adapterMock
            ->shouldReceive('createPayment')
            ->once()
            ->andThrow(PaymentAdapterException::fromProviderError(
                'stripe',
                'Card declined',
                'card_declined'
            ));

        $service = new PaymentService($adapterMock, $transactionRepo);

        // Act & Assert
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment failed');

        $service->initiatePayment($order, 'card');
    }
}
```

**Implementation Order:**
1. Write tests for `PaymentService` using mocked adapter
2. Refactor `PaymentService` to use `PaymentAdapterInterface`
3. Test error handling via adapter exceptions
4. Write integration tests with real adapter (mocked SDK)
5. Verify provider-agnostic business logic

---

### Block 5.6: Two-Step Authorization Flow 🔴 CRITICAL (P0)

**Test Organization Note:** This block covers core authorization/capture flow testing in **component test suites**. Mock `PaymentAdapterInterface` for business logic tests. Provider-specific authorization tests belong in **provider test suites**.

**Key Testing Principles:**
- **Component Tests**: Mock adapter, test authorization → capture → void flow
- **Provider Tests**: Test real provider authorization APIs in sandbox
- **Component Coverage**: 100% (critical money flow)
- **Provider Coverage**: 95% (critical provider integration)

---

#### 5.6.1 Authorization Service (P0-F)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `AuthorizationService` - Authorize payment without capture
  - `PaymentService::authorizePayment()` - Authorization workflow
  - `PaymentService::captureAuthorization()` - Delayed capture
  - `PaymentService::voidAuthorization()` - Cancel authorization
  - Authorization expiration tracking

**Critical Test Scenarios:**
```php
// tests/Component/Unit/Service/AuthorizationServiceTest.php

✅ testAuthorizePayment_CreatesAuthorizationNotCapture()
✅ testAuthorizePayment_TracksAuthorizationExpiry()
✅ testCaptureAuthorization_RequiresValidAuthorization()
✅ testCaptureAuthorization_FullAmount()
✅ testCaptureAuthorization_PartialAmount()
✅ testCaptureAuthorization_AmountExceedsAuthorized_ThrowsException()
✅ testCaptureAuthorization_ExpiredAuthorization_ThrowsException()
✅ testVoidAuthorization_CancelsAuthorization()
✅ testVoidAuthorization_AlreadyCaptured_ThrowsException()
✅ testMultipleCaptures_SumDoesNotExceedAuthorized()
```

**Implementation Order:**
1. Add authorization tracking fields to `osc_payment_transaction`:
   - `OXAUTHORIZATION_ID` (provider authorization ID)
   - `OXAUTHORIZATION_STATUS` (authorized, captured, voided, expired)
   - `OXAUTHORIZATION_EXPIRES` (expiration timestamp)
   - `OXCAPTURED_AMOUNT` (partial capture tracking)
2. Write tests for authorization flow
3. Implement `AuthorizationService` with authorization/capture/void methods
4. Test authorization expiration detection
5. Test partial capture validation
6. Implement authorization state machine

---

#### 5.6.2 Reauthorization Service (P0-G)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `AuthorizationService::reauthorizePayment()` - Renew expired authorization
  - Expiration detection (PayPal: 29 days, Stripe: 7 days, Unzer: 7 days)
  - Reauthorization limit validation (PayPal: 1 reauth, Stripe: N/A)

**Critical Test Scenarios:**
```php
✅ testReauthorizePayment_RenewsExpiringAuthorization()
✅ testReauthorizePayment_UpdatesExpirationDate()
✅ testReauthorizePayment_AlreadyCaptured_ThrowsException()
✅ testReauthorizePayment_ExceedsReauthLimit_ThrowsException()
✅ testReauthorizePayment_TracksReauthCount()
✅ testReauthorizePayment_ProviderNotSupportsReauth_ThrowsException()
✅ testGetAuthorizationExpirationDate_CalculatesCorrectly()
✅ testIsAuthorizationExpired_DetectsExpiry()
```

**Implementation Order:**
1. Add reauthorization tracking fields:
   - `OXREAUTH_COUNT` (number of reauthorizations)
   - `OXMAX_REAUTH_COUNT` (provider-specific limit)
2. Write tests for reauthorization flow
3. Implement reauthorization service with expiration detection
4. Test provider-specific reauth limits
5. Implement reauthorization count tracking

---

### Block 5.7: Idempotency Management 🔴 CRITICAL (P0)

**Test Organization Note:** Idempotency is a **component concern** - test in **component test suites**. Mock adapter to test idempotency logic without provider dependencies.

---

#### 5.7.1 Idempotency Service (P0-H)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration + E2E
- **Components:**
  - `IdempotencyService` - Idempotency key management
  - Duplicate request detection
  - Result caching
  - Key expiration (24-48 hours)

**Critical Test Scenarios:**
```php
// tests/Component/Unit/Service/IdempotencyServiceTest.php

✅ testGenerateKey_CreatesUniqueKey()
✅ testHasBeenProcessed_DetectsDuplicate()
✅ testMarkAsProcessed_StoresResult()
✅ testGetResult_ReturnsCachedResult()
✅ testSameKey_SameResult_NoDuplicateCharge()
✅ testIdempotencyKeyExpiration_After24Hours()
✅ testConcurrentRequests_SameKey_OnlyOneProcessed()
✅ testNetworkRetry_UsesSameKey_NoDuplicateCharge()
✅ testWebhookRedelivery_UsesSameKey_ProcessedOnce()
```

**Implementation Order:**
1. Create `osc_payment_idempotency` table:
   ```sql
   CREATE TABLE osc_payment_idempotency (
       OXID CHAR(32) NOT NULL PRIMARY KEY,
       OXKEY VARCHAR(128) NOT NULL UNIQUE,
       OXORDERID CHAR(32) NOT NULL,
       OXOPERATION VARCHAR(32) NOT NULL,
       OXRESULT TEXT,
       OXSTATUS VARCHAR(32),
       OXCREATED DATETIME NOT NULL,
       OXEXPIRES DATETIME NOT NULL,
       INDEX IDX_KEY (OXKEY),
       INDEX IDX_EXPIRES (OXEXPIRES)
   );
   ```
2. Write tests for idempotency key generation
3. Implement `IdempotencyService` with key generation
4. Test duplicate detection
5. Implement result caching
6. Test key expiration
7. Test concurrent request handling

---

#### 5.7.2 Integration with PaymentService (P0-I)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentService` using `IdempotencyService`
  - Automatic idempotency key generation
  - Cached result retrieval

**Critical Test Scenarios:**
```php
✅ testCreatePayment_GeneratesIdempotencyKey()
✅ testCreatePayment_SameKey_ReturnsCachedResult()
✅ testCreatePayment_DifferentKey_CreatesNewPayment()
✅ testCapturePayment_UsesIdempotencyKey()
✅ testRefundPayment_UsesIdempotencyKey()
✅ testNetworkError_Retry_UsesSameKey()
```

**Implementation Order:**
1. Write tests for payment service with idempotency
2. Integrate `IdempotencyService` into `PaymentService`
3. Test automatic key generation
4. Test cached result retrieval
5. Test network retry scenarios

---

### Block 5.8: Vaulting/Tokenization 🟠 HIGH (P1)

**Test Organization Note:** Vaulting is a **component concern** with **provider-specific implementations**. Component tests mock adapter, provider tests test real vaulting APIs.

---

#### 5.8.1 Vaulting Service (P1-A)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `VaultingService` - Save payment methods for reuse
  - `VaultingService::savePaymentMethod()` - Store payment method
  - `VaultingService::getSavedPaymentMethods()` - List saved methods
  - `VaultingService::deletePaymentMethod()` - Remove payment method
  - `VaultingService::setDefaultPaymentMethod()` - Set default

**Test Scenarios:**
```php
// tests/Component/Unit/Service/VaultingServiceTest.php

✅ testSavePaymentMethod_CreatesRecord()
✅ testSavePaymentMethod_StoresProviderPaymentMethodId()
✅ testGetSavedPaymentMethods_ReturnsUserMethods()
✅ testGetSavedPaymentMethods_ExcludesExpiredCards()
✅ testDeletePaymentMethod_RemovesRecord()
✅ testDeletePaymentMethod_OnlyOwnerCanDelete()
✅ testSetDefaultPaymentMethod_UpdatesDefault()
✅ testSetDefaultPaymentMethod_OnlyOneDefault()
✅ testSavePaymentMethod_DuplicateDetection()
```

**Implementation Order:**
1. Create `osc_payment_saved_methods` table:
   ```sql
   CREATE TABLE osc_payment_saved_methods (
       OXID CHAR(32) NOT NULL PRIMARY KEY,
       OXUSERID CHAR(32) NOT NULL,
       OXPROVIDER VARCHAR(32) NOT NULL,
       OXPROVIDER_PAYMENT_METHOD_ID VARCHAR(128) NOT NULL,
       OXPAYMENT_METHOD_TYPE VARCHAR(32),
       OXLAST_FOUR VARCHAR(4),
       OXEXPIRY_MONTH INT,
       OXEXPIRY_YEAR INT,
       OXIS_DEFAULT BOOLEAN DEFAULT FALSE,
       OXCREATED DATETIME NOT NULL,
       FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE,
       UNIQUE KEY (OXUSERID, OXPROVIDER_PAYMENT_METHOD_ID)
   );
   ```
2. Write tests for vaulting service
3. Implement `VaultingService` with CRUD operations
4. Test default payment method logic
5. Test duplicate detection
6. Test security (user can only access own methods)

---

#### 5.8.2 Integration with PaymentService (P1-B)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 90%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentService::createPaymentWithSavedMethod()` - Use saved method
  - Automatic vaulting after successful payment
  - Saved method validation

**Test Scenarios:**
```php
✅ testCreatePaymentWithSavedMethod_UsesVaultedMethod()
✅ testCreatePaymentWithSavedMethod_ValidatesMethodBelongsToUser()
✅ testCreatePaymentWithSavedMethod_ExpiredCard_ThrowsException()
✅ testSuccessfulPayment_AutoVaults_IfRequested()
✅ testFailedPayment_DoesNotVault()
```

**Implementation Order:**
1. Write tests for payment with saved methods
2. Integrate `VaultingService` into `PaymentService`
3. Test saved method validation
4. Test automatic vaulting after payment
5. Test security validation

---

### Block 5.9: 3D Secure/SCA Verification 🟠 HIGH (P1)

**Test Organization Note:** 3DS is a **component concern** with **provider-specific implementations**. Component tests mock adapter, provider tests test real 3DS flows.

---


---

## Related Documentation

- **[Part 3: Event System](09-03-tdd-event-system.md)** - Event layer and service layer testing
- **[Part 5: Authorization Flow](09-05-tdd-authorization-flow.md)** - Two-step authorization (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
