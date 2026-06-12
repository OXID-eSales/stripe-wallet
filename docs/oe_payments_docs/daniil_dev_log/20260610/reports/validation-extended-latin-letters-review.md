# Review — ValidationSystem rejects extended-Latin letters on address fields

**Date:** 2026-06-10
**Module:** `extensions/stripe` (rules file) — payment-base **untouched**
**Ticket lineage:** STRP-129 (Sprints 119 / 120 / 121)
**Status:** Defect confirmed. TDD remediation plan: [`../sprints/sprint-124-strp-129-extended-latin-address-fields.md`](../done/sprint-124-strp-129-extended-latin-address-fields.md)

---

## 1. Symptom

A customer whose **street** or **company** (or any of four other fields) contains a
German umlaut (`ö ä ü ß`), a Polish letter (`ł ą ę ó ś ż ź ć ń`), or any other
accented Latin letter is **blocked at checkout** with a "field is not valid"
message. Plain-ASCII names pass; `Köln` as a *city* passes; but `Müllerstraße`
as a *street* does not.

## 2. Root cause

The validation grammar (built in Sprint 119) classifies each character against
named tokens defined in
`payment-base/src/Validation/CharacterClass.php:68-77`:

```php
'UNICODE_LETTERS' => (bool) preg_match('/^\p{L}$/u', $char),   // any Unicode letter — öäüßł all pass
'LETTERS'         => (bool) preg_match('/^[A-Za-z]$/', $char),  // ASCII a-z / A-Z ONLY — öäüßł rejected
```

`UNICODE_LETTERS` already accepts every accented letter. The bug is purely in
**which fields were assigned the ASCII-only `LETTERS` token** in the Stripe
rules file `src/Resources/validation-rules.php`.

### Field-by-field audit (`src/Resources/validation-rules.php`)

| Field | Line | Token today | Accepts öäüßł? | Verdict |
|---|---|---|---|---|
| `firstName` | 10 | `UNICODE_LETTERS` | ✅ yes | OK |
| `lastName` | 17 | `UNICODE_LETTERS` | ✅ yes | OK |
| `city` | 50 | `UNICODE_LETTERS` | ✅ yes | OK |
| `captureReason` | 97 | `UNICODE_LETTERS` | ✅ yes | OK (fixed in Sprint 120) |
| `refundDescription` | 107 | `UNICODE_LETTERS` | ✅ yes | OK (fixed in Sprint 121) |
| **`additionalInfo`** | **24** | **`LETTERS`** | ❌ **no** | **BUG** |
| **`street`** | **31** | **`LETTERS`** | ❌ **no** | **BUG** |
| **`houseNumber`** | **38** | **`LETTERS`** | ❌ **no** | **BUG** |
| **`postalCode`** | **44** | **`LETTERS`** | ❌ **no** | **BUG** |
| **`company`** | **57** | **`LETTERS`** | ❌ **no** | **BUG** |
| **`vatId`** | **64** | **`LETTERS`** | ❌ **no** | **BUG** |
| `phone` / `cellPhone` / `personalPhone` / `fax` | 70-88 | `NUMBERS …` (no letter token) | n/a | out of scope (digits only) |

Six fields are affected. The Sprint-119 plan even flagged this risk
(§8: *"false positives on legitimate addresses … the single rules file per
plugin is the one-touch remediation point"*) — the rules file is exactly where
we fix it.

## 3. Why it slipped through

Sprint 119 transcribed the field rules **verbatim from the original user spec**,
which used `LETTERS` for address lines. The happy-path test
(`UserDataValidatorTest::testValidatesAllOxidUserColumnsByLogicalNames`, line 80)
seeds `street => 'Main St.'` and `city => 'Köln'` — the umlaut lives only on the
`UNICODE_LETTERS` city field, so the ASCII-only street token was never exercised
against an umlaut. Sprints 120 and 121 corrected the *admin* free-text fields
(`captureReason`, `refundDescription`) to `UNICODE_LETTERS` for exactly this
reason ("German admins type umlauts") but did **not** revisit the customer
address fields.

## 4. Decision (confirmed with user, 2026-06-10)

1. **Character class:** reuse the existing **`UNICODE_LETTERS`** (`\p{L}`) token.
   No new `CharacterClass` token, no payment-base change. (The alternative —
   a Latin-script-only `\p{Latin}` token — was considered and declined; the
   simpler reuse was chosen.)
2. **Scope:** widen **all six** affected fields
   (`additionalInfo`, `street`, `houseNumber`, `postalCode`, `company`, `vatId`).

### Consequence worth recording
`\p{L}` admits **any** Unicode letter — Cyrillic, Greek, CJK, Arabic — not only
Latin. On `street`/`company` this means e.g. Cyrillic homoglyphs are now
accepted. This is an accepted trade-off: the universal blocklist (control chars,
zero-width/invisible code points) and each field's `block` list (`< > { } | \ ~`
etc.) still apply, so the injection surface is unchanged — only the *script* of
the letters widens. `vatId` is loosened beyond its ASCII spec, also per the
explicit "widen all six" decision.

