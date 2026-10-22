# Subsprint 138g — PayPal webhook signature verification

**Repo:** paypal · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Finding addressed (audit §6)

`PayPalAdapter::verifyAndParse()` (behind `WebhookHandler/WebhookSignatureVerifier`) only checks that a
webhook id is configured and that the body parses as JSON; an unauthenticated POST can therefore drive the
webhook handlers. Stripe verifies (`Webhook::constructEvent`); Mollie re-fetches by id.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                 |
| -- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| G1 | `testVerify_MissingTransmissionHeaders_Rejects`, `testVerify_SignatureFailure_Rejects`, `testVerify_Valid_Parses`: a `WebhookSignatureVerifierInterface` in `Adapter/` backed by PayPal's `verify-webhook-signature` (`PAYPAL-TRANSMISSION-ID/-TIME/-SIG`, `PAYPAL-CERT-URL`, `PAYPAL-AUTH-ALGO`, webhook id, raw body); the SDK call confined to the adapter; the processor rejects anything not `SUCCESS`. |
| G2 | Replay guard: the existing idempotency key `paypal_webhook:<eventId>` is applied **after** verification, not before.                                                                                                                             |
| G3 | Integration: a recorded sandbox event verifies; a tampered body fails; the controller answers 400 without processing. Proof: sandbox webhook delivery screenshot/log in the report.                                                                     |
| G4 | CHANGELOG; merchant doc: the webhook id must be configured (it already is a setting).                                                                                                                                                                 |

## Acceptance

An unsigned or tampered webhook never reaches a handler; a genuine sandbox event is processed exactly
once; unit + integration green; PHPStan without new baseline entries.

## Out of scope

Certificate pinning beyond PayPal's own verification endpoint; webhook retry policy.
