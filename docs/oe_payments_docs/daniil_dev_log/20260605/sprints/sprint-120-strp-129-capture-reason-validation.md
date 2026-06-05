# Sprint 120 — STRP-129 (follow-up) Admin Capture-Reason Validation on the Payment Tab

**Date planned:** 2026-06-05
**Ticket:** STRP-129 (extension of the Sprint 119 validation mechanism to the admin side)
**Branch:** feature branch off `b-7.4.x` (suggested: `b-7.4.x-STRP-129-capture-reason`)
**Repos touched:** `extensions/stripe` ONLY — **zero payment-base changes** (the Sprint 119 `ValidationBase` library is consumed as-is)
**Predecessors:**
- Sprint 119 plan: [`../../20260529/sprints/sprint-119-strp-129-user-address-validation.md`](../../20260529/sprints/sprint-119-strp-129-user-address-validation.md)
- Driver report: [`../../20260529/reports/user-data-and-address-validation.md`](../../20260529/reports/user-data-and-address-validation.md)

---

## 1. Why

The admin Payment tab (Stripe panel) capture form posts a free-text `capture_reason`
(`views/twig/admin/panel/stripe_panel.html.twig:347-349`). Today the only constraint is the
client-side `maxlength="140"`. Server-side, the value flows unvalidated:

```
captureForm POST (cl=PaymentAdmin&fnc=dispatchAction)
  → PaymentAdminController::dispatchAction()           (CSRF only)
  → StripePaymentPanelProvider::handleCapture()        (parseString() — presence check only)
  → OrderActionDispatcher::dispatchCapture()           (context['reason'])
  → StripeCaptureRequestHandler                        ($metadata['reason'] = $reason  — :183-185)
  → Stripe API (PaymentIntent capture metadata)
```

It is **outbound free text into Stripe metadata** — exactly the field class the Sprint 119
`ValidationBase` mechanism was built for. The storefront fields are gated; the admin field is not.
This sprint closes that asymmetry with the **same** validator, the **same** rules file, the
**same** message formatter — no parallel mechanism (DRY).

## 2. Goals

1. `capture_reason` is character-level validated server-side **before**
   `StripeCaptureRequestEvent` is dispatched. On failure the event never fires — no contract
   transition, no transaction record, no Stripe call (same R-8 property as the checkout gate).
2. The admin sees the failure on the re-rendered tab in the existing panel alert style, with the
   same message text the storefront produces: *"The capture reason field is not valid. Allowed
   symbols are: …"* — in the admin's language (en/de).
3. Empty reason stays legitimate (reason is optional; "non-empty" is not this layer's concern —
   same contract as `ValidationBase` itself).

## 3. Out of scope (explicit — no overengineering)

- **Amount validation** (`capture_amount` malformed→null→full-capture footgun) — separate sprint;
  it needs a semantic (range/precision) validator, not the char-level one. Do NOT bolt range
  logic onto `ValidationBase`.
- **Refund / cancel reason fields** (`refund_reason`, `refund_description`, `cancel_reason`) —
  same pattern, follow-up sprint. This sprint's feedback service is named generically
  (`AdminValidationFeedback`) so that sprint adds only rules entries + `validateFieldMap` calls.
- **PayPal adoption** of admin-side validation.
- **Client-side instant feedback** (Stimulus/pattern attr) on the admin form — `maxlength` stays,
  the server is the authority.
- **Echoing the rejected value back into the input** — admin retypes; keep the diff minimal.
- **payment-base changes of any kind.**

## 4. Architecture

### 4.1 Component map (new pieces marked ●)

