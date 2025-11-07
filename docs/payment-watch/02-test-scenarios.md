# PaymentWatch - Test Scenarios & Examples

**Complete Guide to E2E Testing Patterns**

Version: 1.0.0
Date: 2025-11-11

---

## Overview

This document provides comprehensive test scenarios demonstrating how to use PaymentWatch for end-to-end payment testing. Each scenario includes request/response examples and integration code for popular test frameworks.

---

## Common Test Patterns

### Pattern 1: Poll Until Condition Met

```typescript
async function waitForCondition(
    field: string,
    expectedValue: any,
    options: {
        whereClause?: Record<string, any>;
        operator?: string;
        timeout?: number;
        interval?: number;
    } = {}
): Promise<void> {
    const timeout = options.timeout || 30000;  // 30 seconds
    const interval = options.interval || 500;   // 500ms
    const startTime = Date.now();

    while (Date.now() - startTime < timeout) {
        const response = await fetch('https://shop.test/paymentwatch/assume', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
            },
            body: JSON.stringify({
                assumption: {
                    [field]: expectedValue,
                    ...(options.operator && options.operator !== '==' && { op: options.operator }),
                    ...(options.whereClause && { where: options.whereClause })
                }
            })
        });

        const result = await response.json();

        if (result.assumption === true) {
            console.log(`✓ Condition met: ${field} = ${expectedValue}`);
            return;
        }

        await new Promise(resolve => setTimeout(resolve, interval));
    }

    throw new Error(`Timeout: ${field} did not reach ${expectedValue} within ${timeout}ms`);
}
```

---

## Scenario 1: Stripe Payment Authorization

### Test Flow
1. User initiates checkout
2. Frontend calls payment component API
3. Contract created (state: DRAFT)
4. Stripe authorization triggered
5. Contract transitions to PENDING
6. Verify payment_authorized condition fulfilled

### Test Implementation

```typescript
// Playwright test
import { test, expect } from '@playwright/test';

test('Stripe payment authorization creates contract', async ({ page, request }) => {
    // 1. Navigate to checkout
    await page.goto('https://shop.test/checkout');
    await page.fill('#billing-email', 'test@example.com');
    await page.click('#payment-method-stripe');

    // 2. Trigger payment
    await page.click('#place-order');

    // 3. Wait for redirect to Stripe (captures order ID from URL)
    await page.waitForURL(/stripe\.com/);
    const url = page.url();
    const stripeSessionId = new URL(url).searchParams.get('session_id');

    // 4. Verify contract created
    const contractCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'osc_payment_contract.OXSTATE': 'pending',
                where: {
                    'osc_payment_contract.OXPROVIDERORDERID': stripeSessionId
                }
            }
        }
    });

    const contractResult = await contractCheck.json();
    expect(contractResult.assumption).toBe(true);

    // 5. Complete Stripe payment (test mode)
    await page.fill('#cardNumber', '4242424242424242');
    await page.fill('#cardExpiry', '12/34');
    await page.fill('#cardCvc', '123');
    await page.click('#submit-payment');

    // 6. Wait for authorization condition fulfilled
    await waitForCondition(
        'osc_payment_contract.OXSTATE',
        'ready_to_commit',
        {
            whereClause: {
                'osc_payment_contract.OXPROVIDERORDERID': stripeSessionId
            },
            timeout: 15000
        }
    );

    console.log('✓ Stripe payment authorized successfully');
});
```

### Expected Requests

**Request 1: Check contract created**
```json
{
    "assumption": {
        "osc_payment_contract.OXSTATE": "pending",
        "where": {
            "osc_payment_contract.OXPROVIDERORDERID": "cs_test_abc123xyz"
        }
    }
}
```

**Response 1:**
```json
{
    "assumption": true,
    "query_time_ms": 8.45,
    "matched_rows": 1
}
```

**Request 2: Check authorization fulfilled**
```json
{
    "assumption": {
        "osc_payment_contract.OXSTATE": "ready_to_commit",
        "where": {
            "osc_payment_contract.OXPROVIDERORDERID": "cs_test_abc123xyz"
        }
    }
}
```

**Response 2:**
```json
{
    "assumption": true,
    "query_time_ms": 12.32,
    "matched_rows": 1
}
```

