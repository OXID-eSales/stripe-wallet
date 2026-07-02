# Black-box penetration tests

HTTP-level security probes against a **running** shop. Complements the unit/integration
security tests (which assert the F1–F25 fixes from the inside) by attacking the same
guards from the outside — web server + OXID routing + module guards + TLS + headers.

Grown from the Sprint 64 `pentest-verify.sh` (still here as a zero-dependency smoke).

## Run it

```bash
# Local dev shop (self-signed cert):
BASE_URL=https://localhost.local PENTEST_INSECURE_TLS=1 ./tests/Security/pentest.sh

# External shop, conservative profile:
BASE_URL=https://pay1.oxid.dev PENTEST_PROFILE=safe ./tests/Security/pentest.sh

# 10-second curl smoke (the original Sprint 64 checks):
BASE_URL=http://localhost.local ./tests/Security/pentest-verify.sh
```

Requires only `bash`, `curl`, `awk` (and `jq` for `findings.json` — optional).

## Configuration (env)

| Var | Default | Meaning |
|-----|---------|---------|
| `BASE_URL` | `http://localhost.local` | Target shop, no trailing slash |
| `PENTEST_PROFILE` | `standard` | `safe` / `standard` / `aggressive` |
| `FAIL_ON_SEVERITY` | `high` | A failed finding at/above this severity exits non-zero |
| `PENTEST_INSECURE_TLS` | _(unset)_ | Set to allow self-signed certs (`curl -k`) |
| `PENTEST_TIMEOUT` | `15` | Per-request timeout (seconds) |
| `SEED_USER` / `SEED_PASS` | _(unset)_ | Storefront login for the standard-profile tamper check |
| `OUT_DIR` | `tests/Security/report` | Report output directory |

## Profiles

Cumulative: **safe ⊂ standard ⊂ aggressive**.

- **safe** — non-destructive: webhook size/signature/replay/transport, CSRF rejection,
  open redirect, admin auth wall, MCP/JWT disclosure, security headers, info disclosure.
- **standard** — safe + amount/currency tampering (needs `SEED_USER`/`SEED_PASS`; loud
  skip without them).
- **aggressive** — standard + webhook rate-limit flood + login brute-force burst.
  **Only run against a shop you own** — it deliberately floods the target.

> CI clamps an **external** target to `safe` unless `i_own_this_target: true` is set, so
> the workflow can never accidentally flood someone else's server.

## Output

- `report/findings.txt` — raw `id|severity|status|title|evidence` lines
- `report/findings.md` — summary table (used in the CI step summary)
- `report/findings.json` — machine-readable (jq; skipped if jq absent)

`status` is `pass` / `fail` / `skip`. A `skip` means a precondition wasn't met (e.g. no
login creds, endpoint unreachable) — it is logged loudly, never counted as a pass.

## CI

`.github/workflows/security-pentest.yml` (manual dispatch) mirrors `load-test-locust.yml`:

- **`url` empty (default)** → builds a throwaway OXID shop inside the Action (Stripe test
  mode forced), attacks it, tears it down.
- **`url` provided** → targets an already-installed shop; optionally seeds it over SSH.

The workflow self-tests the gate (`lib/gate.test.sh`) before running, uploads the report
as an artifact, and fails the job on a budget breach (`BREACH …` lines).

## Adding a check

1. Add a `check_<name>` function to the relevant `checks/*.sh` (or a new file). End it in a
   single `record <id> <severity> <status> "<title>" "<evidence>"` call.
2. Add the function name to the right profile list in `pentest.sh`.

No other file changes — existing checks are untouched (OCP).
