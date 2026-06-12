# Sprint 124 — STRP-129 Accept extended-Latin letters on address fields

**Module:** `extensions/stripe` (rules file + tests only). **payment-base untouched.**
**Ticket:** STRP-129 (follow-up to Sprints 119/120/121)
**Driver report:** [`../reports/validation-extended-latin-letters-review.md`](../reports/validation-extended-latin-letters-review.md)
**Mode:** TDD-first. **One reviewable commit** (RED → GREEN → verify).
**Engineering requirements:** R-1 (RED before GREEN, real SUT), R-5 (no magic
strings, clean diff), R-6 (`pre-commit-check.sh --full` green; no new
suppressions). R-2/R-3/R-4/R-9 trivially satisfied — no new class, no new
abstraction, no signature change.

---

## 1. Why

Six customer address fields use the ASCII-only `LETTERS` token and reject
`ö ä ü ß ł ą …`, blocking German/Polish customers at checkout. Root cause and
field audit: see the driver report §2. Decision (user-confirmed 2026-06-10):
**reuse `UNICODE_LETTERS` (`\p{L}`)** and **widen all six fields**.

## 2. Scope

| In scope | Out of scope |
|---|---|
| `src/Resources/validation-rules.php`: `LETTERS` → `UNICODE_LETTERS` on `additionalInfo`, `street`, `houseNumber`, `postalCode`, `company`, `vatId` | Any `CharacterClass` / payment-base change |
| New RED tests proving the six fields accept extended Latin | New `LATIN_LETTERS` (`\p{Latin}`) token — declined in favour of reuse |
| Real-rules integration test (umlaut street + Polish company) | NFC normalization in `CustomerDataSanitizer` (one assumption-lock test only; full normalization deferred) |
| | Error-message wording (unchanged — describer collapses both tokens to "letters") |

## 3. The change (GREEN target)

`src/Resources/validation-rules.php` — replace the leading `LETTERS` token with
`UNICODE_LETTERS` on the six fields. Block lists and literals stay byte-identical.

```
additionalInfo  "LETTERS NUMBERS SPACES ' - . , / #"   -> "UNICODE_LETTERS NUMBERS SPACES ' - . , / #"
street          "LETTERS NUMBERS SPACES ' - . , /"     -> "UNICODE_LETTERS NUMBERS SPACES ' - . , /"
houseNumber     "NUMBERS LETTERS - /"                  -> "NUMBERS UNICODE_LETTERS - /"
postalCode      "LETTERS NUMBERS SPACES -"             -> "UNICODE_LETTERS NUMBERS SPACES -"
company         "LETTERS NUMBERS SPACES ' - . & ,"     -> "UNICODE_LETTERS NUMBERS SPACES ' - . & ,"
vatId           "LETTERS NUMBERS SPACES -"             -> "UNICODE_LETTERS NUMBERS SPACES -"
```

## 4. TDD plan (RED → GREEN → REFACTOR)

### RED — write these first; they must fail against the current rules file

**T1 — `ValidationRulesProviderTest` (reads the REAL file).**
Add assertions on the exact allow-strings for the six widened fields. These flip
RED immediately because the file still says `LETTERS`.

```php
public function testAddressFieldsAcceptUnicodeLetters(): void
{
    $map = $this->sut->getFieldAllowMap();

    $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , / #", $map['additionalInfo']);
    $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , /",   $map['street']);
    $this->assertSame('NUMBERS UNICODE_LETTERS - /',                 $map['houseNumber']);
    $this->assertSame('UNICODE_LETTERS NUMBERS SPACES -',            $map['postalCode']);
    $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . & ,",    $map['company']);
    $this->assertSame('UNICODE_LETTERS NUMBERS SPACES -',            $map['vatId']);
}
```

**T2 — real-rules integration test (the behavioural proof).**
New test class `tests/Integration/Validation/ExtendedLatinAddressTest.php` (or a
unit test that builds a real `ValidationBase` over the real
`FilesystemValidationRuleLoader` + the real Stripe rules file — no mocks). This
is the test that proves the *symptom* is gone, end-to-end through `\p{L}`.

Data provider of `[field, value]` that MUST be `valid`:

