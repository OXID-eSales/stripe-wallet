# Sprint 124 — Completion report

**Ticket:** STRP-129 (follow-up) · **Branch:** `b-7.4.x-validation-STRP-149`
**Date:** 2026-06-10 · **Mode:** TDD-first, single logical change
**Plan:** [`../sprints/sprint-124-strp-129-extended-latin-address-fields.md`](../sprints/sprint-124-strp-129-extended-latin-address-fields.md)
**Review:** [`../reports/validation-extended-latin-letters-review.md`](../reports/validation-extended-latin-letters-review.md)

## What changed

`src/Resources/validation-rules.php` — the six address fields that used the
ASCII-only `LETTERS` token now use `UNICODE_LETTERS` (`\p{L}`), so German
umlauts (`öäüß`), Polish letters (`łąę…`) and all accented Latin letters pass:

| Field | before | after |
|---|---|---|
| `additionalInfo` | `LETTERS …` | `UNICODE_LETTERS …` |
| `street` | `LETTERS …` | `UNICODE_LETTERS …` |
| `houseNumber` | `NUMBERS LETTERS - /` | `NUMBERS UNICODE_LETTERS - /` |
| `postalCode` | `LETTERS NUMBERS SPACES -` | `UNICODE_LETTERS NUMBERS SPACES -` |
| `company` | `LETTERS …` | `UNICODE_LETTERS …` |
| `vatId` | `LETTERS NUMBERS SPACES -` | `UNICODE_LETTERS NUMBERS SPACES -` |

Block lists and literals are byte-identical. **No payment-base change, no
`CharacterClass` change, no services.yaml/metadata.php change, no migration.**

## TDD trace

- **RED (confirmed):** ran the two new test groups against the old `LETTERS`
  rules → 9 failures (provider allow-strings + umlaut/ł behavioural cases + NFC).
- **GREEN:** applied the six-field rules edit → 21/21 pass.
- During RED I corrected two of my own expected codes: `<` (street) and `|`
  (company) are in the per-field **block** lists → `blocked_character`, not
  `disallowed_character`. Added a `€` case to cover the genuine allow-list-miss
  `disallowed_character` path. Production behaviour was correct throughout.

## Tests added (15)

- `ValidationRulesProviderTest::testAddressFieldsAcceptUnicodeLetters` (real file).
- `ExtendedLatinAddressFieldsTest` — real `ValidationBase` + real
  `FilesystemValidationRuleLoader` over the real rules file (stub path resolver,
  no DB):
  - 9 valid cases: `Müllerstraße`, `ul. Łąkowa`, `Café Müller GmbH`,
    `Œuvre Frères`, `c/o Jürgen Groß`, plus ASCII/digit regressions.
  - 4 still-rejected cases: `<` (blocked), tab (control), `€` (disallowed),
    `|` (blocked) — proves widening the letter class did **not** loosen the
    block list or universal blocklist.
  - 1 NFC assumption-lock test (precomposed `ö` valid; decomposed out of scope).

## Gate results

| Check | Result |
|---|---|
| Unit suite | **1262 tests, 3057 assertions — all pass** |
| PHPStan (level max) | ✅ No errors |
| PHPCS (PSR-12) | ✅ pass |
| PHPMD | ✅ pass (baseline not grown) |
| Integration | 3 pre-existing **unrelated** errors in `ModuleLifecycleTest` (Symfony DI: `ModuleActivationServiceInterface … removed or inlined`). Proven pre-existing by reproducing on a clean tree. Not caused by this change. |
| paypal / OPC | untouched (Stripe-rules-file-only change) |

## Accepted trade-offs (recorded)

- `\p{L}` admits **any** Unicode script (Cyrillic/CJK/Arabic), not only Latin —
  per the user's explicit "reuse UNICODE_LETTERS / widen all six" decision. The
  per-field `block` lists and universal blocklist still apply, so the injection
  surface is unchanged; only the script of accepted *letters* widens.
- `vatId` is intentionally loosened beyond its ASCII spec (same decision). A
  strict country-prefix/length VAT format check would be a separate sprint.

## Follow-ups (not done, by design)

- Full NFC normalization in `CustomerDataSanitizer` — only if a real client is
  found submitting decomposed combining marks (none observed).
- PayPal adoption of the same widening (its own `validation-rules.php`).

## Working-tree note

While verifying the pre-existing nature of the `ModuleLifecycleTest` failure, a
`git stash push` aborted on an untracked pathspec and a subsequent `git stash
pop` applied a **pre-existing STRP-145 WIP stash** onto `StripeStatusMapper.php`.
This was reverted (`git checkout HEAD -- StripeStatusMapper.php`); both STRP-145
stashes (`stash@{0}`, `stash@{1}`) were preserved intact. Final tree contains
only Sprint 124 changes + the dev-log docs.
