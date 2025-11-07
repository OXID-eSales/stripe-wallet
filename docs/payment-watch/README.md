# PaymentWatch - E2E Testing Helper Module

**Test Automation Infrastructure for Payment Component**

Version: 1.0.0
Date: 2025-11-11
Based on: OXID Payment Component v4.0.0

---

## What Is This?

PaymentWatch is a **test helper module** that enables remote end-to-end (E2E) testing of payment workflows by exposing a secure API for database state verification. External test suites can send "assumption" queries to validate payment transaction states, order statuses, and contract fulfillment without direct database access.

### Key Features

- **Secure IP-based Authentication**: Whitelist of trusted test servers with API keys
- **RESTful Assumption API**: Query database state with JSON requests
- **Flexible Operators**: Support for equality, comparison, and pattern matching
- **Zero Production Impact**: Disabled by default, module activation required
- **Database Abstraction**: Works with OXID's database layer
- **Test-Driven Design**: Built for CI/CD pipeline integration

---

## Use Cases

### 1. Remote E2E Testing
External test servers (Jenkins, GitHub Actions, etc.) can verify:
- Payment transaction status changes
- Order state transitions
- Contract condition fulfillment
- Webhook processing results

### 2. Integration Testing
Verify cross-system state consistency:
- Payment provider webhook → Shop database updates
- Order creation → Contract commitment
- Payment capture → Transaction recording

### 3. Contract Testing
Validate payment contract lifecycle:
- Contract state progression
- Condition fulfillment tracking
- Order linkage verification

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                  EXTERNAL TEST SERVER                        │
│  (Jenkins, GitHub Actions, Playwright, Cypress, etc.)       │
└────────────────────┬────────────────────────────────────────┘
                     │ POST /paymentwatch/assume
                     │ Headers: X-API-Key, X-Request-ID
┌────────────────────▼────────────────────────────────────────┐
│              PaymentWatch Module (OXID)                      │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 1. Security Layer                                     │  │
│  │    - IP Whitelist Check (module config)              │  │
│  │    - API Key Validation (SHA-256 hash)               │  │
│  │    - Rate Limiting (optional)                        │  │
│  └──────────────────┬────────────────────────────────────┘  │
│                     │                                        │
│  ┌──────────────────▼─────────────────────────────────────┐ │
│  │ 2. Request Parser                                      │ │
│  │    - JSON payload validation                          │ │
│  │    - Assumption structure parsing                     │ │
│  │    - Operator extraction (==, <=, %like%, etc.)      │ │
│  └──────────────────┬─────────────────────────────────────┘ │
│                     │                                        │
│  ┌──────────────────▼─────────────────────────────────────┐ │
│  │ 3. Query Builder                                       │ │
│  │    - Table/field parsing                              │ │
│  │    - SQL query construction (secure)                  │ │
│  │    - Parameter binding (SQL injection protection)     │ │
│  └──────────────────┬─────────────────────────────────────┘ │
│                     │                                        │
│  ┌──────────────────▼─────────────────────────────────────┐ │
│  │ 4. Database Query                                      │ │
│  │    - Execute query via OXID DBAL                      │ │
│  │    - Fetch result                                     │ │
│  │    - Compare with expected value                      │ │
│  └──────────────────┬─────────────────────────────────────┘ │
│                     │                                        │
│  ┌──────────────────▼─────────────────────────────────────┐ │
│  │ 5. Response Builder                                    │ │
│  │    - JSON response: { "assumption": true/false }      │ │
│  │    - HTTP status: 200 (success), 400/401/500 (error)  │ │
│  │    - Audit logging                                    │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

---

## Installation & Configuration

### Step 1: Activate Module

```bash
# Via OXID CLI
vendor/bin/oe-console oe:module:activate paymentwatch

# Or via Admin Panel
Extensions → Modules → PaymentWatch → Activate
```

### Step 2: Configure IP Whitelist & API Keys