```
stripe_panel.html.twig (capture form)
        │ POST capture_reason
        ▼
StripePaymentPanelProvider::handleCapture()
        ├─ UserDataValidatorInterface::validateFieldMap(            ← exists (Sprint 119 Phase B)
        │      ['captureReason' => $reason], 'admin')
        │        └─ ValidationBase::validateField()                 ← exists (payment-base)
        │              └─ FilesystemValidationRuleLoader            ← exists; reads
        │                    src/Resources/validation-rules.php     ← ● +1 entry: captureReason
        ├─ failures? → ● AdminValidationFeedbackInterface::store()  ← new, session-backed
        │              → return (capture event NOT dispatched)
        └─ no failures → OrderActionDispatcher::capture()           ← unchanged
        ▼ (tab re-renders)
StripePanelViewDataBuilder::build()
        ├─ ● AdminValidationFeedbackInterface::consume(orderId)     (read-and-clear)
        ├─ UserDataValidationMessageFormatter::format()             ← exists (Sprint 119 Phase E)
        └─ viewData['validationErrors'] = list<string>
        ▼
stripe_panel.html.twig: ● s-alert s-alert-danger block above the capture form
```

### 4.2 Why the gate lives in `StripePaymentPanelProvider` (not the controller, not the handler)

- `PaymentAdminController` is payment-base and provider-agnostic — it must not know Stripe rules.
- `StripeCaptureRequestHandler` / `CaptureService` are too late: by then the event is public API
  and other listeners may have reacted. Pre-dispatch is the Sprint 119 Phase C analog.
- The provider is a plain constructor-DI service (`services.yaml:1096`) — **no** OXID
  testable-subclass dance needed; dependencies are mockable interfaces.

### 4.3 The rules entry (the activation switch)

`ValidationBase` returns *valid* for unknown fields, so adding the entry IS the feature toggle.
Append to `src/Resources/validation-rules.php`:

```php
[
    'field' => 'captureReason',
    'rules' => [
        'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :",
        'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "',
    ],
],
```

- `UNICODE_LETTERS`, not `LETTERS`: German admins type umlauts (`Rückerstattung`);
  `LETTERS` is ASCII-only (`CharacterClass.php:64,72`).
- Block set mirrors the injection-relevant set used by `additionalInfo`/`street`, plus `"`.
- The same entry automatically feeds `AllowedSymbolsDescriber` (display) because
  `ValidationRulesProvider::getFieldAllowMap()` iterates the file generically — the
  "allowed symbols are: …" message needs zero extra code.

### 4.4 `AdminValidationFeedback` — the only genuinely new component

`handleAction()` is `void` and the tab re-renders after the POST, so failures must survive
exactly one render cycle.

```php
interface AdminValidationFeedbackInterface
{
    /** @param FieldValidationFailure[] $failures */
    public function store(string $orderId, string $action, array $failures): void;

    /** @return list<array{field: string, code: string, char: ?string, action: string}> */
    public function consume(string $orderId): array;
}
```

- Impl `AdminValidationFeedback` in `src/Stripe/Admin/`, depends on the existing
  `OxidEsales\PaymentBase\Adapter\SessionAdapterInterface` (already aliased in
  `services.yaml:271`) — no `Registry::getSession()` in business code (DIP).
- Session payload is **plain arrays** (field/code/char/action), never serialized VOs.
- `consume()` reads and clears in one call → no stale error on the next render (test-pinned).
- Session key namespaced per order: `stripe_admin_validation_<orderId>` — two admins editing
  different orders in one session don't cross-talk.
- Interface exists because two consumers (provider, view-data builder) must mock it in unit
  tests (memory: mock interfaces, not concretes). Not speculative — it has a real seam role.

### 4.5 Wiring changes (`services.yaml`)

```yaml
OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface:
  class: OxidEsales\Payments\Stripe\Admin\AdminValidationFeedback
  arguments:
    $session: '@OxidEsales\PaymentBase\Adapter\SessionAdapterInterface'
  public: false

OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider:
  arguments:
    # …existing three args unchanged…
    $userDataValidator: '@OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface'
    $validationFeedback: '@OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface'

OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder:
  arguments:
    # …existing three args unchanged…
    $validationFeedback: '@OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface'
    $messageFormatter: '@OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter'
```

New ctor args are **appended** (existing arg order untouched). If the autowire sweep picks up the
new interface file, add it to the exclude list at the top of `services.yaml` like
`UserDataValidatorInterface.php` (line 19) — verify with `oe:module:activate` (memory:
`feedback_symfony_di_autowire_sweep`).