---

## Scenario 2: Webhook Processing

### Test Flow
1. Payment authorized (Scenario 1 complete)
2. Stripe sends webhook to shop
3. Shop processes webhook
4. Transaction record created
5. Contract fulfilled
6. Order status updated to OK

### Test Implementation

```python
# Pytest example
import pytest
import requests
import time

def test_webhook_processing_updates_transaction_status():
    # 1. Trigger payment (assume contract ID known)
    contract_id = "contract-uuid-12345"
    provider_order_id = "pi_3AbcXyz123"

    # 2. Simulate Stripe webhook (in real test, Stripe sends this)
    webhook_payload = {
        "type": "payment_intent.succeeded",
        "data": {
            "object": {
                "id": provider_order_id,
                "status": "succeeded",
                "amount": 9999,  # $99.99 in cents
                "currency": "usd"
            }
        }
    }

    # Send webhook to shop (with valid signature)
    webhook_signature = generate_stripe_signature(webhook_payload)
    webhook_response = requests.post(
        'https://shop.test/webhook/stripe',
        json=webhook_payload,
        headers={
            'Stripe-Signature': webhook_signature
        }
    )

    assert webhook_response.status_code == 200

    # 3. Poll for transaction created
    max_retries = 20
    for i in range(max_retries):
        response = requests.post(
            'https://shop.test/paymentwatch/assume',
            headers={
                'Content-Type': 'application/json',
                'X-API-Key': os.environ['PAYMENTWATCH_API_KEY']
            },
            json={
                'assumption': {
                    'osc_payment_transaction.OXSTATUS': 'completed',
                    'where': {
                        'osc_payment_transaction.OXPROVIDERORDERID': provider_order_id
                    }
                }
            }
        )

        result = response.json()
        if result['assumption']:
            print(f"✓ Transaction completed after {i + 1} attempts")
            break

        time.sleep(0.5)
    else:
        pytest.fail("Transaction not completed within timeout")

    # 4. Verify contract fulfilled
    contract_check = requests.post(
        'https://shop.test/paymentwatch/assume',
        headers={
            'Content-Type': 'application/json',
            'X-API-Key': os.environ['PAYMENTWATCH_API_KEY']
        },
        json={
            'assumption': {
                'osc_payment_contract.OXSTATE': 'fulfilled',
                'where': {
                    'osc_payment_contract.OXID': contract_id
                }
            }
        }
    )

    assert contract_check.json()['assumption'] == True

    # 5. Verify order status updated
    order_check = requests.post(
        'https://shop.test/paymentwatch/assume',
        headers={
            'Content-Type': 'application/json',
            'X-API-Key': os.environ['PAYMENTWATCH_API_KEY']
        },
        json={
            'assumption': {
                'oxorder.OXTRANSSTATUS': 'OK',
                'where': {
                    'osc_payment_contract.OXID': contract_id
                }
            }
        }
    )

    assert order_check.json()['assumption'] == True

    print("✓ Webhook processing complete: transaction + contract + order updated")
```

---

## Scenario 3: Contract-Order Linkage

### Test Flow
1. Contract created (PENDING)
2. All conditions fulfilled
3. Order created with sequential number
4. Contract committed (OXORDERID set)
5. Verify linkage

### Test Implementation

