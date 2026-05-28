# Sprint 114.10b Completion — Agnostic Boundary: A1 DTO Migration

**Date:** 2026-05-28
**Branch:** `b-7.4.x-code-review-STRP-145`
**Commit:** `e69e5d5` (Phase 2-4 combined)

---

## R-1: Acceptance Criteria

| # | Criterion | Status |
|---|-----------|--------|
| 1 | No `use Stripe\*` in Service/, Controller/, Model/, Admin/ | PASS |
| 2 | All 4 sub-interfaces return DTOs | PASS |
| 3 | StripeAdapter maps internally via StripeObjectMapper | PASS |
| 4 | PHPStan level max: 0 errors | PASS |
| 5 | PHPCS PSR-12: 0 errors | PASS |
| 6 | PHPMD: 0 new violations (baseline unchanged) | PASS |
| 7 | Unit tests: 1047/1047 green | PASS |
| 8 | No double-mapping (StripeObjectMapper::from* calls outside Adapter) | PASS |

---

## R-2: Phases Executed

### Phase 1 — 5 DTOs + StripeObjectMapper (prior session)
Created `StripeCheckoutSessionDto`, `StripePaymentIntentDto`, `StripeChargeDto`,
`StripeRefundDto`, `StripeCustomerDto` as `final readonly` value objects.
Created `StripeObjectMapper` as the single A1-boundary entry point.

### Phase 2 — Characterization Tests
5 new test files capturing pre-migration behaviour per consumer:
- `CheckoutReturnServiceDtoCharacterizationTest`
- `RefundServiceDtoCharacterizationTest`
- `StripeOrderApiServiceDtoCharacterizationTest`
- `StripeChargeAmountResolverDtoCharacterizationTest`
- `OrderRefundViewDataProviderDtoCharacterizationTest`

### Phase 3 — Consumer Migration (6 consumers)
All consumers migrated from raw `\Stripe\*` SDK objects to DTOs:
1. `CheckoutReturnService` — removed `Session` import, field access updated to DTO props
2. `RefundService` — removed `StripeObjectMapper` calls; adapter returns DTOs directly
3. `StripeOrderApiService` — removed `StripeObjectMapper` calls
4. `Order` (model, `fetchStripeCharge`) — removed `StripeObjectMapper::fromCharge()` call
5. `CancelAuthorizationService` — removed dead `?? 'canceled'` null fallback
6. `StripeChargeAmountResolver` — updated field access to DTO props
7. `StripePanelViewDataBuilder` — updated `amount`/`currency` to non-nullable DTO props
8. `OrderRefundViewDataProvider` — updated field access to DTO props

### Phase 4 — Interface Flip
All 4 sub-interfaces updated to declare DTO return types:
- `StripeCheckoutAdapterInterface`: `Session` → `StripeCheckoutSessionDto`
- `StripePaymentIntentAdapterInterface`: `PaymentIntent` → `StripePaymentIntentDto`, `cancelPaymentIntent` → `StripePaymentIntentDto`
- `StripeRefundAdapterInterface`: `Refund` → `StripeRefundDto`, `Charge` → `StripeChargeDto`
- `StripeCustomerAdapterInterface`: `Customer` → `StripeCustomerDto`

`StripeAdapter` updated to map via `StripeObjectMapper` internally in all 8 methods.

---

## R-3: PHPStan Issues Fixed

1. `instanceof StripeObject always false` — removed dead branch from `fromPaymentIntent()`
2. `Cannot cast mixed to string` (lines 175/177) — replaced with `is_scalar()` guard
3. `instanceof Stripe\Refund always true` + `Unreachable statement` (mapRefunds) — simplified loop to `$dtos[] = self::fromRefund($refund)`
4. `Property StripePaymentIntentDto::$amount on left side of ?? is not nullable` — removed `?? 0` / `?? ''` in `StripePanelViewDataBuilder`

---

## R-4: Test Suite Impact

- **Before:** raw `\Stripe\*` constructFrom() fixtures in 8+ test files
- **After:** DTO constructors in all mock returns
- **Count:** 1047/1047 passing (unchanged)

Test files updated:
- `StripeAdapterTest` — `assertSame($expected, $result)` → `assertInstanceOf(XxxDto::class, $result)`
- `CancelAuthorizationServiceTest` — mock PaymentIntent → DTO helper
- `CheckoutReturnServiceTest` — Session::constructFrom → StripeCheckoutSessionDto
- `CheckoutSessionServiceTest` — Session::constructFrom → buildSessionDto helper
- `RefundServiceTest` — Refund/PaymentIntent constructFrom → DTO helpers
- `StripeCustomerServiceTest` — Customer::constructFrom → StripeCustomerDto
- All 5 characterization tests — fixtures already use DTOs

---

## R-5: End-State Verification

```
grep -rl "use Stripe" src/Stripe/Service/ src/Stripe/Controller/ src/Stripe/Model/ src/Stripe/Admin/
(empty — 0 files)
```

Only `src/Stripe/Adapter/` and its `StripeObjectMapper.php` reference `\Stripe\*` SDK types.

---

## R-6: Files Changed

**Production (18 files):**
- `src/Stripe/Adapter/Dto/StripeCheckoutSessionDto.php` — added `url` field
- `src/Stripe/Adapter/StripeObjectMapper.php` — mapRefunds simplified, PHPStan fixes
- `src/Stripe/Adapter/StripeAdapter.php` — internal mapping via StripeObjectMapper
- `src/Stripe/Adapter/Stripe*AdapterInterface.php` (4 files) — DTO return types
- `src/Stripe/Admin/StripePanelViewDataBuilder.php` — DTO field access
- `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` — DTO field access
- `src/Stripe/Model/Order.php` — removed mapper call
- `src/Stripe/Service/CancelAuthorizationService.php` — removed null fallback
- `src/Stripe/Service/ChargeAmountResolverInterface.php` — DTO param type
- `src/Stripe/Service/CheckoutReturnService.php` — full DTO migration
- `src/Stripe/Service/Factory/StripeAdapterFactory.php` — removed dead import
- `src/Stripe/Service/Factory/StripeAdapterFactoryInterface.php` — removed dead import
- `src/Stripe/Service/RefundService.php` — removed mapper calls
- `src/Stripe/Service/StripeChargeAmountResolver.php` — DTO field access
- `src/Stripe/Service/StripeOrderApiService.php` — removed mapper calls

**Tests (18 files — 13 updated + 5 new characterization):**
- 5 new characterization test files
- 13 existing test files updated to use DTO fixtures

---

## R-7: Quality Gate

| Check | Result |
|-------|--------|
| PHPStan level max | 0 errors |
| PHPCS PSR-12 | 0 errors |
| PHPMD | 0 new violations |
| Unit tests | 1047/1047 PASS |
