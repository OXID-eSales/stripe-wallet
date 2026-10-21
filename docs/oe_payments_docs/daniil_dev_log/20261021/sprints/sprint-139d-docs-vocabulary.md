# Subsprint 139d — Developer guide in the documentation's terms

**Repo:** stripe · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** 139a

## Findings (report #12, #13)

`docs/for_developer/05-headless-graphql-checkout.md` does not map our contract-first flow to the
documentation's "standard / express / own workflow" models, and does not state the basket-removal
semantics (`UserBasketRemovalHandler` on contract commit = the documented removal on `placeOrder`).

## Stories

| #  | Story                                                                                                                                                                                                                  |
| -- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1 | One section "How this relates to the OXID third-party-payments models": a three-row table (standard `3rdPartyApproval`+`placeOrder`, express, own workflow) with our mutations in the third row and why `placeOrder` is refused for our baskets (`BeforePlaceOrder` subscriber). |
| D2 | One paragraph on basket removal: when, by which handler, and that `BeforeBasketRemoveOnPlaceOrder` does not fire on our path.                                                                                      |
| D3 | A "Versions" note: tested against graphql-base `dev-b-7.4.x` (12.x) / storefront 4.x; what to check before moving to 13 / 5 (the report's item 17).                                                              |
| D4 | Link-check the developer and merchant README indexes; CHANGELOG [Unreleased] line.                                                                                                                                   |

## Acceptance

A developer who knows the OXID GraphQL documentation reads our guide without translating terms;
every statement in it is checked against the code in this sprint (cite the class next to each claim).

## Out of scope

Rewriting the guide's structure; mollie's and paypal's guides (they link to this one).
