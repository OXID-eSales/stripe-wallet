# Sprint 131 — Security response-header & banner hardening (pentest HDR-* findings)

> Fixes the four `fail` findings from the Sprint 130 black-box pentest
> (`reports/` / `tests/Security/report/findings.md`): missing HSTS, `X-Content-Type-Options`,
> clickjacking guard, and a leaked `Server`/`X-Powered-By` version banner. All four are
> **web-server / shop-config** concerns, not payment-module logic — so the fix is delivered at
> the correct layer, with the module hardening only the responses it actually owns.

**Repos:** `extensions/stripe` (docs + module endpoints + CI) · **Branch:** `b-7.4.x`
**Ticket:** STRP-XXX (TBD) · **Type:** security hardening (low severity, defence-in-depth)
**Binding:** SOLID · no overreach (module must not own shop-wide headers) · no overengineering
(minimal headers, no full CSP) · PSR-12 · PHPStan max.

---

## 1. Findings being fixed

| ID | Sev | Title | Root layer |
|----|-----|-------|-----------|
| `HDR-hsts` | low | Missing `Strict-Transport-Security` | web server / shop `.htaccess` |
| `HDR-nosniff` | low | Missing `X-Content-Type-Options: nosniff` | web server / shop `.htaccess` (+ module endpoints) |
| `HDR-frame` | low | Missing clickjacking guard (`X-Frame-Options`/CSP) | web server / shop `.htaccess` (+ module endpoints) |
| `HDR-banner` | low | `Server`/`X-Powered-By` version banner leaked | server config (`ServerTokens`) + `php.ini` (`expose_php`) |