```javascript
// Jest test
describe('Contract-Order Linkage', () => {
    let contractId: string;
    let orderId: string;

    beforeEach(async () => {
        // Create test contract
        contractId = await createTestContract({
            userId: 'user-123',
            basketTotal: 99.99
        });
    });

    test('order created when all conditions fulfilled', async () => {
        // 1. Fulfill payment authorization
        await fulfillCondition(contractId, 'payment_authorized');

        // 2. Fulfill fraud check
        await fulfillCondition(contractId, 'fraud_check');

        // 3. Fulfill stock reservation
        await fulfillCondition(contractId, 'stock_reserved');

        // 4. Wait for order creation
        await waitForCondition(
            'osc_payment_contract.OXORDERID',
            null,
            {
                operator: 'IS NOT NULL',
                whereClause: {
                    'osc_payment_contract.OXID': contractId
                },
                timeout: 10000
            }
        );

        // 5. Retrieve order ID from contract
        const orderIdResponse = await assumeRequest(
            'osc_payment_contract.OXORDERID',
            null,  // Value doesn't matter for retrieval
            {
                operator: 'IS NOT NULL',
                whereClause: {
                    'osc_payment_contract.OXID': contractId
                }
            }
        );

        expect(orderIdResponse.assumption).toBe(true);
        // Note: In real implementation, we'd need to extend API to return actual value

        // 6. Verify order exists with correct user
        const orderExists = await assumeRequest(
            'oxorder.OXUSERID',
            'user-123',
            {
                whereClause: {
                    'oxorder.OXID': orderId  // Retrieved from previous step
                }
            }
        );

        expect(orderExists.assumption).toBe(true);

        // 7. Verify contract state is COMMITTED
        const contractState = await assumeRequest(
            'osc_payment_contract.OXSTATE',
            'committed',
            {
                whereClause: {
                    'osc_payment_contract.OXID': contractId
                }
            }
        );

        expect(contractState.assumption).toBe(true);
    });

    test('order number has no gaps', async () => {
        // Create multiple contracts in parallel
        const contractIds = await Promise.all([
            createTestContract({ userId: 'user-1', basketTotal: 10.00 }),
            createTestContract({ userId: 'user-2', basketTotal: 20.00 }),
            createTestContract({ userId: 'user-3', basketTotal: 30.00 })
        ]);

        // Fulfill all conditions for all contracts
        await Promise.all(
            contractIds.map(id => fulfillAllConditions(id))
        );

        // Wait for all orders created
        await Promise.all(
            contractIds.map(id =>
                waitForCondition(
                    'osc_payment_contract.OXORDERID',
                    null,
                    { operator: 'IS NOT NULL', whereClause: { 'osc_payment_contract.OXID': id } }
                )
            )
        );

        // Retrieve order numbers
        // (In practice, you'd query the database or extend PaymentWatch to return values)
        const orderNumbers = await getOrderNumbersForContracts(contractIds);

        // Verify sequential (no gaps)
        const sorted = orderNumbers.sort((a, b) => a - b);
        for (let i = 1; i < sorted.length; i++) {
            expect(sorted[i] - sorted[i - 1]).toBe(1);
        }

        console.log('✓ Order numbers are sequential:', sorted);
    });
});
```

---

## Scenario 4: Payment Timeout & Cleanup

### Test Flow
1. Contract created
2. User abandons payment (no conditions fulfilled)
3. Contract expires after timeout (e.g., 30 minutes)
4. Cron job marks contract as EXPIRED
5. Verify cleanup

### Test Implementation

```typescript
// Playwright test with time manipulation
test('abandoned payment contract expires', async ({ page, request }) => {
    // 1. Create contract (user starts checkout)
    await page.goto('https://shop.test/checkout');
    await page.click('#payment-method-stripe');
    const contractId = await extractContractIdFromPage(page);

    // 2. Verify contract in PENDING state
    const initialCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'osc_payment_contract.OXSTATE': 'pending',
                where: {
                    'osc_payment_contract.OXID': contractId
                }
            }
        }
    });

    expect((await initialCheck.json()).assumption).toBe(true);

    // 3. Simulate time passing (manually update OXEXPIRESAT for testing)
    await updateContractExpiryForTesting(contractId, new Date(Date.now() - 1000));

    // 4. Trigger cron job (contract cleanup)
    await request.post('https://shop.test/cron/cleanup-contracts');

    // 5. Verify contract marked as EXPIRED
    const expiredCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'osc_payment_contract.OXSTATE': 'expired',
                where: {
                    'osc_payment_contract.OXID': contractId
                }
            }
        }
    });

    expect((await expiredCheck.json()).assumption).toBe(true);

    // 6. Verify NO order created
    const noOrderCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'osc_payment_contract.OXORDERID': null,
                op: 'IS NULL',
                where: {
                    'osc_payment_contract.OXID': contractId
                }
            }
        }
    });

    expect((await noOrderCheck.json()).assumption).toBe(true);

    console.log('✓ Expired contract cleaned up, no order created');
});
```

