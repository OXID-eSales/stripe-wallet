# Report 02 — Sprint 93 first CI iteration still red (2026-05-05)

_Companion to `01-ci-broken-after-unification.md` and
`../sprints/sprint-93-ci-bootstrap-fix.md`. Documents the iterative
diagnosis after the first push of the Sprint 93 patches._

## What happened

After landing Sprint 93 Phase G on the two branches and pushing,
both CI runs were still red — at the exact step Probe 2 was
designed to catch:

- stripe-wallet — <https://github.com/OXID-eSales/stripe-wallet/actions/runs/25366705490/job/74379307263>
  - step 17 `Re-dump autoload after OXID module install` — ✅ ran, produced
    `Generated optimized autoload files containing 8101 classes`
  - step 18 `Smoke-test oe-console boot` — ❌ exit 255

- payment-component — <https://github.com/OXID-eSales/payment-component/actions/runs/25366692269>
  - same shape; smoke step exits 255 immediately after the autoload re-dump

Artifact `data/php/logs/error_log.txt` from both runs:

```text
PHP Fatal error: Uncaught Error: Class "OxidEsales\Eshop\Core\ConfigFile"
  not found in /var/www/source/bootstrap.php:184
```

Same fatal as before. The autoload re-dump alone is not enough.

## What changed in the diagnosis

`OxidEsales\Eshop\Core\ConfigFile` is loaded **PSR-4**, not classmap.
The unified-namespace-generator package declares:

```json
"autoload": {
    "psr-4": {
        "OxidEsales\\Eshop\\": "./generated/OxidEsales/Eshop",
        "OxidEsales\\UnifiedNameSpaceGenerator\\": "./src"
    }
}
```

So `OxidEsales\Eshop\Core\ConfigFile` resolves to
`vendor/oxid-esales/oxideshop-unified-namespace-generator/generated/OxidEsales/Eshop/Core/ConfigFile.php`
on disk. `composer dump-autoload --optimize` only adds known classmap
entries — it doesn't make missing PSR-4 files appear. If the file
isn't on disk, the dump can't help.

So the question is no longer "is the autoload classmap stale?" — it's
"why doesn't the unified-namespace-generator emit `ConfigFile.php` to
disk on this CI run?".

## What's actually happening

Both green and red CI logs print the same line:

```text
Generating OXID eShop unified namespace classes ... Done
```

