# Subsprint 139b — Language and shop selected the documented way (`?lang=`, `?shp=`)

**Repos:** oxid-fe-core, oxid-fe-user, hydroxid-project · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Finding (report #2)

The API contract selects language and shop by query-string ids (`/graphql/?shp=2&lang=1`).
`GraphqlClient` sends `Accept-Language` only; it works through the shop's browser auto-detection
(first two letters), cannot address a subshop and breaks when auto-detection is off.

## Design (smallest thing)

- core: `GraphqlClient` gets an optional `endpointParams` provider → `{ lang?: number, shp?: number }`
  appended to the endpoint URL per request; `Accept-Language` stays (shops that auto-detect keep working).
- user: instance config `shop.language_ids: { de: 0, en: 1 }` (default: the demo shop's ids) and
  `shop.id` (optional, subshop); the provider maps the active locale through the table; an unmapped
  locale sends no `lang` (LSP-safe default, no exception). Rendered by `public-config.mjs` (allow-list
  row); validated in `schema.ts`.
- hydroxid: instances declare their `language_ids` where they differ from the default.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                          |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| B1 | core unit: `testRequest_LangProvided_AppendsLangToEndpoint`, `…_ShopIdProvided_AppendsShp`, `…_NoProvider_EndpointUnchanged`, `…_EndpointHasQuery_AppendsWithAmpersand`; the response cache key includes the final URL. |
| B2 | user unit: `schema.ts` accepts/rejects `language_ids` shapes; `languageIdFor(locale)` mapping (`de→0`, unknown → `undefined`); `public-config.mjs` renders the two fields.                                        |
| B3 | user e2e: extend `tests/e2e/shop-language.spec.ts` — every `/graphql` request of a German-only instance carries `lang=0`, of an English-only one `lang=1`, both still carry `Accept-Language`; after the language switch (sprint 16) the id follows. |
| B4 | hydroxid: config for the three instances, unit test of the rendered public config, e2e `language.spec.ts` asserts the `lang` id after a switch; docs `docs/instances.md`.                                             |
| B5 | Release core → user → hydroxid (pins by `#main` / tags, never commits); CHANGELOGs.                                                                                                                                       |

## Acceptance

With the shop's browser language detection switched off, the storefront still shows content in its
own language; a subshop id in the config reaches the API; the language switch changes the id;
gates and e2e green in all three repos.

## Out of scope

Reading language ids from the API (the storefront `Language` type has no code → id map we can trust
across shops); per-language URLs.
