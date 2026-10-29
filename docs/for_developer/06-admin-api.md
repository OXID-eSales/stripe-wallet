# Stripe admin API (for `oxid-admin-graphql`)

The Stripe module adds the Stripe settings screen of the headless admin (`oxid-fe-admin`) and the Stripe part of the
Payment tab to the admin GraphQL API of `oxid-admin-graphql`. It builds on payment-base's admin payment API
(`payment-base/docs/admin-payment-api.md`): the right `ADMIN_PAYMENT_CONFIG`, the step-up service and
`AdminPaymentContract`. It mirrors the PayPal module's admin API (`paypal/docs/for_developer/06-admin-api.md`).

**Everything is optional.** The module installs, activates and passes its suites without `oxid-admin-graphql`: the admin
API's services are reached only through `@?`, nothing of it is autowired, the classes implement nothing of it, and
`GraphQL\Service\NamespaceMapper` maps the controllers, the types and the contract extension only when `AdminOrder` (admin
API) **and** payment-base's `AdminPaymentContract` exist. `tests/Support/admin-api-stubs.php` are the stand-ins of the
standalone unit suite and PHPStan; `Integration\Stripe\GraphQL\Admin\AdminModuleParityTest` proves them against the real
classes; `Unit\Stripe\GraphQL\Admin\AdminApiIsOptionalTest` proves every service class loads in a PHP process where none of
the optional packages exist. After a change of the schema: `bin/oe-console oe:graphql:cache-clear` (and delete
`var/cache/container/container_cache_shop_*.php` after a change of `services.yaml`).

Requires payment-base with its admin payment API (1.5.0) and, for the provider wiring below, payment-base's PAY-1 release
(state-help provider, action options, bounds, typed refusals). Until that is tagged, CI pins payment-base's branch
`b-7.4.x-admin-payment-plugins`.

## Operations (`#[Right]` without `#[Logged]`, like every admin operation)

| Operation | Right | Does |
| --- | --- | --- |
| `adminStripeConfig: AdminStripeConfig!` | `ADMIN_PAYMENT_CONFIG` | mode, captureMode, removeByBillingCountry, removeByBasketCurrency, provideCustomerEmail, logLevel, logWebhooks, test/live publishable keys, webhookEndpointUrl, `classicSettingsUrl` (the classic admin's settings page of this module for the current shop, `{shopUrl}{adminDir}/index.php?cl=module_config&oxid=oe_payments_stripe_wallet&shp=<shopId>`; where the Connect onboarding runs, no secret in it), flags `testTokenSet` `liveTokenSet` `testPlatformKeySet` `livePlatformKeySet` `webhookSecretSet` `testConnected` `liveConnected`, `keyValidationError`, `version` |
| `adminStripeWebhookStatus: AdminStripeWebhookStatus!` | `ADMIN_PAYMENT_CONFIG` | `mode`, `configured`, `endpointId`, `url` |
| `AdminPaymentContract.stripe: AdminStripePaymentDetails` | `ADMIN_PAYMENT_VIEW` | `paymentIntentId`, `chargeId`, `externalTransactionId`, `intentStatus`, `dashboardUrl`, `apiError` (read-only; null for a contract of another provider) |

### Read-only: the settings change in the classic admin only (CFG-1)

The admin API **cannot change any Stripe setting**: there is no mutation for the configuration, the API keys, the webhook
secret or the webhook endpoints (`adminStripeConfigModify`, `adminStripeWebhookRegister` and `adminStripeWebhookClearAll`
were removed, with their input and payload types, the modifier, validator, audit and step-up services and
`AdminStripeConfig.secretsEditable`). Use the classic admin's module configuration (`classicSettingsUrl`): the form, the
Connect onboarding and the "Create webhooks" / "Clear all webhooks" buttons are unchanged. What stays: the two read queries,
the contract extension below, and payment-base's money operations (capture, refund, void).

### Secrets never leave the shop

No type has a field that could carry a secret value: the config says `testTokenSet`, `liveTokenSet`, `testPlatformKeySet`,
`livePlatformKeySet`, `webhookSecretSet` and nothing else. Stripe's own error messages shown with a contract are scrubbed of
`sk_`/`rk_`/`pk_`/`whsec_` strings (`StripeSecretRedactor`). Proof: `Unit\Stripe\GraphQL\Admin\NoSecretInAnyAnswerTest`
searches every field of every answer for every stored secret and for secret-shaped strings; the integration test does the
same against the secrets stored in the real shop; `StripeAdminApiWiringTest` proves the schema has no settings-changing
operation.

### The `stripe` details of a contract

`StripePaymentDetailsReader`: `paymentIntentId` is `oxorder.oxtransid` of the contract's order (read by SQL, no order model),
`externalTransactionId` the reference checkout stored on the contract (a PaymentIntent or Checkout Session id), `dashboardUrl`
`https://dashboard.stripe.com[/test]/payments/{pi}` by the shop's mode. `intentStatus` and `chargeId` are asked from Stripe
through the module's adapter, once per intent and request. **Fails closed**: when Stripe cannot be asked they are null and
`apiError` holds the first line of the message with every key masked (200 characters at most), never a payload; the stored
facts stay.

Known difference to the classic panel: it reads `oxorder.stripeexternaltransid`, a column that does not exist in the shop
schema, so its "external transaction id" is always empty. The API does not use it.

## Provider wiring of payment-base's admin payment API

| payment-base asks for | Stripe |
| --- | --- |
| `Admin\Help\ContractStateHelpProviderInterface` (tag `oe.payment.admin_state_help`) | `StripeContractStateHelp`: provider `stripe`, the PaymentIntent status per contract state |
| `AdminActionOptionsProviderInterface` | `StripePaymentPanelProvider::actionOptions`: refund reasons `duplicate`, `requested_by_customer`, `fraudulent`; void reasons plus `abandoned`; `refundDescription` true; 140 characters (the classic form's `maxlength`) |
| `AdminActionBoundsProviderInterface` | `StripePaymentPanelProvider::boundsFor`: the live `amount_capturable` and the refundable amount of the intent; unavailable (writes refused) when the intent cannot be read |
| `AdminActionRefusedException` | the gate of `handleAction` (amount and text validation) stores the classic feedback and throws it with `feedbackRecorded: true`: codes `amount_malformed`, `amount_not_positive`, `amount_precision`, `amount_exceeds_bound`, `amount_bound_unavailable`, `blocked_character`; the sentence names field and rule, never the value |

`capture_reason`, `cancel_reason`, `refund_reason` and `refund_description` of the admin API reach Stripe through the same
dispatcher as the classic form's.

## Tests

- Unit (standalone, `tests/phpunit-unit.xml`): `tests/Unit/Stripe/GraphQL/Admin/**`, `tests/Unit/Stripe/Admin/*Pay1*`,
  `AdminRefusalSentenceTest`, `StripeContractStateHelpTest`, `StripeAdminActionBoundsTest`. Stripe is always a scripted fake.
- Integration (the shop): `tests/Integration/Stripe/GraphQL/Admin/**`: schema, wiring, parity, state help, no stored secret
  in a real answer.
- Playwright API matrix (not-authorized per operation, step-up, a real test-mode webhook round trip): PAY-9.