### 4.6 Admin-language translations — the one subtlety

`UserDataValidationMessageFormatter` translates via `LanguageTranslatorInterface` →
`Registry::getLang()`. In **admin context** OXID resolves from the admin lang files
(`views/admin_twig/{en,de}/stripe_lang.php`), NOT `translations/`. The Sprint 119 keys exist only
in `translations/` — so the formatter would emit raw keys on the admin tab.

Add to `views/admin_twig/en/stripe_lang.php` (and `de` mirror):

```php
'STRIPE_VALIDATION_FIELD_INVALID'        => 'The %1$s field is not valid. Allowed symbols are: %2$s',
'STRIPE_VALIDATION_LABEL_CAPTUREREASON'  => 'capture reason',
'STRIPE_VALIDATION_CLASS_LETTERS'        => 'letters',
'STRIPE_VALIDATION_CLASS_DIGITS'         => 'digits',
'STRIPE_VALIDATION_CLASS_SPACES'         => 'spaces',
```

de: `'Buchungsgrund'`, `'Buchstaben'`, `'Ziffern'`, `'Leerzeichen'`, template per
`translations/de/stripe_lang.php:121`. Values stay **identical** to the frontend keys —
duplication across lang-file *locations* is an OXID constraint, not a DRY violation; the single
source for message *shape* remains the formatter.

### 4.7 Template block (`stripe_panel.html.twig`)

Directly above the capture-form card (existing alert CSS, `:60-73`):

```twig
{% if validationErrors is defined and validationErrors is not empty %}
    <div class="s-alert s-alert-danger" data-testid="stripe-admin-validation-error">
        {% for message in validationErrors %}
            <div>{{ message }}</div>
        {% endfor %}
    </div>
{% endif %}
```

(Values pass through Twig auto-escaping — the offending input never renders raw.)

---

## 5. TDD plan (per-phase RED → GREEN → REFACTOR)

Gate after EVERY phase: `./bin/pre-commit-check.sh` green, no new suppressions, PHPMD baseline
unchanged (3 entries). One commit per phase (memory: split dispatch enforces granularity).

### Phase A — rules entry + validator end-to-end (rules file is the SUT)

RED (extend `tests/Unit/Stripe/Service/UserDataValidatorTest.php`, real `ValidationBase` + real
`FilesystemValidationRuleLoader` over the real rules file — the Sprint 119 end-to-end pattern,
no method-under-test doubles):

| # | Input (`validateFieldMap(['captureReason' => …], 'admin')`) | Expect |
|---|---|---|
| A1 | `'Rückerstattung (Teillieferung) #2: Kunde'` | `[]` (umlauts + all allowed literals pass) |
| A2 | `'<script>alert(1)</script>'` | 1 failure, `field=captureReason`, blocked char `<` |
| A3 | `'reason {x}'` | 1 failure, blocked `{` |
| A4 | `"reason\u{0000}"` | 1 failure, `code=controlCharacter` |
| A5 | `''` | `[]` (empty = valid, optional field) |
| A6 | failure carries `addressKind='admin'`, `oxidColumn=null` | pinned |

Also RED: `ValidationRulesProviderTest` — `assertCount(13…)` at `:44` becomes **14**;
add `assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :", $map['captureReason'])`.
Check `AllowedSymbolsDescriberTest`: `describe('captureReason')` →
`"letters, digits, spaces, ' - . , / # ( ) :"`.

GREEN: append the rules entry (§4.3). No PHP code changes in this phase.

### Phase B — `AdminValidationFeedback` (new, isolated)

RED (`tests/Unit/Stripe/Admin/AdminValidationFeedbackTest.php`, mocked
`SessionAdapterInterface`):

