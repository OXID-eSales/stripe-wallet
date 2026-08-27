# Sprint 136 — Which payment method actually paid? Surface the PSP method in the admin "Payment" tab

**Ticket:** STRP-TBD (assign before branching)
**Branches:** stripe `b-7.4.x-psp-payment-method-STRP-TBD` · mollie-payment `main-psp-payment-method-STRP-TBD`
**Base:** stripe `b-7.4.x` · mollie-payment `main`
**Repos touched:** `extensions/stripe`, `extensions/mollie-payment`. **`payment-base` is NOT touched** (see *Design decisions*).
**Prerequisite:** none.

---

## Why this sprint exists

Both PSP panels already render a row labelled "Payment type" (Stripe:
`STRIPE_PAYMENT_TYPE`, Mollie: `MOLLIE_PAYMENT_TYPE` — the German file even says
*"Zahlungsart"*). Both fill it from the same place:

```php
'paymentType' => $this->readField($order, 'oxorder__oxpaymenttype'),   // Stripe
'paymentType' => $this->readOrderField($order, 'oxpaymenttype'),       // Mollie
```

`OXPAYMENTTYPE` is the **shop's** payment-method id — `oscstripe`,
`mollie_payment`. It is identical for every order that went through the module.
It cannot answer the only question an operator actually has in front of a
refund form:

> *Did this customer pay by card, by Klarna, by PayPal, by SEPA direct debit?*

That answer decides real work: Klarna and SEPA have different refund
settlement times than card; a PayPal payment is disputed through a different
channel; "card" plus brand/last4 is what a customer quotes on the phone. Today
the operator has to leave OXID and open the Stripe/Mollie dashboard to learn it,
which is exactly the trip the "Payment" tab was built to eliminate.

The data is one field away in both modules and is currently **thrown away at the
adapter boundary**:

| | where the answer lives | current state |
|---|---|---|
| Stripe | `charge.payment_method_details.type` (+ `.card.brand`, `.card.last4`, `.card.wallet.type`) | `StripeObjectMapper::fromCharge()` does not map it; `StripeChargeDto` has no field for it |
| Mollie | `payment.method` (+ `details.cardLabel`, `details.cardNumber`) | `MolliePaymentDto::$method` **already exists and is already mapped** — no consumer reads it |

There is also a third, colder finding: `oe_payments_transaction` has
`OXPAYMENTMETHODTYPE` / `OXPAYMENTMETHODID` columns,
`TransactionInterface::setPaymentMethodType()` exists, and
`DoctrineTransactionRepository` persists both — but **no module in any repo ever
calls the setter**. The column has been NULL since it was created. That is a
separate write-path sprint (see *Out of scope*); it must not become this
sprint's excuse to skip the live read, because the live read is the only thing
that works for the orders already in the database.

## What ships

One new row in each panel's payment-details card, above the amounts:

```
Payment method used     Credit card · Visa •••• 4242
Payment method used     Klarna
Payment method used     PayPal
Payment method used     Apple Pay (Mastercard •••• 0007)
Payment method used     —                                  ← nothing charged yet / PSP unreachable
```

Existing rows are not removed. Mollie's misleading `MOLLIE_PAYMENT_TYPE` label
is retitled "Shop payment method" / "Shop-Zahlart" (lang files only) so the two
rows cannot be confused; Stripe's already reads "Payment type" / "Zahlart" and
stays.

## Design decisions (and the alternatives rejected)

**1. Live PSP read is the source of truth — not a new DB column.**
Matches the module's documented Transaction Storage Strategy (B+): *display from
the PSP API, audit-log to the DB*. It also works retroactively for every order
already placed, which a write-path-only solution cannot.

**2. Provider-local descriptor VOs — `payment-base` is not touched.**
The tempting move is a shared `PaymentMethodDescriptor` + canonical enum in
`payment-base`, since there are exactly two consumers. Rejected:

- the established precedent in this exact layer is provider-local duplication —
  `AdminAmountValidator`, `AdminValidationFeedback`, `AmountValidationResult`
  and `AdminActionBounds` all already exist twice, once per module, and
  `payment-base` holds only the contracts the *shared controller* consumes
  (`PaymentPanelProviderInterface`, `PaymentPanelRenderable`,
  `PaymentPanelContext`). The shared controller does not consume this data — each
  body template does;
- a `payment-base` change drags in the additive-only audit (paypal + OPC
  reference counts must match exactly before and after) and a third release for
  a ~30-line value object;
- the raw vocabularies genuinely differ (`card` vs `creditcard`, `sepa_debit`
  vs `directdebit`, `ideal`/`bancontact` are Mollie-only). Only the *English
  wording* is shared, and that is shared through the lang files, not through code.

Consistency is enforced by using identical EN/DE strings for the same canonical
method in both modules' lang files.

