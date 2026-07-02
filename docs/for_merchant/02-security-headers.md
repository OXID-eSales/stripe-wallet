# Security response headers (web-server hardening)

The Stripe module's black-box pentest (`tests/Security/pentest.sh`) checks that your
shop sets a baseline of HTTP security headers. These are **web-server / shop
configuration** — not something a payment module can or should set globally — so
applying them is the shop operator's responsibility. This page is the canonical recipe.

> The module already sets `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY`
> on **its own** JSON/webhook responses (those are API endpoints, never framed). The
> directives below cover the **rest of the shop** (storefront + admin).

## 1. Shop `.htaccess` — security headers

Append to your shop's `source/.htaccess` (requires Apache `mod_headers` and
`AllowOverride All`, both default in the OXID docker-eshop-sdk). The committed snippet
lives at `extensions/stripe/tests/Security/conf/hardening.htaccess`.

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always unset X-Powered-By
</IfModule>
```

| Directive | Why | Notes |
|-----------|-----|-------|
| `X-Content-Type-Options: nosniff` | Stop browsers MIME-sniffing responses | — |
| `X-Frame-Options: SAMEORIGIN` | Clickjacking guard | **SAMEORIGIN, not DENY** — the OXID **admin uses framesets** and must frame its own pages. `DENY` would break the admin UI. |
| `Strict-Transport-Security` | Force HTTPS for a year incl. subdomains | Browsers ignore HSTS sent over plain HTTP, so it is safe to set unconditionally. Add `; preload` only after every subdomain is verified HTTPS-only (preload is hard to undo). |
| `Header always unset X-Powered-By` | Remove the PHP banner | Belt-and-braces; prefer removing it at source via `php.ini` (below). |

## 2. Web-server config — `Server:` banner

The `Server:` version banner (`Apache/2.4.x (Unix) OpenSSL/3.x`) **cannot** be removed
from `.htaccess` — `ServerTokens` / `ServerSignature` are *server-config* context. Add
to your httpd / vhost config:

```apache
ServerTokens Prod
ServerSignature Off
```

This reduces the `Server:` header to just `Apache` (no version), so an attacker can't
fingerprint your exact patch level.

## 3. `php.ini` — remove `X-Powered-By` at source

```ini
expose_php = Off
```

Removes the `X-Powered-By: PHP/8.x` header before it is ever sent (cleaner than the
`.htaccess unset` fallback).

## 4. Verify

```bash
# From the Stripe module dir, against your shop:
BASE_URL=https://your-shop.example PENTEST_PROFILE=safe ./tests/Security/pentest.sh
```

The `HDR-hsts`, `HDR-nosniff`, `HDR-frame`, and `HDR-banner` checks should all report
`pass`. (See `tests/Security/README.md` for the full pentest usage.)

## What is intentionally NOT here

- **Content-Security-Policy** — a full storefront CSP carries high regression risk and is
  not part of this baseline. Roll it out separately, report-only first.
- **Per-route `X-Frame-Options: DENY`** — only the module's own JSON/webhook endpoints use
  `DENY`; that is handled in module code, not shop config.
