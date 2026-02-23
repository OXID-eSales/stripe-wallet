# Sprint 63a/63b/63c Completion Report — Already Fixed

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`

---

## Discovery

During implementation of Sprints 63a (C1), 63b (H2), and 63c (C5), we discovered that these findings were **already fixed** in the current codebase. The audit report (`01-security-audit-strp99-no-mcp.md`, dated 2026-02-19) was based on an earlier code state.

Additionally, findings **C2** (weak ID), **C4** (refund validation), and **H4** (currency validation) were also found to be already fixed.

---

## Evidence

### C1: API Key Exposure — ALREADY FIXED

**Current code** (`StripeOrderController.php:133-137`):
```php
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    'url' => $context->get('checkoutUrl'),
    'contract_id' => $context->get('contractId'),
]);
```
- No `_debug` block, no `sk_prefix`/`pk_prefix`
- No secret key logging
- Error handler returns generic message: `'Payment processing failed. Please try again.'`
- Existing test: `StripeOrderControllerSecurityTest::testCreateCheckoutSessionOutputContainsNoDebugInfo()`

### H2: Capture Mode Override — ALREADY FIXED

**Current code** (`StripeOrderController.php:342-349`):
```php
protected function getCaptureMode(): string
{
    $config = $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class);
    return $config->getCaptureMode();
}
```
- No `getRequestParameter('capture_mode_override')` anywhere
- Existing test: `StripeOrderControllerSecurityTest::testGetCaptureModeIgnoresRequestParameter()`

### C5: Amount Validation — ALREADY FIXED

**Current code** (`BasketSnapshot.php:102-118`):
```php
private static function extractFloat(array $data, string $key): float
{
    // ... validates type is float|int
    $result = (float) $value;
    if (!is_finite($result) || $result < 0) {
        throw new InvalidArgumentException("...");
    }
    return $result;
}
```
- `is_finite()` check rejects NAN, INF, -INF
- `< 0` check rejects negative amounts
- Type check rejects non-numeric inputs

### C2: Weak ID Generation — ALREADY FIXED

**Current code** (`AbstractModel.php:16-18`):
```php
protected function generateId(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(16));
}
```
- Uses CSPRNG (`random_bytes`), not `uniqid()`
- Also fixed in `WebhookLog.php:25` and `DoctrineTransactionRepository.php:151`

### C4: Refund Amount Validation — ALREADY FIXED

**Current code** (`AbstractPaymentRefundService.php:151-168`):
```php
protected function calculateRefundAmounts(PaymentContractInterface $contract, ?float $amount): array
{
    $capturedAmount = $contract->getCapturedAmount();  // Uses captured amount, NOT basket total
    // ...
}
```
- Validates against `getCapturedAmount()`, not basket total
- `validateRefundAmount()` checks `is_finite()` and `> 0`

### H4: Currency Validation — ALREADY FIXED

**Current code** (`BasketSnapshot.php:123-137`):
```php
if (preg_match('/^[A-Z]{3}$/', $data['currency']) !== 1) {
    throw new InvalidArgumentException('...');
}
```
- ISO 4217 format validation (3 uppercase letters)

---

## Findings Status Update

| Finding | Audit Status | Actual Status | Evidence |
|---------|-------------|---------------|----------|
| C1 | CRITICAL | ALREADY FIXED | No debug block in controller output |
| C2 | CRITICAL | ALREADY FIXED | `random_bytes(16)` replaces `uniqid()` |
| C4 | CRITICAL | ALREADY FIXED | Uses `getCapturedAmount()` not basket total |
| C5 | CRITICAL | ALREADY FIXED | `is_finite()` + `< 0` validation |
| H2 | HIGH | ALREADY FIXED | No request parameter override |
| H4 | HIGH | ALREADY FIXED | ISO 4217 regex validation |

**Remaining from Sprint 63:** Only **H1 (DumpExtension)** needs implementation.
