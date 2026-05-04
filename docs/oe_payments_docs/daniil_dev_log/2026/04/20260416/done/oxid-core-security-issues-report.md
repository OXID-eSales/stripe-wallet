# OXID Core Security Issues — Report for Core Team

**Date:** 2026-04-16
**Source:** BetterQA AI Security Assessment of `osc1.oxid.shop`
**Affected:** OXID eShop Enterprise Edition 7.4, Apache/2.4.62, PHP 8.3.19
**Reporter:** Stripe Module Team (Daniil Tkachev)

---

## Summary

The security assessment identified **9 issues** that are outside the Stripe module's scope and require OXID core / infrastructure changes. None are critical, but 2 are HIGH severity.

| Severity | Count | Responsibility |
|----------|-------|---------------|
| HIGH | 2 | OXID Core + Infrastructure |
| MEDIUM | 4 | Infrastructure (Apache/PHP config) |
| LOW | 3 | Infrastructure + OXID Core |

---

## HIGH Severity

### 1. No Rate Limiting on Login Endpoint

**CVSS:** 7.5 (High)
**Component:** OXID Core (`fnc=login`)
**Responsibility:** OXID Core Team + Infrastructure

**Evidence:**
```
50 rapid login attempts in 26 seconds
Successful requests: 50
Blocked requests: 0
```

**Impact:**
- Brute force attacks on customer and admin accounts
- Credential stuffing with leaked password databases
- No account lockout protection
- Potential DoS via login endpoint flooding

**Recommended Fix (OXID Core):**
- Implement rate limiting: max 5 attempts per minute per IP
- Add CAPTCHA after 3 consecutive failed attempts
- Progressive delay (1s, 2s, 4s, 8s...) after failures
- Account lockout after 10 failed attempts (configurable)
- Log failed login attempts for monitoring

**Recommended Fix (Infrastructure — immediate mitigation):**
```apache
# Apache mod_evasive or mod_ratelimit
<Location /index.php>
    SetEnvIf Request_Method POST rate_limit_post
    # 10 requests per second maximum
    SetOutputFilter RATE_LIMIT
    SetEnv rate-limit 10
</Location>
```

**Priority:** HIGH — this is the most impactful finding.

---

### 2. Missing Security Headers (8 headers)

**CVSS:** 6.1 (Medium-High)
**Component:** Apache web server configuration
**Responsibility:** Infrastructure Team

**Missing headers:**

| Header | Risk | Priority |
|--------|------|----------|
| `Strict-Transport-Security` | No HTTPS enforcement after first visit | HIGH |
| `Content-Security-Policy` | No XSS/injection protection | HIGH |
| `X-Frame-Options` | Clickjacking possible | HIGH |
| `X-Content-Type-Options` | MIME sniffing attacks | MEDIUM |
| `Referrer-Policy` | Referrer data leakage | MEDIUM |
| `Permissions-Policy` | Browser features unrestricted | LOW |
| `Cross-Origin-Opener-Policy` | Cross-origin attacks | LOW |
| `Cross-Origin-Resource-Policy` | Resource sharing unrestricted | LOW |

**Recommended Fix:**
```apache
# /etc/apache2/conf-available/security-headers.conf
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' js.stripe.com m.stripe.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; frame-ancestors 'self';"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
Header always set Cross-Origin-Opener-Policy "same-origin"
Header always set Cross-Origin-Resource-Policy "same-origin"
```

```bash
a2enmod headers
a2enconf security-headers
systemctl reload apache2
```

**Note for CSP:** The Stripe module requires `js.stripe.com` and `m.stripe.com` in `script-src`. The Stripe module already adds a CSP `<meta>` tag for payment pages (Sprint 91), but a server-wide header provides broader protection.

---

## MEDIUM Severity

### 3. Cookie Missing `SameSite` Attribute

**CVSS:** 4.3
**Component:** OXID Core session configuration / PHP config
**Responsibility:** OXID Core Team

**Evidence:**
```
Set-Cookie: language=0; path=/; secure; HttpOnly
```
Missing: `SameSite=Lax` or `SameSite=Strict`

**Impact:** CSRF attacks possible via cross-site form submission.