| # | Behaviour |
|---|---|
| B1 | `store()` writes plain arrays (field/code/char/action) under `stripe_admin_validation_<orderId>` |
| B2 | `consume()` returns what was stored AND clears the session variable |
| B3 | `consume()` on empty session → `[]` |
| B4 | two order IDs do not cross-talk |
| B5 | `consume()` twice → second call `[]` (read-once pinned) |
| B6 | malformed session payload (non-array) → `[]`, no throw |

GREEN: implement interface + class (§4.4). Methods ≤25 lines, early returns, no `else`.

### Phase C — the gate in `StripePaymentPanelProvider::handleCapture()`

RED (`tests/Unit/Stripe/Admin/StripePaymentPanelProviderTest.php` — extend or create; mocked
`AdminActionDispatcherInterface`, mocked `UserDataValidatorInterface`, mocked
`AdminValidationFeedbackInterface`, real provider):

| # | Behaviour |
|---|---|
| C1 | **invalid reason → `capture()` NEVER called** + `store()` called with the failures (the critical pair) |
| C2 | valid reason → `capture()` called with unchanged args, `store()` not called |
| C3 | absent/empty reason → validator NOT called for the empty value, `capture()` dispatched with `reason=null` (full-capture semantics untouched) |
| C4 | refund/cancel actions unaffected (no validator call) — guards the follow-up boundary |

GREEN: inject the two new deps (appended ctor args), implement the early-return gate
(§4.1 flow). Extract a private `validateCaptureReason(Order, array): bool` helper to keep
`handleCapture()` inside the line budget.

REFACTOR check: `parseString()` / `parseAmount()` untouched (amount is out of scope).

### Phase D — view-data + template + admin translations

RED (`tests/Unit/Stripe/Admin/StripePanelViewDataBuilderTest.php` — mocked feedback + a real
`UserDataValidationMessageFormatter` over stubbed `LanguageTranslatorInterface` and a real
`AllowedSymbolsDescriber`):

| # | Behaviour |
|---|---|
| D1 | feedback entries → `viewData['validationErrors']` = formatted strings (template `%1$s/%2$s` filled with label + allowed symbols) |
| D2 | no feedback → key absent or `[]` (template-safe either way, pin one) |
| D3 | consume-once: builder calls `consume()` exactly once per build |
| D4 | unknown label key → formatter falls back to raw field name (already pinned in Sprint 119 — extend for `captureReason` only if the label key is absent in test stub) |

GREEN: builder wiring + Twig block (§4.7) + admin lang keys (§4.6, both locales).

Manual verify (memory: opcache + module config):
```bash
docker compose exec php bin/oe-console oe:cache:clear   # expect "Cleared cache files" — silence = fail
docker compose restart php                              # opcache does not react to cache:clear
rm -rf source/tmp/*                                     # Twig cache after template change
```
Then on the admin tab: capture with reason `<test>` → red alert, NO Stripe Dashboard activity,
transaction history unchanged; capture with reason `Teillieferung #2` → succeeds.

### Phase E (optional, time-boxed) — Playwright admin spec

`tests/e2e/playwright/playwright/tests/admin/stripe-capture-reason-validation.spec.ts`
(`--project=admin-tests`): submit invalid reason → `[data-testid=stripe-admin-validation-error]`
visible, capture form still present; submit valid → capture flow proceeds. Playwright is
manual-trigger-only in CI (`a19aac0`) — run locally, do not gate the sprint on it.

---

## 6. Files touched (complete list)

**New:**
- `src/Stripe/Admin/AdminValidationFeedbackInterface.php`
- `src/Stripe/Admin/AdminValidationFeedback.php`
- `tests/Unit/Stripe/Admin/AdminValidationFeedbackTest.php`
- `tests/Unit/Stripe/Admin/StripePaymentPanelProviderTest.php` (if not existing)
- `tests/Unit/Stripe/Admin/StripePanelViewDataBuilderTest.php` (if not existing)
- (Phase E) `tests/e2e/.../admin/stripe-capture-reason-validation.spec.ts`

