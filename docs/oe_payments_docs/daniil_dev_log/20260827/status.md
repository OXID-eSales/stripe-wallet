# 2026-08-27

## Sprint 136 — PSP payment method in the admin "Payment" tab — DONE

Both PSP panels now show what the customer actually paid with (Klarna, credit
card, PayPal, SEPA, wallets), not just the shop's payment-method id.

- **Stripe** `b-7.4.x-psp-payment-method-STRP-TBD` — `625b8d2`, `2eb106e`
- **Mollie** `main-psp-payment-method-STRP-TBD` — `9360d2a`, `f106e0c`
- **payment-base** — untouched (rationale in the sprint doc, *Design decisions*)

115 new tests. All gates green in both repos; two pre-existing failures
confirmed unrelated and documented.

Side win: a Mollie panel render went from **3 live API calls to 1** (it would
have been 4 with the new row).

- Sprint: [`done/sprint-136-which-method-actually-paid.md`](done/sprint-136-which-method-actually-paid.md)
- Report: [`reports/01-sprint-136-completion.md`](reports/01-sprint-136-completion.md)

### Open hand-off
`oe_payments_transaction.OXPAYMENTMETHODTYPE` / `OXPAYMENTMETHODID`: columns,
setters and repository persistence all exist, no module has ever called the
setters. Display now works without them; the write path is the next sprint.