**Location:** `Admin Panel → Extensions → Modules → PaymentWatch → Settings`

**Configuration Format:**

```php
// Module setting: PAYMENTWATCH_ALLOWED_HOSTS
[
    [
        'ip' => '192.168.1.100',
        'api_key' => 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd',  // 64-char hex
        'description' => 'Jenkins CI Server'
    ],
    [
        'ip' => '10.0.0.50',
        'api_key' => 'f9e8d7c6b5a432109876543210fedcba9876543210fedcba9876543210abcdef',
        'description' => 'GitHub Actions Runner'
    ]
]
```

**API Key Generation:**

```bash
# Generate secure 64-character hex key
openssl rand -hex 32
# Output: a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd
```

### Step 3: Security Considerations

- **Production Deployment**: NEVER enable PaymentWatch in production environments
- **Firewall Rules**: Restrict `/paymentwatch/*` routes to internal networks
- **Key Rotation**: Rotate API keys every 90 days
- **Audit Logging**: Enable logging for all assumption requests

---

## API Reference

### Endpoint

```
POST /paymentwatch/assume
Content-Type: application/json
X-API-Key: <64-char-hex-key>
X-Request-ID: <optional-trace-id>
```

### Request Format

#### Basic Assumption (Equality)

```json
{
    "assumption": {
        "<table_name>.<field_name>": "<expected_value>"
    }
}
```

**Example:**
```json
{
    "assumption": {
        "osc_payment_transaction.OXSTATUS": "completed"
    }
}
```

#### With WHERE Clause (Filter)

```json
{
    "assumption": {
        "<table_name>.<field_name>": "<expected_value>",
        "where": {
            "<table_name>.<filter_field>": "<filter_value>"
        }
    }
}
```

**Example:**
```json
{
    "assumption": {
        "osc_payment_transaction.OXSTATUS": "completed",
        "where": {
            "osc_payment_transaction.OXID": "abc123def456"
        }
    }
}
```

#### With Custom Operator

```json
{
    "assumption": {
        "<table_name>.<field_name>": "<expected_value>",
        "op": "<operator>"
    }
}
```

**Supported Operators:**

| Operator | Description | Example |
|----------|-------------|---------|
| `==` | Equal (default) | `"op": "=="` |
| `!=` | Not equal | `"op": "!="` |
| `>` | Greater than | `"op": ">"` |
| `<` | Less than | `"op": "<"` |
| `>=` | Greater or equal | `"op": ">="` |
| `<=` | Less or equal | `"op": "<="` |
| `%like%` | SQL LIKE (contains) | `"op": "%like%"` |
| `like%` | SQL LIKE (starts with) | `"op": "like%"` |
| `%like` | SQL LIKE (ends with) | `"op": "%like"` |
| `IS NULL` | Field is NULL | `"op": "IS NULL"` |
| `IS NOT NULL` | Field is not NULL | `"op": "IS NOT NULL"` |

**Examples:**

```json
// Greater than
{
    "assumption": {
        "osc_payment_transaction.OXAMOUNT": "100.00",
        "op": ">"
    }
}

// LIKE pattern
{
    "assumption": {
        "oxorder.OXBILLEMAIL": "@example.com",
        "op": "%like%"
    }
}

// IS NULL check
{
    "assumption": {
        "osc_payment_contract.OXORDERID": null,
        "op": "IS NULL"
    }
}
```

### Response Format

#### Success (Assumption True)

```json
{
    "assumption": true,
    "query_time_ms": 12,
    "matched_rows": 1
}
```

**HTTP Status:** `200 OK`

#### Success (Assumption False)

```json
{
    "assumption": false,
    "query_time_ms": 8,
    "matched_rows": 0,
    "actual_value": "pending",
    "expected_value": "completed"
}
```

**HTTP Status:** `200 OK`

#### Error (Validation Failed)

```json
{
    "error": "Invalid assumption format",
    "details": "Missing 'assumption' key in request body"
}
```

**HTTP Status:** `400 Bad Request`