All four are **low severity** (they don't breach the default `high` gate) — this is hardening, not
an active-exploit fix. They are infrastructure/deployment concerns surfaced by the module's pentest.

---

## 2. Why this layering (the scope decision)

A payment module must **not** inject shop-wide response headers (e.g. via an OXID output filter) —
that is the wrong layer: it would set policy for the whole shop, conflict with other modules, and
couple a PSP integration to site-wide security config. So:

- **Shop-wide headers (HSTS, nosniff, X-Frame)** → the shop operator's web-server config. We **ship a
  canonical, copy-pasteable recipe** (`.htaccess` + httpd + `php.ini`) and **prove it** by wiring it
  into the CI pentest shop so the `HDR-*` checks turn green (regression guard).
- **The module's OWN responses (webhook JSON, Stripe/admin JSON)** → the module sets `nosniff` +
  `X-Frame-Options: DENY` on them directly. These are responses the module fully controls and that
  are never legitimately framed — in-scope defence-in-depth, unit-tested.

### What can be set where (Apache facts that shape the recipe)

- `.htaccess` (the shop ships one; vhost has `AllowOverride All`, `mod_headers` loaded) **can** set:
  `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, and
  `Header always unset X-Powered-By`.
- `.htaccess` **cannot** set `ServerTokens` / `ServerSignature` (context: *server config*) — the
  `Server:` banner minimisation must live in httpd/vhost config.
- `X-Powered-By` is best removed at source with `expose_php = Off` (`php.ini`); the `.htaccess`
  `unset` is the belt-and-braces fallback.

### Constraint — OXID admin uses framesets

The admin (`adminnav`/`basefrm`/`list`/`edit` frames) needs same-origin framing. Shop-wide clickjacking
protection is therefore **`X-Frame-Options: SAMEORIGIN`**, NOT `DENY` (DENY would break the admin UI).
The module's JSON/webhook endpoints are never framed, so those use `DENY`. A full `Content-Security-Policy`
is **out of scope** (high regression risk for a storefront; `frame-ancestors` deferred).

---

## 3. What gets built

### 3.1 Canonical hardening recipe (docs) — the real operator fix
`docs/for_merchant/02-security-headers.md` (new): copy-pasteable, with rationale per directive.

```apache
# --- shop source/.htaccess (mod_headers) -----------------------------------
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"           # admin framesets need same-origin
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always unset X-Powered-By
</IfModule>
```
```apache
# --- httpd/vhost (server-config context) -----------------------------------
ServerTokens Prod
ServerSignature Off
```
```ini
; --- php.ini ---------------------------------------------------------------
expose_php = Off
```
Notes documented: HSTS is HTTPS-only (browsers ignore it over http; `includeSubDomains`/`preload`
caveats called out); `SAMEORIGIN` rationale (admin frames); no full CSP.

### 3.2 Committed snippet for CI + operators
`tests/Security/conf/hardening.htaccess` — the exact `mod_headers` block above, so the CI step (3.4)
appends it to the in-Action shop's `source/.htaccess`, and operators have a versioned source of truth.

### 3.3 Module-owned response hardening (defence-in-depth, module source)
A tiny shared helper applies `X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` to the
module's own HTTP responses, at the points that already emit headers:
- `WebhookController` (already calls `Registry::getUtils()->setHeader(...)` / `http_response_code`)
- `StripeOrderController` JSON responses (`header('Content-Type: application/json')` sites)
- `Admin/ModuleConfiguration` JSON response

Single helper (e.g. `Stripe/Adapter/Helper/ResponseHeaders::applySecurity(callable setHeader)`), pure
and static (static-for-pure-utilities), so each call site is one line and the behaviour is unit-tested
once. No global filter, no new request middleware.

### 3.4 CI wiring (regression guard)
`.github/workflows/security-pentest.yml` — in the in-Action build (`inputs.url == ''`):
- append `tests/Security/conf/hardening.htaccess` to `source/source/.htaccess`,
- add `ServerTokens Prod` / `ServerSignature Off` to the Apache config and `expose_php = Off` to
  `containers/php/custom.ini` (the workflow already `perl -pi` edits both files),
- then the existing pentest run's `HDR-hsts/nosniff/frame/banner` checks flip to `pass`.

External-`url` runs are unaffected (we never rewrite a remote shop's config — those findings remain
the operator's to apply from the §3.1 recipe).

---

## 4. Tests

- **Module endpoints (unit):** assert `ResponseHeaders::applySecurity()` emits `nosniff` + `X-Frame:
  DENY` (capture via an injected `setHeader` callable); assert the webhook / order / admin JSON paths
  call it. TDD: write these first.
- **Recipe (CI regression):** re-run the Sprint 130 pentest against the hardened in-Action shop — the
  four `HDR-*` checks must report `pass`. This is the acceptance gate for §3.1–§3.4.
- **No admin-frameset regression:** confirm the admin still renders (frames load under `SAMEORIGIN`) —
  manual check / existing admin Playwright specs.

---

## 5. Edit boundaries & decisions

- **No shop-wide header injection from module PHP** — headers for the whole shop live in web-server
  config only. The module touches headers solely on responses it emits.
- **`SAMEORIGIN`, not `DENY`, shop-wide** — OXID admin framesets. `DENY` reserved for module JSON/API.
- **No full CSP** this sprint (storefront regression risk) — `frame-ancestors`/CSP deferred to a
  dedicated ticket with its own report-only rollout.
- **`Server:` banner** can only be minimised in server config (`ServerTokens`), not `.htaccess` — so
  the prod fix is operator-owned; CI applies it to the in-Action Apache to prove it.
- **payment-base untouched.** No DB, no metadata settings.
- These are **low severity** — shipping value is the documented recipe + the module-endpoint
  defence-in-depth + a CI guard, not an urgent patch.

---

## 6. DONE criteria

- [x] `docs/for_merchant/02-security-headers.md` recipe (htaccess + httpd + php.ini), with rationale.
- [x] `tests/Security/conf/hardening.htaccess` committed.
- [x] `ResponseHeaders` helper + wired into Webhook / StripeOrder / ModuleConfiguration responses; unit-tested
      (`ResponseHeadersTest` 4 + `StripeOrderControllerResponseHeadersTest` 1).
- [x] CI applies the snippet + `ServerTokens`/`expose_php` to the in-Action shop; the four `HDR-*`
      pentest checks report `pass`; gate stays green. **Validated locally** (2026-06-23): applied the
      exact CI edits to the running shop → all 4 HDR checks `pass`, gate exit 0, 0 fails on `standard`.
- [x] Admin UI still renders under `SAMEORIGIN` (no frameset regression) — `admin/` returns 200.
- [x] PHPCS 0 errors · PHPStan max 0 · PHPMD 0 new (no suppressions) · full Unit suite 1301 pass (+5).
      `status.md` updated. Commits HELD (awaiting ticket / go).

---

## 6a. Implementation notes (2026-06-23)

- **Trailing-newline gotcha (fixed):** `containers/httpd/project.conf` ships with **no trailing
  newline**, so a bare `echo 'ServerTokens Prod' >> project.conf` concatenated onto the last line
  (`</VirtualHost>ServerTokens Prod`) → Apache `Syntax error … missing closing '>'`. The workflow now
  uses `printf '\n…\n'` (leading newline) for both `project.conf` and `custom.ini`. Caught only because
  the local validation rebuilt Apache and it refused to start.
- **Config is COPY'd, not bind-mounted:** the SDK bakes `project.conf`/`custom.ini` into the httpd/php
  images at build, so banner changes need `docker compose up -d --build` locally. CI is unaffected (it
  edits before `make up`). `.htaccess` IS live (AllowOverride All) — appended post-`make up` in CI.
- **PHPStan:** the header-sink closures had to be explicit `function(...): void {}` bodies — an arrow
  `fn($h) => $utils->setHeader($h)` is `Closure(string): mixed` (setHeader returns mixed) and tripped
  `callable(string): void`.
- **PHPCS:** 4 pre-existing line-length **warnings** in the touched controllers (not on new lines); 0 errors.

---

## 7. Artifacts

- New: `docs/for_merchant/02-security-headers.md`, `tests/Security/conf/hardening.htaccess`,
  `src/Stripe/Adapter/Helper/ResponseHeaders.php` (+ test)
- Changed: `src/Stripe/Controller/Webhook/WebhookController.php`,
  `src/Stripe/Controller/StripeOrderController.php`,
  `src/Stripe/Controller/Admin/ModuleConfiguration.php`,
  `.github/workflows/security-pentest.yml`
- Source finding: Sprint 130 `tests/Security/report/findings.md` (HDR-hsts/nosniff/frame/banner)
