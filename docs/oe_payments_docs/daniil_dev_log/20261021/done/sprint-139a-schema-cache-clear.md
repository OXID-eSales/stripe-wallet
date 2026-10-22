# Subsprint 139a — `oe:graphql:cache-clear` in every install and update procedure

**Repos:** stripe, payment-base, mollie-payment, hydroxid-project · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Finding (report #1)

GraphQLite's schema is cached on the file system and never cleared automatically. None of our docs,
test scripts or the pay1 update steps run `vendor/bin/oe-console oe:graphql:cache-clear`; a module
update that adds a query can leave the old schema live (`Cannot query field "paymentClientConfig"`).

## Stories

| #  | Story                                                                                                                                                                                                                                                                                      |
| -- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1 | **Reproduce first (proof, red):** on the SDK shop activate a module version without `paymentClientConfig`, update to one with it, re-activate without clearing; query the field; record the error. Then clear and query again. Both outputs into `reports/139a-…`. |
| A2 | stripe `docs/for_merchant/02-headless-storefronts.md`: setup checklist step after activation + troubleshooting row for `Cannot query field …` → the cache-clear command; `docs/for_developer/05-headless-graphql-checkout.md` §1 and §11; `bin/graph-ql-cli-test.md` setup; `bin/graph-ql-cli-test.sh` runs the clear before the first query. |
| A3 | payment-base `docs/graphql-headless-checkout.md` install section; mollie-payment `docs/for_merchant` and `for_developer` headless pages (add the step where the GraphQL setup is described; if there is no such page, add one short page and link it from the README). |
| A4 | hydroxid `docs/ci.md` pay1 section: the shop update procedure (composer update → re-activate modules → cache clear → smoke query `{ paymentClientConfig … }`), plus the verification of the `/graphql/` rewrite (`QSA`, `skipSession=1`) with the exact `grep` to run on the host. |
| A5 | CI (all four module workflows): one line after module activation, `vendor/bin/oe-console oe:graphql:cache-clear`, so CI equals the documented procedure (fresh containers do not need it; the step documents it).                                                                   |

## Acceptance

Every page that tells a reader to activate or update a module with GraphQL controllers tells them to
clear the schema cache in the next step; the reproduction and the fix are in the report; CI green.

## Out of scope

Automating the clear inside module activation (shop core / graphql-base behaviour); pay1 host changes
(the owner runs the documented commands).
