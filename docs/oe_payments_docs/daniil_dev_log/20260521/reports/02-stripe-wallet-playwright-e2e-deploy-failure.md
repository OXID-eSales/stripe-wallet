# stripe-wallet Playwright E2E — deploy failure on remote shop

**Failing run:** [Playwright E2E #49 (attempt 2)](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26209790612)
**Failing job:** [Deploy & activate module](https://github.com/OXID-eSales/stripe-wallet/actions/runs/26209790612/job/77122134153)
**Branch / SHA:** `b-7.4.x` @ `8a5085d` (STRP-134 Loading spinner and blur layer on the Payment tab)
**Workflow file:** `.github/workflows/playwright-ci.yml`

## Immediate cause

The `Update dependencies and activate module` SSH step on `pay1.oxid.dev` failed four times in a row with the same error:

```
docker compose exec php bash
service "php" is not running
make: *** [Makefile:45: php] Error 1
```

Each of the four calls aborted at the docker-compose layer before reaching OXID:

| # | Intended command | Result |
|---|---|---|
| 1 | `make php CMD="composer update --no-interaction"` | `service "php" is not running` |
| 2 | `make php CMD="bin/oe-console oe:module:deactivate oe_payments_stripe_wallet"` | `service "php" is not running` |
| 3 | `make php CMD="bin/oe-console oe:module:activate oe_payments_stripe_wallet"` | `service "php" is not running` |
| 4 | `make php CMD="bin/oe-console o:c:c"` | `service "php" is not running` |

`drone-ssh` then exited with status 2 and the GitHub Actions step was marked `failure`. The dependent `Run Playwright tests` job was `skipped` because of `needs: deploy`.

**Where the box stands:** the docker-compose stack on `pay1.oxid.dev` is not running. The `php` service is the first to be addressed; the other services (`mysql`, `apache`) may also be down — the workflow can't tell because it stops on the first failure. The first SSH step (`git fetch / checkout / reset --hard`) succeeded, so SSH access and the working tree are intact. Only the container layer is dead.

This is **not** caused by the change in this commit (STRP-134 was a Twig template and CSS edit). It's environmental.

## Latent design issue — `make php CMD=…` is a no-op even when the container is up

Reading the local `Makefile` at `/home/dtkachev/osc/strpwt7-nov26/Makefile`:

```makefile
44 php:
45 	docker compose exec php bash
```

The `php:` target is a fixed two-line rule. It does **not** reference any `CMD` variable. The workflow's invocation

```bash
make php CMD="composer update --no-interaction"
```

sets `CMD` as a make-time variable, but the rule never expands it. Even if the container were running, the result would be `docker compose exec php bash` four times — an interactive shell that promptly exits over SSH (no PTY).

Two possibilities:

1. **The remote host has a different Makefile** that wires up `CMD`, e.g.:
   ```makefile
   php:
   	docker compose exec php $(if $(CMD),sh -c "$(CMD)",bash)
   ```
   If so, the SDK template diverged from the deployed shop and we are blind to that drift from the local checkout. Worth verifying via SSH next time someone is on `pay1.oxid.dev`.
2. **The remote host also has the upstream `php: docker compose exec php bash` target**, in which case the entire `make php CMD=…` chain has been a no-op since day one, and this workflow has never actually deactivated/reactivated/cache-cleared the module on the shop — it just SSH'd in, fired up `bash`, and the step was marked green when the prior failure (the container-down condition) was absent. The Playwright job ran against whatever state the shop happened to be in.

Either way, the workflow's deploy step is fragile. Step 1 in the fix should be confirming which Makefile lives on the remote, because the fix-it-locally path is very different from the fix-it-on-the-server path.

## Historical pattern — this workflow has never been green

Listing runs of workflow `252328442` (`Playwright E2E`):

| Range | Runs | Outcomes |
|---|---|---|
| 1–9 | initial setup commits ("add playwright tests") | all `failure` or `cancelled` |
| 10–29 | development through STRP-118 / STRP-122 | mix of `failure` / `cancelled`, no `success` |
| 30–49 | STRP-118 onward through STRP-134 (current) | 10 consecutive `failure` (runs 40–49) |

**Zero successful runs in 49 attempts.** This is not a regression — the workflow has been red since inception and was never green. The current container-down failure is just the latest manifestation. Past failures may have had different root causes (missing secrets, branch mismatch, MissingSubmodule, etc.), so it's worth pulling a couple of older job logs (e.g. runs 40 and 30) before concluding that "restart docker" is the whole story.

## Why this is not a code regression

- Local Stripe `./bin/pre-commit-check.sh --full` is green at HEAD (1002 unit + integration tests).
- The failing step never reaches the PHP runtime — it dies at the docker-compose layer.
- The previous commit (`aec97ce` STRP-142) and the one before that (`208d5bb` STRP-134 Slow loading) hit the exact same `service "php" is not running` error (runs 48 and 47). The breakage predates the current change.

## Recommended next steps

1. **Unblock the box.** SSH into `pay1.oxid.dev` as the workflow's user and run:
   ```bash
   cd /var/www/oxid/oxid75
   docker compose ps
   docker compose up -d
   # if the build context changed: docker compose up --build -d
   ```
   Confirm `php`, `mysql`, `apache` are `Up (healthy)` before re-running the workflow.
2. **Inspect the remote Makefile.** `cat /var/www/oxid/oxid75/Makefile | sed -n '40,55p'` — if the `php:` target doesn't reference `CMD`, the workflow's "deactivate / activate / cache-clear" calls have been silent no-ops. Fix the Makefile (preferred — adds value), or change the workflow to call `docker compose exec -T php <command>` directly (also fine — bypasses make entirely and is more transparent).
3. **Add a sanity check to the workflow.** Before the `make php CMD=…` sequence, fail fast if the container isn't running. Something like:
   ```bash
   cd /var/www/oxid/oxid75
   docker compose ps --status running --services | grep -q '^php$' \
     || { echo "::error::php container is not running on pay1.oxid.dev"; exit 1; }
   ```
   At least the failure message will be actionable instead of a four-line repetition of the same docker error.
4. **Decide whether this workflow should keep running.** If no human is watching 49 consecutive red runs, the workflow is noise. Either disable it until the remote is reliable, or make a runbook for restarting `pay1.oxid.dev` and assign ownership.

## Run metadata snapshot

- Commit: `8a5085d48c9b1f49ecf39049fd68d0cad2358105`
- Actor: `dantweb` (Daniil Tkachev)
- Triggered by: push to `b-7.4.x`
- Run attempt: 2 (attempt 1 failed with the same root cause; rerun did not fix the environment)
- Started: 2026-05-21 07:10:12 UTC
- Failed at: 2026-05-21 07:10:19 UTC (~7 seconds in — never reached the PHP runtime)
- Runner: `arc-runner-set`
- Remote host: `pay1.oxid.dev` (via SSH from `secrets.SHOP_HOST`)
