# TDD Strategy - Part 3 of 8: Event System & Business Logic

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- **Part 3** (This document): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

### Block 3: Event System & Business Logic 🟠 HIGH (P1)

#### 3.1 Event Layer (P1-A)
- **Coverage Required:** 100%
- **Test Types:** Unit + Integration
- **Components:**
  - Event creation and dispatching
  - EventContext caching
  - Event immutability

**Test Scenarios:**
```php
✅ testEventImmutable_CannotModifyAfterCreation()
✅ testEventContext_CachesDataCorrectly()
✅ testEventDispatcher_InvokesAllSubscribers()
✅ testEventHandlerException_DoesNotAffectOtherSubscribers()
```

**Implementation Order:**
1. Define all domain events
2. Implement EventContext for request caching
3. Test event immutability
4. Implement event dispatcher integration
5. Test subscriber invocation order

---

#### 3.2 Event Handlers (P1-B)
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentCaptureHandler`
  - `PaymentRefundHandler`
  - `WebhookProcessingHandler`

**Test Scenarios:**
```php
✅ testCaptureHandler_ValidatesOrderState()
✅ testCaptureHandler_CallsProviderAPI()
✅ testCaptureHandler_EmitsSuccessEvent()
✅ testCaptureHandler_HandlesProviderErrors()
✅ testRefundHandler_ValidatesRefundableAmount()
```

**Implementation Order:**
1. Implement handler base class with common logic
2. Write tests for state validation
3. Implement payment capture handler
4. Write tests for error handling
5. Implement payment refund handler
6. Test event flow (event → handler → service → repository)

---

#### 3.3 Domain Layer (P1-C) - OXID 7.4+ Architecture
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentOrderState` model - state machine (references oxorder via FK)
  - `PaymentTransaction` model - transaction tracking
  - `PaymentCustomer` model - customer data (references oxuser via FK)
  - `PaymentBasketSnapshot` model - basket state capture
  - **NO class extensions** in metadata.php

**Test Scenarios:**
```php
✅ testPaymentOrderState_StateTransitions_ValidSequence()
✅ testPaymentOrderState_InvalidTransition_ThrowsException()
✅ testPaymentTransaction_CreatedWithForeignKeyReference()
✅ testPaymentCustomer_OneToOneWithUser()
✅ testBasketSnapshot_TotalMatching()
✅ testComponentModels_NoOxidDependencies()
```

**Implementation Order:**
1. Implement PaymentOrderState model with state machine (references oxorder.OXID)
2. Write tests for state transitions (NOT_FINISHED → IN_PROGRESS → OK)
3. Implement PaymentTransaction model with FK to oxorder
4. Test amount calculations (no rounding errors)
5. Implement PaymentCustomer model with FK to oxuser (1:1)
6. Implement PaymentBasketSnapshot model
7. Verify NO table extensions on OXID core

---

### Block 4: Service Layer 🟠 HIGH (P1)

#### 4.1 Payment Service (P1-D)
- **Coverage Required:** 90%
- **Test Types:** Unit + Integration
- **Components:**
  - Payment orchestration
  - Provider API calls
  - Error mapping

**Test Scenarios:**
```php
✅ testCreatePaymentOrder_CallsProviderAPI()
✅ testCapturePayment_UpdatesOrderState()
✅ testProviderError_MapsToComponentException()
✅ testRetryLogic_HandlesTransientFailures()
```

**Implementation Order:**
1. Define service interface
2. Write tests for API calls
3. Implement provider API client wrapper
4. Test error handling and mapping
5. Implement retry logic for transient failures

---

#### 4.2 Module Settings & Configuration (P1-E)
- **Coverage Required:** 90%
- **Test Types:** Unit
- **Components:**
  - Configuration validation
  - Environment-specific settings
  - Credential management

**Test Scenarios:**
```php
✅ testMissingCredentials_ThrowsConfigurationException()
✅ testSandboxMode_UsesTestEndpoints()
✅ testCaptureStrategy_ValidatesAllowedValues()
```

**Implementation Order:**
1. Define configuration schema
2. Write tests for required fields
3. Implement configuration validation
4. Test environment separation (sandbox/production)

---

### Block 5: Provider Integration 🟡 MEDIUM (P2)

#### 5.1 Request Factories (P2-A)
- **Coverage Required:** 85%
- **Test Types:** Unit
- **Components:**
  - Request builders
  - Response parsers
  - Data transformation

**Test Scenarios:**
```php
✅ testBuildRequest_CorrectFormat()
✅ testAmountConversion_ToCents()
✅ testParseResponse_HandlesAllFields()
```

**Implementation Order:**
1. Define request/response interfaces
2. Write tests for request building
3. Implement factory pattern
4. Test response parsing

---

#### 5.2 Error Mapping (P2-B)
- **Coverage Required:** 85%
- **Test Types:** Unit
- **Components:**
  - Provider error to component error mapping
  - User-friendly error messages

**Test Scenarios:**
```php
✅ testCardDeclined_MapsToPaymentDeclined()
✅ testInsufficientFunds_MapsToPaymentDeclined()
✅ testInvalidCard_MapsToInvalidPaymentMethod()
```

---

### Block 5.5: SDK-Adapter Layer (NEW) 🟡 MEDIUM (P2)

**Test Organization Note:** This block covers testing for both component and provider code. Provider adapter tests (5.5.2) belong in **provider test suites** (e.g., `tests/Stripe/`, `tests/Unzer/`), while adapter interface tests (5.5.1) and service integration tests (5.5.4) belong in **component test suites** (`tests/Component/Unit/`, `tests/Component/Integration/`). See [09-test-organization.md](09-test-organization.md) for complete test separation strategy.

