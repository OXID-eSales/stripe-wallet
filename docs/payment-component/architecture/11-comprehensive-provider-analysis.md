# Comprehensive Provider Analysis - PayPal, Amazon Pay, Stripe, Unzer, TeleCash

**Document Version:** 1.0
**Last Updated:** 2025-10-16
**Status:** Analysis & Requirements Definition

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Provider Analysis Matrix](#provider-analysis-matrix)
3. [Missing Component Features](#missing-component-features)
4. [Enhanced Component Architecture](#enhanced-component-architecture)
5. [Implementation Priority](#implementation-priority)
6. [Updated TDD Blocks](#updated-tdd-blocks)

---

## 1. Executive Summary

### Analysis Scope

Analyzed **5 payment provider modules** for OXID eShop 7:
- **Stripe** (stripe/stripe-php v13)
- **Unzer** (unzerdev/php-sdk v3.6)
- **TeleCash** (Custom SOAP client)
- **PayPal** (oxid-solution-catalysts/paypal-client v3)
- **Amazon Pay** (amzn/amazon-pay-api-sdk-php v2.5)

### Key Findings

After comprehensive analysis, **12 additional component features** are required to ensure the payment component can support ALL providers without modification:

1. **Two-Step Payment Flow** (Authorize → Capture)
2. **Reauthorization Support** (for expired authorizations)
3. **Idempotency Key Management** (prevent double-charging)
4. **Order Number Generation & Custom ID** (provider metadata)
5. **Vaulting/Tokenization Support** (saved payment methods)
6. **Address Validation & Normalization** (provider-provided addresses)
7. **3D Secure (SCA) Verification** (Strong Customer Authentication)
8. **Multi-Currency & Locale Support** (language mapping)
9. **Delivery Tracking & Notifications** (shipping updates to provider)
10. **Partial Refund Support** (refund less than capture amount)
11. **Payment Status Polling** (check payment status after creation)
12. **Session State Management** (checkout session persistence)

### Impact

**Without these features**, each provider extension will need to:
- Re-implement common patterns (30-40% duplicate code)
- Create custom state management
- Build provider-specific workflow handling
- Maintain separate authorization/capture logic

**With these features**, provider extensions become **true 30% adapters**:
- Only SDK translation logic
- No workflow management
- No state machine logic
- Maximum code reuse

---

## 2. Provider Analysis Matrix

### 2.1 SDK Dependencies

| Provider | SDK Package | Version | Type | Complexity |
|----------|-------------|---------|------|------------|
| **Stripe** | `stripe/stripe-php` | v13 | REST API | Simple |
| **Unzer** | `unzerdev/php-sdk` | v3.6 | REST API | Medium |
| **TeleCash** | Custom SOAP client | N/A | SOAP/XML | High |
| **PayPal** | `oxid-solution-catalysts/paypal-client` | v3 | REST API | Complex |
| **Amazon Pay** | `amzn/amazon-pay-api-sdk-php` | v2.5 | REST API | Medium |

### 2.2 Payment Flow Patterns

| Pattern | Stripe | Unzer | TeleCash | PayPal | Amazon Pay | Component Support |
|---------|--------|-------|----------|--------|------------|-------------------|
| **Direct Capture** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ Existing |
| **Authorize + Capture** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Reauthorization** | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ **MISSING** |
| **Partial Capture** | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ Partial |
| **Partial Refund** | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ Partial |
| **Void/Cancel** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ Existing |
| **Idempotency** | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ **MISSING** |

### 2.3 Advanced Features

| Feature | Stripe | Unzer | TeleCash | PayPal | Amazon Pay | Component Support |
|---------|--------|-------|----------|--------|------------|-------------------|
| **Vaulting/Tokenization** | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **3D Secure (SCA)** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ **MISSING** |
| **Address Validation** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Multi-Currency** | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ Partial |
| **Locale Mapping** | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **Delivery Tracking** | ✅ | ❌ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **Webhooks** | ✅ | ✅ | ✅ | ✅ | ✅ (IPN) | ✅ Existing |

### 2.4 State Management

| State | Stripe | Unzer | TeleCash | PayPal | Amazon Pay | Component State |
|-------|--------|-------|----------|--------|------------|-----------------|
| **Created/Pending** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `pending` |
| **Authorized** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `authorized` |
| **Captured/Completed** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `captured` |
| **Requires Action (3DS)** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ **MISSING** |
| **Processing** | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ **MISSING** |
| **Failed** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `failed` |
| **Canceled** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ `canceled` |
| **Refunded** | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ Partial |
| **Partially Refunded** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **MISSING** |
| **Expired** | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ **MISSING** |

---

## 3. Missing Component Features

### 3.1 Two-Step Payment Flow (Authorize → Capture)

**Status:** ❌ **CRITICAL MISSING**

**Required By:** All providers (Stripe, Unzer, PayPal, Amazon, TeleCash)

**Current Issue:**
- Component only supports direct capture
- No authorization tracking
- No delayed capture workflow

**PayPal Example:**
```php
// Step 1: Authorize payment
$orderService->authorizePaymentForOrder($checkoutOrderId, $request);
$authorizationId = $payPalOrder->purchase_units[0]->payments->authorizations[0]->id;

// Step 2: Capture later (e.g., on shipment)
$paymentService->captureAuthorizedPayment($authorizationId, $request);
```

**Amazon Pay Example:**
```php
// Two-step flow with separate auth and capture
$this->isTwoStep = true;
$this->processPayment($amazonSessionId, $basket, $logger);
// Later capture via capturePaymentForOrder()
```

**Component Requirements:**
1. **New Transaction Types:**
   - `authorization` (not just `capture`)
   - Track authorization ID separately from transaction ID

2. **New Payment States:**
   - `authorized` (already exists)
   - `requires_capture` (new)
   - `authorization_expired` (new)

3. **New Service Methods:**
   ```php
   interface PaymentAdapterInterface {
       public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;
       public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;
       public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;
   }
   ```

4. **Authorization Expiration Handling:**
   - Track authorization creation time
   - Check expiration before capture
   - Trigger reauthorization if needed

---

### 3.2 Reauthorization Support

**Status:** ❌ **HIGH PRIORITY MISSING**

**Required By:** PayPal, Unzer

**Current Issue:**
- No reauthorization logic in component
- Each provider implements separately

**PayPal Example:**
```php
// Check authorization validity (29 days for PayPal)
$timeAuthorizationValidity = time()
    - strtotime($payPalOrder->update_time ?? '')
    + Constants::PAYPAL_AUTHORIZATION_VALIDITY;

if ($timeAuthorizationValidity <= 0) {
    $reAuthorizeRequest = new ReauthorizeRequest();
    $paymentService->reauthorizeAuthorizedPayment($authorizationId, $reAuthorizeRequest);
}
```

**Component Requirements:**
1. **Authorization Expiration Configuration:**
   ```php
   class AuthorizationConfig {
       public function getAuthorizationValidityPeriod(string $provider): int;
       // Stripe: 7 days, PayPal: 29 days, Unzer: 7 days
   }
   ```

2. **Automatic Reauthorization Check:**
   ```php
   class PaymentService {
       public function capturePayment(string $authorizationId): CaptureResponse {
           if ($this->isAuthorizationExpired($authorizationId)) {
               $this->reauthorizePayment($authorizationId);
           }
           return $this->adapter->captureAuthorization($request);
       }
   }
   ```

3. **New Adapter Method:**
   ```php
   interface PaymentAdapterInterface {
       public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;
   }
   ```

---

### 3.3 Idempotency Key Management

**Status:** ❌ **CRITICAL MISSING**

**Required By:** Stripe, PayPal, Amazon Pay, Unzer

**Current Issue:**
- No idempotency key generation
- No duplicate request prevention
- Risk of double-charging on network retries

**Amazon Pay Example:**
```php
$result = OxidServiceProvider::getAmazonClient()->completeCheckoutSession(
    $amazonSessionId,
    $data,
    [
        'x-amz-pay-Idempotency-Key' => $amazonConfig->getUuid()
    ]
);
```

**PayPal Example:**
```php
// Idempotency built into order tracking
$payPalOrder = $this->trackPayPalOrder(
    $shopOrderId,
    $payPalOrderId,
    $paymentMethodId,
    $status,
    $payPalTransactionId,
    $transactionType
);
```

**Component Requirements:**
1. **Idempotency Key Service:**
   ```php
   class IdempotencyService {
       public function generateKey(string $orderId, string $operation): string;
       public function hasBeenProcessed(string $key): bool;
       public function markAsProcessed(string $key, mixed $result, int $ttl = 86400): void;
       public function getResult(string $key): mixed;
   }
   ```

2. **Database Schema:**
   ```sql
   CREATE TABLE oe_payments_idempotency (
       OXID CHAR(32) NOT NULL PRIMARY KEY,
       OXKEY VARCHAR(128) NOT NULL UNIQUE,
       OXORDERID CHAR(32) NOT NULL,
       OXOPERATION VARCHAR(32) NOT NULL,
       OXRESULT TEXT,
       OXSTATUS VARCHAR(32),
       OXCREATED DATETIME NOT NULL,
       OXEXPIRES DATETIME NOT NULL,
       INDEX IDX_KEY (OXKEY),
       INDEX IDX_ORDER (OXORDERID)
   );
   ```

3. **Adapter Integration:**
   ```php
   class PaymentService {
       public function createPayment(...): PaymentResponse {
           $idempotencyKey = $this->idempotencyService->generateKey($orderId, 'create');

           if ($this->idempotencyService->hasBeenProcessed($idempotencyKey)) {
               return $this->idempotencyService->getResult($idempotencyKey);
           }

           $request->setIdempotencyKey($idempotencyKey);
           $response = $this->adapter->createPayment($request);

           $this->idempotencyService->markAsProcessed($idempotencyKey, $response);
           return $response;
       }
   }
   ```

---

### 3.4 Order Number Generation & Custom ID

**Status:** ⚠️ **PARTIALLY MISSING**

**Required By:** PayPal, Stripe, Unzer, Amazon Pay

**Current Issue:**
- No standardized order number generation
- No custom ID format for provider metadata

**PayPal Example:**
```php
public function getCustomIdParameter(?EshopModelOrder $order): string {
    $orderNumber = (int) $order->getFieldData('oxordernr');
    if ($orderNumber === 0) {
        $order->setOrderNumber();
        $orderNumber = $order->getFieldData('oxordernr');
    }

    if ($moduleSettings->isCustomIdSchemaStructural()) {
        $customID = [
            'oxordernr' => $orderNumber,
            'moduleVersion' => $module->getInfo('version'),
            'oxidVersion' => ShopVersion::getVersion()
        ];
        return json_encode($customID);
    }

    return $orderNumber;
}
```

**Component Requirements:**
1. **Order Number Service:**
   ```php
   class OrderNumberService {
       public function ensureOrderNumber(Order $order): string;
       public function getCustomId(Order $order, array $metadata = []): string;
       public function parseCustomId(string $customId): array;
   }
   ```

2. **Configuration:**
   ```php
   class ModuleSettings {
       public function getCustomIdFormat(): string; // 'simple' | 'structured'
       public function getCustomIdTemplate(): string;
   }
   ```

---

### 3.5 Vaulting/Tokenization Support

**Status:** ❌ **HIGH PRIORITY MISSING**

**Required By:** Stripe, PayPal, Unzer, Amazon Pay

**Current Issue:**
- No saved payment method support
- No customer tokenization

**PayPal Example:**
```php
if ($vault->status === "VAULTED") {
    $vaultSuccess = false;
    if ($id = $vault->customer["id"]) {
        $user->oxuser__oscpaypalcustomerid = new Field($id);
        if ($user->save()) {
            $vaultSuccess = true;
        }
    }
    $session->setVariable("vaultSuccess", $vaultSuccess);
}
```

**Component Requirements:**
1. **Vaulting Service:**
   ```php
   interface VaultingServiceInterface {
       public function savePaymentMethod(User $user, string $providerPaymentMethodId, array $metadata): SavedPaymentMethod;
       public function getSavedPaymentMethods(User $user): array;
       public function deletePaymentMethod(User $user, string $paymentMethodId): bool;
       public function setDefaultPaymentMethod(User $user, string $paymentMethodId): bool;
   }
   ```

2. **Database Schema:**
   ```sql
   CREATE TABLE oe_payments_saved_methods (
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
       OXUPDATED DATETIME NOT NULL,
       FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE,
       INDEX IDX_USER_PROVIDER (OXUSERID, OXPROVIDER)
   );
   ```

3. **Adapter Integration:**
   ```php
   interface PaymentAdapterInterface {
       public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse;
       public function listPaymentMethods(string $customerId): array;
       public function deletePaymentMethod(string $paymentMethodId): bool;
   }
   ```

---

### 3.6 Address Validation & Normalization

**Status:** ❌ **MEDIUM PRIORITY MISSING**

**Required By:** Amazon Pay, PayPal, Stripe, Unzer

**Current Issue:**
- Providers supply addresses that must be validated
- No address normalization service

**Amazon Pay Example:**
```php
// Amazon provides shipping and billing addresses
$deliveryAddress = $checkoutSession['response']['shippingAddress'] ?? [];
$billingAddress = $checkoutSession['response']['billingAddress'] ?? [];

// Must map to OXID format
return Address::mapAddressToView($address);
```

**Component Requirements:**
1. **Address Validation Service:**
   ```php
   class AddressValidationService {
       public function validateAddress(array $address): ValidationResult;
       public function normalizeAddress(array $address, string $countryCode): array;
       public function mapProviderAddress(array $providerAddress, string $provider): array;
   }
   ```

2. **Address Mapping:**
   ```php
   abstract class AbstractAddressMapper {
       abstract public function mapToOxidFormat(array $providerAddress): array;
       abstract public function mapFromOxidFormat(User $user, ?Order $order = null): array;
   }
   ```

---

### 3.7 3D Secure (SCA) Verification

**Status:** ❌ **HIGH PRIORITY MISSING**

**Required By:** Stripe, PayPal, Unzer, TeleCash

**Current Issue:**
- No SCA verification workflow
- No "requires_action" state handling

**PayPal Example:**
```php
public function verify3D(string $paymentId, Order $payPalOrder): bool {
    if ($this->moduleSettings->alwaysIgnoreSCAResult()) {
        return true;
    }

    if ($this->scaValidator->isCardUsableForPayment($payPalOrder)) {
        return true;
    }

    return false;
}
```

**Component Requirements:**
1. **SCA Validator Interface:**
   ```php
   interface SCAValidatorInterface {
       public function requiresAuthentication(PaymentResponse $response): bool;
       public function getAuthenticationUrl(PaymentResponse $response): ?string;
       public function validateAuthenticationResult(string $providerPaymentId): bool;
       public function isCardUsableForPayment(mixed $providerPayment): bool;
   }
   ```

2. **New Payment States:**
   ```php
   const STATE_REQUIRES_ACTION = 'requires_action';
   const STATE_REQUIRES_AUTHENTICATION = 'requires_authentication';
   ```

3. **Workflow Support:**
   ```php
   class PaymentService {
       public function initiatePayment(...): PaymentResponse {
           $response = $this->adapter->createPayment($request);

           if ($response->requiresAction()) {
               // Store state and redirect to authentication URL
               $this->stateManager->setAwaitingAuthentication($orderId, $response->getProviderPaymentId());
               return $response; // Contains authenticationUrl
           }

           return $response;
       }

       public function handleAuthenticationCallback(string $orderId, array $params): PaymentResponse {
           // Verify authentication result
           $providerPaymentId = $this->stateManager->getProviderPaymentId($orderId);
           if (!$this->scaValidator->validateAuthenticationResult($providerPaymentId)) {
               throw new AuthenticationFailedException();
           }

           // Continue with payment
           return $this->adapter->getPaymentDetails($providerPaymentId);
       }
   }
   ```

---

### 3.8 Multi-Currency & Locale Support

**Status:** ⚠️ **PARTIALLY MISSING**

**Required By:** All providers

**Current Issue:**
- No locale mapping service
- No language code translation

**PayPal Example:**
```php
class LanguageLocaleMapper {
    private const LANGUAGE_LOCALE_MAP = [
        'de' => 'de-DE',
        'en' => 'en-US',
        'fr' => 'fr-FR',
        // ... 20+ locales
    ];

    public function getLocaleForLanguage(string $languageAbbr): string;
    public function getBuyerLocale(): string;
}
```

**Component Requirements:**
1. **Locale Service:**
   ```php
   class LocaleService {
       public function getProviderLocale(string $provider, string $oxid LangCode): string;
       public function getCurrencyForLocale(string $locale): string;
       public function formatAmount(float $amount, string $currency, string $locale): string;
   }
   ```

2. **Configuration:**
   ```php
   // Component provides default mappings
   private const LOCALE_MAP = [
       'de' => ['stripe' => 'de-DE', 'paypal' => 'de_DE', 'unzer' => 'de-DE'],
       'en' => ['stripe' => 'en-US', 'paypal' => 'en_US', 'unzer' => 'en-US'],
       // ...
   ];
   ```

---

### 3.9 Delivery Tracking & Notifications

**Status:** ❌ **LOW PRIORITY MISSING**

**Required By:** Amazon Pay, PayPal, Stripe

**Current Issue:**
- No delivery tracking integration
- No shipment notifications to provider

**Amazon Pay Example:**
```php
public function sendAlexaNotification(
    string $chargePermissionId,
    string $trackingCode = '',
    string $deliveryType = ''
) {
    $payload = [
        'amazonOrderReferenceId' => $chargePermissionId,
        'deliveryDetails' => [
            ['trackingNumber' => $trackingCode, 'carrierCode' => $carrierCode]
        ]
    ];

    $result = OxidServiceProvider::getAmazonClient()->deliveryTrackers($payload);
}
```

**Component Requirements:**
1. **Delivery Tracking Service:**
   ```php
   interface DeliveryTrackingInterface {
       public function notifyShipment(string $orderId, string $trackingCode, string $carrier): bool;
       public function updateDeliveryStatus(string $orderId, string $status): bool;
   }
   ```

2. **Adapter Method:**
   ```php
   interface PaymentAdapterInterface {
       public function sendDeliveryNotification(DeliveryNotificationRequest $request): bool;
   }
   ```

---

### 3.10 Partial Refund Support

**Status:** ⚠️ **PARTIALLY MISSING**

**Required By:** All providers

**Current Issue:**
- Component has basic refund
- No partial refund tracking
- No maximum refund calculation

**Amazon Pay Example:**
```php
public function getMaximalRefundAmount(string $orderId): float {
    $orderAmount = (float)$order->getTotalOrderSum();
    $compensation = min(75, 0.15 * $orderAmount);
    return min(150000, $orderAmount + $compensation);
}

public function createRefund(string $orderId, float $refundAmount) {
    if ($refundAmount > round($this->getMaximalRefundAmount($orderId), 2)) {
        throw new RefundAmountExceededException();
    }
    // Process refund...
}
```

**Component Requirements:**
1. **Refund Tracking:**
   ```sql
   ALTER TABLE oe_payments_transaction ADD COLUMN:
       OXREFUNDED_AMOUNT DECIMAL(10,2) DEFAULT 0.00,
       OXREFUNDABLE_AMOUNT DECIMAL(10,2),
       OXMAX_REFUND_AMOUNT DECIMAL(10,2)
   ```

2. **Refund Service:**
   ```php
   class RefundService {
       public function getRefundableAmount(string $transactionId): float;
       public function getMaxRefundAmount(string $transactionId, string $provider): float;
       public function createPartialRefund(string $transactionId, float $amount, string $reason): RefundResponse;
   }
   ```

---

### 3.11 Payment Status Polling

**Status:** ❌ **MEDIUM PRIORITY MISSING**

**Required By:** Amazon Pay, PayPal

**Current Issue:**
- No polling mechanism for async payments
- No status check scheduler

**Amazon Pay Example:**
```php
public function checkOrderState(string $orderId) {
    $result = OxidServiceProvider::getAmazonClient()->getCharge($chargeId);
    $response = $result['response'];

    if ($response['statusDetails']['state'] === 'Canceled' && $isCancelled === false) {
        $this->processCancel($orderId);
    } elseif ($response['statusDetails']['state'] === 'Captured' && $isCaptured === false) {
        $repository->markOrderPaid($orderId, ...);
    }
}
```

**Component Requirements:**
1. **Status Polling Service:**
   ```php
   class PaymentStatusPollingService {
       public function scheduleStatusCheck(string $orderId, int $intervalSeconds = 300): void;
       public function checkPaymentStatus(string $orderId): PaymentResponse;
       public function processStatusUpdate(string $orderId, PaymentResponse $response): void;
   }
   ```

2. **Cron Job Integration:**
   ```php
   class PaymentStatusCronJob {
       public function execute(): void {
           $pendingPayments = $this->repository->getPendingPayments();
           foreach ($pendingPayments as $payment) {
               $this->pollingService->checkPaymentStatus($payment->getOrderId());
           }
       }
   }
   ```

---

### 3.12 Session State Management

**Status:** ⚠️ **PARTIALLY MISSING**

**Required By:** Amazon Pay, PayPal

**Current Issue:**
- No checkout session persistence
- No provider session ID storage

**Amazon Pay Example:**
```php
public function storeAmazonSession(string $checkoutSessionId) {
    Registry::getSession()->setVariable(Constants::SESSION_CHECKOUT_ID, $checkoutSessionId);
}

public function getCheckoutSession(): array {
    $checkoutSessionId = $this->getCheckoutSessionId();
    $this->checkoutSession = $this->client->getCheckoutSession($checkoutSessionId);
    return $this->checkoutSession;
}
```

**Component Requirements:**
1. **Session Manager:**
   ```php
   class PaymentSessionManager {
       public function storeProviderSession(string $provider, string $sessionId, array $data): void;
       public function getProviderSession(string $provider): ?array;
       public function clearProviderSession(string $provider): void;
       public function isSessionActive(string $provider): bool;
   }
   ```

2. **Database Persistence:**
   ```sql
   CREATE TABLE oe_payments_sessions (
       OXID CHAR(32) NOT NULL PRIMARY KEY,
       OXPROVIDER VARCHAR(32) NOT NULL,
       OXSESSIONID VARCHAR(128) NOT NULL,
       OXUSERID CHAR(32),
       OXBASKETID CHAR(32),
       OXDATA TEXT,
       OXCREATED DATETIME NOT NULL,
       OXEXPIRES DATETIME NOT NULL,
       INDEX IDX_PROVIDER_SESSION (OXPROVIDER, OXSESSIONID)
   );
   ```

---

## 4. Enhanced Component Architecture

### 4.1 New Component Services

```
src/Component/Service/
├── Authorization/
│   ├── AuthorizationService.php           # NEW
│   ├── ReauthorizationService.php         # NEW
│   └── AuthorizationExpirationChecker.php # NEW
├── Idempotency/
│   ├── IdempotencyService.php             # NEW
│   └── IdempotencyKeyGenerator.php        # NEW
├── Vaulting/
│   ├── VaultingService.php                # NEW
│   └── SavedPaymentMethodRepository.php   # NEW
├── Address/
│   ├── AddressValidationService.php       # NEW
│   └── AddressNormalizationService.php    # NEW
├── Authentication/
│   ├── SCAValidatorInterface.php          # NEW
│   └── AbstractSCAValidator.php           # NEW
├── Locale/
│   ├── LocaleService.php                  # NEW
│   └── CurrencyFormatter.php              # NEW
├── Tracking/
│   ├── DeliveryTrackingService.php        # NEW
│   └── ShipmentNotificationService.php    # NEW
├── Refund/
│   ├── RefundService.php                  # ENHANCE
│   └── PartialRefundCalculator.php        # NEW
├── Polling/
│   ├── PaymentStatusPollingService.php    # NEW
│   └── StatusCheckScheduler.php           # NEW
├── Session/
│   ├── PaymentSessionManager.php          # NEW
│   └── CheckoutSessionPersistence.php     # NEW
└── OrderNumber/
    ├── OrderNumberService.php             # NEW
    └── CustomIdFormatter.php              # NEW
```

### 4.2 Enhanced Adapter Interface

```php
interface PaymentAdapterInterface
{
    // EXISTING: Basic payment operations
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;
    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;
    public function refundPayment(RefundPaymentRequest $request): RefundResponse;
    public function voidPayment(VoidPaymentRequest $request): VoidResponse;
    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;

    // NEW: Two-step authorization flow
    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse;
    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse;
    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse;
    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse;

    // NEW: Partial operations
    public function partialCapture(PartialCaptureRequest $request): CaptureResponse;
    public function partialRefund(PartialRefundRequest $request): RefundResponse;

    // NEW: Vaulting/Tokenization
    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse;
    public function listPaymentMethods(string $customerId): array;
    public function deletePaymentMethod(string $paymentMethodId): bool;
    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): bool;

    // NEW: 3D Secure/SCA
    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse;
    public function verify3DSecureResult(string $providerPaymentId): bool;

    // NEW: Delivery tracking
    public function sendDeliveryNotification(DeliveryNotificationRequest $request): bool;
    public function updateTrackingInfo(UpdateTrackingRequest $request): bool;

    // NEW: Status polling
    public function getChargeStatus(string $chargeId): ChargeStatusResponse;
    public function getAuthorizationStatus(string $authorizationId): AuthorizationStatusResponse;

    // EXISTING: Metadata
    public function getSupportedPaymentMethods(): array;
    public function getProviderName(): string;
    public function supportsFeature(string $feature): bool;

    // EXISTING: Webhook handling
    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent;
}
```

### 4.3 Enhanced Payment States

```php
interface PaymentStates
{
    // EXISTING States
    const STATE_PENDING = 'pending';
    const STATE_AUTHORIZED = 'authorized';
    const STATE_CAPTURED = 'captured';
    const STATE_FAILED = 'failed';
    const STATE_CANCELED = 'canceled';

    // NEW States
    const STATE_REQUIRES_ACTION = 'requires_action';
    const STATE_REQUIRES_AUTHENTICATION = 'requires_authentication';
    const STATE_PROCESSING = 'processing';
    const STATE_REQUIRES_CAPTURE = 'requires_capture';
    const STATE_PARTIALLY_CAPTURED = 'partially_captured';
    const STATE_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATE_REFUNDED = 'refunded';
    const STATE_AUTHORIZATION_EXPIRED = 'authorization_expired';
    const STATE_AWAITING_REAUTHORIZATION = 'awaiting_reauthorization';
}
```

### 4.4 New Database Tables

```sql
-- Idempotency tracking
CREATE TABLE oe_payments_idempotency (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXKEY VARCHAR(128) NOT NULL UNIQUE,
    OXORDERID CHAR(32) NOT NULL,
    OXOPERATION VARCHAR(32) NOT NULL,
    OXRESULT TEXT,
    OXSTATUS VARCHAR(32),
    OXCREATED DATETIME NOT NULL,
    OXEXPIRES DATETIME NOT NULL
);

-- Saved payment methods (vaulting)
CREATE TABLE oe_payments_saved_methods (
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
    FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE
);

-- Checkout sessions
CREATE TABLE oe_payments_sessions (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXPROVIDER VARCHAR(32) NOT NULL,
    OXSESSIONID VARCHAR(128) NOT NULL,
    OXUSERID CHAR(32),
    OXBASKETID CHAR(32),
    OXDATA TEXT,
    OXCREATED DATETIME NOT NULL,
    OXEXPIRES DATETIME NOT NULL
);

-- Authorization tracking (enhance existing transaction table)
ALTER TABLE oe_payments_transaction ADD COLUMN:
    OXAUTHORIZATION_ID VARCHAR(128),
    OXAUTHORIZATION_STATUS VARCHAR(32),
    OXAUTHORIZATION_EXPIRES DATETIME,
    OXCAPTURED_AMOUNT DECIMAL(10,2) DEFAULT 0.00,
    OXREFUNDED_AMOUNT DECIMAL(10,2) DEFAULT 0.00,
    OXREFUNDABLE_AMOUNT DECIMAL(10,2),
    OXMAX_REFUND_AMOUNT DECIMAL(10,2),
    OXREQUIRES_ACTION BOOLEAN DEFAULT FALSE,
    OXACTION_URL TEXT,
    INDEX IDX_AUTHORIZATION (OXAUTHORIZATION_ID);
```

---

## 5. Implementation Priority

### Phase 1: Critical (Sprint 1-2) - Enables All Providers

1. **Two-Step Authorization Flow** ⭐⭐⭐⭐⭐
   - Required by: ALL providers
   - Complexity: Medium
   - Estimated: 3-5 days
   - Blocks: Other authorization features

2. **Idempotency Management** ⭐⭐⭐⭐⭐
   - Required by: Stripe, PayPal, Amazon, Unzer
   - Complexity: Medium
   - Estimated: 2-3 days
   - Critical for: Production safety

3. **Enhanced Payment States** ⭐⭐⭐⭐⭐
   - Required by: ALL providers
   - Complexity: Low
   - Estimated: 1-2 days
   - Blocks: State machine logic

### Phase 2: High Priority (Sprint 2-3) - Enables Advanced Features

4. **Reauthorization Support** ⭐⭐⭐⭐
   - Required by: PayPal, Unzer
   - Complexity: Medium
   - Estimated: 2-3 days
   - Depends on: Two-step flow

5. **3D Secure/SCA Verification** ⭐⭐⭐⭐
   - Required by: Stripe, PayPal, Unzer, TeleCash
   - Complexity: High
   - Estimated: 3-4 days
   - Critical for: EU compliance

6. **Vaulting/Tokenization** ⭐⭐⭐⭐
   - Required by: Stripe, PayPal, Unzer, Amazon
   - Complexity: Medium
   - Estimated: 3-4 days
   - User experience: Major improvement

### Phase 3: Medium Priority (Sprint 3-4) - Enables Full Feature Set

7. **Order Number & Custom ID** ⭐⭐⭐
   - Required by: PayPal, Stripe, Unzer, Amazon
   - Complexity: Low
   - Estimated: 1-2 days

8. **Partial Refund Enhancement** ⭐⭐⭐
   - Required by: ALL providers
   - Complexity: Medium
   - Estimated: 2-3 days

9. **Multi-Currency & Locale** ⭐⭐⭐
   - Required by: ALL providers
   - Complexity: Low
   - Estimated: 1-2 days

10. **Address Validation** ⭐⭐⭐
    - Required by: Amazon, PayPal
    - Complexity: Medium
    - Estimated: 2-3 days

### Phase 4: Low Priority (Sprint 4-5) - Nice-to-Have

11. **Session State Management** ⭐⭐
    - Required by: Amazon, PayPal
    - Complexity: Low
    - Estimated: 1-2 days

12. **Payment Status Polling** ⭐⭐
    - Required by: Amazon, PayPal
    - Complexity: Medium
    - Estimated: 2-3 days

13. **Delivery Tracking** ⭐
    - Required by: Amazon, PayPal, Stripe
    - Complexity: Low
    - Estimated: 1-2 days

---

## 6. Updated TDD Blocks

### Block 5.6: Two-Step Authorization Flow (NEW - P0)

**Coverage Required:** 100%
**Test Types:** Unit + Integration

**Components:**
- Authorization request/response DTOs
- Authorization expiration tracking
- Reauthorization service
- Authorization → Capture workflow

**Test Scenarios:**
```php
✅ testAuthorizePayment_CreatesAuthorization()
✅ testCaptureAuthorization_CompletesPayment()
✅ testVoidAuthorization_CancelsAuthorization()
✅ testAuthorizationExpiration_DetectsExpiredAuthorization()
✅ testReauthorization_RenewsExpiredAuthorization()
✅ testPartialCapture_CapturesPartialAmount()
✅ testMultipleCaptures_AllowsMultipleCapturesUpToAuthAmount()
```

### Block 5.7: Idempotency Management (NEW - P0)

**Coverage Required:** 100%
**Test Types:** Unit + Integration

**Components:**
- IdempotencyService
- Idempotency key generation
- Duplicate request detection
- Result caching

**Test Scenarios:**
```php
✅ testGenerateKey_UniqueForOrderAndOperation()
✅ testDuplicateRequest_ReturnsCachedResult()
✅ testIdempotencyKeyExpiration_ExpiresAfter24Hours()
✅ testConcurrentRequests_OnlyOneProcessed()
✅ testNetworkRetry_NoDuplicateCharge()
```

### Block 5.8: Vaulting/Tokenization (NEW - P1)

**Coverage Required:** 95%
**Test Types:** Unit + Integration

**Components:**
- VaultingService
- SavedPaymentMethod model
- Payment method CRUD operations

**Test Scenarios:**
```php
✅ testSavePaymentMethod_StoresProviderToken()
✅ testListPaymentMethods_ReturnsUserPaymentMethods()
✅ testDeletePaymentMethod_RemovesFromVault()
✅ testSetDefaultPaymentMethod_UpdatesUserDefault()
✅ testVaultedPayment_UsesStoredPaymentMethod()
```

### Block 5.9: 3D Secure/SCA Verification (NEW - P1)

**Coverage Required:** 95%
**Test Types:** Unit + Integration

**Components:**
- SCAValidatorInterface
- 3DS authentication workflow
- Authentication result verification

**Test Scenarios:**
```php
✅ testRequiresAuthentication_DetectsWhen3DSRequired()
✅ testAuthenticationUrl_ReturnsProviderAuthUrl()
✅ testAuthentication Callback_VerifiesResult()
✅ testAuthenticationFailure_ThrowsException()
✅ testAuthenticationSuccess_ContinuesPayment()
```

### Block 5.10: Partial Refund & Calculation (NEW - P2)

**Coverage Required:** 90%
**Test Types:** Unit

**Components:**
- RefundService (enhanced)
- PartialRefundCalculator
- Maximum refund calculation

**Test Scenarios:**
```php
✅ testPartialRefund_RefundsPartialAmount()
✅ testMaxRefundCalculation_IncludesCompensation()
✅ testRefundableAmount_TracksRemainingAmount()
✅ testMultiplePartialRefunds_TracksTotalRefunded()
✅ testExceedMaxRefund_ThrowsException()
```

---

## Conclusion

By implementing these **12 additional features**, the payment component will become **truly provider-agnostic** and support:
- ✅ Stripe
- ✅ Unzer
- ✅ TeleCash
- ✅ PayPal
- ✅ Amazon Pay
- ✅ **Any future payment provider**

**Without modifications to the component.**

Provider extensions will be **true 30% adapters**:
- Only SDK translation logic
- No workflow management
- No duplicate implementations
- Maximum code reuse

**Next Steps:**
1. Review and approve this analysis
2. Update implementation tickets with new features
3. Update TDD strategy with new test blocks
4. Prioritize Phase 1 features for Sprint 1-2
