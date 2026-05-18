# Sprint 107 — Busy overlay (blur + spinner) on admin Refund / Capture / Cancel

**Module:** `extensions/stripe`
**Mode:** single atomic commit. One template edit, one small CSS
addition, one small JS file. ~80 lines of total diff.
**Trigger:** UX request — after an admin confirms the
JavaScript `confirm()` popup on Refund / Capture / Cancel, the
panel should immediately enter a "busy" state (blurred + spinner)
so the operator cannot fire a second mutating action before the
in-flight one navigates the page.
**Relation to Sprint 106:** independent and complementary. 107 is
the small UX fix the operator actually feels; 106 (async fragment
loader) is shelved or deferred — 107 ships first.

## 1. Why

The panel already gates the three mutating buttons behind
`onclick="return confirm(…)"` (template lines 340 / 372 / 412).
If the operator clicks OK they intend to commit; once committed,
the form POSTs and the admin reloads. But between "OK" and the
reload the page is fully interactive — the operator can:

- Tab back and click *Cancel Authorization* between *Capture* and
  the reload (contradictory action).
- Double-click *Refund* (some browsers send two form submissions
  if the request takes more than a few hundred ms).
- Navigate to a different tab and lose the in-flight result
  context.

The fix is purely client-side: as soon as the form starts
submitting (which only happens after `confirm()` returned `true`),
freeze the panel — blur the content, show a spinner, disable
every interactive element inside the panel. The freeze ends when
the page navigates away, which is its natural lifecycle.

No backend change. No new endpoint. No idempotency token. The
backend's existing admin action handling stays exactly as today.

## 2. Goals

- **G1.** On Refund / Capture / Cancel form submission, the panel
  enters busy state immediately and stays there until navigation.
- **G2.** Busy state = panel content is visually blurred
  (`backdrop-filter: blur(2px)` and `opacity: 0.6`), every
  `button` / `input` / `select` / `a` inside the panel is
  non-interactive (`pointer-events: none`), and a centered
  spinner is visible.
- **G3.** The confirm() popup is unchanged — the busy state only
  activates *after* the operator clicks OK. Clicking Cancel does
  not show the spinner.
- **G4.** No regression to the existing form-submit success
  path. The page still reloads, the success flash banner still
  appears post-reload.
- **G5.** Keyboard users are not trapped — the spinner placeholder
  has `aria-busy="true"`, the focus is moved to the spinner so
  screen-readers announce the wait, and `Escape` is *not* wired
  to a "cancel busy" action (the operation is server-side, the
  client cannot abort it).
- **G6.** `./bin/pre-commit-check.sh --full` green.

## 3. Scope inventory

| File | Change |
|---|---|
| `views/twig/admin/panel/stripe_panel.html.twig` | (a) Wrap the panel body in `<div data-stripe-panel-busy>`. (b) Tag each of the three action forms with `class="js-stripe-action-form"`. No change to the existing `onclick="return confirm(...)"` attributes — the form submit listener is the new hook. |
| `out/twig/admin/css/stripe_panel.css` (or the existing module CSS bundle — locate via metadata) | Add ~25 lines: `.stripe-panel-busy { ... }`, `.stripe-spinner { ... }`, the inline SVG keyframes. |
| `out/twig/admin/js/stripe-panel-busy.js` (new) | ~30 lines. Vanilla DOM, no jQuery. Binds one `submit` listener to every `.js-stripe-action-form`. On submit fire: add `stripe-panel-busy` class to the wrapper, set `aria-busy="true"`, move focus to the spinner. |
| `metadata.php` | If the CSS / JS bundle is registered there, add the new file. If the CSS lives in a single bundle that is auto-loaded, no change. |
| `tests/e2e/playwright/playwright/tests/admin/stripe-tab-busy-overlay.spec.ts` (new) | Playwright spec — confirm OK → spinner visible & inputs disabled → reload → spinner gone. |

### Explicitly *not* touched

- The three admin controllers (`OrderRefund`, action dispatcher)
  and any backend service. The busy overlay is presentation only.
- The existing `confirm()` strings and their translations.
- Sprints 104 / 105 / 106 work, the Stripe tab tests, the panel
  view-data builder.
- The contract state machine, the webhook handlers.
- `services.yaml`.

## 4. Design

### 4.1 The hook — `submit` event, not `click`

`<input type="submit" onclick="return confirm(...)">` returns
`false` from the inline handler when the operator clicks Cancel,
which aborts the form submission. When the operator clicks OK,
the handler returns `true`, and only then does the browser fire
the `submit` event on the form. Listening on `submit` therefore
correctly fires only on confirmed actions — no need to refactor
the confirm() machinery.

### 4.2 Marking the form