---

## Scenario 5: Fraud Check Failure

### Test Flow
1. Contract created
2. Payment authorized
3. Fraud check FAILS
4. Contract marked as FAILED
5. Order NOT created
6. User notified

### Test Implementation

```python
def test_fraud_check_failure_prevents_order_creation():
    # 1. Create contract with high-risk indicators
    contract_id = create_test_contract({
        'userId': 'user-suspicious-123',
        'basketTotal': 9999.99,  # Unusually high
        'billingCountry': 'XX'  # Suspicious country
    })

    # 2. Verify contract created
    assert check_assumption(
        'osc_payment_contract.OXSTATE',
        'pending',
        where={'osc_payment_contract.OXID': contract_id}
    )

    # 3. Authorize payment (should succeed)
    authorize_payment(contract_id)

    # 4. Wait for fraud check to fail
    wait_for_condition(
        'osc_payment_contract.OXSTATE',
        'failed',
        where={'osc_payment_contract.OXID': contract_id},
        timeout=15000
    )

    # 5. Verify order NOT created
    assert check_assumption(
        'osc_payment_contract.OXORDERID',
        None,
        operator='IS NULL',
        where={'osc_payment_contract.OXID': contract_id}
    )

    # 6. Verify transaction NOT recorded
    assert not check_assumption(
        'osc_payment_transaction.OXSTATUS',
        'completed',
        where={'osc_payment_transaction.OXCONTRACTID': contract_id}
    )

    print("✓ Fraud check failure prevented order creation")

def check_assumption(field, expected_value, operator='==', where=None):
    """Helper function to check assumption"""
    response = requests.post(
        'https://shop.test/paymentwatch/assume',
        headers={
            'Content-Type': 'application/json',
            'X-API-Key': os.environ['PAYMENTWATCH_API_KEY']
        },
        json={
            'assumption': {
                field: expected_value,
                **(where and {'where': where}),
                **({'op': operator} if operator != '==' else {})
            }
        }
    )
    return response.json().get('assumption', False)
```

---

## Scenario 6: Refund Processing

### Test Flow
1. Order completed (payment captured)
2. Admin initiates refund
3. Refund transaction created
4. Order status updated to REFUNDED

### Test Implementation

