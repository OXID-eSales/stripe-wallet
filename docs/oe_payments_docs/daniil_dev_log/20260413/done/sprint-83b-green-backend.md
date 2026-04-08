# Sprint 83b: GREEN — Backend Implementation

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Parent:** Sprint 83 (Transaction History Table)
**Blocked by:** Sprint 83a (tests must exist first)

## Objective

Implement the PHP backend to make all Sprint 83a tests pass. Minimal code — just enough to turn RED to GREEN.

## Changes

### 1. MODIFY `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php`

- Add `TransactionRepositoryInterface` as second constructor parameter
- Add method:
  ```php
  /** @return array<int, array<string, mixed>> */
  public function getTransactionsForContract(string $contractId): array
  ```
- Implementation: call `$this->transactionRepository->findByContractId($contractId)`, map each `Transaction` through `toArray()`, return result

### 2. MODIFY `src/Stripe/Controller/Admin/OrderRefund.php`

- Add method in "Template-facing view data" section:
  ```php
  /** @return array<int, array<string, mixed>> */
  public function getTransactions(): array
  ```
- Implementation: get `$contractId = $this->getContractId()`, early return `[]` if null, delegate to `$this->getViewDataProvider()->getTransactionsForContract($contractId)`

### 3. MODIFY `services.yaml` (~line 876)

- Add `$transactionRepository` argument to `OrderRefundViewDataProvider`:
  ```yaml
  OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider:
    arguments:
      $transactionRepository: '@OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface'
    public: true
  ```

## Acceptance Criteria

- [ ] All 5 tests from Sprint 83a pass (GREEN)
- [ ] No existing tests broken
- [ ] PHPStan level max clean
- [ ] PHPCS PSR-12 clean