The three forms need one class added (see template lines 333,
365, 405 — the exact `<form>` tags wrapping each action button).
`class="js-stripe-action-form"` is enough; the JS attaches via
`document.querySelectorAll('.js-stripe-action-form')`.

### 4.3 The JS controller (full content, for review in the sprint)

```js
// out/twig/admin/js/stripe-panel-busy.js
(function () {
  function enterBusy(panel) {
    if (!panel) return;
    panel.classList.add('stripe-panel-busy');
    panel.setAttribute('aria-busy', 'true');
    const spinner = panel.querySelector('.stripe-spinner');
    if (spinner) spinner.focus();
  }

  function init() {
    const panel = document.querySelector('[data-stripe-panel-busy]');
    if (!panel) return;
    document.querySelectorAll('.js-stripe-action-form').forEach((form) => {
      form.addEventListener('submit', () => enterBusy(panel), { once: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

### 4.4 The CSS

```css
[data-stripe-panel-busy] { position: relative; }

.stripe-panel-busy > *:not(.stripe-spinner) {
  opacity: 0.5;
  filter: blur(2px);
  pointer-events: none;
  user-select: none;
}

.stripe-spinner {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 48px; height: 48px;
  display: none;
  border: 4px solid #cccccc;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: stripe-spin 0.8s linear infinite;
  outline: 0;
}

.stripe-panel-busy .stripe-spinner { display: block; }