**3. The raw/canonical map is `final` + `static`.**
It is a pure, stateless, deterministic string→string function, so per the
standing rule it is not an injected interface and needs no DI entry. This also
sidesteps the container trap: `services.yaml` sweeps `src/*/Service/*` in both
modules, so a VO or enum dropped there breaks container compilation. Everything
new lands in `src/Stripe/Admin/` and `src/Mollie/Admin/`, which are explicitly
wired, not swept.

**4. Unknown method → `—`, never a guess.**
No charge yet (`requires_payment_method`), a PSP method the map does not know,
or an API failure all render the em dash. Stripe's PI-level
`payment_method_types` (what was *offered*) is deliberately **not** used as a
fallback: "one of card, klarna, paypal" is noise dressed as an answer.

**5. Mollie gets one API call, not four.**
`AdminActionBounds` calls `getPayment()` three times per render already
(`captureBound`, `refundBound`, `isAuthorizedHold` — each an unmemoized round
trip). Adding a fourth for the method is not acceptable, and extending
`AdminActionBoundsInterface` with a `paymentMethod()` accessor would be a
straight SRP violation — that interface is about *how much may move*. So Story 3
extracts a memoizing `MolliePaymentSnapshotProvider` seam, refactors
`AdminActionBounds` onto it (characterization tests first), and the new resolver
consumes the same seam: **3 calls → 1**, with a fourth consumer added. Net win,
not speculative abstraction.

## Definition of Done (sprint-level)

1. Both panels render the real PSP method, with card brand/last4 and wallet
   where the PSP reports them.
2. **Every new production line arrives after a RED test.** No exceptions.
3. `oxpaymenttype`-fed rows still render exactly as before — pinned by test, not
   by eyeball.