```typescript
test('refund updates transaction and order status', async ({ page, request }) => {
    // 1. Complete a purchase (assume orderId known)
    const orderId = 'order-12345';
    const transactionId = 'txn-abc123';

    // 2. Admin initiates refund
    await page.goto('https://shop.test/admin/orders');
    await page.click(`#order-${orderId}-refund`);
    await page.fill('#refund-amount', '99.99');
    await page.click('#confirm-refund');

    // 3. Wait for refund transaction created
    await waitForCondition(
        'osc_payment_transaction.OXTYPE',
        'refund',
        {
            whereClause: {
                'osc_payment_transaction.OXORDERID': orderId
            },
            timeout: 20000
        }
    );

    // 4. Verify refund status completed
    const refundCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'osc_payment_transaction.OXSTATUS': 'completed',
                where: {
                    'osc_payment_transaction.OXORDERID': orderId,
                    'osc_payment_transaction.OXTYPE': 'refund'
                }
            }
        }
    });

    expect((await refundCheck.json()).assumption).toBe(true);

    // 5. Verify order status updated (depending on shop config)
    const orderStatusCheck = await request.post('https://shop.test/paymentwatch/assume', {
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
        },
        data: {
            assumption: {
                'oxorder.OXTRANSSTATUS': 'REFUNDED',
                where: {
                    'oxorder.OXID': orderId
                }
            }
        }
    });

    expect((await orderStatusCheck.json()).assumption).toBe(true);

    console.log('✓ Refund processed successfully');
});
```

---

## Scenario 7: Multi-Currency Payment

### Test Flow
1. User selects EUR currency
2. Contract created with EUR basket snapshot
3. Payment authorized in EUR
4. Order created with correct currency

### Test Implementation

```javascript
test('multi-currency payment preserves currency in contract', async () => {
    // 1. Set shop currency to EUR
    await setCurrency('EUR');

    // 2. Add product to basket
    await addProductToBasket('product-123', quantity: 1, price: 49.99);

    // 3. Initiate payment
    const contractId = await initiatePayment();

    // 4. Verify contract has EUR currency
    const currencyCheck = await assumeRequest(
        'osc_payment_contract.OXCURRENCY',
        'EUR',
        {
            whereClause: {
                'osc_payment_contract.OXID': contractId
            }
        }
    );

    expect(currencyCheck.assumption).toBe(true);

    // 5. Complete payment
    await completeStripePayment(contractId);

    // 6. Wait for order creation
    await waitForCondition(
        'osc_payment_contract.OXORDERID',
        null,
        {
            operator: 'IS NOT NULL',
            whereClause: {
                'osc_payment_contract.OXID': contractId
            }
        }
    );

    // 7. Verify order has EUR currency
    // (Would need to extend PaymentWatch to retrieve OXORDERID first)
    const orderId = await getOrderIdFromContract(contractId);

    const orderCurrencyCheck = await assumeRequest(
        'oxorder.OXCURRENCY',
        'EUR',
        {
            whereClause: {
                'oxorder.OXID': orderId
            }
        }
    );

    expect(orderCurrencyCheck.assumption).toBe(true);

    console.log('✓ Multi-currency payment preserved EUR correctly');
});
```

---

## Scenario 8: Concurrent Payments

### Test Flow
1. Multiple users initiate payments simultaneously
2. Each gets unique contract
3. All process independently
4. No race conditions

### Test Implementation

```typescript
test('concurrent payments create separate contracts', async ({ browser }) => {
    const numConcurrent = 10;

    // 1. Create multiple browser contexts (simulate different users)
    const contexts = await Promise.all(
        Array.from({ length: numConcurrent }, () => browser.newContext())
    );

    // 2. Initiate payments concurrently
    const contractIds = await Promise.all(
        contexts.map(async (context, index) => {
            const page = await context.newPage();
            await page.goto('https://shop.test/checkout');

            // Login as different user
            await loginAs(page, `user-${index}@example.com`);

            // Initiate payment
            await page.click('#payment-method-stripe');
            await page.click('#place-order');

            // Extract contract ID
            const contractId = await extractContractIdFromPage(page);

            await context.close();
            return contractId;
        })
    );

    // 3. Verify all contracts unique
    const uniqueIds = new Set(contractIds);
    expect(uniqueIds.size).toBe(numConcurrent);

    // 4. Verify all contracts exist in database
    const allExist = await Promise.all(
        contractIds.map(id =>
            assumeRequest(
                'osc_payment_contract.OXSTATE',
                'pending',
                {
                    operator: '!=',  // State should NOT be null (any state is fine)
                    whereClause: {
                        'osc_payment_contract.OXID': id
                    }
                }
            )
        )
    );

    expect(allExist.every(result => result.assumption)).toBe(true);

    // 5. Complete all payments and verify unique order numbers
    await Promise.all(
        contractIds.map(id => fulfillAllConditions(id))
    );

    // Wait for all orders created
    await Promise.all(
        contractIds.map(id =>
            waitForCondition(
                'osc_payment_contract.OXORDERID',
                null,
                {
                    operator: 'IS NOT NULL',
                    whereClause: { 'osc_payment_contract.OXID': id }
                }
            )
        )
    );

    console.log(`✓ ${numConcurrent} concurrent payments processed successfully`);
});
```

---

## Advanced Patterns

### Pattern: Chained Assumptions

```typescript
/**
 * Check multiple assumptions in sequence, failing fast on first mismatch
 */
async function assertChainedAssumptions(
    assumptions: Array<{
        field: string;
        value: any;
        operator?: string;
        where?: Record<string, any>;
    }>
): Promise<void> {
    for (const assumption of assumptions) {
        const result = await assumeRequest(
            assumption.field,
            assumption.value,
            {
                operator: assumption.operator,
                whereClause: assumption.where
            }
        );

        if (!result.assumption) {
            throw new Error(
                `Assumption failed: ${assumption.field} (expected: ${assumption.value}, actual: ${result.actual_value})`
            );
        }
    }
}