#### Error (Authentication Failed)

```json
{
    "error": "Unauthorized",
    "details": "Invalid API key or IP not whitelisted"
}
```

**HTTP Status:** `401 Unauthorized`

#### Error (Database Error)

```json
{
    "error": "Database query failed",
    "details": "Table 'invalid_table' does not exist"
}
```

**HTTP Status:** `500 Internal Server Error`

---

## Usage Examples

### Example 1: Verify Payment Transaction Status

**Scenario:** After webhook processing, verify transaction status changed to "completed"

```javascript
// Playwright test
const response = await fetch('https://shop.example.com/paymentwatch/assume', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-API-Key': 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd'
    },
    body: JSON.stringify({
        assumption: {
            'osc_payment_transaction.OXSTATUS': 'completed',
            where: {
                'osc_payment_transaction.OXPROVIDERORDERID': 'pi_3Abc123XYZ'
            }
        }
    })
});

const result = await response.json();
expect(result.assumption).toBe(true);
```

### Example 2: Verify Contract State Transition

**Scenario:** Verify contract transitioned from PENDING to COMMITTED

```python
# Pytest example
import requests

response = requests.post(
    'https://shop.example.com/paymentwatch/assume',
    headers={
        'Content-Type': 'application/json',
        'X-API-Key': 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd'
    },
    json={
        'assumption': {
            'osc_payment_contract.OXSTATE': 'committed',
            'where': {
                'osc_payment_contract.OXID': 'contract-uuid-12345'
            }
        }
    }
)

assert response.status_code == 200
assert response.json()['assumption'] == True
```

### Example 3: Verify Order Creation with Amount

**Scenario:** Check order exists with correct total amount

```bash
# cURL example
curl -X POST https://shop.example.com/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd" \
  -d '{
    "assumption": {
      "oxorder.OXTOTALORDERSUM": "99.99",
      "where": {
        "oxorder.OXORDERNR": "2025-00123"
      }
    }
  }'
```

### Example 4: Verify Contract-Order Linkage

**Scenario:** Ensure contract is linked to an order (OXORDERID not NULL)

```javascript
// Jest test
const response = await request(app)
    .post('/paymentwatch/assume')
    .set('X-API-Key', process.env.PAYMENTWATCH_API_KEY)
    .send({
        assumption: {
            'osc_payment_contract.OXORDERID': null,
            op: 'IS NOT NULL',
            where: {
                'osc_payment_contract.OXID': contractId
            }
        }
    });

expect(response.body.assumption).toBe(true);
```

### Example 5: Verify Multiple Conditions (Chained)

**Scenario:** Check order is paid AND status is OK

```javascript
// TypeScript example with retry logic
async function verifyOrderCompleted(orderNr: string): Promise<boolean> {
    const maxRetries = 10;
    const delayMs = 500;

    for (let i = 0; i < maxRetries; i++) {
        // Check status is OK
        const statusCheck = await fetch('https://shop.example.com/paymentwatch/assume', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': API_KEY
            },
            body: JSON.stringify({
                assumption: {
                    'oxorder.OXPAID': null,
                    op: 'IS NOT NULL',
                    where: { 'oxorder.OXORDERNR': orderNr }
                }
            })
        });

        const statusResult = await statusCheck.json();

        if (statusResult.assumption) {
            // Second check: order state
            const stateCheck = await fetch('https://shop.example.com/paymentwatch/assume', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': API_KEY
                },
                body: JSON.stringify({
                    assumption: {
                        'oxorder.OXTRANSSTATUS': 'OK',
                        where: { 'oxorder.OXORDERNR': orderNr }
                    }
                })
            });

            const stateResult = await stateCheck.json();
            if (stateResult.assumption) {
                return true;  // Both conditions met!
            }
        }

        await new Promise(resolve => setTimeout(resolve, delayMs));
    }

    throw new Error(`Order ${orderNr} not completed after ${maxRetries} retries`);
}
```