## 5. Blast-radius analysis (what changes vs. what is safe)

| Area | Impact |
|---|---|
| `CharacterClass` (payment-base) | **No change.** `LETTERS` token stays ASCII-only; other PSPs may still use it. |
| User-facing error messages | **No change.** `AllowedSymbolsDescriber.php:29-30` maps **both** `UNICODE_LETTERS` and `LETTERS` → `STRIPE_VALIDATION_CLASS_LETTERS` ("letters"). The "Allowed symbols are: letters, …" string is identical before/after. No formatter/describer test churn. |
| `ValidationRulesProvider` | Reads the real file; its tests assert exact allow-strings → **must add RED assertions** for the six widened fields. |
| `UserDataValidator` tests | Mock `ValidationBaseInterface`, so they don't exercise real rules. Add a real-rules integration test for umlaut/ł street + company. |
| payment-base CharacterClassTest | `testMatchesClassLetters` (asserts `ö` is NOT a `LETTERS` match) stays green — token semantics unchanged. |
| paypal / OPC suites | Untouched — change is Stripe-rules-file only. |

## 6. The one-line-per-field fix (preview)

In `src/Resources/validation-rules.php`, replace the leading `LETTERS` token with
`UNICODE_LETTERS` on the six fields (block lists and literals unchanged):

```diff
- 'additionalInfo' allow: "LETTERS NUMBERS SPACES ' - . , / #"
+ 'additionalInfo' allow: "UNICODE_LETTERS NUMBERS SPACES ' - . , / #"
- 'street'         allow: "LETTERS NUMBERS SPACES ' - . , /"
+ 'street'         allow: "UNICODE_LETTERS NUMBERS SPACES ' - . , /"
- 'houseNumber'    allow: "NUMBERS LETTERS - /"
+ 'houseNumber'    allow: "NUMBERS UNICODE_LETTERS - /"
- 'postalCode'     allow: "LETTERS NUMBERS SPACES -"
+ 'postalCode'     allow: "UNICODE_LETTERS NUMBERS SPACES -"
- 'company'        allow: "LETTERS NUMBERS SPACES ' - . & ,"
+ 'company'        allow: "UNICODE_LETTERS NUMBERS SPACES ' - . & ,"
- 'vatId'          allow: "LETTERS NUMBERS SPACES -"
+ 'vatId'          allow: "UNICODE_LETTERS NUMBERS SPACES -"
```

## 7. Normalization caveat (impl note, not a blocker)

`\p{L}` matches **precomposed** letters (NFC: `ö` = U+00F6). A **decomposed**
`ö` (`o` + U+0308 combining diaeresis) would have its combining mark classified
as `\p{M}`/`\p{Inherited}` — not `\p{L}` — and still fail. Browser form input is
NFC in practice, and `CustomerDataSanitizer` already normalizes whitespace/control
chars. The TDD plan adds one NFC test to lock the assumption; full NFC
normalization in the sanitizer is **out of scope** (no evidence any real client
submits decomposed forms) and flagged as a follow-up only if it ever surfaces.

## 8. Recommendation

Proceed with the single-commit TDD remediation in
[`sprint-124`](../done/sprint-124-strp-129-extended-latin-address-fields.md):
RED tests first (provider allow-strings + real-rules umlaut/ł integration),
then the six-line rules edit, then `pre-commit-check.sh --full`. No payment-base
touch, no message changes, no migration.
