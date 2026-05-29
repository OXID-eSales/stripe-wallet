# User-Data & Address-Field Validation in the Stripe Module

**Date:** 2026-05-29
**Branch:** `b-7.4.x-user-data-filter-STRP-129` (HEAD == `b-7.4.x` at `12cb786` — **no STRP-129 commits exist yet**; this branch is placeholder for the work below)
**Scope:** Trace every layer of validation that touches user, address, or basket data on the path from "Place Order" to `Stripe\Checkout\Session::create()` / `Stripe\PaymentIntent::create()`. Answer four questions: single source of truth, pre-Stripe validation, per-field rules, OXID-provided primitives.

---

## TL;DR

| Question | Answer |
|----------|--------|
| Single source of truth for user/address validation? | **No.** Validation is split across OXID core (`RequiredFieldsValidator`, `InputValidator`, `User::checkValues`), the OXID `Order` model (`validateDeliveryAddress`, `validateOrder`), and the Stripe module (`ConfigurationValidator`, `CustomerDataSanitizer`, `ContractCreationHandler` base). The Stripe module **deliberately bypasses** OXID's `validateDeliveryAddress` for its own flow. |
| Validation before Stripe-adapter call? | **Partial.** API keys, basket existence, min order amount, and userId non-empty are checked. Address-field format, country/currency re-validation, and email format are **not** re-checked at the Stripe boundary — the module trusts OXID's prior save. |
| Per-field rule sets? | **Effectively one rule: "non-empty (trim)" for the OXID-configured `aMustFillFields` list.** No format/length/regex checks anywhere — neither in OXID core nor in the Stripe module. Email uniqueness and country-ID existence are the only structural checks, and both fire at registration/save time, not at checkout. |
| What does OXID provide? | `InputValidator` (login/password/country/VAT), `User::checkValues` (registration-time), `RequiredAddressFields` (configurable required-field list), `RequiredFieldsValidator` (non-empty enforcement), `Order::validateOrder` (full chain). The Stripe module uses **only** `RequiredFieldsValidator` indirectly via the parent `Order` model — and only when the bypass flag is absent. |

**Risk verdict:** Low operationally (address is not currently sent to Stripe — `PaymentMethodHelper` wiring exists but no callsite populates `billingAddress`). Medium structurally: if a future Payment Element path activates that wiring, there is **no input filter at the Stripe boundary**. STRP-129 is the right ticket to introduce one.

---

## 1. Entry-point trace (request → Stripe API)

### Checkout Session flow (primary)
1. `StripeOrderController::createCheckoutSession()` — `src/Stripe/Controller/StripeOrderController.php:162`
   - `validateSessionChallenge()` (CSRF) — line 167
   - `ensureAgbAccepted()` — line 174
   - `validateCheckoutPreconditions()` → `ConfigurationValidator::getKeyValidationError()` — line 199
   - Sets `stripe_skip_addr_check` session flag immediately before dispatch
2. `ControllerRequestHelper` extracts basket / user / payment ID from session — `src/Stripe/Controller/ControllerRequestHelper.php:86-94`
3. `StripeContractCreationHandler` (extends payment-base `ContractCreationHandler`) — `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php:31`
   - Base class validates: userId non-empty string, basket is object — `payment-base/src/EventSystem/Handler/ContractCreationHandler.php:75-101`
   - Creates contract + basket snapshot via `ContractService::createContract()`
   - `storeDeliveryAddressMetadata()` hashes address (MD5 + HMAC), does **not** validate it
4. `StripeCheckoutSessionHandler::extractCustomerData()` — `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php:199-224`
   - Reads `oxusername`, `oxfname`, `oxlname` from User
   - Pipes through `CustomerDataSanitizer::sanitize()` (UTF-8 normalise, strip control chars, truncate)
   - Returns NULL if fname/lname missing — safe fallback, customer email omitted
5. `CheckoutSessionService::createSession()` — `src/Stripe/Service/CheckoutSessionService.php:52-124`
   - Builds line items from basket snapshot — no address data in line items
   - Stripe params: `mode`, `line_items`, `success_url`, `cancel_url`, `metadata`, optional `customer`