@keyframes stripe-spin { to { transform: translate(-50%, -50%) rotate(360deg); } }
```

The spinner sits *inside* `data-stripe-panel-busy` but receives
the `display:block` only when the wrapper has `.stripe-panel-busy`.
It is excluded from the blur-and-fade rule via `*:not(.stripe-spinner)`.

### 4.5 Template addition

```twig
{# views/twig/admin/panel/stripe_panel.html.twig #}
<div data-stripe-panel-busy>
  {# ... existing panel content ... #}

  {# Spinner placeholder — invisible until .stripe-panel-busy is set #}
  <div class="stripe-spinner" role="status" tabindex="-1"
       aria-label="{{ translate({ ident: 'STRIPE_BUSY' }) }}"></div>
</div>
```

Plus the three forms gain `class="js-stripe-action-form"`. No
other template change.

### 4.6 Why this is much smaller than Sprint 106

Sprint 106 splits the panel into a synchronous skeleton + an
async-loaded fragment, with a new admin XHR endpoint, a new
controller, a fragment template, and bidirectional state
synchronisation between the JS controller and the panel state.
That is the right design *if* the goal is "make first paint
instant on cold blob." It is the wrong design if the goal is
"don't let the operator fire two mutating actions back-to-back" —
that goal is solved by ~80 lines of presentation code.

107 leaves the synchronous render exactly as today (after Sprint
104 it is one Stripe API call; after Sprint 105 it is zero in
the warm case). The freeze covers only the *mutating-action gap*,
which is the window the operator actually misuses.

## 5. The five pillars — applied with restraint

This sprint is small and largely presentational; over-justifying
SOLID would be performative. Briefly:

- **SRP.** The JS file does one thing: tag the panel as busy on
  form submit. The CSS does one thing: style the busy state.
- **OCP.** Adding a fourth mutating action later means adding
  `class="js-stripe-action-form"` to its `<form>`. No JS change.
- **LSP.** No class hierarchy involved.
- **ISP.** No interface involved.
- **DIP.** N/A (no DI).
- **TDD.** Playwright spec (§6) is written first. It fails until
  the template + JS + CSS land.
- **DRY.** The busy-state styles live in one CSS block; the
  busy-state activation lives in one JS function.
- **Clean Code.** ≤ 80 lines of total new code across JS + CSS.
- **Liskov.** N/A.

## 6. Test matrix

### 6.1 Playwright spec — `stripe-tab-busy-overlay.spec.ts`

```ts
test('busy overlay activates on refund confirm', async ({ page }) => {
  // Pre: an order with a captured charge.
  await page.goto('/admin/...');               // open Stripe tab on order A
  await page.getByRole('tab', { name: 'Stripe' }).click();
  await page.waitForSelector('[data-stripe-panel-busy]');

  // Pre-confirm: spinner is NOT visible, inputs are interactive.
  await expect(page.locator('.stripe-spinner')).toBeHidden();
  await expect(page.locator('input[name="refundAmount"]')).toBeEnabled();

  // Intercept the confirm() popup and accept it.
  page.once('dialog', (d) => d.accept());

  // Submit the refund form.
  await page.locator('input[name="refundAmount"]').fill('1.00');
  await page.getByRole('button', { name: /refund/i }).click();

  // Immediately after confirm OK and before the navigation completes,
  // the panel must be in busy state.
  await expect(page.locator('.stripe-spinner')).toBeVisible();
  await expect(page.locator('[data-stripe-panel-busy]'))
    .toHaveClass(/stripe-panel-busy/);

  // After navigation, the busy state is gone (the page reloaded).
  await page.waitForLoadState('networkidle');
  await expect(page.locator('.stripe-spinner')).toBeHidden();
});

test('busy overlay does NOT activate when operator cancels confirm', async ({ page }) => {
  await page.goto('/admin/...');
  await page.getByRole('tab', { name: 'Stripe' }).click();

  page.once('dialog', (d) => d.dismiss());        // operator clicks Cancel

  await page.locator('input[name="refundAmount"]').fill('1.00');
  await page.getByRole('button', { name: /refund/i }).click();

  await expect(page.locator('.stripe-spinner')).toBeHidden();
  await expect(page.locator('[data-stripe-panel-busy]'))
    .not.toHaveClass(/stripe-panel-busy/);
});

test('busy overlay covers capture and cancel-authorization equally', async ({ page }) => {
  // Same shape as the refund test, but on a manual-capture order.
  // Verifies all three .js-stripe-action-form submits fire the handler.
});
```

Three Playwright cases; runs in the existing `admin-tests`
project.

### 6.2 Manual smoke

| # | Scenario | Expected |
|---|---|---|
| 1 | Click Refund → OK in confirm | Spinner visible immediately; panel blurred; inputs un-clickable. Page reloads; spinner gone. |
| 2 | Click Refund → Cancel in confirm | Nothing happens. No spinner, no blur. |
| 3 | Click Refund → OK → try to click Capture before reload | The Capture button is in the blurred area with `pointer-events: none`; cannot be clicked. |
| 4 | Slow network throttling (3G), click Refund → OK | Spinner remains visible for the entire ~1 s in-flight window. |
| 5 | Keyboard nav: Tab to Refund → Enter → OK in confirm dialog | Spinner takes focus; `aria-busy` is announced by VoiceOver / NVDA. |

## 7. Acceptance gates

- [ ] `./bin/pre-commit-check.sh --full` green. (No PHP changes, but
      assets must clear PHPCS/PHPMD if they touch any PHP file —
      this sprint should not.)
- [ ] Playwright spec from §6.1 passes locally.
- [ ] Manual smoke from §6.2, all five scenarios.
- [ ] No diff to admin controllers, services, webhook handlers,
      `services.yaml`, `metadata.php` (unless CSS/JS bundling
      requires the asset registration there — note in §3 then).
- [ ] No regression to the existing success-flash flow.

## 8. Out of scope / explicit deferrals

- **Sprint 106 (async fragment loader).** Shelved pending demand.
  107 covers the in-flight-action UX gap on its own.
- **Server-side idempotency token on the mutating endpoints.**
  Belt-and-braces; not needed for the UX fix and the existing
  CSRF tokens already prevent cross-site doubles. The
  same-session double-click case is closed by 107's freeze. A
  server-side token can be filed as a follow-up if the freeze
  proves insufficient (e.g. with browser back-button replay).
- **Loading skeleton on the very first panel paint.** That is
  the Sprint 106 problem; 107 only covers the mutating-action
  window.
- **Animation tuning, spinner branding, dark-mode.** The CSS in
  §4.4 is the minimum acceptable visual; design polish is
  separate.

## 9. Risk register

- **Risk.** A browser extension or operator script aborts the
  form submission after the `submit` event fires. The panel
  ends up stuck in busy state with no navigation.
  **Mitigation.** Add `setTimeout(() => panel.classList.remove(
  'stripe-panel-busy'), 30000)` as a hard release. 30 s is
  longer than any legitimate Stripe action; the operator gets
  control back if something is genuinely wrong.
- **Risk.** Form submit handlers are bound `once: true`; if the
  operator cancels the confirm() on a re-rendered form, the
  listener has already detached. **Mitigation.** Drop the
  `{ once: true }` option — the listener is idempotent
  (`classList.add` on an already-added class is a no-op).
- **Risk.** The `backdrop-filter: blur(...)` rule is unsupported
  on very old browsers. **Mitigation.** The `opacity: 0.5`
  fallback alone is sufficient to communicate "do not click."
  No JS-side polyfill needed.
- **Risk.** Operators on slow connections see the spinner for
  long enough to think it's hung and refresh.
  **Mitigation.** A page refresh during an in-flight Stripe
  request is harmless — the action either completed
  server-side (idempotent at Stripe via the request's
  `Idempotency-Key`) or did not. The next render shows the
  authoritative state.

## 10. Done definition

- [ ] §7 acceptance, every box.
- [ ] Sprint markdown moves to `done/` with a completion report
      including a GIF / screenshot sequence of the busy
      overlay activating and de-activating.
- [ ] `status.md` updated.