**Modified:**
- `src/Resources/validation-rules.php` (+1 entry)
- `src/Stripe/Admin/StripePaymentPanelProvider.php` (gate; +2 ctor args)
- `src/Stripe/Admin/StripePanelViewDataBuilder.php` (consume + format; +2 ctor args)
- `views/twig/admin/panel/stripe_panel.html.twig` (alert block)
- `views/admin_twig/en/stripe_lang.php`, `views/admin_twig/de/stripe_lang.php` (+5 keys each)
- `services.yaml` (1 new service, 2 arg additions, possibly 1 exclude entry)
- `tests/Unit/Stripe/Service/UserDataValidatorTest.php` (+6 cases)
- `tests/Unit/Stripe/Service/ValidationRulesProviderTest.php` (count 13→14, +1 assert)
- `tests/Unit/Stripe/Service/AllowedSymbolsDescriberTest.php` (+1 case)

## 7. Known ripple effects (verify, don't get surprised)

1. **`ValidationRulesProviderTest:44` asserts `assertCount(13, …)`** — Phase A flips it to 14.
   Grep for other count assertions over the map before starting:
   `grep -rn "assertCount(13" tests/`.
2. **OPC footer ships the new field**: `StripeCheckoutFooter::getValidationFieldAllowed()`
   iterates the whole map → `captureReason` appears in the storefront `data-…-allowed-value`
   attribute. Harmless (the JS only looks up fields present in error responses). Check
   `StripeCheckoutFooterTest` for full-map assertions and extend if it pins exact contents.
3. **Central frontend endpoint now "knows" `captureReason`**: `cl=oepaymentvalidationapi`
   validates whatever field map is posted, so the storefront could validate `captureReason` —
   read-only validation, no data exposure beyond the allow-list string already shipped in the
   footer attribute. Acceptable; note in completion report.
4. **PHPMD baseline must stay at 3 entries** — the provider gains one small private method; if
   WMC complains, extract, don't suppress.

## 8. Risks & rollback

| Risk | Mitigation |
|---|---|
| Admin lang keys missed → raw `STRIPE_VALIDATION_…` shown | Phase D manual verify in BOTH locales; formatter's raw-field-name fallback keeps the message readable even then |
| Session feedback leaks across tabs/orders | per-order session key + read-once `consume()` (B4/B5 pin it) |
| services.yaml edit breaks activation | `oe:module:activate` smoke after wiring; autowire-sweep exclude check (§4.5) |
| Rules too strict for real-world reasons (e.g. `&`, `;`) | allow-set decision is one line in the rules file; loosening is a config-only change + 1 test row |
| Existing capture E2E flows break | C2/C3 pin the pass-through path; reason remains optional |

Rollback = remove the rules entry (validation deactivates, unknown field → valid) — the rest of
the machinery is inert without it.

## 9. Definition of Done

- [ ] All phases RED→GREEN; one commit per phase, `STRP-129` prefix
- [ ] Invalid `capture_reason` → event NOT dispatched, no Stripe call, admin sees translated
      message with allowed-symbols list (en + de verified manually)
- [ ] Empty reason → capture proceeds exactly as before
- [ ] `./bin/pre-commit-check.sh --full` green; PHPCS 0, PHPStan level max 0, PHPMD baseline
      unchanged (3 entries), zero new suppressions
- [ ] No payment-base file modified (`git -C ../payment-base status` clean)
- [ ] paypal + one-page-checkout untouched
- [ ] Completion report in `done/` with per-phase test-count deltas; ripple-effect items from §7
      explicitly confirmed

## 10. Follow-ups seeded by this sprint (NOT in scope)

1. **Sprint 12x — capture/refund amount validator** (semantic: malformed≠null, >0, ≤capturable
   from the PI-derived source, currency precision) — fixes the silent full-capture/full-refund
   footgun in `StripePaymentPanelProvider::parseAmount()`.
2. **Sprint 12x — refund/cancel reason + description** rules entries reusing
   `AdminValidationFeedback` as-is.
3. Possible memory note after completion: admin lang files (`views/admin_twig/`) vs storefront
   (`translations/`) resolution split for `translateString()` in admin context.