6. `CheckoutSessionHelper::createCheckoutSession()` — `src/Stripe/Adapter/Helper/CheckoutSessionHelper.php:28-35`
   - `$client->checkout->sessions->create($params)` — **no address fields in `$params`**

### PaymentIntent flow (manual confirmation)
- `PaymentIntentHelper::buildPaymentIntentParams()` — `src/Stripe/Adapter/Helper/PaymentIntentHelper.php:231-270`
- Sends `amount`, `currency`, `capture_method`, `metadata`. No address.

### PaymentMethod flow (dormant — has billing-details wiring)
- `PaymentMethodHelper::createPaymentMethod()` — `src/Stripe/Adapter/Helper/PaymentMethodHelper.php:38-39`
  ```php
  if ($request->billingAddress !== null) {
      $params['billing_details'] = ['address' => $request->billingAddress];
  }
  ```
- **`$request->billingAddress` is never populated anywhere in the current code.** `grep -r "billingAddress:\|new CreatePaymentMethodRequest\|new CreatePaymentRequest\|new AuthorizePaymentRequest"` against both repos returns no callsite. The DTO fields (`AuthorizePaymentRequest.php:52`, `CreatePaymentRequest.php:46`, `CreatePaymentMethodRequest.php:40`) are defined and forwarded but unused. **Flag for STRP-129.**

### Order finalisation (after Stripe redirect)
- `Order::validateOrder()` — OXID core, `source/source/Application/Model/Order.php:2033-2064`
- Chain includes `validateDeliveryAddress()` at line 2050 — **overridden** by Stripe model

---

## 2. Inventory of explicit validators in the Stripe module

