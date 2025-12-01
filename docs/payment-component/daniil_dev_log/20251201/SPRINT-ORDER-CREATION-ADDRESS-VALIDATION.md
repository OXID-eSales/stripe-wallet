# Sprint: Order Creation with Address Validation Fix

**Date:** 2025-12-01
**Status:** Planning
**Issue:** Order finalization fails with state 7 (ORDER_STATE_INVALIDDELADDRESSCHANGED)

## Problem Description

When returning from Stripe Checkout, order creation fails with:
```
Order finalization failed with state: 7 (invalid_delivery_address)
```

**Log details:**
```json
{
  "order_state": 7,
  "error_code": "invalid_delivery_address",
  "session_id": "ec9ec56a5aa9147aec973c2a3b2c9820",
  "user_id": "3443d2a1dc4b21b6dc0cfb817d3d8087",
  "payment_id": "osc_stripe_wallet",
  "basket_count": 1,
  "basket_total": 30.9
}
```

## Root Cause Analysis

### OXID's Address Validation

OXID's `Order::finalizeOrder()` includes a security check that compares:
1. **Stored delivery address hash** (saved in session before payment)
2. **Current delivery address hash** (computed at order finalization)

If these don't match, OXID returns `ORDER_STATE_INVALIDDELADDRESSCHANGED` to prevent address manipulation attacks (e.g., user changes address after payment is authorized).

### Why It Fails in Stripe Checkout Flow

In the standard OXID checkout:
1. User fills address → hash stored in session (`sDelAddrMD5`)
2. User confirms order → `finalizeOrder()` validates hash
3. Order created immediately

In Stripe Checkout flow:
1. User fills address → hash stored in session
2. User redirected to Stripe → session continues
3. User returns from Stripe → session may have changed or hash expired
4. `finalizeOrder()` → hash mismatch → `ORDER_STATE_INVALIDDELADDRESSCHANGED`

### The Hash Mechanism

```php
// In Order::validateDeliveryAddress()
$sDelAddrMD5 = Registry::getSession()->getVariable('sDelAddrMD5');
$sCurrentMD5 = $oUser->getEncodedDeliveryAddress();

if ($sDeliveryAddress) {
    $sCurrentMD5 .= $sDeliveryAddress->getEncodedDeliveryAddress();
}

if ($sDelAddrMD5 && $sDelAddrMD5 !== $sCurrentMD5) {
    return self::ORDER_STATE_INVALIDDELADDRESSCHANGED;
}
```

## Proposed Solution

### Option A: Store and Restore Address Hash ✅ SELECTED

Before redirecting to Stripe, store the delivery address hash in the contract metadata. When returning from Stripe, restore the hash to session before calling `finalizeOrder()`.

**Advantages:**
- Maintains OXID's security model
- Minimal code changes
- Works with existing OXID validation

### Option B: Skip Address Validation for Stripe Orders ❌ REJECTED BY HUMAN

Override `Order::validateDeliveryAddress()` in Stripe Order model to skip validation for Stripe payments.

**Disadvantages:**
- Reduces security
- May allow address manipulation

### Option C: Recalculate and Store Hash on Return ❌ REJECTED BY HUMAN

On return from Stripe, recalculate the address hash and store it in session before finalization.

**Disadvantages:**
- Could mask legitimate address changes
- Less secure

## Sprint Plan (TDD Approach)

### Phase 1: Write Tests First (Red)

#### Test 1: Address Hash Stored Before Redirect
```php
public function testAddressHashStoredInContractBeforeStripeRedirect(): void
{
    // Arrange: User with delivery address, basket ready
    // Act: Create checkout session
    // Assert: Contract metadata contains 'delivery_address_hash'
}
```

#### Test 2: Address Hash Restored on Return
```php
public function testAddressHashRestoredToSessionOnStripeReturn(): void
{
    // Arrange: Contract with stored delivery_address_hash
    // Act: Process StripeCheckoutReturnEvent
    // Assert: Session variable 'sDelAddrMD5' matches stored hash
}
```

#### Test 3: Order Created Successfully with Valid Address
```php
public function testOrderCreatedWhenAddressHashRestored(): void
{
    // Arrange: Valid payment, contract with address hash
    // Act: Full checkout return flow
    // Assert: Order created successfully, context has orderId
}
```

