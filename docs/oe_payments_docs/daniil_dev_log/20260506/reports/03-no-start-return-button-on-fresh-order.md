# 03 — Why order 48 has no "Start return" button while order 32 does

**Date:** 2026-05-06
**Author:** Daniil Tkachev
**Scope:** `extensions/opalreturns`

## Direct answer

Order 48 was reported as **`fully_refunded`** by
`ReturnEligibilityService` even though it had **never been refunded**.
The eligibility template rule

```twig
{% if oViewConf.opalreturnsIsOrderEligible(order.oxorder__oxid.value) %}
    <a ...>Start return</a>
{% endif %}
```

…then hid the button.

The wrong `fully_refunded` reason came from
`PaymentComponentDataProvider`, which returned `0.0` when the contract's
`OXCAPTUREDAMOUNT` / `OXREFUNDEDAMOUNT` columns are SQL `NULL` (capture
not yet reported back from Stripe). The eligibility service then
computed `refundable = max(0, 0 - 0) = 0`, ticked the
`fully_refunded` box, and the order became ineligible.

**Fix landed:** the adapter now distinguishes "no contract row",
"row exists but column is NULL" (both → `null`) and "row exists with
a numeric value" (→ float). After the fix, the eligibility service
re-evaluates order 48 as `eligible=true`, `reasons=[]`, and the
button shows.

## What is different between the two orders (DB snapshot)

| Order | `oxsenddate`            | `oxpaid`              | Contract state | `OXCAPTUREDAMOUNT` | `OXREFUNDEDAMOUNT` |
|------:|--------------------------|------------------------|----------------|--------------------:|--------------------:|
| **48** | `2026-05-06 16:26:46`   | `0000-00-00 00:00:00` | `committed`    | `NULL`              | `NULL`              |
| **32** | `2026-05-05 15:54:18`   | `2026-05-05 15:37:07` | `fulfilled`    | `116.50`            | `5.00`              |

Both orders are **shipped** (so `order_not_shipped` is not the reason).
Both are within the withdrawal window. Order 32 has had a real
capture + small partial refund recorded on its contract; order 48's
contract reached `committed` state but the capture amount has not
been written back yet.

## Code trail

### Eligibility check

`extensions/opalreturns/src/Service/Eligibility/ReturnEligibilityService.php:142-148`

```php
private function appendRefundReason(array &$reasons, string $orderId): void
{
    $refundable = $this->calculateRefundableAmount($orderId);
    if ($this->paymentData->isAvailable() && $refundable !== null && $refundable <= 0.0) {
        $reasons[] = 'fully_refunded';
    }
}
```

`calculateRefundableAmount()` only returns `null` when **either**
`getCapturedAmount()` **or** `getRefundedAmount()` returns `null`.
If the adapter answers `0.0` for both, refundable becomes `0.0`,
the guard triggers, and the order is rejected.

### The buggy adapter (before fix)

`extensions/opalreturns/src/Adapter/PaymentComponent/PaymentComponentDataProvider.php` (pre-fix)

```php
$value = $result->fetchOne();
return $value === false ? null : (float) $value;
```

Three states collapsed into two:

| Doctrine `fetchOne()` | Pre-fix | Correct semantic |
|---|---|---|
| `false` (no row at all) | `null` | "unknown" → `null` ✓ |
| `null` (row exists, column is `NULL`) | `(float) null === 0.0` ✗ | "not yet reported" → `null` |
| `'116.50'` (numeric string) | `116.50` ✓ | as float ✓ |

Casting PHP `null` to `float` yields `0.0`, indistinguishable from
"explicitly captured zero". The eligibility code is right to treat
`0` as "fully refunded"; the adapter is the one telling it the wrong
story.

### The fix

`extensions/opalreturns/src/Adapter/PaymentComponent/PaymentComponentDataProvider.php` (after fix)

The two near-identical `getCapturedAmount` / `getRefundedAmount`
bodies were also DRY'd out into a single private `fetchAmount(string $orderId, string $column): ?float`.
The whitelist is internal — the column argument is only ever passed as
the two column-name constants — so no SQL-injection surface.

```php
$value = $result->fetchOne();
if ($value === false || $value === null) {
    return null;
}
return (float) $value;
```

## Live verification

```bash
docker compose exec php php -r "
require '/var/www/source/bootstrap.php';
\$svc = ContainerFactory::getInstance()->getContainer()
    ->get(ReturnEligibilityServiceInterface::class);

echo \$svc->checkOrder('2d7defb1139703c9b826b03715ac222f')['eligible']; // order 48
echo \$svc->checkOrder('94adc2b071abfa5107d4cc7f44397847')['eligible']; // order 32
"
# pre-fix : false / true
# post-fix: true  / true
```

## Tests added

`extensions/opalreturns/tests/Unit/Adapter/PaymentComponent/PaymentComponentDataProviderTest.php` — 7 tests, locks down the
three-state semantics of `getCapturedAmount` / `getRefundedAmount`:

1. No contract row → `null`.
2. Row exists, column SQL NULL → `null` (the regression case).
3. Row exists with numeric string → float.
4. Row exists with `'0.00'` → `0.0` (distinguishes "explicit zero" from "unknown").
5. Same three states mirrored for refunded amount.
6. Query throws → `null`.

Full opalreturns pre-commit: `260 tests, 646 assertions`,
PHPCS / PHPStan / PHPMD / architecture guards all green.

## What else does the operator need to do?

Nothing on the customer side: clearing the OXID cache + restarting
PHP-FPM is enough for the new code to take effect on `daniil.oxiddev.de`
(both done already by this fix's verification step).

If the merchant wants a long-term cure for the underlying inconsistency
(orders that reach `committed` without a captured amount being
reported back), the right follow-up is on the Stripe side:

- Make sure `payment_intent.succeeded` /
  `charge.captured` webhooks land on `StripeWebhookController` and
  trigger the contract update that writes `OXCAPTUREDAMOUNT` /
  `OXCAPTUREDAT`.
- Verify the webhook secret in `sStripeWebhookEndpointSecret` matches
  the actual endpoint configured in Stripe.

The eligibility-side fix in this report is the **defensive** half:
it stops the missing webhook from cascading into a hidden-button UX
bug. The other half (making the webhook always land) is independent
and beyond this report.

## Files touched

```
M  source/extensions/opalreturns/src/Adapter/PaymentComponent/PaymentComponentDataProvider.php
A  source/extensions/opalreturns/tests/Unit/Adapter/PaymentComponent/PaymentComponentDataProviderTest.php
```
