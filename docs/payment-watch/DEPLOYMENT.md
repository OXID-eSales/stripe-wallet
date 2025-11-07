# PaymentWatch Production Deployment Guide

**Version:** 1.0.0
**Last Updated:** 2025-01-12

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Security Setup](#security-setup)
5. [Database Optimization](#database-optimization)
6. [Testing Deployment](#testing-deployment)
7. [Monitoring](#monitoring)
8. [Troubleshooting](#troubleshooting)
9. [Rollback Procedure](#rollback-procedure)

---

## Prerequisites

### System Requirements

- **OXID eShop:** 7.0+ (Community or Professional Edition)
- **PHP:** 8.2 or higher
- **MySQL:** 8.0+ or MariaDB 10.6+
- **Composer:** 2.0+
- **Redis:** (Optional, for caching)

### Network Requirements

- Firewall access for CI/testing servers
- HTTPS/TLS certificate (SSL)
- Reverse proxy configuration (Nginx/Apache)

---

## Installation

### Step 1: Install Module via Composer

```bash
cd /path/to/oxid-shop
composer require oxid-solution-catalysts/stripe-wallet
```

The PaymentWatch module is included in the Stripe payment module.

### Step 2: Run Database Migrations

```bash
# Apply migrations
vendor/bin/oe-console migrations:migrate

# Verify migrations applied
vendor/bin/oe-console migrations:status
```

### Step 3: Activate Module

```bash
# Activate the Stripe module (includes PaymentWatch)
vendor/bin/oe-console oe:module:activate osc_stripe_wallet

# Verify activation
vendor/bin/oe-console oe:module:list | grep stripe
```

Output should show:
```
osc_stripe_wallet | active | Stripe Payment Gateway
```

---

## Configuration

### Step 1: Generate API Keys

Generate a secure 64-character hexadecimal API key:

```bash
# Generate API key
openssl rand -hex 32

# Example output:
# a1b2c3d4e5f6789012345678901234567890123456789012345678901234
```

**Important:** Store this key securely! You'll need it for CI/testing servers.

### Step 2: Configure Module Settings

Navigate to OXID Admin Panel:

1. **Extensions → Modules → Stripe Payment Gateway → Settings**
2. **PaymentWatch Tab**

Configure the following settings:

#### Enable PaymentWatch

```
✓ PaymentWatch Enabled: Yes
```

#### Configure Allowed Hosts

Add your CI/testing servers with their IP addresses and API keys:

```json
[
  {
    "ip": "192.168.1.100",
    "api_key": "a1b2c3d4e5f6789012345678901234567890123456789012345678901234",
    "description": "CI Server - Jenkins"
  },
  {
    "ip": "10.0.0.0/24",
    "api_key": "b2c3d4e5f67890123456789012345678901234567890123456789012345678",
    "description": "Testing Network (CIDR)"
  }
]
```

**IP Address Formats:**
- Exact IP: `192.168.1.100`
- CIDR range: `192.168.1.0/24`
- IPv6: `2001:db8::1`

#### Optional: Rate Limiting

```
Rate Limiting Enabled: Yes (Recommended for production)
Rate Limit Per Minute: 100 (Adjust based on your needs)
```

### Step 3: Configure Symfony Services (Optional)

If using custom service configuration, edit:

`/path/to/oxid/var/configuration/configurable_services.yaml`

```yaml
# PaymentWatch configuration
services:
  paymentwatch.auth_config:
    arguments:
      $allowedHosts: '%env(json:PAYMENTWATCH_ALLOWED_HOSTS)%'
```

---

## Security Setup

### Step 1: Configure Firewall

Restrict access to PaymentWatch endpoint to only trusted IPs.

#### Nginx Configuration

```nginx
# /etc/nginx/sites-available/shop.example.com.conf

location /paymentwatch {
    # Allow only CI/testing servers
    allow 192.168.1.100;
    allow 10.0.0.0/24;
    deny all;

    # Forward to PHP-FPM
    try_files $uri /index.php$is_args$args;
}
```

Reload Nginx:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

#### Apache Configuration

```apache
# /etc/apache2/sites-available/shop.example.com.conf

<Location /paymentwatch>
    # Allow only CI/testing servers
    Require ip 192.168.1.100
    Require ip 10.0.0.0/24
</Location>
```

Reload Apache:
```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Step 2: Enable HTTPS

**PaymentWatch MUST use HTTPS in production!**

Ensure your SSL/TLS certificate is valid and properly configured.

```bash
# Test HTTPS
curl -I https://shop.example.com/paymentwatch/assume
```

Should return: `HTTP/2 401` (Unauthorized without API key)

### Step 3: Configure Rate Limiting (Nginx)

```nginx
# /etc/nginx/nginx.conf

http {
    # Define rate limit zone
    limit_req_zone $binary_remote_addr zone=paymentwatch:10m rate=10r/s;

    server {
        location /paymentwatch {
            # Apply rate limit
            limit_req zone=paymentwatch burst=20 nodelay;

            # ... other config
        }
    }
}
```

---

## Database Optimization

### Step 1: Verify Indexes

Check that performance indexes were created:

```sql
-- Check contract indexes
SHOW INDEX FROM osc_payment_contract WHERE Key_name LIKE 'idx_pw%';

-- Check transaction indexes
SHOW INDEX FROM osc_payment_transaction WHERE Key_name LIKE 'idx_pw%';
```

Expected indexes:
- `idx_pw_contract_state`
- `idx_pw_contract_provider_order`
- `idx_pw_contract_order`
- `idx_pw_contract_user`
- `idx_pw_contract_id_state`
- `idx_pw_transaction_status`
- `idx_pw_transaction_contract`
- `idx_pw_transaction_provider_order`
- `idx_pw_transaction_type`
- `idx_pw_transaction_contract_status`

### Step 2: Analyze Query Performance

```sql
-- Verify query uses indexes
EXPLAIN SELECT OXSTATE
FROM osc_payment_contract
WHERE OXID = 'test123';
```

Should show:
```
type: ref
possible_keys: PRIMARY, idx_pw_contract_id_state
key: PRIMARY
```

### Step 3: Optimize Database (Optional)

```sql
-- Analyze tables
ANALYZE TABLE osc_payment_contract;
ANALYZE TABLE osc_payment_transaction;

-- Optimize tables (run during maintenance window)
OPTIMIZE TABLE osc_payment_contract;
OPTIMIZE TABLE osc_payment_transaction;
```

---

## Testing Deployment

### Step 1: Verify Endpoint Accessibility

```bash
# Test without API key (should return 401)
curl -X POST https://shop.example.com/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -d '{"assumption": {"osc_payment_contract.OXSTATE": "pending"}}'

# Expected: HTTP 401 Unauthorized
```

### Step 2: Test with Valid API Key

```bash
curl -X POST https://shop.example.com/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "assumption": {
      "osc_payment_contract.OXSTATE": "pending",
      "where": {
        "OXID": "test-contract-123"
      }
    }
  }'

# Expected: HTTP 200 with JSON response
# {
#   "assumption": false,
#   "query_time_ms": 12.5,
#   "matched_rows": 0
# }
```

### Step 3: Run Integration Tests

```bash
# Set environment variables
export PAYMENTWATCH_URL=https://shop.example.com
export PAYMENTWATCH_API_KEY=your_api_key_here

# Run integration tests
cd /path/to/stripe-module
docker compose exec php vendor/bin/phpunit \
  -c tests/phpunit.xml \
  --group integration \
  --group watch

# Expected: All tests passing
```

---

## Monitoring

### Key Metrics to Monitor

1. **Response Time**
   - Target: < 50ms average
   - Alert if: > 100ms for 5 minutes

2. **Error Rate**
   - Target: < 0.1%
   - Alert if: > 1% for 5 minutes

3. **Request Volume**
   - Normal: 10-100 req/min (varies by test frequency)
   - Alert if: > 1000 req/min (possible DoS)

4. **Authentication Failures**
   - Target: < 5 failures/hour
   - Alert if: > 50 failures/hour (possible attack)

### Logging

PaymentWatch logs to the standard OXID log:

```bash
# View recent logs
tail -f /path/to/oxid/log/oxideshop.log | grep PaymentWatch

# Example log entry:
# [2025-01-12 10:30:45] INFO: PaymentWatch request: osc_payment_contract.OXSTATE == [match=true, time=12.50ms]
```

### Grafana Dashboard (Optional)

Create a Grafana dashboard to visualize:
- Request rate
- Response times (P50, P95, P99)
- Error rates
- Top queried tables/fields

See: [GRAFANA-INTEGRATION.md](GRAFANA-INTEGRATION.md)

---

## Troubleshooting

### Issue: 401 Unauthorized

**Cause:** Invalid API key or IP not whitelisted

**Solution:**
1. Verify API key is 64-character hex:
   ```bash
   echo "$PAYMENTWATCH_API_KEY" | wc -c
   # Should output: 65 (64 + newline)
   ```

2. Check IP whitelist in module settings

3. Verify firewall allows traffic:
   ```bash
   # Check your public IP
   curl https://api.ipify.org

   # Ensure it matches configured IP
   ```

### Issue: 400 Bad Request

**Cause:** Malformed JSON or invalid field names

**Solution:**
1. Validate JSON syntax:
   ```bash
   echo '{"assumption": {...}}' | jq .
   ```

2. Verify field path format: `table.field`

3. Check for SQL injection attempts (special characters)

### Issue: Slow Response Times

**Cause:** Missing indexes or unoptimized queries

**Solution:**
1. Verify indexes exist (see Database Optimization)

2. Run EXPLAIN on slow queries:
   ```sql
   EXPLAIN SELECT OXSTATE FROM osc_payment_contract WHERE OXID = '...';
   ```

3. Check database server load

### Issue: Connection Refused

**Cause:** Firewall blocking traffic

**Solution:**
1. Verify Nginx/Apache is running:
   ```bash
   systemctl status nginx
   ```

2. Check firewall rules:
   ```bash
   sudo iptables -L -n | grep 80
   sudo iptables -L -n | grep 443
   ```

3. Test local connectivity:
   ```bash
   curl -I http://localhost/paymentwatch/assume
   ```

---

## Rollback Procedure

If issues occur after deployment:

### Step 1: Deactivate Module

```bash
vendor/bin/oe-console oe:module:deactivate osc_stripe_wallet
```

### Step 2: Rollback Database Migrations (if needed)

```bash
# Check current migration version
vendor/bin/oe-console migrations:status

# Rollback one migration
vendor/bin/oe-console migrations:migrate prev

# Rollback to specific version
vendor/bin/oe-console migrations:migrate Version20250111_PreviousVersion
```

### Step 3: Clear Cache

```bash
vendor/bin/oe-console oe:cache:clear
```

### Step 4: Verify Shop Functionality

```bash
# Test shop frontend
curl -I https://shop.example.com

# Test admin panel
curl -I https://shop.example.com/admin
```

---

## Support

- **Documentation:** `/docs/payment-watch/`
- **Issues:** https://github.com/OXID-eSales/stripe-wallet/issues
- **Email:** support@oxid-esales.com

---

## Appendix: Environment Variables

For containerized deployments:

```bash
# .env file
PAYMENTWATCH_ENABLED=true
PAYMENTWATCH_ALLOWED_HOSTS='[{"ip":"192.168.1.100","api_key":"...","description":"CI Server"}]'
PAYMENTWATCH_RATE_LIMIT_ENABLED=true
PAYMENTWATCH_RATE_LIMIT_PER_MINUTE=100
```

Load in Docker Compose:

```yaml
# docker-compose.yml
services:
  php:
    env_file: .env
    environment:
      - PAYMENTWATCH_ENABLED=${PAYMENTWATCH_ENABLED}
      - PAYMENTWATCH_ALLOWED_HOSTS=${PAYMENTWATCH_ALLOWED_HOSTS}
```

---

**Deployment Checklist:**

- [ ] Module installed via Composer
- [ ] Database migrations applied
- [ ] Module activated
- [ ] API keys generated and configured
- [ ] Allowed hosts configured
- [ ] Firewall rules configured
- [ ] HTTPS enabled and verified
- [ ] Indexes created and verified
- [ ] Integration tests passing
- [ ] Monitoring configured
- [ ] Rollback procedure documented
- [ ] Team trained on troubleshooting

**Ready for Production!** ✅
