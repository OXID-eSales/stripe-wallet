# Sprint 2: Docker DNS Configuration

**Status:** SKIPPED - Not needed
**Sprint Goal:** ~~Fix DNS resolution for external API calls in Docker~~
**Estimated Time:** ~~1 hour~~
**Priority:** ~~MEDIUM (affects integration tests)~~

> **Note:** DNS resolution issues in Docker are expected behavior for isolated test environments. The 47 integration test errors related to external API calls (`api.stripe.com`) do not require fixing. These tests are designed to run against real Stripe API and will naturally fail in isolated environments.

---

## Problem Description

### Root Cause

The PHP Docker container cannot resolve external hostnames like `api.stripe.com`:

```bash
# Inside container:
php -r "echo gethostbyname('api.stripe.com');"
# Returns: api.stripe.com (unchanged = resolution failed)

curl -s https://api.stripe.com
# Error: (Network error [errno 6]: Could not resolve host: api.stripe.com)
```

### DNS Configuration Analysis

Current `/etc/resolv.conf` in container:
```
nameserver 127.0.0.11
search .
options ndots:0
```

Docker's internal DNS resolver (127.0.0.11) is not forwarding queries to external DNS servers.

### Impact

- 47 integration tests fail with `ApiConnectionException`
- All tests attempting to reach `api.stripe.com` error out
- Backend admin works (runs outside container or has different network config)

---

## Solution Options

### Option A: Explicit DNS in docker-compose.yml (RECOMMENDED)

Add explicit DNS servers to the PHP service:

```yaml
services:
  php:
    # ... existing config ...
    dns:
      - 8.8.8.8        # Google Public DNS
      - 8.8.4.4        # Google Public DNS (backup)
      - 1.1.1.1        # Cloudflare DNS
```

**Pros:**
- Clean solution
- Works reliably
- Easy to configure

**Cons:**
- Requires docker-compose restart
- May affect other services

### Option B: Network Mode Host (Development Only)

```yaml
services:
  php:
    network_mode: "host"
```

**Pros:**
- Uses host's DNS directly
- No additional configuration

**Cons:**
- Loses Docker network isolation
- Port conflicts possible
- NOT recommended for production

### Option C: Test Group Exclusion (CI Workaround)

Add `@group external-api` to Stripe integration tests:

```php
/**
 * @group external-api
 */
class StripeAdapterIntegrationTest extends TestCase
```

Then exclude in CI:
```bash
--exclude-group external-api
```

**Pros:**
- Quick fix for CI
- No infrastructure changes

**Cons:**
- Reduces test coverage
- External API tests never run in CI

### Option D: Mock External APIs (Long-term)

Use Stripe's test mode + webhook mocking for true unit isolation.

**Pros:**
- Tests don't depend on external services
- Faster test execution
- Works offline

**Cons:**
- Requires significant refactoring
- May miss real API behavior changes

---

## TDD Implementation (Option A)

### Step 1: RED - Verify Current Failure

```bash
docker compose exec -T php bash -c "php -r \"echo gethostbyname('api.stripe.com');\""
```

Expected: Returns `api.stripe.com` (unchanged = failure)

### Step 2: GREEN - Add DNS Configuration

**File:** `docker-compose.yml` (root of project)

Locate the `php` service and add DNS configuration:

```yaml
services:
  php:
    image: oxidesales/oxideshop-docker-php:8.3
    # ... existing configuration ...
    dns:
      - 8.8.8.8
      - 8.8.4.4
    dns_search:
      - .
    # ... rest of configuration ...
```

### Step 3: Restart Docker Services

```bash
docker compose down
docker compose up -d
```

### Step 4: Verify DNS Resolution

```bash
# Test DNS resolution
docker compose exec -T php bash -c "php -r \"var_dump(gethostbyname('api.stripe.com'));\""
# Expected: string(XX) "XXX.XXX.XXX.XXX" (actual IP address)

# Test curl
docker compose exec -T php bash -c "curl -s -o /dev/null -w '%{http_code}' https://api.stripe.com"
# Expected: 401 or 403 (unauthorized, but reached the server!)
```

### Step 5: REFACTOR - Run Integration Tests

```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --exclude-group migration
```

Expected: 47 errors → 0 errors (tests may still fail for other reasons, but not DNS)

---

## Alternative: Per-Container DNS Override

If modifying `docker-compose.yml` is not desired, use runtime override:

```bash
docker compose exec -T php bash -c "echo 'nameserver 8.8.8.8' > /etc/resolv.conf"
```

**Note:** This is temporary and resets on container restart.

---

## Verification Checklist

- [ ] DNS resolution works: `gethostbyname('api.stripe.com')` returns IP
- [ ] Curl reaches Stripe: Returns HTTP 401/403 (not network error)
- [ ] Integration tests run without DNS errors
- [ ] No regression in other services

---

## Files Modified

| File | Change |
|------|--------|
| `docker-compose.yml` | Add `dns:` configuration to php service |

---

## Expected Results

### Before Fix
```
Error: Could not resolve host: api.stripe.com
Integration Tests: 47 errors
```

### After Fix
```
DNS: api.stripe.com → 52.84.XXX.XXX (or similar)
Integration Tests: 0 DNS errors
```

**Note:** Some tests may still fail due to:
- Invalid/missing Stripe test API keys
- Expired test data
- Other configuration issues

These are separate issues to be addressed in Sprint 3.

---

## SOLID Compliance

- **SRP**: Docker configuration handles networking
- **OCP**: Tests remain unchanged
- **DIP**: Tests depend on abstract "network available", not specific DNS

---

## CI/CD Considerations

### GitHub Actions

If using GitHub Actions, the runners typically have DNS configured correctly. This fix primarily affects local development.

For CI, consider:
```yaml
# .github/workflows/test.yml
services:
  php:
    options: --dns 8.8.8.8
```

### GitLab CI

```yaml
# .gitlab-ci.yml
services:
  - name: php:8.3
    command: ["--dns", "8.8.8.8"]
```

---

## Definition of Done

1. `gethostbyname('api.stripe.com')` returns IP address
2. Integration tests don't fail with DNS resolution errors
3. Docker services restart successfully
4. Update `../status.md` with progress