**Key Testing Principles:**
- **Component Tests** (5.5.1, 5.5.3, 5.5.4): Mock `PaymentAdapterInterface`, no provider SDK dependencies
- **Provider Tests** (5.5.2): Mock or use real provider SDKs, test adapter implementations
- **Component Coverage**: 95%+ (fast, no external dependencies)
- **Provider Coverage**: 90%+ (slower, real SDK integration)

---

#### 5.5.1 Adapter Interface & Request/Response Objects (P2-A+)
**Test Location:** `tests/Component/Unit/Adapter/`
- **Coverage Required:** 100%
- **Test Types:** Unit
- **Components:**
  - `PaymentAdapterInterface` - Unified interface
  - Request objects (`CreatePaymentRequest`, `CapturePaymentRequest`, etc.)
  - Response objects (`PaymentResponse`, `CaptureResponse`, etc.)
  - `PaymentAdapterException` - Unified error handling

**Test Scenarios:**
```php
✅ testCreatePaymentRequest_ImmutableAfterCreation()
✅ testPaymentResponse_StatusHelpers()
✅ testPaymentResponse_NormalizedAmounts()
✅ testAdapterException_FromProviderError()
✅ testAdapterException_IsCardDeclined()
✅ testAdapterException_IsNetworkError()
```

**Implementation Order:**
1. Define `PaymentAdapterInterface` with all methods
2. Write tests for request objects (immutability, validation)
3. Implement request objects as readonly DTOs
4. Write tests for response objects (status helpers, amount normalization)
5. Implement response objects
6. Write tests for exception hierarchy
7. Implement `PaymentAdapterException` with helper methods

---

#### 5.5.2 Provider Adapters (P2-B)
**Test Location:** `tests/Stripe/Unit/`, `tests/Unzer/Unit/`, `tests/PayPal/Unit/` (Provider-specific test suites)
- **Coverage Required:** 90%
- **Test Types:** Unit (mock SDKs) + Integration (real SDKs with sandbox)
- **Components:**
  - `StripeAdapter` - Stripe SDK integration
  - `UnzerAdapter` - Unzer SDK integration
  - `PayPalAdapter` - PayPal SDK integration
  - Request translation (component format → provider format)
  - Response translation (provider format → component format)
  - Error mapping (provider errors → component exceptions)

**Test Scenarios (Unit Tests - Mocked SDKs):**
```php
// tests/Stripe/Unit/StripeAdapterTest.php

✅ testCreatePayment_TranslatesRequestToStripeFormat()
✅ testCreatePayment_ConvertsamountToCents()
✅ testCreatePayment_ConvertsCurrencyToLowercase()
✅ testCreatePayment_MapsStripeStatusToComponentStatus()
✅ testCreatePayment_HandlesStripeException()
✅ testCapturePayment_TranslatesRequestToStripeFormat()
✅ testRefundPayment_TranslatesRequestToStripeFormat()
✅ testGetSupportedPaymentMethods_ReturnsStripeethods()
✅ testSupportsFeature_ReturnsCorrectCapabilities()
✅ testParseWebhook_ValidatesSignature()
✅ testParseWebhook_RejectsInvalidSignature()
```

**Test Scenarios (Integration Tests - Real SDKs with Sandbox):**
```php
// tests/Stripe/Integration/StripeAdapterIntegrationTest.php

✅ testCreatePayment_WithRealStripeAPI_Sandbox()
✅ testCapturePayment_WithRealStripeAPI_Sandbox()
✅ testRefundPayment_WithRealStripeAPI_Sandbox()
✅ testGetPaymentDetails_WithRealStripeAPI_Sandbox()
✅ testWebhookParsing_WithRealStripeEvent()
```

**Implementation Order:**
1. Write unit tests for `StripeAdapter` (mock Stripe SDK)
2. Implement `StripeAdapter` with request/response translation
3. Write integration tests with real Stripe sandbox
4. Repeat for `UnzerAdapter`, `PayPalAdapter`
5. Ensure all adapters follow same pattern

---

#### 5.5.3 Adapter Factory (P2-C)
**Test Location:** `tests/Component/Unit/Adapter/`
- **Coverage Required:** 95%
- **Test Types:** Unit
- **Components:**
  - `AdapterFactory` - Configuration-driven adapter creation
  - Provider configuration validation
  - Credential management

**Test Scenarios:**
```php
✅ testCreateAdapter_Stripe_ReturnsStripeAdapter()
✅ testCreateAdapter_Unzer_ReturnsUnzerAdapter()
✅ testCreateAdapter_PayPal_ReturnsPayPalAdapter()
✅ testCreateAdapter_UnknownProvider_ThrowsException()
✅ testCreateDefaultAdapter_UsesConfiguredProvider()
✅ testCreateAdapter_ValidatesCredentials()
✅ testCreateAdapter_UsesSandboxMode()
```

**Implementation Order:**
1. Write tests for factory pattern
2. Implement `AdapterFactory` with provider switch
3. Test configuration validation
4. Test credential management
5. Test sandbox mode toggling

---

#### 5.5.4 Integration with PaymentService (P2-D)
**Test Location:** `tests/Component/Unit/Service/` and `tests/Component/Integration/Service/`

---

## Related Documentation

- **[Part 2: Data Persistence](09-02-tdd-data-persistence.md)** - Repository layer testing
- **[Part 4: Provider Integration](09-04-tdd-provider-integration.md)** - SDK-Adapter layer (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
