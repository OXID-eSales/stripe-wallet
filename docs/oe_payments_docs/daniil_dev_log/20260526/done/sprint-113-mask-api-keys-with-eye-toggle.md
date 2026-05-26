# Sprint 113 — Mask API keys with `type="password"` + eye-toggle button

**Module:** `extensions/stripe`
**Branch base:** `b-7.4.x-webhook-STRP-144` or fresh off `b-7.4.x`
**Mode:** TDD-first. Single feature branch, one PR.
**Sprint principles:** TDD, SOLID, DI, Liskov, DRY, Clean Code, no overengineering.

## 1. Why

The Module Configuration admin page renders 7 sensitive Stripe credentials in plaintext inside `<input type="text">` form fields:

- `sStripeTestToken` / `sStripeLiveToken` — OAuth connected-account access tokens (charge authority)
- `sStripeTestKey` / `sStripeLiveKey` — Platform-account secret keys (webhook management; can read all Stripe data)
- `sStripeTestPk` / `sStripeLivePk` — Publishable keys (lower-sensitivity but still account-identifying)
- `sStripeWebhookEndpointSecret` — Webhook signing secret (anyone with it can forge webhooks)

Anyone walking past an admin's screen can read these in seconds.

**Approach:** swap the input type from `text` to `password` in the existing parallel template so the browser dots all characters out natively. Pair with a small cross-browser eye-toggle button that switches the input between `password` and `text` modes — Chrome/Edge ship their own built-in eye icon for `<input type="password">` but Firefox and Safari don't, so a custom button normalizes the UX.

**This is a shoulder-surfing mitigation, not a security control.** The full key still lives in the input's `value` attribute and can be inspected via dev tools. Real protection would require server-side never-render-after-save patterns — explicitly out of scope (§3).

### Why not the original "first-15-visible + custom overlay" approach

Considered and rejected. The first-15-visible UX is a nicety, not a protection — the threat model is shoulder-surfing, and a passer-by reading `sk_test_51TEpLE` + a string of dots gains nothing actionable over reading just dots. Building a custom overlay to render a hybrid masked-prefix display would cost ~150 LOC + ~90 test LOC for a UX that browser-native `type="password"` already covers in ~30 LOC total. Per the no-overengineering principle: use the platform's native primitive.

## 2. Goals

- **G1.** All 7 sensitive fields render as `<input type="password">` by default — browser dots out the entire value uniformly.
- **G2.** A small eye-icon `<button>` sits adjacent to each sensitive field. Click toggles the input between `type="password"` (masked, default) and `type="text"` (revealed).
- **G3.** Form submission round-trips the value unchanged. Both `type="password"` and `type="text"` submit the same string, so this is automatic — but the Playwright spec asserts it explicitly.
- **G4.** No PHP changes. No new HTTP endpoint. No server-side rendering of masked values.
- **G5.** Accessible: button has a localized `aria-label` that flips between "Reveal" and "Hide" on toggle; `aria-pressed` reflects state; button is keyboard-operable (Enter/Space).
- **G6.** `npm run build` clean. `./bin/pre-commit-check.sh --full` green (no PHP touched, so PHPCS/PHPStan/PHPMD are unaffected).

## 3. Out of scope

| Item | Why deferred |
|---|---|
| Server-side never-render-after-save | Different security model; needs schema change to distinguish "has-value" from "the-value" plus admin UX rework. Separate sprint. |
| Encryption-at-rest in `oxconfig` | Significant change touching `ModuleSettingBridge`. Separate sprint. |
| Audit log of reveal-click events | Speculative until a real compliance ask exists. |
| Auto-hide on inactivity timer | Adds complexity without clear ask. |
| Copy-to-clipboard button | Not requested. |
| Metadata.php `type: password` | No-op for these specific fields because the parallel template (`views/twig/extensions/themes/admin_twig/module_config.html.twig`) overrides the rendering — changing metadata alone wouldn't reach the rendered HTML. Skipped to avoid misleading "we changed it but nothing happened" diffs. |

## 4. Implementation plan

LOC budget: **~30 prod (Twig + ~20 LOC JS + ~10 LOC CSS) + ~90 test (Playwright)**. No PHP. Total churn under 130 LOC including the test spec.

### Step 1 — RED Playwright spec

**Why first:** without a behavioural test pinning the masked-by-default + eye-toggle UX, any subsequent refactor can silently regress.

**File:** `tests/e2e/playwright/playwright/tests/admin/stripe-api-key-mask.spec.ts`

Parametrize over the 7 field names and assert:

1. Page loaded → input has `type="password"` and a non-empty `value`.
2. Adjacent eye button exists, has `aria-pressed="false"` and a non-empty `aria-label`.
3. Click the eye → input now has `type="text"`; `aria-pressed="true"`; `aria-label` flipped to the "Hide" string.
4. Click again → back to `type="password"`; `aria-pressed="false"`; `aria-label` back to "Reveal".
5. Button is keyboard-reachable: `Tab` to it, press `Enter` — toggles. Press `Space` — toggles.
6. Save the form (or intercept the POST) → the field value in `request.postData()` is the same string regardless of the input's current `type`.

Spec file size: ~120 LOC for 7 × 6 assertions = 42 checks, parametrized.

### Step 2 — GREEN: Twig template edit

Edit `views/twig/extensions/themes/admin_twig/module_config.html.twig`. Apply to **5 distinct `<input>` blocks** (some fields share the same block by branching):

- Line 21 — `sStripeTestToken` / `sStripeLiveToken` (the `readonly disabled` token field)
- Line 39 — `sStripeTestPk` / `sStripeLivePk` (the editable publishable-key field)
- Lines for `sStripeTestKey` / `sStripeLiveKey` (the editable platform-key field — locate during implementation)
- Line 186 region — `sStripeWebhookEndpointSecret`

For each:

```twig
{# Sprint 113: mask sensitive credentials with type=password + custom toggle. #}
<span class="stripe-key-field">
    <input type="password" name="confstrs[{{ module_var }}]" value="{{ confstrs[module_var] }}" {{ readonly }} class="txt" style="width: 250px;" data-stripe-secret>
    <button type="button" class="stripe-key-toggle"
            aria-label="{{ translate({ ident: 'STRIPE_REVEAL_API_KEY' }) }}"
            data-label-reveal="{{ translate({ ident: 'STRIPE_REVEAL_API_KEY' }) }}"
            data-label-hide="{{ translate({ ident: 'STRIPE_HIDE_API_KEY' }) }}"
            aria-pressed="false">
        {# inline SVG eye icon, 16x16, currentColor #}
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">…</svg>
    </button>
</span>
```

The wrapper `<span>` exists so the button can be CSS-positioned adjacent to the input without restructuring OXID's `<dl><dt>` layout.

Add a single `<style>` block at the top of the parallel template for the wrapper layout + button styling. Inline (~10 LOC) — no separate CSS file, follows the existing inline-style convention in this template.

### Step 3 — GREEN: vanilla-JS toggle class

**File:** `assets/js/stripe-key-toggle.js` (raw source). Register as an entry in `resources/esbuild/config.js` if the existing `stripe-admin` bundle is suitable; otherwise build a standalone admin-mask bundle.

```js
class StripeKeyToggle {
  static init(root = document) {
    root.querySelectorAll('.stripe-key-toggle').forEach(button => new StripeKeyToggle(button));
  }

  constructor(button) {
    this.button = button;
    this.input = button.parentElement.querySelector('input[data-stripe-secret]');
    this.labelReveal = button.dataset.labelReveal || 'Reveal';
    this.labelHide = button.dataset.labelHide || 'Hide';
    button.addEventListener('click', () => this.toggle());
  }

  toggle() {
    const revealing = this.input.type === 'password';
    this.input.type = revealing ? 'text' : 'password';
    this.button.setAttribute('aria-pressed', revealing ? 'true' : 'false');
    this.button.setAttribute('aria-label', revealing ? this.labelHide : this.labelReveal);
  }
}

document.addEventListener('DOMContentLoaded', () => StripeKeyToggle.init());
```

~20 LOC. Single responsibility (SRP): toggle one input's `type` attribute. No persistence, no AJAX, no logging.

**Why a plain class, not a Stimulus controller:** the existing admin bundle has no Stimulus app instance (per inspection of `assets/js/stripe-admin.min.js` build target). Introducing one for a 20-LOC toggle is overengineering. Vanilla DOM is the proportional response.

### Step 4 — Lang keys (en + de)

Add to `views/admin_twig/en/stripe_lang.php` and `de/stripe_lang.php`:

```php
'STRIPE_REVEAL_API_KEY' => 'Reveal API key',    // en
'STRIPE_HIDE_API_KEY'   => 'Hide API key',
```

```php
'STRIPE_REVEAL_API_KEY' => 'API-Schlüssel anzeigen',  // de
'STRIPE_HIDE_API_KEY'   => 'API-Schlüssel verbergen',
```

### Step 5 — Build + cache clear + Playwright RED→GREEN

