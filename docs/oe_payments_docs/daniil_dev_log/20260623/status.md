# Status — 2026-06-23

## Reports
- [01 — CI black-box pentest suite & security-header hardening](reports/01-ci-pentest-suite-and-header-hardening.md) — covers Sprints 130 + 131: harness, dual-mode CI, live findings, fixes, verification, next steps.

## Sprints
- [Sprint 130 — CI-driven black-box pentest suite](sprints/sprint-130-strp-xxx-ci-pentest-suite.md) — bash/curl pentest harness grown from `pentest-verify.sh`, dual-mode CI (in-Action shop default + external URL), mirrors `load-test-locust.yml`. **Implemented + validated live** — harness (`tests/Security/{pentest.sh,lib/,checks/}`) + workflow (`.github/workflows/security-pentest.yml`); gate self-test green, full `aggressive` run vs local shop exits 0. Pending: a real CI run. Commits held.
- [Sprint 131 — Security response-header & banner hardening](sprints/sprint-131-strp-xxx-security-header-hardening.md) — fixes the 4 `HDR-*` low-sev pentest fails (HSTS, nosniff, clickjacking, version banner) at the correct layer: operator `.htaccess`/httpd/php.ini recipe + CI regression guard + module-owned-endpoint defence-in-depth. **Implemented + validated** — all 4 HDR pentest checks now `pass` (gate exit 0), admin renders under SAMEORIGIN, PHPCS/PHPStan(max)/PHPMD clean, Unit 1301 pass (+5). Commits held.