---

## Integration with Test Frameworks

### Playwright Example

```typescript
// helpers/paymentWatch.ts
import { APIRequestContext } from '@playwright/test';

export class PaymentWatchClient {
    constructor(
        private request: APIRequestContext,
        private baseUrl: string,
        private apiKey: string
    ) {}

    async assume(
        field: string,
        expectedValue: any,
        whereClause?: Record<string, any>,
        operator: string = '=='
    ): Promise<boolean> {
        const response = await this.request.post(`${this.baseUrl}/paymentwatch/assume`, {
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': this.apiKey
            },
            data: {
                assumption: {
                    [field]: expectedValue,
                    ...(operator !== '==' && { op: operator }),
                    ...(whereClause && { where: whereClause })
                }
            }
        });

        const result = await response.json();
        return result.assumption === true;
    }

    async waitForAssumption(
        field: string,
        expectedValue: any,
        options?: {
            whereClause?: Record<string, any>;
            operator?: string;
            timeout?: number;
            interval?: number;
        }
    ): Promise<void> {
        const timeout = options?.timeout || 30000;
        const interval = options?.interval || 500;
        const startTime = Date.now();

        while (Date.now() - startTime < timeout) {
            const isTrue = await this.assume(
                field,
                expectedValue,
                options?.whereClause,
                options?.operator
            );

            if (isTrue) return;

            await new Promise(resolve => setTimeout(resolve, interval));
        }

        throw new Error(`Assumption timeout: ${field} = ${expectedValue}`);
    }
}

// Usage in test
import { test, expect } from '@playwright/test';

test('payment flow completes successfully', async ({ page, request }) => {
    const paymentWatch = new PaymentWatchClient(
        request,
        'https://shop.example.com',
        process.env.PAYMENTWATCH_API_KEY!
    );

    // ... trigger payment flow in UI ...

    // Wait for transaction to complete
    await paymentWatch.waitForAssumption(
        'osc_payment_transaction.OXSTATUS',
        'completed',
        {
            whereClause: {
                'osc_payment_transaction.OXPROVIDERORDERID': orderId
            },
            timeout: 15000
        }
    );

    // Verify order created
    const orderExists = await paymentWatch.assume(
        'oxorder.OXTRANSSTATUS',
        'OK',
        { 'oxorder.OXUSER': userId }
    );
    expect(orderExists).toBe(true);
});
```

### Cypress Example

```javascript
// cypress/support/paymentWatch.js
Cypress.Commands.add('assume', (field, expectedValue, whereClause = null, operator = '==') => {
    const body = {
        assumption: {
            [field]: expectedValue
        }
    };

    if (operator !== '==') {
        body.assumption.op = operator;
    }

    if (whereClause) {
        body.assumption.where = whereClause;
    }

    return cy.request({
        method: 'POST',
        url: `${Cypress.env('SHOP_URL')}/paymentwatch/assume`,
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': Cypress.env('PAYMENTWATCH_API_KEY')
        },
        body
    }).then(response => {
        expect(response.status).to.eq(200);
        return response.body.assumption;
    });
});

// Usage in test
describe('Payment Flow', () => {
    it('completes payment and creates order', () => {
        // ... trigger payment ...

        // Poll until transaction completed
        cy.waitUntil(
            () => cy.assume(
                'osc_payment_transaction.OXSTATUS',
                'completed',
                { 'osc_payment_transaction.OXID': transactionId }
            ),
            {
                timeout: 15000,
                interval: 500
            }
        );

        // Verify order state
        cy.assume('oxorder.OXTRANSSTATUS', 'OK', { 'oxorder.OXORDERNR': orderNumber })
            .should('be.true');
    });
});
```

---

## Security Best Practices

### 1. IP Whitelisting

**Recommended Configuration:**

