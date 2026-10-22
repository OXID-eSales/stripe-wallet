# Subsprint 138a — Purge committed secrets, rotate them, gitleaks everywhere

**Repos:** mollie-payment, stripe, paypal, oxid-fe-user, hydroxid-project · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)
**Why first:** every other subsprint pushes branches; the scanners must be in place and the trees clean before that.

## Findings addressed (audit §5)

- mollie `docs/dev_day_log/20260923/reports/01-order-shipping-address-from-billing.md:17`: a real-looking Mollie
  sandbox API key with the admin login e-mail and password.
- stripe `docs/oe_payments_docs/daniil_dev_log/20260918/reports/01-fresh-session-run.log`: a Checkout Session
  client secret and an OXID `force_sid`.
- paypal (`main`) `docs/paypal_sdk_docs/sdk_docs.md:79`: a sample `access_token` value.
- oxid-fe-user `tests/e2e/.env.dist` and fallback defaults in `tests/e2e/fixtures/test-data.ts`, hydroxid
  `tests/e2e/live.spec.ts`, `tests/e2e/helpers/payments.ts`: a working login to the public pay1 demo.
- oxid-fe-user / hydroxid: provider public ids in pushed history (`810203b`, `04d8cf8`), truncated prefixes in
  `docs/dev_log/20261019/sprints/sprint-15-payment-keys-from-backend.md`.
- gitleaks CI missing in paypal, oxid-fe-user, hydroxid; rules lack our shapes.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                                                 |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1 | **Red:** add the missing rules to every `.gitleaks.toml` (`cs_(test|live)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+`, `force_sid=[0-9a-z]{32}`, PayPal `A21AA[A-Za-z0-9_-]{60,}`, Mollie `(live|test)_[A-Za-z0-9]{30}` where absent, a credential-pair rule for `password:`/`Password:` next to an e-mail) and the workflow to paypal, oxid-fe-user, hydroxid (copy of payment-base's job, both tree and history scans). The scans must be red on the current trees. |
| A2 | Remove the offending content (redact the lines, keep the documents); replace `.env.dist` values and the three fallback passwords with placeholders that make the dependent tests **skip** with a clear message (`test.skip(!process.env.TEST_USER_PASSWORD, …)`). Scans green. |
| A3 | **Rotate** (manual, recorded in the report with date and who did it, never the values): the Mollie sandbox API key, the admin password of that shop, the pay1 test user's password; invalidate the PayPal sample token if it was ever real. |
| A4 | Prepare the history decision: a list of the commits and files holding the public ids (no values) in the report; if you decide to rewrite, it is your push. |
| A5 | Proof: gitleaks output per repo (tree + history) in `reports/138a-…`, the rotation log, CI links.                                                                                                                                                                               |

## Acceptance

gitleaks tree and history scans green in all five repos (payment-base already green); rotated credentials
refused by the providers/shop; no test uses a committed password.

## Out of scope

History rewrite; secrets outside these repositories (pay1 host files); the Twig flow's URLs.
