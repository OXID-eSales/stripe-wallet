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
| `adminStripeConfigModify(input: AdminStripeConfigInput!): AdminStripeConfigPayload!` | `ADMIN_PAYMENT_CONFIG` | changes settings; `{ config, errors }` |
| `adminStripeWebhookStatus: AdminStripeWebhookStatus!` | `ADMIN_PAYMENT_CONFIG` | `mode`, `configured`, `endpointId`, `url` |
| `adminStripeWebhookRegister: AdminStripeWebhookPayload!` | `ADMIN_PAYMENT_CONFIG` + step-up | registers or updates the shop's endpoint at Stripe, stores id and signing secret |
| `adminStripeWebhookClearAll: AdminStripeWebhookPayload!` | `ADMIN_PAYMENT_CONFIG` + step-up | deletes the endpoints at Stripe that point at this shop's URL, forgets the stored one |
| `AdminPaymentContract.stripe: AdminStripePaymentDetails` | `ADMIN_PAYMENT_VIEW` | `paymentIntentId`, `chargeId`, `externalTransactionId`, `intentStatus`, `dashboardUrl`, `apiError` (read-only; null for a contract of another provider) |

### Secrets are write-only

No type has a field that could carry a secret value: the config says `testTokenSet`, `liveTokenSet`, `testPlatformKeySet`,
`livePlatformKeySet`, `webhookSecretSet` and nothing else. The input takes `testToken`, `liveToken`, `testPlatformKey`,
`livePlatformKey` and `webhookSecret`; **empty or absent means unchanged** (there is no way to read one back, so empty cannot
mean "clear"). A secret is a field name in the audit log, never a value; `StripeConfigChange` marks its secrets
`#[SensitiveParameter]`; validation messages never quote a value; Stripe's own error messages are scrubbed of `sk_`/`rk_`/
`pk_`/`whsec_` strings (`StripeSecretRedactor`). The signing secret Stripe returns when it creates an endpoint is stored and
never returned. Proof: `Unit\Stripe\GraphQL\Admin\NoSecretInAnyAnswerTest` searches every field of every answer, refusals
included, for every stored, new and Stripe-created secret and for secret-shaped strings; the integration test does the same
against the secrets stored in the real shop.

### `adminStripeConfigModify`

1. **Step-up** (payment-base's `AdminStepUpInterface`) when the call switches to `live` or carries any non-empty secret
   (connected-account key, platform key, webhook secret). Like PayPal: not for every edit while already in live.
2. **Optimistic lock**: `expectedVersion` is the `version` of the config read (a hash of its fields; module settings have no
   row timestamp). A mismatch is a `ConflictError` and nothing is written. The lock is checked before the fields.
3. **Validation** (`StripeConfigChangeValidator`): enums (`not_allowed`), key formats (`invalid_format`: `pk_`, `sk_` for the
   token, `sk_`/`rk_` for the platform key, `whsec_`) and that a key belongs to its mode (`wrong_mode`).
4. **Key mismatch**: for every mode whose publishable key or token changes, the pair the change would leave behind is judged
   by `ConfigurationValidator::getKeyPairError`; both halves must exist for a judgement. Refused at the changed field with
   `key_mismatch`; the message quotes no key. Nothing is saved, not even the other fields of the call.
5. The write goes through OXID's module setting service (`OxidStripeAdminSettings`), the path of the classic form; a webhook
   secret goes to the per-mode oxconfig variable `ModuleConfigurationService::getWebhookSecret` reads first.

One audit row per call (`adminStripeConfigModify`, entity `paymentProvider` / `stripe`, field names only), success or
failure, including a refused step-up. Errors are `ConflictError` (`errorCode: conflict`) and `ValidationError`
(`errorCode: validation_failed`, `field`, `code`) in the payload.

### Webhook operations

They do what the classic "Create webhooks" / "Clear all webhooks" buttons do, through
`WebhookEndpointRegistrarInterface::registerForShop` / `clearAll` (`StripeWebhookAdministrator`). Both mutations need a
step-up first and write one audit row (`adminStripeWebhookRegister` / `adminStripeWebhookClearAll`, field `webhookEndpoint`).

- Register: the shop's own key first, a Connect webhook on the platform only for a connected account the platform
  controls (the registrar's rule); an existing endpoint id is updated in place and its secret is kept. Stored per mode in
  oxconfig (`sStripeWebhookEndpointId{Test,Live}`, `sStripeWebhookEndpointSecret{Test,Live}`) and, as the classic form does,
  in the legacy module settings. Refusals (`validation_failed`): `token_missing` (field `token`), `registration_failed`
  (field `webhook`, Stripe's message scrubbed).
- Clear-all: only endpoints of this shop's URL; refusals `platform_key_missing` (field `platformKey`), `clear_failed`.
  `deleted` in the payload is how many were removed.

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