```bash
cd source/extensions/stripe
npm run build
cd /home/dtkachev/osc/strpwt7-nov26
docker compose exec -T php bin/oe-console oe:cache:clear   # admin Twig template cache invalidation
docker compose restart php                                  # OXID class chain doesn't need it; Twig template cache does sometimes
```

Run the Playwright spec — should now pass.

### Step 6 — Existing Sprint 110/111 AJAX integration check

The webhook-creation AJAX action (Sprint 110/111) writes the issued whsec back into `input[name="confstrs[sStripeWebhookEndpointSecret]"]`. With the input type now `password`, the write should still work (it's setting `.value`, not `.type`). Verify by:

1. Click "Create webhooks" in admin.
2. Observe: secret input updates with a new `whsec_…` value, still masked (dotted) because the input is `type="password"`.
3. Click the eye → full new secret visible.

No code change expected — but the Playwright spec should include this scenario to lock in the integration.

## 5. Files touched (estimate)

```
M  views/twig/extensions/themes/admin_twig/module_config.html.twig   # 5 input blocks: text→password, add wrapper + button, add <style>
A  assets/js/stripe-key-toggle.js                                     # ~20 LOC, single class
M  resources/esbuild/config.js                                        # add new entry OR merge into stripe-admin entry
M  views/admin_twig/en/stripe_lang.php                                # +2 lang keys
M  views/admin_twig/de/stripe_lang.php                                # +2 lang keys
A  tests/e2e/playwright/playwright/tests/admin/stripe-api-key-mask.spec.ts  # ~120 LOC parametrized
```

Rebuilt assets (committed):

```
M  assets/js/stripe-admin.min.js   # produced by `npm run build`
```

## 6. Quality gates

| Tool | Threshold |
|---|---|
| `./bin/pre-commit-check.sh --full` | green (PHPCS/PHPStan/PHPMD untouched — no PHP edits) |
| `npm run build` | exits 0; bundle delta < 1 KB minified |
| Playwright spec | 100% pass; 42 checks across 7 fields |

## 7. TDD discipline reminder

The Playwright spec must exist and **fail** before any production change lands. Verify:

```bash
ls tests/e2e/playwright/playwright/tests/admin/stripe-api-key-mask.spec.ts && \
  cd tests/e2e/playwright/playwright && npx playwright test tests/admin/stripe-api-key-mask.spec.ts
# Expect: fail (toggle not implemented; inputs still type=text)
```

Then implement Steps 2–4 and re-run. **If the spec ever went green without a failing-first cycle, you wrote a regression check, not TDD.**

## 8. Risk and rollback

- **Risk: `type="password"` triggers password-manager autofill** for the Stripe key field. Mitigation: set `autocomplete="off"` on the input. Verify in Chrome + Firefox by checking the autofill behaviour with a saved test credential.
- **Risk: form submission breaks** if some downstream code reads `input.type` rather than `input.value`. Mitigation: Playwright Step 1 case 6 asserts `request.postData()` integrity. If a breakage surfaces, fix the consumer to read `value`.
- **Risk: eye button styling collides** with the existing webhook-section "Create webhooks" / "Clear all" buttons. Mitigation: scope the CSS to `.stripe-key-field .stripe-key-toggle` — class names are namespaced.
- **Risk: Sprint 110/111 AJAX-write integration breaks** because the new whsec value setter triggers some event the toggle listens to. Mitigation: the toggle only listens to `click` on its own button; setting `input.value` from elsewhere is transparent to it. Step 6's manual check verifies.

**Rollback:** revert the single PR. No DB migration, no schema change, nothing to undo server-side.

## 9. Definition of Done

- Playwright spec passes locally.
- All 7 fields render as `type="password"` by default.
- Eye toggle works on each (mouse + keyboard).
- `aria-label` and `aria-pressed` flip correctly with state.
- Form submission round-trips the value unchanged (Playwright network assertion).
- `npm run build` exits 0.
- `./bin/pre-commit-check.sh --full` green.
- Sprint 110/111 "Create webhooks" / "Clear all" buttons still functional (manual smoke).
- Sprint plan moves `sprints/` → `done/sprint-113-completion-report.md` with outcome summary.
- Status.md row for this sprint flipped to ✅.

## 10. Not committed by this sprint

- Live manual smoke in a real browser at multiple zoom levels (100% / 150% / 200%) and across Chrome + Firefox + Safari.
- Screen-reader walkthrough with NVDA / VoiceOver — accessibility lint passes don't substitute for a real AT smoke.
- Verification that Stripe Dashboard's own eye-icon pattern is followed (visual consistency with Stripe's UX).
