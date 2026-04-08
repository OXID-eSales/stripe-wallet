# Sprint 83c: GREEN — Frontend (Twig Template + Translations)

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Parent:** Sprint 83 (Transaction History Table)
**Blocked by:** Sprint 83b (controller method must exist)

## Objective

Add the transaction history table to the admin template and translation keys for EN/DE.

## Changes

### 1. MODIFY `views/twig/admin/stripe_order_refund.html.twig`

Insert new `<fieldset>` block **after line 196** (closing `</fieldset>` of Payment Details):

- Set `transactions` variable via `oView.getTransactions()`
- Wrap in `{% if transactions is not empty %}` guard
- Table columns: Type, Status, Amount, Currency, Transaction ID, Date
- Each row iterates `{% for tx in transactions %}`
- Amount formatted via `oView.getFormatedPrice(tx.amount)`
- Transaction ID: show value or `-` if null
- Empty state: show `STRIPE_NO_TRANSACTIONS` message when no transactions
- Use `data-testid="transaction-history-table"` on the table for future E2E

### 2. MODIFY `views/admin_twig/en/stripe_lang.php`

Add after existing `STRIPE_EXTERNAL_TRANSACTION_ID` key:
```php
'STRIPE_TRANSACTION_HISTORY'      => 'Transaction History',
'STRIPE_TRANSACTION_TYPE'         => 'Type',
'STRIPE_TRANSACTION_STATUS'       => 'Status',
'STRIPE_TRANSACTION_AMOUNT'       => 'Amount',
'STRIPE_TRANSACTION_CURRENCY'     => 'Currency',
'STRIPE_TRANSACTION_PROVIDER_ID'  => 'Provider Transaction ID',
'STRIPE_TRANSACTION_DATE'         => 'Date',
'STRIPE_NO_TRANSACTIONS'          => 'No transactions recorded.',
```

### 3. MODIFY `views/admin_twig/de/stripe_lang.php`

Same keys, German values:
```php
'STRIPE_TRANSACTION_HISTORY'      => 'Transaktionsverlauf',
'STRIPE_TRANSACTION_TYPE'         => 'Typ',
'STRIPE_TRANSACTION_STATUS'       => 'Status',
'STRIPE_TRANSACTION_AMOUNT'       => 'Betrag',
'STRIPE_TRANSACTION_CURRENCY'     => 'Währung',
'STRIPE_TRANSACTION_PROVIDER_ID'  => 'Anbieter-Transaktions-ID',
'STRIPE_TRANSACTION_DATE'         => 'Datum',
'STRIPE_NO_TRANSACTIONS'          => 'Keine Transaktionen vorhanden.',
```

## Acceptance Criteria

- [ ] Table visible in admin when order has transactions
- [ ] Empty state message shown when no transactions
- [ ] EN and DE translations render correctly
- [ ] Template follows existing OXID admin styling (edittext classes, fieldset/legend pattern)
