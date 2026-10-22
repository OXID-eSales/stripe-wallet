# Subsprint 139c — `paymentContract` on the storefront `Order` type

**Repos:** payment-base, oxid-fe-user · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Finding (report #14)

The documentation's `@ExtendType` mechanism is unused. Headless order lists cannot show the
payment state of an order without a second channel.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                      |
| -- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1 | payment-base unit: `PaymentContractSummary` readonly value object (`state`, `provider`, `contractId`, `updatedAt`), built from a contract row by a `ContractSummaryQuery` (reads by `order_id`, returns `null` without a contract). |
| C2 | payment-base: `OrderRelationService` with `#[ExtendType(class: Order::class)]` and `#[Field] paymentContract(Order $order): ?PaymentContractSummary`; registered the documented way (namespace mapper type mapping, DI by FQCN, `@?` optional like the other GraphQL services). Schema test proves the field appears when graphql-storefront is installed and the module loads without it. |
| C3 | Integration: order with a committed contract → `{ orders { paymentContract { state provider } } }` returns it; an order placed through the Twig flow returns `null`; a foreign user's order is not reachable (storefront's own guard, asserted). |
| C4 | user: dashboard orders view shows the payment state badge from the field (unit test on the mapping, e2e on the sandbox order list); core's order query gains the optional selection.                                                                   |
| C5 | payment-base `docs/graphql-headless-checkout.md` section "Order type extension"; CHANGELOGs; `oe:graphql:cache-clear` in the proof (139a).                                                                                                        |

## Acceptance

A headless storefront lists orders with their payment state through one query; the module still
activates without graphql-storefront; CI green on 7.4/7.5/7.6.

## Out of scope

Mutations on the order (refund/capture stay in the admin); exposing provider ids or raw payloads.