#### Test 4: Address Change Detected (Security Test)
```php
public function testOrderFailsWhenAddressActuallyChanged(): void
{
    // Arrange: Store hash, then change user's address
    // Act: Process return with old hash
    // Assert: Order fails with invalid_delivery_address (expected security behavior)
}
```

### Phase 2: Implement Solution (Green)

#### Step 1: Modify StripeContractCreationHandler

Store delivery address hash when creating contract:

```php
// In StripeContractCreationHandler::handle()
$deliveryAddressHash = $this->calculateDeliveryAddressHash($user);
$contract->setMetadata('delivery_address_hash', $deliveryAddressHash);
```

#### Step 2: Modify StripeCheckoutReturnHandler

Restore hash to session before dispatching PaymentAuthorizedEvent:

```php
// In StripeCheckoutReturnHandler::handle()
$deliveryAddressHash = $contract->getMetadata('delivery_address_hash');
if ($deliveryAddressHash) {
    Registry::getSession()->setVariable('sDelAddrMD5', $deliveryAddressHash);
}
```

#### Step 3: Update Contract Schema (if needed)

Ensure contract metadata can store the hash:
```php
$contract->setMetadata('delivery_address_hash', $hash);
$contract->setMetadata('delivery_address_id', $deliveryAddressId);
```

### Phase 3: Refactor and Document (Refactor)

1. Extract address hash logic to dedicated service
2. Add logging for debugging
3. Document security implications

## Implementation Tasks

### Task 1: Create Test for Address Hash Storage
- [ ] Create `StripeContractCreationHandlerAddressTest.php`
- [ ] Test hash is stored in contract metadata
- [ ] Test hash format is correct

### Task 2: Create Test for Address Hash Restoration
- [ ] Create `StripeCheckoutReturnHandlerAddressTest.php`
- [ ] Test hash is restored to session
- [ ] Test order creation succeeds with restored hash

### Task 3: Implement Address Hash Storage
- [ ] Modify `StripeContractCreationHandler`
- [ ] Add `calculateDeliveryAddressHash()` method
- [ ] Store hash in contract metadata

### Task 4: Implement Address Hash Restoration
- [ ] Modify `StripeCheckoutReturnHandler`
- [ ] Restore hash before event dispatch
- [ ] Log restoration for debugging

### Task 5: Integration Testing
- [ ] Test full checkout flow
- [ ] Verify order created
- [ ] Verify redirect to thank you page

### Task 6: Update Documentation
- [ ] Document the address validation flow
- [ ] Add to `STRIPE-CHECKOUT-RETURN-FLOW-FIX.md`

## Files to Modify

1. **`src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php`**
   - Add delivery address hash calculation
   - Store in contract metadata

2. **`src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`**
   - Retrieve hash from contract
   - Restore to session before order creation

3. **`src/Component/Contract/PaymentContract.php`** (if needed)
   - Ensure metadata supports hash storage

4. **Test files:**
   - `tests/Unit/Stripe/EventSystem/Handler/StripeContractCreationHandlerAddressTest.php`
   - `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerAddressTest.php`

## Security Considerations

1. **Hash must be stored securely** - Contract is in database, not client-side
2. **Hash should not be modifiable by client** - Only set by server
3. **Time-bound validation** - Contract has expiration, so hash expires too
4. **Logging for audit** - Log hash mismatches for security monitoring

## Success Criteria

1. ✅ All new tests pass
2. ✅ Existing tests still pass (869 tests)
3. ✅ Manual checkout flow succeeds
4. ✅ Order appears in admin panel
5. ✅ User redirected to thank you page
6. ✅ Address manipulation still detected (security maintained)

## Estimated Effort

| Task | Complexity | Estimate |
|------|------------|----------|
| Write tests | Medium | - |
| Implement hash storage | Low | - |
| Implement hash restoration | Low | - |
| Integration testing | Medium | - |
| Documentation | Low | - |

## Risks

1. **Session expiration** - If user takes too long on Stripe, session may expire
   - Mitigation: Use contract metadata, not session, for storage

2. **Multiple delivery addresses** - User may have changed selected address
   - Mitigation: Store delivery address ID alongside hash

3. **Guest checkout** - Address handling may differ
   - Mitigation: Test both logged-in and guest flows