4. Mollie `getPayment()` calls per panel render: **exactly 1**, asserted in a
   test (this is the story's hard gate, not a nice-to-have).
5. Both DTO widenings are **additive with defaults** — every existing
   construction site compiles untouched, and no positional-argument site changes
   meaning. Named arguments at both new construction sites.
6. EN **and** DE lang keys for every new ident, in `views/admin_twig/{en,de}/`
   (admin reads there, not `translations/`).
7. Gates green in **both** repos: `composer phpcs` · `composer phpstan` (level
   max, **no new baseline entries**) · `composer phpmd` (**no new baseline
   entries**) · `--testsuite Unit` · `--testsuite Integration` ·
   `./bin/pre-commit-check.sh --full` for stripe.
8. Completion report in `reports/`, sprint doc moved to `done/`, `status.md`
   updated.

---

## Story 1 — Stripe: stop discarding `payment_method_details` at the adapter boundary

**RED first:** `StripeObjectMapperTest` — `fromCharge()` on a
`Charge::constructFrom()` fixture carrying
`payment_method_details.type = 'klarna'`, and a second carrying
`type = 'card'` with `card.brand = 'visa'`, `card.last4 = '4242'`,
`card.wallet.type = 'apple_pay'`. Plus the absent-details case → all four
fields null (a Charge retrieved without the expansion must not throw).

**GREEN:**
- `StripeChargeDto` += `?string $paymentMethodType`, `?string $cardBrand`,
  `?string $cardLast4`, `?string $walletType`, all `= null` at the end of the
  signature (additive).
- `StripeObjectMapper::fromCharge()` maps them. Extract the
  `payment_method_details` reading into a private static helper so `fromCharge()`
  stays inside the 15–25-line target and PHPMD's ECC threshold is untouched.

The expansion is already requested: `StripeOrderApiService::getPaymentIntentWithRefunds()`
expands `latest_charge.refunds`, and `payment_method_details` ships inside the
expanded Charge — **no extra API call, no expand-list change**. Pin that in the
test by asserting the fields survive `fromPaymentIntent()` with an expanded
`latest_charge`.

## Story 2 — Stripe: descriptor, label map, view data, template row

**RED first:**
- `StripePaymentMethodDescriptorTest` — `fromCharge(null)` → unknown;
  `fromCharge($klarnaCharge)` → known, no card detail; card+wallet → detail
  string `Visa •••• 4242`, wallet `apple_pay`; unknown raw code → unknown but
  raw code preserved.
- `StripePaymentMethodLabelsTest` — `keyFor('card') === 'STRIPE_PAYMENT_METHOD_CARD'`,
  … `keyFor('bogus_wallet') === 'STRIPE_PAYMENT_METHOD_UNKNOWN'`. One case per
  supported method, so an added method cannot silently fall through to unknown.
- `StripePanelViewDataBuilderTest` — `build()` exposes
  `viewData['paymentMethod']` with translated `label`, `detail`, `raw`; and the
  no-charge order still yields the unknown shape rather than an exception.

**GREEN:** `src/Stripe/Admin/PaymentMethodDescriptor.php` (readonly VO, static
named constructor `fromCharge(?StripeChargeDto)`),
`src/Stripe/Admin/PaymentMethodLabels.php` (`final`, static map),
`StripePanelViewDataBuilder` projects the row through its existing
`LanguageTranslatorInterface`, `stripe_panel.html.twig` renders one `<tr>` with
`data-testid="payment-method-used"`, and both admin lang files get
`STRIPE_PAYMENT_METHOD_USED` + one key per canonical method.

Canonical set (Stripe raw → label): `card` → Credit card, `klarna` → Klarna,
`paypal` → PayPal, `sepa_debit` → SEPA direct debit, `sofort` → Sofort,
`giropay` → giropay, `eps` → EPS, `p24` → Przelewy24, `ideal` → iDEAL,
`bancontact` → Bancontact, `link` → Link, `us_bank_account` → Bank account,
`customer_balance` → Customer balance, `wechat_pay` → WeChat Pay,
`alipay` → Alipay, `revolut_pay` → Revolut Pay, `afterpay_clearpay` → Clearpay,
`multibanco` → Multibanco, `twint` → TWINT. Wallets (`apple_pay`, `google_pay`,
`link`) come from `card.wallet.type` and take the label slot with the card brand
demoted into the detail — an Apple Pay payment is not "a card payment" to the
operator holding the phone.

## Story 3 — Mollie: one payment fetch per render (refactor, behaviour-preserving)

**Characterization first** — `AdminActionBoundsTest` currently mocks
`MolliePaymentsAdapterInterface`. Before touching it, pin today's behaviour:
each of the three methods, the `providerOrderId === null|''` short circuit, and
the `Throwable` → `0.0` + `logger->warning()` path (assert on the warning, not
on the mere fact the call ran — Sprint 135's rule).

**GREEN:** `MolliePaymentSnapshotProviderInterface` + implementation in
`src/Mollie/Admin/`: `snapshot(PaymentContractInterface): ?MolliePaymentDto`,
memoized per `providerOrderId`, owning the null-id short circuit and the
`Throwable`→`null`+`warning` handling that `AdminActionBounds::loadPayment()`
holds today. `AdminActionBounds` consumes the seam and loses `loadPayment()`;
its public interface and its logging behaviour do not change. New test:
**four** snapshot consumers, **one** `getPayment()` call —
`$adapter->expects(self::once())`.

## Story 4 — Mollie: method row with card detail parity

**RED first:** `PaymentMoneyMappingRegressionTest`'s sibling for the details
fields (`MollieAdapter::mapPayment()` maps `details.cardLabel` /
`details.cardNumber` → `cardBrand` / `cardLast4`, absent details → null);
descriptor + label-map tests mirroring Story 2; `MolliePanelViewDataBuilderTest`
for the projected row including the no-contract `empty()` shape.

**GREEN:** `MolliePaymentDto` += `?string $cardBrand`, `?string $cardLast4`
(additive, defaults null, named-argument construction site already in place);
`MollieValueMapper` helper for the `details` bag; `src/Mollie/Admin/`
descriptor + label map; builder projection through the snapshot provider;
`mollie_panel.html.twig` row with `data-testid="payment-method-used"`; EN/DE
lang keys — **identical English and German wording to Stripe's for the same
canonical method**.

Canonical set (Mollie raw → label): `creditcard` → Credit card,
`klarna`/`klarnapaylater`/`klarnasliceit`/`klarnapaynow` → Klarna,
`paypal` → PayPal, `directdebit` → SEPA direct debit, `ideal` → iDEAL,
`bancontact` → Bancontact, `sofort` → Sofort, `giftcard` → Gift card,
`banktransfer` → Bank transfer, `eps` → EPS, `przelewy24` → Przelewy24,
`applepay` → Apple Pay, `belfius` → Belfius, `kbc` → KBC/CBC,
`voucher` → Voucher, `twint` → TWINT, `trustly` → Trustly,
`in3` → in3, `riverty` → Riverty, `billie` → Billie, `alma` → Alma,
`blik` → BLIK, `mbway` → MB WAY, `multibanco` → Multibanco,
`satispay` → Satispay, `paybybank` → Pay by Bank, `payconiq` → Payconiq,
`pointofsale` → Point of sale.

## Story 5 — Gates, docs, commits

Per-story commits in each repo. Both repos' full gate suites. Completion report
recording: the em-dash cases an operator will actually see, the Mollie call-count
before/after, and the `OXPAYMENTMETHODTYPE` orphan hand-off.

## Out of scope (deliberately)

- **Writing `OXPAYMENTMETHODTYPE` / `OXPAYMENTMETHODID`.** The columns, the
  setters and the repository persistence all exist and are never called. Wiring
  them belongs in the authorization/capture event handlers with their own
  webhook-path tests, and it cannot serve the historical orders this sprint has
  to cover. Next sprint; recorded in the report so it does not go cold again.
- **A shared `payment-base` descriptor.** Rationale above. Revisit if and only
  if a third PSP panel appears or the shared controller itself needs the data.
- **Storefront / order-confirmation display of the method.** Admin tab only.
- **Mollie `details` beyond card brand/last4** (`consumerName`, `bankAccount`,
  Klarna line detail): no operator request behind it yet.