**Recommended Fix (PHP config):**
```ini
; php.ini
session.cookie_samesite = Lax
```

**Recommended Fix (OXID Core):**
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
```

---

### 4. Server Version Disclosure

**CVSS:** 5.3
**Component:** Apache + PHP configuration
**Responsibility:** Infrastructure Team

**Disclosed:**
```
Server: Apache/2.4.62 (Debian)
X-Powered-By: PHP/8.3.19
```

**Impact:** Attackers can target known CVEs for specific Apache/PHP versions.

**Fix:**
```apache
# /etc/apache2/conf-enabled/security.conf
ServerTokens Prod
ServerSignature Off
```

```ini
; php.ini
expose_php = Off
```

---

### 5. TLS Session Ticket Key Rotation

**CVSS:** 4.0
**Component:** Apache SSL configuration
**Responsibility:** Infrastructure Team

**Finding:** Session ticket hint = 604,800 seconds (7 days). RFC 5077 recommends < 24 hours for forward secrecy.

**Fix:**
```apache
# Rotate session ticket keys daily
SSLSessionTickets on
# Or disable entirely for maximum forward secrecy:
# SSLSessionTickets off
```

---

### 6. CBC Cipher Suites Offered (TLS 1.2)

**CVSS:** 3.1
**Component:** Apache SSL configuration
**Responsibility:** Infrastructure Team

**Affected ciphers:**
- `ECDHE-RSA-AES128-SHA` (CBC)
- `ECDHE-RSA-AES256-SHA` (CBC)

**Fix:**
```apache
SSLCipherSuite ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384
SSLHonorCipherOrder on
```

---

## LOW Severity

### 7. Negative Quantity Accepted in Cart

**Component:** OXID Core (cart/basket validation)
**Responsibility:** OXID Core Team

**Finding:** Adding negative quantity to cart returns HTTP 200. Server-side validation should reject negative values.

**Recommendation:** Add `max(0, $quantity)` validation in basket `addToBasket()` logic.

---

### 8. PUT/DELETE HTTP Methods Return 200

**Component:** Apache configuration
**Responsibility:** Infrastructure Team

**Finding:** PUT and DELETE return HTTP 200 but are treated as GET (no modification). Should return 405 Method Not Allowed.

**Fix:**
```apache
<LimitExcept GET POST HEAD>
    Require all denied
</LimitExcept>
```

---

### 9. Admin Panel Publicly Accessible

**Component:** Apache / network configuration
**Responsibility:** Infrastructure Team

**Finding:** `/admin/` login page is accessible from any IP. Protected by credentials (no default creds found), but exposed to brute force (see #1).

**Recommendation:**
- IP whitelist for admin panel
- Add 2FA for admin accounts
- Monitor failed admin login attempts

---

## What the Stripe Module Already Handles

These were tested and confirmed secure — no action needed from OXID core:

| Control | Status | Module |
|---------|--------|--------|
| Payment data security | PCI compliant via Stripe.js | Stripe module |
| CSP for payment pages | `<meta>` tag restricts script sources | Stripe module (Sprint 91) |
| CSRF on payment actions | `stoken` validated | Stripe module |
| XSS in payment forms | Stripe Elements (iframe isolation) | Stripe SDK |
| SQL injection | Parameterized queries | OXID Core (already secure) |
| Session management | Server-side sessions | OXID Core (already secure) |
| Output encoding | HTML entity encoding | OXID Core (already secure) |

---

## Action Items for OXID Core Team

| Priority | Issue | Effort | Impact |
|----------|-------|--------|--------|
| 1 | Rate limiting on login | Medium | Prevents brute force + credential stuffing |
| 2 | Security headers (Apache config) | Low | Prevents clickjacking, XSS, MIME sniffing |
| 3 | Cookie SameSite attribute | Low | Prevents CSRF |
| 4 | Server version disclosure | Low | Reduces attack surface |
| 5 | TLS session ticket rotation | Low | Improves forward secrecy |
| 6 | Remove CBC ciphers | Low | Removes weak crypto |
| 7 | Cart negative quantity | Low | Input validation |
| 8 | Block PUT/DELETE methods | Low | HTTP hygiene |
| 9 | Admin IP whitelisting | Medium | Reduces admin attack surface |