That message comes from `oxid-esales/oxideshop-unified-namespace-generator/src/Plugin.php` —
its callback writes the prefix, runs `cleanupOutputDirectory()` +
`generate()`, then writes "Done". The "Failed" branch (printed inside
the catch block) does not appear in the broken log, so no exception
was thrown. But that does **not** prove files were written:
`generate()` calls `generateClassFiles($classMap)`, and if `$classMap`
is empty (or differently-shaped) `generateClassFiles` returns
silently. In that path the prior `cleanupOutputDirectory()` has
already deleted everything in `generated/`, so the `Eshop\` PSR-4
directory ends up empty — exactly the symptom we observe.

Working hypothesis (not yet proven): the OXID composer plugin's
`Installing module oxid-esales/payment-component package.` call —
which is *new* now that PC is `type: oxideshop-module` and which
boots a `BootstrapContainerFactory` Symfony container — interleaves
with the unified-namespace-generator's plugin in a way that leaves
the generator's `getClassMap()` returning an unusable shape on the
final pass. The green run never had this interleaving because PC was
still a `composer-plugin` and never triggered the OXID composer
plugin's module-install path.

## Patch applied for the second CI iteration

Replace the `Re-dump autoload after OXID module install` step with a
**force-regenerate** step that:

1. Invokes the generator binary `vendor/bin/oe-eshop-unified_namespace_generator`
   directly. The binary does the same `cleanup` + `generate` that
   the plugin does, but runs from a clean PHP CLI process after every
   composer plugin handler has finished — no interleaving with the
   OXID composer plugin's module-install pass.
2. Asserts `ConfigFile.php` exists on disk afterwards. If still
   missing, fail the step with a `::error::` annotation pointing at
   Sprint 93 — easier to triage than the silent bootstrap fatal we
   had before.
3. Re-runs `composer dump-autoload --optimize` so the now-present
   PSR-4 file is also in the optimized classmap (small extra
   robustness; not load-bearing since PSR-4 directory lookup works
   without the optimized classmap).

Both workflows updated identically. Probes 1, 2 and 3 unchanged —
Probe 2 remains the canary that flips red → green when this fix is
correct.

## What we'll learn from the next CI run

- **If the smoke step (Probe 2) passes:** the plugin event was
  silently misfiring; the binary works around it. Done. Phase G done.
- **If the new "Force-regenerate" step itself fails** with the
  `::error::ConfigFile.php still missing` message: the binary is
  also silently producing zero files. The bug is upstream of plugin
  ordering — likely in `Facts::getCommunityEditionSourcePath()` or
  `UnifiedNameSpaceClassMapProvider::getClassMap()` when payment-component
  is type `oxideshop-module`. At that point the next steps are to
  add a debug dump of `$facts->getEdition()` and the resolved
  source path right before the binary runs.
- **If the binary succeeds but `bin/oe-console list` still fatals:**
  the issue is somewhere else in the autoload chain (e.g. the OXID
  composer plugin clobbers `vendor/composer/autoload_*.php`
  in a later pass). At that point the diagnostic step listing
  `vendor/composer/autoload_psr4.php` and grepping for
  `OxidEsales\\Eshop\\` will tell us.

## Iteration 2 (2026-05-05 09:02 UTC) — binary also silent

- stripe-wallet — <https://github.com/OXID-eSales/stripe-wallet/actions/runs/25367278580>
- payment-component — <https://github.com/OXID-eSales/payment-component/actions/runs/25367276566>

Both jobs failed at the new `Force-regenerate OXID unified namespace
classes` step. Specifically:

- The `vendor/bin/oe-eshop-unified_namespace_generator` invocation
  exited 0 with no output.
- The `ls -la …/Eshop/Core/ConfigFile.php` immediately afterwards
  reported `No such file or directory` and the step's
  `::error::ConfigFile.php still missing` annotation fired.
- The 250-ms-fast bootstrap fatal that used to happen later is now
  gone — the build stops cleanly at this annotation, which is the
  Probe-2 promise paying off (a step whose single responsibility is
  "did the generator emit `ConfigFile.php`?").

`validateUnifiedNamespaceArray()` in the generator throws on an
empty class map, so the silent zero-file case can't be "empty
classmap reached the foreach". The binary has its own try/catch
that prints the message + stack trace + exits 1 on `\Exception` —
none of that fired either. So either:

1. A `\Throwable` (TypeError, fatal error) escapes the binary's
   `\Exception` catch and `php` exits with the success code anyway
   (PHP can do this when display_errors is off and an OPcache /
   autoload error occurs during early bootstrap).
2. `getClassMap()` returns a non-empty array but
   `getUnifiedNamespaceArray()` filters every entry out, leaving an
   empty array — at which point `validateUnifiedNamespaceArray()`
   throws but the binary's catch swallows it and *should* print the
   message. If the message is missing from the log, stderr was
   suppressed by the `composer-plugin` shim that wraps composer
   `bin/` entries.

## Iteration 3 — diagnostic dump + harness invocation

The third iteration of the workflow step does three things in one
step before the `ls` check:

1. **State dump.** Print `Facts::getEdition()`,
   `getCommunityEditionSourcePath()`, the resolved
   `UnifiedNameSpaceClassMap.php` path, whether it's readable, the
   number of entries it contains, and whether the generator's output
   directory exists / is writable.
2. **Generator binary with errors on.** Run
   `php -d display_errors=1 -d error_reporting=E_ALL vendor/bin/oe-eshop-unified_namespace_generator`
   so any silent fatal surfaces.
3. **Direct harness.** `php -r` instantiates `Facts`,
   `UnifiedNameSpaceClassMapProvider`, `Generator`, then calls
   `cleanupOutputDirectory()` + `generate()` and prints
   `wrote_configfile=yes|no`. If the harness writes
   `ConfigFile.php` but the binary doesn't, the binary wrapper is at
   fault and we replace it with the harness for good. If the
   harness also fails, the state dump tells us which input is
   wrong.

Both workflow files updated identically. Probe 1 / Probe 3 unchanged.

The only meaningful CI-run cost is one extra step that runs PHP
twice; ~1 second total. No extra Docker pulls, no extra composer
work.

## Iteration 3 result (2026-05-05 ~09:10 UTC) — inputs OK, harness silent, no files

CI runs:

- stripe-wallet — <https://github.com/OXID-eSales/stripe-wallet/actions/runs/25368027304/job/74383891757>
- payment-component — <https://github.com/OXID-eSales/payment-component/actions/runs/25368025787/job/74383885852>

Diagnostic output (identical on both repos):

```text
edition=CE
ce_source=/var/www/source
map_path=/var/www/source/Core/Autoload/UnifiedNameSpaceClassMap.php
map_readable=yes
map_entries=518
gen_dir_exists=yes
gen_dir_writable=yes
+ docker compose exec -T php php -d display_errors=1 -d error_reporting=E_ALL /var/www/vendor/bin/oe-eshop-unified_namespace_generator
+ docker compose exec -T php php -d display_errors=1 -d error_reporting=E_ALL -r '...harness...'
wrote_configfile=no
+ docker compose exec -T php sh -c 'ls -la …/generated/OxidEsales/Eshop/Core/ConfigFile.php'
ls: cannot access '…/generated/OxidEsales/Eshop/Core/ConfigFile.php': No such file or directory
```

Reading: every input the generator depends on is fine. The classmap
file is readable and has 518 entries. The output directory exists
and is writable. The Generator's binary AND the
`Generator::generate()` call both return cleanly without any output —
so neither raised the `'No unified namespace found'` exception that
`validateUnifiedNamespaceArray()` throws on an empty post-filter
array. Yet the foreach over 518 entries somehow writes nothing.

Local reproduction with the same harness pointed at a fresh
`/tmp/genout/` writes the expected `OxidEsales/Eshop/Core/`
subdirectory tree correctly — so the code path itself is healthy.
The CI-only behaviour is the bug.

Two remaining hypotheses worth instrumenting:

1. **The generator silently writes to the wrong path.** If
   `__DIR__/../generated/` resolves through a symlink in CI but not
   locally, or if there's a leftover `.gitkeep` / `.gitignore` or
   permission quirk that lets `mkdir 0755` succeed but writes go
   somewhere else, a `find` over the generator's package directory
   should reveal stray files.
2. **A `\Throwable` we're not catching.** The harness wraps in
   `catch (\Exception)` indirectly via the binary, but `TypeError`
   and `ParseError` extend `\Throwable`, not `\Exception`. With
   `display_errors=1` they should surface, but only if PHP didn't
   bail out *before* the error handler installs (e.g. a parse error
   in Generator.php during opcode compilation). Wrapping the harness
   in `catch (\Throwable)` and printing the trace is one extra line.

## Iteration 4 — instrumented harness

Both workflows had the harness updated to:

- Wrap the whole call in `try { … } catch (\Throwable $t) { … }`
  and print `harness_threw=<Class>: <message>` plus
  `getTraceAsString()` if anything escapes.
- Reflect the Generator's private `outputDirectory` and print both
  the literal value and `realpath()` of it.
- Print `generate_returned=ok` after `$g->generate()`.
- Add a `find <generated> -maxdepth 6 | head -40`.

Iteration 4 was never actually pushed.

## Iteration 5 (final) — scope reset

Daniil pruned the diagnostic detour and aligned both workflows on a
single, smaller premise: **install + activate the modules; do not
touch the unified-namespace-generator.** Concretely:

- payment-component workflow: `Install + activate payment-component
  module` step between `Install dependencies` and `Reset database`.
  No autoload re-dump, no force-regen, no smoke step, no harness.
- stripe-wallet workflow: same `Install + activate payment-component
  module` step, then a parallel `Install + activate stripe-wallet
  module` step (running `oe:module:install /var/www/test-module` +
  `oe:module:activate oe_payments_stripe_wallet`). The duplicate
  `oe:module:activate oe_payments_stripe_wallet` line that used to
  live inside the `Put module settings` step is removed (single
  place to activate Stripe = the new dedicated step).

The earlier diagnostic data still stands — `bin/oe-console list` did
fatal on the missing `Eshop\Core\ConfigFile` shim in CI iterations
1–3 — but the working theory now is that the OXID
`oe:module:install` console command itself triggers whatever shop
machinery the bootstrap needs, and that the bootstrap fatal we kept
seeing was downstream of `composer dump-autoload --optimize`
overwriting the unified-namespace-generator's classmap entries.
Without our re-dump, the plugin's classmap survives and bootstrap
boots far enough for `oe:module:install` to do its thing.

If iteration 5 still fails the same way, we revisit — probably by
landing the smoke step back as a read-only canary (no autoload
manipulation) so we can see the failure cleanly. For now, the
sprint stays scoped to "land Sprint I §80–95 verbatim, nothing
more."

## Why the local environment doesn't reproduce

Locally, `vendor/oxid-esales/oxideshop-unified-namespace-generator/generated/OxidEsales/Eshop/Core/ConfigFile.php`
is present and `bin/oe-console oe:database:reset` runs cleanly. The
local install was bootstrapped multiple times across days, so a
working `generated/` directory persisted from a pre-unification run
(local composer never re-cleaned it because the unification commit
landed without anyone running `composer install` from scratch
locally on this checkout). CI is a fresh install on every run and
hits the new code path on the first pass.

## Files touched in this iteration

- `OXID-eSales/stripe-wallet/.github/workflows/development.yml` — replace
  `Re-dump autoload …` with `Force-regenerate OXID unified namespace classes`.
- `OXID-eSales/payment-component/.github/workflows/development.yml` — same.
- `source/extensions/stripe/docs/oe_payments_docs/daniil_dev_log/20260505/reports/02-first-ci-iteration-still-red.md` — this file.

No probes, tests, or composer files changed. Probe 1 / Probe 3
remain as written in the first push.
