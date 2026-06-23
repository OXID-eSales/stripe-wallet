# Status — 2026-06-23

## Sprints
- [Sprint 130 — CI-driven black-box pentest suite](sprints/sprint-130-strp-xxx-ci-pentest-suite.md) — bash/curl pentest harness grown from `pentest-verify.sh`, dual-mode CI (in-Action shop default + external URL), mirrors `load-test-locust.yml`. **Implemented** — harness (`tests/Security/{pentest.sh,lib/,checks/}`) + workflow (`.github/workflows/security-pentest.yml`); gate self-test green, all profiles run, YAML valid. Pending: a real CI run against a built/external shop. Commits held.