| Class / Method | Location | Fields checked | Rule | Where invoked | On failure |
|---|---|---|---|---|---|
| `ConfigurationValidator::getKeyValidationError()` | `src/Stripe/Service/ConfigurationValidator.php:32-67` | publishable + secret keys | Extract account ID, compare; format must match `pk_test_*` / `sk_test_*` etc. | `StripeOrderController::validateCheckoutPreconditions()` | RuntimeException → checkout rejected |
| `ControllerRequestHelper::validateSessionChallenge()` | `src/Stripe/Controller/ControllerRequestHelper.php:170-173` | PHP session token | `Registry::getSession()->checkSessionChallenge()` | `createCheckoutSession()`, `executeStripePayment()` | HTTP 403 |
| `ControllerRequestHelper::validateContractToken()` | `src/Stripe/Controller/ControllerRequestHelper.php:175-182` | `contractId`, `contractToken` | HMAC token check | `readReturnInputs()` | NULL → return-flow rejection |
| `PaymentController::validatePayment()` | `src/Stripe/Controller/PaymentController.php:106-135` | Configured? Min order amount? | `isConfigured()` + numeric compare | Standard OXID payment-step controller flow | Adds error, returns 'payment' |
| `Order::validateDeliveryAddress()` **(override)** | `src/Stripe/Model/Order.php:123-135` | basket payment ID, `stripe_skip_addr_check` flag | If Stripe + flag → return 0 (skip); else delegate to parent | `Order::validateOrder()` line 2050 | Returns parent's state (7 = invalid-deliv-addr-changed) |
| `Order::executePayment()` **(override)** | `src/Stripe/Model/Order.php:84-102` | basket payment ID | If Stripe → `return true` (skip OXID gateway) | `Order::finalize()` | Bypass; no validation |
| `CustomerDataSanitizer::sanitize()` | `src/Stripe/Service/CustomerDataSanitizer.php:25-47` | email, name (combined fname + lname) | UTF-8 enforce, strip control chars (except `\t \n`), collapse whitespace, char-boundary truncate | `StripeCheckoutSessionHandler::extractCustomerData()` line 211-217 | Returns sanitised string (never throws — it's a filter, not a validator) |

### What the module does **not** have
- No `UserDataValidator` / `AddressValidator` / `FieldValidator` class.
- No per-field rule (format, regex, length-min, country-conditional).
- No `validate*()` methods on any user/address data outside the table above.
- The webhook guards (`WebhookHttpsGuard`, `WebhookIpAllowlistGuard`, `WebhookPayloadSizeGuard`, `WebhookRateLimitGuard` under `src/Stripe/Controller/Webhook/`) are **inbound** — they protect Stripe → shop webhooks. They do **nothing** for outbound user data.

---

## 3. payment-base validators

| Class | Location | What it checks | Notes |
|---|---|---|---|
| `ContractCreationHandler::handle()` | `payment-base/src/EventSystem/Handler/ContractCreationHandler.php:75-101` | userId non-empty string, basket is object | InvalidArgumentException on failure |
| `ContractMetadataService::storeDeliveryAddressMetadata()` | `payment-base/src/Service/ContractMetadataService.php:44-68` | — (only hashes) | Stores MD5(address) + HMAC for tamper-detect (Sprint 68b M9), **does not validate** |
| `ContractService::createContract()` | `payment-base/src/Service/ContractService.php:22-49` | — | Extracts product items / discounts / totals via duck-typing; no validation |
| `BasketSnapshot` | `payment-base/src/ValueObject/BasketSnapshot.php` | — | Pure value object; immutable basket capture |

Per memory `feedback_payment_base_additive_only`: payment-base must remain additive. Any validation logic added there has to land with a safe default.

---

## 4. OXID-provided primitives

| OXID class / method | What it provides | When it fires (default OXID flow) | Does Stripe use it? |
|---|---|---|---|
| `InputValidator::checkLogin()` | Login uniqueness, password match on update | Registration / profile-update | ✗ Not invoked from Stripe code |
| `InputValidator::checkPassword()` | Password strength / confirm match | Registration / profile-update | ✗ |
| `InputValidator::checkCountries()` | Country ID exists in `oxcountry` table | User save | ✗ (Stripe payment filtering is config-driven only) |
| `InputValidator::checkVatId()` | VAT-ID format check | User save (when VAT entered) | ✗ |
| `User::checkValues()` | Wraps the above; runs at user save | `User::save()` | ✗ Stripe never calls `User::save()` on the checkout path |
| `RequiredAddressFields::getRequiredFields()` | Reads `aMustFillFields` config | `validateDeliveryAddress()` | ✓ Indirectly, via parent `Order` (gated by the bypass flag) |
| `RequiredFieldsValidator::validateFields()` | Iterates required fields → `RequiredFieldValidator::validateFieldValue()` | `Order::validateDeliveryAddress()` | ✓ Indirectly, parent path |
| `RequiredFieldValidator::validateFieldValue()` | **Single rule: trim + non-empty** (arrays accepted as non-empty if non-empty) | Same | ✓ Indirectly |
| `Order::validateOrder()` | Chain: stock → delivery → payment → **deliv-addr** → basket → vouchers | `Order::finalize()` | ✓ Always; only `validateDeliveryAddress` step is overridden by Stripe |
| `Order::validateDeliveryAddress()` | Hash compare (sDeliveryAddressMD5 form-param vs server-side) + required-fields | Same | **Bypassed** for Stripe when `stripe_skip_addr_check` flag set |

### What OXID actually enforces in practice
Two things:
1. **Required-fields non-empty.** Default list: `oxfname, oxlname, oxstreet, oxstreetnr, oxzip, oxcity`. Configurable via `aMustFillFields` admin-shop-config.
2. **Address-hash tamper detection.** Form posts `sDeliveryAddressMD5`; server recomputes and compares. Catches mid-session address change.

That's the entire surface. No format rules, no regex, no country-conditional rules, no street-number parsing.

---

## 5. Per-field rule sets (Stripe-payload perspective)

For every field that could end up in a Stripe API call:

| OXID field | Required? | Format rule | Country-conditional | Sanitisation before Stripe | Sent to Stripe today? |
|---|---|---|---|---|---|
| `oxuser__oxusername` (email) | ✓ default | Uniqueness only (User save) | — | `CustomerDataSanitizer` (UTF-8, strip ctrl, 320 cap) | ✓ as `customer` if email-prefill enabled |
| `oxuser__oxfname` | ✓ default | none | — | `CustomerDataSanitizer` (255 cap) | ✓ as part of `customer.name` |
| `oxuser__oxlname` | ✓ default | none | — | `CustomerDataSanitizer` | ✓ as part of `customer.name` |
| `oxuser__oxstreet` | ✓ default | none | — | — | ✗ (not in payload) |
| `oxuser__oxstreetnr` | ✓ default | none | — | — | ✗ |
| `oxuser__oxcity` | ✓ default | none | — | — | ✗ |
| `oxuser__oxzip` | ✓ default | none | — | — | ✗ |
| `oxuser__oxcountryid` | ✓ default | exists-in-DB (`checkCountries`, fires at user save) | — | — | ✗ |
| `oxuser__oxstateid` | conditional | none | — | — | ✗ |
| `oxuser__oxfon`, `oxmobfon` | conditional (config) | none | — | — | ✗ |
| `oxuser__oxcompany` | conditional (config) | none | — | — | ✗ |
| `oxaddress__*` (delivery) | as billing | none | — | — | ✗ |

**Stripe is currently fed two derived fields and nothing else:** sanitised email, sanitised concatenated name. Everything else is either kept shop-side or collected by Stripe Checkout on its hosted page.

---

## 6. Gaps and risks

### G1 — STRP-129 is unimplemented (branch is empty)
- `git diff b-7.4.x..HEAD` is empty; HEAD == `b-7.4.x` at `12cb786`.
- The branch name implies a user-data filter is planned. **Nothing has shipped.** This report is the scoping artefact.

### G2 — `PaymentMethodHelper` forwards billing address but no caller populates it
- `src/Stripe/Adapter/Helper/PaymentMethodHelper.php:38-39` will pass `billing_details.address` to Stripe **if** `CreatePaymentMethodRequest::$billingAddress` is non-null.
- Grep across both repos for callsites: zero matches. Dormant wiring.
- **Risk:** If a future contributor populates the DTO (e.g., for Payment Element vault flow), the address goes to Stripe with **no validation or sanitisation** — the sanitiser only covers email/name.
- **STRP-129 should:** decide whether (a) to ban the field at the DTO boundary or (b) plumb an address-sanitiser equivalent to `CustomerDataSanitizer`.

### G3 — `validateDeliveryAddress` bypass is intentional but undocumented as a security boundary
- The bypass in `src/Stripe/Model/Order.php:123-135` is well-documented in the docblock (R-3, R-8 markers — "narrow, flag-gated, cleared on completion").
- The bypass is **safe** because the address hash is computed from the form post, which is absent in the AJAX Checkout-Session creation call. Stripe later collects the address itself on its hosted page.
- **Recorded in memory as code-review-114 finding** (`project_code_review_114_latent_bugs`) — that note mentions a "blanket Stripe bypass." Re-reading the current implementation, the bypass is gated on both `StripeDefinitions::isStripePaymentMethod($paymentId)` **and** `isStripeSkipAddressCheck()`. The flag is set immediately before dispatch and cleared by `ControllerRequestHelper::clearStripeSessionVariables()`. Scope is narrow. **Update the memory finding to reflect this verification.**

### G4 — No backend re-validation of `aPaymentCountries` / `aPaymentCurrencies` filtering
- Settings exist (`blStripeRemoveByBillingCountry`, `blStripeRemoveByBasketCurrency`) and `ModuleConfigurationService` exposes getters.
- The filter only **hides** the payment method on the frontend. If a malicious request POSTs `paymentid=oe_payments_stripe_wallet` directly, no backend code re-checks "is this country/currency allowed for Stripe?" before `CheckoutSessionService::createSession()`.
- **Real-world fallout:** Stripe API would reject the unsupported currency, OXID would surface a generic error, no data leaks. Severity: low. But it's an inconsistency worth fixing.

### G5 — No pre-flight amount check against Stripe minima
- Stripe rejects payments below ~$0.50 (currency-dependent). The module doesn't pre-check.
- `PaymentController::validatePayment()` line 124 checks OXID's `sMinOrderPrice`, not Stripe's per-currency floor.
- **Fallout:** Stripe API returns an error, surfaced via `StripeExceptionConverter`. Annoying UX but not unsafe.

### G6 — Stripe module makes zero use of OXID's `InputValidator`
- Verified: `grep -rn "InputValidator\|checkValues\|aMustFillFields"` in `src/Stripe/` and `payment-base/src/` returns no hits beyond the unrelated `ReturnSessionSecurityService::validateUserAgent`.
- The module is deliberately decoupled from OXID's user-side validation. This is **defensible** — `InputValidator` is registration-time, not checkout-time. But it does mean the module has **no independent contract** about what shape its inputs take.

### G7 — Guest checkout has no extra validation layer
- `ControllerRequestHelper` does not distinguish guest vs. registered.
- If a guest's `User` object is missing `oxfname` / `oxlname`, `StripeCheckoutSessionHandler::extractCustomerData()` returns NULL and the Stripe call proceeds without `customer`. Safe fallback, but no warning to the operator that customer attribution will be missing.

### G8 — `ContractMetadataService` hashes addresses but cannot detect missing fields
- The HMAC tamper-check (Sprint 68b M9) is good for "did the address change between draft and commit?" but useless for "is this address actually fillable in the first place?"
- An empty address hashes to a deterministic empty-hash; both draft and return would agree. Defence-in-depth gap.

---

## 7. Direct answers to the four questions

### Q1. Is there a single source of truth?
**No.** Validation responsibility is split across three layers:

| Layer | Owns | Stripe relies on? |
|---|---|---|
| OXID core (`User`, `InputValidator`) | Registration-time format + uniqueness | Implicitly, transitively |
| OXID core (`Order::validateOrder`, `RequiredFieldsValidator`) | Checkout-time non-empty + hash | Yes — but `validateDeliveryAddress` step is **bypassed by design** |
| Stripe module (`ConfigurationValidator`, `CustomerDataSanitizer`) | API-key + outbound text sanitation | Yes |

There is no canonical "validate this user/address before Stripe" component. **STRP-129's deliverable should be exactly that.**

### Q2. Is validation performed before the Stripe adapter is called?
**Partially.**

Performed:
- ✅ API keys (`ConfigurationValidator`)
- ✅ CSRF / session integrity (`validateSessionChallenge`)
- ✅ Min order amount (`PaymentController::validatePayment`)
- ✅ Basket existence, userId non-empty (`ContractCreationHandler` base)
- ✅ Outbound text sanitisation (`CustomerDataSanitizer` → email/name)

Not performed at the Stripe boundary:
- ❌ Address-field format / non-empty re-check (relies on OXID's prior save, which the bypass skips)
- ❌ Country / currency re-validation against Stripe's allow-list
- ❌ Email format check (only uniqueness, at user-save time)
- ❌ Stripe per-currency minimum amount
- ❌ Any check on `billing_details.address` if `PaymentMethodHelper` wiring becomes live

### Q3. Per-field rule sets?
**One rule, applied uniformly: trim + non-empty.** That's `RequiredFieldValidator::validateFieldValue()`, the entirety of OXID's per-field enforcement at checkout. No format checks, no regex, no length-min, no country-conditional rules. Email uniqueness and country-ID existence are the only structural checks, both at user-save time.

### Q4. What does OXID provide?
- **`InputValidator`** — login uniqueness, password match, country-ID existence in DB, VAT-ID format. Fires at user save / registration. **Not used by Stripe code.**
- **`User::checkValues`** — calls the InputValidator pieces. Fires at user save. **Not used by Stripe.**
- **`RequiredAddressFields`** — reads `aMustFillFields` config, returns the required-field list per address type (billing/delivery). **Used transitively** by parent Order.
- **`RequiredFieldsValidator` + `RequiredFieldValidator`** — non-empty enforcement. **Used transitively** (and bypassed for Stripe via the flag).
- **`Order::validateOrder` chain** — full pre-finalise validation. **Stripe overrides only `validateDeliveryAddress` step**, retaining the rest (stock, delivery, payment, basket, vouchers).

---

## 8. Recommendations for STRP-129

In priority order:

1. **Introduce a `UserAddressInputFilter` service in the Stripe module.** Place it on the contract-creation path **and** the would-be `PaymentMethodHelper` path. Make it the single boundary between OXID-side data and Stripe-side data. Same role as `CustomerDataSanitizer` but for the structured fields (street, city, zip, country ISO2, phone).
2. **Reject populated `billingAddress` in payment-base DTOs at the Stripe adapter** until the filter exists — or, if the filter ships in the same sprint, route `billingAddress` through it. Don't let the dormant wiring become a silent footgun.
3. **Add backend re-validation for country / currency filters.** Move the filter check from frontend-only to a guard in `StripeOrderController::validateCheckoutPreconditions()`. One line, closes G4.
4. **Pre-flight Stripe per-currency minimum amount.** Either a static table or a service-level cache of `account.country` limits. Closes G5.
5. **Re-affirm the `validateDeliveryAddress` bypass with a regression test** that asserts the flag is cleared after success **and** after cancellation, and that the bypass does not fire without the flag. Closes G3 and updates the code-review-114 memory note.
6. **Defence-in-depth on `ContractMetadataService` address hashing:** refuse to store the contract if the input address is empty (the bypass itself can stay). Closes G8.

---

## 9. Citations

### Stripe module
- `src/Stripe/Controller/StripeOrderController.php:162-258` — checkout entry point + skip-addr-check flag setup
- `src/Stripe/Controller/ControllerRequestHelper.php:86-94, 106-113, 150, 170-182` — session extraction, flag lifecycle, token / CSRF guards
- `src/Stripe/Controller/PaymentController.php:72, 106-135` — min order check
- `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php:31, 76` — handler base + address-metadata storage
- `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php:55-125, 199-224` — checkout dispatch + customer-data extraction
- `src/Stripe/Service/CheckoutSessionService.php:52-124` — Stripe params builder (no address)
- `src/Stripe/Service/ConfigurationValidator.php:32-67` — key-format validator
- `src/Stripe/Service/CustomerDataSanitizer.php:25-47` — outbound text filter
- `src/Stripe/Adapter/Helper/CheckoutSessionHelper.php:28-35` — Stripe API call
- `src/Stripe/Adapter/Helper/PaymentMethodHelper.php:38-39` — **dormant** billing-address forwarding
- `src/Stripe/Adapter/Helper/PaymentIntentHelper.php:231-270` — PaymentIntent params builder (no address)
- `src/Stripe/Model/Order.php:84-102, 123-135` — `executePayment` + `validateDeliveryAddress` overrides

### payment-base
- `payment-base/src/EventSystem/Handler/ContractCreationHandler.php:55-111` — userId + basket existence check
- `payment-base/src/Service/ContractMetadataService.php:44-68, 76-96` — address hashing + context capture
- `payment-base/src/Service/ContractService.php:22-49` — contract creation; no validation
- `payment-base/src/Adapter/Request/AuthorizePaymentRequest.php:38, 52` — `?array $billingAddress` field
- `payment-base/src/Adapter/Request/CreatePaymentRequest.php:31, 46` — same
- `payment-base/src/Adapter/Request/CreatePaymentMethodRequest.php:33, 40` — same

### OXID core
- `source/source/Application/Model/Order.php:2033-2064, 2098-2127` — `validateOrder` + `validateDeliveryAddress`
- `source/source/Application/Model/RequiredAddressFields.php:22-29, 45, 76-93` — default required-field list + config read
- `source/source/Application/Model/RequiredFieldsValidator.php:107-121` — field-list iterator
- `source/source/Application/Model/RequiredFieldValidator.php:22-34` — the single "trim + non-empty" rule
- `source/source/Core/InputValidator.php:110-151, 195+, 295+, 336+` — login / password / country / VAT
- `source/source/Application/Model/User.php:1140-1152` — `checkValues`

---

## 10. Memory updates suggested

1. **`project_code_review_114_latent_bugs`** — update the `validateDeliveryAddress()` "blanket Stripe bypass" note. After re-verification today the bypass is narrowly gated (Stripe payment ID **and** session flag), the flag is set right before dispatch and cleared by `ControllerRequestHelper::clearStripeSessionVariables()`, and the override docblock at `src/Stripe/Model/Order.php:104-122` explicitly documents the scope. The remaining concern is **regression coverage**, not a latent bypass.
2. **New project memory `project_strp_129_scope`** — record that the branch was placeholder until 2026-05-29 and that this report defines the deliverable: a `UserAddressInputFilter` at the Stripe boundary, country/currency backend re-validation, Stripe-minimum pre-flight, and `billingAddress`-DTO routing decision.