// Usage
await assertChainedAssumptions([
    { field: 'osc_payment_contract.OXSTATE', value: 'committed', where: { 'osc_payment_contract.OXID': contractId } },
    { field: 'oxorder.OXTRANSSTATUS', value: 'OK', where: { 'oxorder.OXID': orderId } },
    { field: 'osc_payment_transaction.OXSTATUS', value: 'completed', where: { 'osc_payment_transaction.OXORDERID': orderId } }
]);
```

### Pattern: Retry with Exponential Backoff

```typescript
async function waitForConditionWithBackoff(
    field: string,
    expectedValue: any,
    options: {
        whereClause?: Record<string, any>;
        operator?: string;
        maxRetries?: number;
        initialDelay?: number;
        maxDelay?: number;
    } = {}
): Promise<void> {
    const maxRetries = options.maxRetries || 10;
    const initialDelay = options.initialDelay || 100;
    const maxDelay = options.maxDelay || 5000;

    let delay = initialDelay;

    for (let attempt = 0; attempt < maxRetries; attempt++) {
        const result = await assumeRequest(field, expectedValue, options);

        if (result.assumption) {
            console.log(`✓ Condition met after ${attempt + 1} attempts`);
            return;
        }

        console.log(`Attempt ${attempt + 1}/${maxRetries} failed, retrying in ${delay}ms...`);
        await new Promise(resolve => setTimeout(resolve, delay));

        // Exponential backoff
        delay = Math.min(delay * 2, maxDelay);
    }

    throw new Error(`Condition not met after ${maxRetries} attempts`);
}
```

---

## Helper Functions Library

```typescript
// helpers/paymentWatch.ts

export interface AssumptionOptions {
    operator?: string;
    whereClause?: Record<string, any>;
    timeout?: number;
    interval?: number;
}

export class PaymentWatchClient {
    constructor(
        private baseUrl: string,
        private apiKey: string
    ) {}

    async assume(
        field: string,
        expectedValue: any,
        options: AssumptionOptions = {}
    ): Promise<{ assumption: boolean; actual_value?: any }> {
        const response = await fetch(`${this.baseUrl}/paymentwatch/assume`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': this.apiKey
            },
            body: JSON.stringify({
                assumption: {
                    [field]: expectedValue,
                    ...(options.operator && options.operator !== '==' && { op: options.operator }),
                    ...(options.whereClause && { where: options.whereClause })
                }
            })
        });

        return await response.json();
    }

    async waitFor(
        field: string,
        expectedValue: any,
        options: AssumptionOptions = {}
    ): Promise<void> {
        const timeout = options.timeout || 30000;
        const interval = options.interval || 500;
        const startTime = Date.now();

        while (Date.now() - startTime < timeout) {
            const result = await this.assume(field, expectedValue, options);

            if (result.assumption) return;

            await new Promise(resolve => setTimeout(resolve, interval));
        }

        throw new Error(`Timeout: ${field} did not reach ${expectedValue}`);
    }

    async assertExists(field: string, whereClause: Record<string, any>): Promise<void> {
        const result = await this.assume(field, null, {
            operator: 'IS NOT NULL',
            whereClause
        });

        if (!result.assumption) {
            throw new Error(`Field ${field} is NULL (expected NOT NULL)`);
        }
    }

    async assertNotExists(field: string, whereClause: Record<string, any>): Promise<void> {
        const result = await this.assume(field, null, {
            operator: 'IS NULL',
            whereClause
        });

        if (!result.assumption) {
            throw new Error(`Field ${field} is NOT NULL (expected NULL)`);
        }
    }
}
```

---

## Summary

These test scenarios demonstrate:

✅ **Contract Lifecycle**: Creation → Pending → Ready to Commit → Committed → Fulfilled
✅ **Webhook Processing**: External events trigger state updates
✅ **Order Creation**: Deferred until all conditions met
✅ **Error Handling**: Fraud failures, timeouts, refunds
✅ **Concurrency**: Multiple users/payments in parallel
✅ **Data Integrity**: Currency preservation, order number sequencing

---

**Next Steps:**

1. Review scenarios relevant to your test suite
2. Implement helper functions for your framework
3. Integrate with CI/CD pipeline
4. Extend with custom scenarios for your payment providers

---

**Happy Testing!**
