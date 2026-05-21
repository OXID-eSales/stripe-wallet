# stripe-wallet `Development` workflow — payment-base checkout 404

**Failing run:** [Development #539](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26209790610)
**Failing jobs:** [`styles (8.2)`](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26209790610/job/77117716343), [`install_shop_with_module`](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26209790610/job/77117716367)
**Skipped jobs (cascade):** `integration_tests`, `isolated_unit_tests` (`needs: install_shop_with_module`)
**Branch / SHA:** `b-7.4.x` @ `8a5085d` (STRP-134 Loading spinner and blur layer on the Payment tab)
**Workflow file:** `.github/workflows/development.yml`
**Sibling failure today:** Playwright E2E #49 (run `26209790612`, reported separately in `02-…`) — different repo state, different root cause.

## Immediate cause

`actions/checkout@v5` cannot read `OXID-eSales/payment-base`. The same error appears three times in both failing jobs (built-in retry, 3 attempts at exponential backoff):

```
[command]/usr/bin/git -c protocol.version=2 fetch --no-tags --prune --no-recurse-submodules --depth=1
        origin +refs/heads/b-7.4.x*:refs/remotes/origin/b-7.4.x* +refs/tags/b-7.4.x*:refs/tags/b-7.4.x*
remote: Repository not found.
##[error]fatal: repository 'https://github.com/OXID-eSales/payment-base/' not found
The process '/usr/bin/git' failed with exit code 128
```

The step is the `Checkout payment-base (private dependency)` step that exists in three jobs of the workflow (`development.yml` lines 94-100, 267-273, 335-341). Each instance is wired identically:

```yaml
- name: Checkout payment-base (private dependency)
  uses: actions/checkout@v5
  with:
    repository: OXID-eSales/payment-base
    ref: b-7.4.x
    path: source/payment-base
    token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GITHUB_TOKEN }}
```

After the third retry fails, the job exits non-zero, and the dependent jobs (`integration_tests`, `isolated_unit_tests`) are auto-skipped.

## Root cause — almost certainly an auth issue, not a "missing repo"

`OXID-eSales/payment-base` exists and is reachable. I just queried it minutes ago: `gh api repos/OXID-eSales/payment-base` returns 200, the repo is private (`"private": true`), and I successfully fetched its Actions run logs in the previous turn. So the repo is fine.

GitHub returns **the same "Repository not found" 404 for both "repo does not exist" and "repo exists but the token lacks read access"** to prevent enumeration of private resources. Since the repo demonstrably exists, the failure has to be on the auth side.

The workflow's token expression is:

```yaml
token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GITHUB_TOKEN }}
```

This is a misleading fallback — `secrets.GITHUB_TOKEN` is **automatically provisioned per workflow run** and is scoped to the **current** repository only. It physically cannot read another private repo, no matter what scopes are set. So if `ENTERPRISE_GITHUB_TOKEN` is missing or empty, the `||` falls through to `GITHUB_TOKEN`, the checkout attempts the cross-repo fetch with a token that has no business reading payment-base, and GitHub responds with 404.

**Most likely failure mode:** `secrets.ENTERPRISE_GITHUB_TOKEN` is either unset, empty, expired, or has had its read permission on `OXID-eSales/payment-base` revoked. The `|| secrets.GITHUB_TOKEN` fallback masked the missing-token condition and produced the misleading "Repository not found" error instead of a clear "no token" failure.

## Why I'm sure this is not a code regression

Recent runs of this workflow (workflow ID `199579576`):

| Run # | Conclusion | SHA | Notes |
|---|---|---|---|
| 539 | failure | `8a5085d` | this run |
| 538 | failure | `aec97ce` | STRP-142 Add dependancies.yaml |
| 537 | failure | `8aab85d` | first new red |
| 536 | failure | `208d5bb` | first new red (same SHA, retried) |
| 535 | failure | `208d5bb` | first new red |
| **534** | **success** | `67661fb` | **last green** |
| 533 | success | `67661fb` | green |
| 532 | success | `8854893` | green |
| 531 | failure | `0f4e4d2` | previous incident, unrelated |
| 530 | failure | `0f4e4d2` | unrelated |

The workflow file `.github/workflows/development.yml` was last touched in `2644ff5` (STRP-135 namespace refactor). Run #534 was green at `67661fb5` (STRP-131 Partial refund fix), which is **two commits after** `2644ff5`. So the workflow definition has not changed between the last green and the current red — it's the same file, same `actions/checkout@v5` invocation, same token expression. The flip from green-to-red is environmental.

`./bin/pre-commit-check.sh --full` at HEAD is green locally (1002 Stripe tests + style checks). The change in `8a5085d` was a Twig template / CSS / JS edit and could not affect `actions/checkout`.

## Hypothesis ranking

1. **`ENTERPRISE_GITHUB_TOKEN` expired** (most likely). Fine-grained PATs and GitHub Apps have explicit expiry dates. If the secret was set with a 30/60/90-day PAT in February-March, it would have died exactly around when the failures started.
2. **`ENTERPRISE_GITHUB_TOKEN` was rotated and the new value was not propagated to `stripe-wallet` secrets.** Common when a token is rotated org-wide and one repo's secret is missed.
3. **The token user's access to `OXID-eSales/payment-base` was removed.** Less likely but possible — if the token belongs to a person/bot whose team membership changed.
4. **Repository setting change on stripe-wallet** that broke secret resolution for the `arc-runner-set` runner. Less likely — would affect more workflows than just this one (Playwright E2E would still fail at a similar step if it tried payment-base, but it doesn't).

## How to verify and fix

1. **Check the secret.** As a stripe-wallet repo admin:
   ```
   GitHub → stripe-wallet → Settings → Secrets and variables → Actions
   ```
   Look at `ENTERPRISE_GITHUB_TOKEN` — note its "Updated" timestamp. If it predates the start of failures (~2026-05-18), it's almost certainly the cause. The UI shows only the secret name and update date, not the value or expiry — you need the underlying PAT's settings or the GitHub App installation page.
2. **Regenerate.** Create a new fine-grained PAT (or GitHub App installation token) with **`repository:read` for `OXID-eSales/payment-base`** and **`contents:read`**. Update `stripe-wallet` repo secret `ENTERPRISE_GITHUB_TOKEN`. Re-run the workflow — should go green without code changes.
3. **Improve the failure signal.** The `|| secrets.GITHUB_TOKEN` fallback is the reason this looked like a "repo not found" instead of "no token configured." Two cleaner options:
   - **Drop the fallback:** `token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN }}` — if the secret is missing, the step fails with "Input required and not supplied: token" which is unambiguous.
   - **Assert the secret early:** add a step before the checkout that fails fast:
     ```yaml
     - name: Assert ENTERPRISE_GITHUB_TOKEN is set
       run: |
         if [ -z "${{ secrets.ENTERPRISE_GITHUB_TOKEN }}" ]; then
           echo "::error::ENTERPRISE_GITHUB_TOKEN is not configured on stripe-wallet"
           exit 1
         fi
     ```
4. **Long-term: move payment-base off the cross-repo checkout.** Three options, ordered by effort:
   - Make `OXID-eSales/payment-base` public (depends on legal/business policy — not a developer call).
   - Publish payment-base as a tagged composer package via Packagist or a private registry and consume it via `composer require oxid-esales/payment-base:^X` — no checkout needed.
   - Use a GitHub App installation across the org so the token is centrally managed and auto-renews — less manual rotation.

   This is a structural cleanup, not an emergency fix.

## Update — fix attempt `de39856` "fixing ci" did not work

**Follow-up run:** [Development #540 — install_shop_with_module](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26215958954/job/77138251774)
**Commit:** `de39856` "fixing ci"
**Status:** failure — same error, same step:

```
remote: Repository not found.
Error: fatal: repository 'https://github.com/OXID-eSales/payment-base/' not found
```

### What the fix changed

Five `||` fallbacks in `development.yml` were swapped from `secrets.GITHUB_TOKEN` to `secrets.GH_TOKEN`:

```diff
-          token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GITHUB_TOKEN }}
+          token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GH_TOKEN }}
```

at lines 100, 130 (env), 273, 290 (env COMPOSER_AUTH), 341, and 395.

### Why this did not help

The diagnosis in this report is that `ENTERPRISE_GITHUB_TOKEN` is unset or invalid and the workflow is falling through to the second branch of the `||`. Changing **which** secondary secret the fallback points at doesn't address that — it just picks a different empty/wrong value:

- The previous fallback `secrets.GITHUB_TOKEN` is **auto-provisioned** by GitHub Actions on every run. It's never empty, but it's scoped to the current repo (`stripe-wallet`) and physically cannot read `OXID-eSales/payment-base`. Outcome: 404.
- The new fallback `secrets.GH_TOKEN` is a **user-defined custom secret**. If it isn't set on the `stripe-wallet` repo (or it is set but lacks `repo:read` on `payment-base`), the expression resolves to an empty string or an unprivileged token. Outcome: identical 404.

Either way, when GitHub sees an unauthenticated (or under-privileged) fetch against a private repo, it returns "Repository not found." The visible error is the same; the underlying problem — `ENTERPRISE_GITHUB_TOKEN` is the only credential here that can read payment-base, and it's not doing its job — is unchanged.

### The fix has to be on `ENTERPRISE_GITHUB_TOKEN`

There is **no valid fallback** for cross-repo private-repo access. `GITHUB_TOKEN` can't do it (per-repo scope, by design). `GH_TOKEN` can't do it unless it's a separately-issued PAT with explicit `repo:read` on `payment-base` — at which point it's just another `ENTERPRISE_GITHUB_TOKEN` with a different name. The whole `|| secrets.X` chain is misleading because it makes "missing token" look like "missing repo."

Steps to actually fix:

1. **In the `stripe-wallet` repo:** Settings → Secrets and variables → Actions → check `ENTERPRISE_GITHUB_TOKEN`. Note its "Updated" timestamp. If it predates ~2026-05-18 (when the failures started), regenerate.
2. **Generate a new fine-grained PAT** (or use a GitHub App installation token) with these scopes:
   - **Repository access:** `OXID-eSales/payment-base` (and any other private OXID repos the workflows depend on).
   - **Permissions:** `Contents: Read`, `Metadata: Read`.
3. **Update the secret** with the new value.
4. **Re-run the workflow** (or push a no-op commit). It should go green without touching code.
5. **Then delete the misleading fallback.** Replace
   ```yaml
   token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN || secrets.GH_TOKEN }}
   ```
   with
   ```yaml
   token: ${{ secrets.ENTERPRISE_GITHUB_TOKEN }}
   ```
   so any future expiry surfaces immediately as `Input required and not supplied: token` instead of a misleading 404. The fallback in this position is not protecting against any realistic failure mode — it's only hiding the one failure mode that actually happens.

The current failure is the same bug as run #539, observed again. The token still needs replacing.

## Run metadata snapshot

- Commit: `8a5085d48c9b1f49ecf39049fd68d0cad2358105`
- Actor: `dantweb` (Daniil Tkachev)
- Triggered by: push to `b-7.4.x`
- Run attempt: 1 (run #539)
- Started: 2026-05-21 06:36:48 UTC
- Failed at: ~06:38:35 UTC (payment-base 3rd retry timeout)
- Runner: `arc-runner-set` (self-hosted)
- PHP / MySQL / template engine (env): 8.2 / 5.7 / twig
- Failing step in both jobs: `Checkout payment-base (private dependency)`
