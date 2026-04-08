# Sprint 84: Transaction Recording in Authorization and Capture Flows

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Ticket:** STRP-119

## Problem

The `oe_payments_transaction` table has zero records for real orders. Only refund flow writes transactions (via `AbstractPaymentRefundService::afterRefund()` → `logRefund()`). Authorization and capture events never record transactions, making Sprint 83's transaction history table permanently empty.

## Root Cause

`TransactionRepositoryInterface::save()` is never called during:
- Checkout return (authorization)
- Payment capture (admin or webhook)

Only `logRefund()` is called during refunds.

## Fix

Add `TransactionRepositoryInterface` to two services and record transactions:

1. **`CaptureService`** — record `capture` transaction after successful Stripe API capture
2. **`StripeCheckoutReturnHandler`** — record `authorization` transaction after payment event dispatch

## Subtasks

| # | Task | Status |
|---|------|--------|
| 1 | Write failing tests for CaptureService transaction recording | pending |
| 2 | Write failing tests for StripeCheckoutReturnHandler transaction recording | pending |
| 3 | Implement recording in CaptureService | pending |
| 4 | Implement recording in StripeCheckoutReturnHandler | pending |
| 5 | Wire DI in services.yaml | pending |
| 6 | Pre-commit validation | pending |