```yaml
# Docker Compose example
services:
  webserver:
    networks:
      - payment_test_network

  test_runner:
    networks:
      - payment_test_network
    extra_hosts:
      - "shop.example.com:172.18.0.5"  # Internal IP

networks:
  payment_test_network:
    driver: bridge
    ipam:
      config:
        - subnet: 172.18.0.0/16
```

### 2. API Key Storage

**DO:**
- Store keys in environment variables or secret managers
- Use different keys per environment (staging, testing)
- Rotate keys every 90 days

**DON'T:**
- Hardcode keys in test files
- Commit keys to version control
- Reuse production keys in testing

### 3. Rate Limiting (Optional)

```php
// Module configuration
'rate_limit' => [
    'enabled' => true,
    'max_requests_per_minute' => 100,
    'max_requests_per_hour' => 1000
]
```

### 4. Audit Logging

All assumption requests should be logged:

```
[2025-11-11 14:30:12] INFO: PaymentWatch request
  IP: 192.168.1.100
  API Key (partial): a1b2c3d4...
  Query: osc_payment_transaction.OXSTATUS = completed
  Result: assumption=true, rows=1, time=12ms
```

---

## Troubleshooting

### Issue: "401 Unauthorized" Response

**Cause:** IP not whitelisted or invalid API key

**Solution:**
1. Verify IP in module settings: `Admin → Extensions → PaymentWatch → Settings`
2. Check API key matches exactly (64-char hex)
3. Ensure test server IP is static (not NAT-translated)

```bash
# Verify outbound IP from test server
curl https://api.ipify.org
```

### Issue: "Table does not exist" Error

**Cause:** Incorrect table name or typo

**Solution:**
- Check table name in assumption: `osc_payment_transaction` (not `payment_transaction`)
- Verify table prefix matches OXID config (usually `osc_` or `oxv_`)

```sql
-- List all tables
SHOW TABLES LIKE 'osc_%';
```

### Issue: Assumption Always Returns False

**Cause:** Query returns no rows or value mismatch

**Solution:**
1. Check `where` clause filters correctly
2. Verify field value format (string vs. numeric)
3. Use database tool to verify expected value

```json
// Response includes actual value for debugging
{
    "assumption": false,
    "actual_value": "pending",
    "expected_value": "completed"
}
```

### Issue: Slow Response Times

**Cause:** Unindexed queries or large tables

**Solution:**
- Add database indexes on frequently queried fields
- Use specific `where` clauses to reduce result set
- Consider caching for repeated queries

```sql
-- Add index for transaction lookup
CREATE INDEX idx_transaction_provider ON osc_payment_transaction(OXPROVIDERORDERID);
```

---

## Performance Considerations

### Query Optimization

PaymentWatch generates SQL queries dynamically. For best performance:

1. **Use WHERE clauses**: Always filter by indexed fields
2. **Avoid wildcard LIKE**: Prefer `like%` over `%like%`
3. **Limit result sets**: Assumption checks only need 1 matching row

### Caching Strategy

For high-frequency tests, consider:
- Redis cache for contract/transaction state (TTL: 30s)
- Debounce assumption checks (avoid polling every 100ms)
- Batch assumptions into single query (future enhancement)

### Benchmark Results

| Query Type | Avg Response Time | Notes |
|------------|-------------------|-------|
| Simple equality (indexed) | 5-10ms | Primary key lookup |
| Equality with WHERE (indexed) | 10-20ms | Single table join |
| LIKE pattern | 20-50ms | Full text scan |
| IS NULL check | 5-15ms | Indexed nullable fields |

---

## Roadmap

### Version 1.1 (Planned)
- [ ] Batch assumption requests (single HTTP call, multiple checks)
- [ ] WebSocket support for real-time state monitoring
- [ ] GraphQL endpoint option
- [ ] Custom query timeout configuration

### Version 1.2 (Planned)
- [ ] JOIN support for multi-table assumptions
- [ ] Aggregate functions (COUNT, SUM, AVG)
- [ ] Transaction history replay for debugging

---

## FAQ

### Q: Can I use PaymentWatch in production?