| field | value | letter exercised |
|---|---|---|
| `street` | `Müllerstraße` | ö-family + ß |
| `street` | `ul. Łąkowa` | Polish ł, ą |
| `company` | `Café Müller GmbH` | é, ü |
| `company` | `Œuvre Frères` | ligature/accent |
| `additionalInfo` | `c/o Jürgen Groß` | ü, ß |
| `houseNumber` | `12a` | regression — ASCII still OK |
| `postalCode` | `1010` / `EC1A 1BB` | regression — digits + ASCII alpha |
| `vatId` | `DE123456789` | regression — ASCII still OK |

Plus **negative regressions that MUST stay invalid** (prove we widened the
*letter class only*, not the block list):

| field | value | expected code |
|---|---|---|
| `street` | `Main<script>` | `disallowed_character` / `blocked_character` (`<` still blocked) |
| `street` | `Main\tStreet` | `control_character` (tab still universally rejected) |
| `company` | `Acme | Co` | blocked (`|` still in block list) |

**T3 — NFC assumption lock (one test).**
Assert the *precomposed* `ö` (`"\u{00F6}"`) is valid for `street`, and document
(via test name + comment) that decomposed `o\u{0308}` is NOT this sprint's
concern. This pins the §7 caveat from the report so a future reader knows it was
considered, not missed.

```php
public function testStreetAcceptsPrecomposedUmlautNfc(): void { /* "\u{00F6}" valid */ }
// decomposed combining-mark normalization is out of scope — see report §7
```

### GREEN

Apply the §3 edit to `validation-rules.php`. Nothing else.

### REFACTOR

None expected. If T2 grew a bespoke `ValidationBase` builder, extract a small
`buildRealStripeValidationBase()` test helper — no production refactor.

## 5. Regression guard (must stay green, unchanged)

- `payment-base` `CharacterClassTest::testMatchesClassLetters` — `ö` is still
  NOT a `LETTERS` match (we did not touch the token).
- `UserDataValidationMessageFormatterTest` / `AllowedSymbolsDescriberTest` —
  "letters" wording unchanged (both tokens map to `STRIPE_VALIDATION_CLASS_LETTERS`).
- `UserDataValidatorTest` happy path — still green (it mocks the base).
- paypal + one-page-checkout suites — untouched; assertion counts identical.

## 6. Gate

- `./bin/pre-commit-check.sh --full` green (Unit + Integration + PHPCS + PHPStan
  max + PHPMD). No new suppressions, no baseline growth.
- Cache clear + `docker compose restart php` not required (no class/services.yaml
  change — pure data file), but clear `source/tmp/*` if a manual storefront smoke
  is done.
- Manual smoke (optional): checkout with `street = Müllerstraße`,
  `company = Café Müller` → no validation error; checkout with
  `street = Main<script>` → still blocked.

## 7. Definition of Done

- [ ] T1, T2, T3 written RED first, then GREEN after the rules edit.
- [ ] Six fields widened in `validation-rules.php`; block lists byte-identical.
- [ ] Negative regressions (`<`, tab, `|`) still rejected.
- [ ] `pre-commit-check.sh --full` green; assertion count rises by the new tests.
- [ ] Completion report `done/sprint-124-completion.md` records before/after
      test+assertion counts.
- [ ] Memory: update the validation memory note — six address fields now use
      `UNICODE_LETTERS`; `\p{L}` admits any script (accepted trade-off, block
      lists + universal blocklist unchanged); `vatId` intentionally widened
      beyond ASCII per the "widen all six" decision.

## 8. Risk & rollback

- **Risk — over-permissive script acceptance** (Cyrillic/CJK on street/company):
  accepted per the user decision; injection surface unchanged (block list +
  universal blocklist still apply). Recorded in the report §4 and in memory so it
  isn't re-litigated.
- **Risk — `vatId` loosened beyond spec:** accepted per "widen all six". If a
  later sprint wants strict VAT validation, that is a *format* check (country
  prefix + length), orthogonal to this character-class change.
- **Rollback:** single commit, single data file — `git revert` restores the
  ASCII-only tokens instantly. No schema, no payment-base, no JS.