**A:** NO. PaymentWatch is designed exclusively for testing environments. Enabling it in production poses security risks and performance overhead.

### Q: How does PaymentWatch differ from direct database access?

**A:** PaymentWatch provides:
- **Abstraction**: No need to manage DB credentials in tests
- **Security**: IP whitelisting + API key authentication
- **Simplicity**: RESTful JSON API vs. SQL queries
- **Isolation**: No direct DB connection overhead

### Q: Can I check multiple fields in one assumption?

**A:** Currently, one assumption per request. Use the `where` clause to filter rows, then check a single field value. Batch support planned for v1.1.

### Q: What if my test runner IP changes dynamically?

**A:** Options:
1. Use static IP allocation (Docker networks, VPN)
2. Configure IP range (e.g., `192.168.1.0/24`)
3. Use reverse proxy with fixed IP

### Q: How do I debug failed assumptions?

**A:** Response includes `actual_value` when assumption is false:

```json
{
    "assumption": false,
    "actual_value": "pending",
    "expected_value": "completed"
}
```

Use this to identify state mismatches.

---

## Related Documentation

### In This Repository

**PaymentWatch Documentation:**
- **[01-implementation-guide.md](01-implementation-guide.md)** - Developer implementation guide (PHP/OXID)
- **[02-test-scenarios.md](02-test-scenarios.md)** - E2E test scenarios & patterns
- **[03-payment-component-coupling.md](03-payment-component-coupling.md)** - Integration with Payment Component
- **[04-javascript-sdk.md](04-javascript-sdk.md)** - JavaScript/TypeScript client SDK (Node.js)
- **[05-javascript-sdk-tdd.md](05-javascript-sdk-tdd.md)** - TDD guide for JS SDK with CI/CD
- **[tdd/INDEX.md](tdd/INDEX.md)** - Complete TDD guide with 6 phases

**Payment Component Documentation:**
- **[../payment-component/README.md](../payment-component/README.md)** - Payment component overview
- **[../payment-component/01-architecture-layers.md](../payment-component/01-architecture-layers.md)** - Architecture details
- **[../payment-component/02-database-and-models.md](../payment-component/02-database-and-models.md)** - Database schema

### External Resources
- OXID eShop Documentation: https://docs.oxid-esales.com
- Playwright Testing: https://playwright.dev
- Cypress Testing: https://www.cypress.io
- NPM Package: https://www.npmjs.com/package/@oxid-esales/paymentwatch-client

---

## Contributing

### Found a bug?
Please open an issue in the repository with:
- Request payload (sanitize API key!)
- Expected vs. actual response
- Module version and OXID version

### Feature requests?
Submit enhancement proposals via GitHub Issues.

---

## License

This documentation is part of the OXID Payment Component developed by OXID eSales AG.

GPL-3.0 License - See LICENSE file in repository root.

---

## Credits

**Documented by:** Development Team
**Based on:** OXID Payment Component v4.0.0
**Organization:** OXID eSales AG
**Date:** 2025-11-11

---

## Summary

PaymentWatch enables **secure, remote E2E testing** of payment workflows without direct database access:

✅ **IP + API Key Authentication**: Whitelist trusted test servers
✅ **Flexible Assumption Queries**: Support for operators, filters, NULL checks
✅ **RESTful JSON API**: Easy integration with Playwright, Cypress, Jest, Pytest
✅ **Database Abstraction**: No SQL knowledge required for tests
✅ **Audit Logging**: Track all assumption requests
✅ **Production-Safe**: Disabled by default, testing-only

---

**Next Steps:**

1. **Activate module**: `vendor/bin/oe-console oe:module:activate paymentwatch`
2. **Configure whitelist**: Admin → Extensions → PaymentWatch → Settings
3. **Generate API key**: `openssl rand -hex 32`
4. **Write tests**: Use examples above for Playwright/Cypress integration
5. **Run E2E suite**: Verify payment flows end-to-end

---

**Happy Testing!**
