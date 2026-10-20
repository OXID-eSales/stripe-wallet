# Sprint plan: graphql-base — a request without a GraphQL query must get a client error, not an HTTP 500

**For:** OXID GraphQL team (`oxid-esales/graphql-base`)
**Reported by:** OXID Payments (Stripe / payment-base), from a demo shop's production log — 2026-10-20
**Affected:** `graphql-base` `b-7.4.x` @ `b5a17d8` (12.0.x line); the code path is the same in earlier branches.
**Definition of Done (sprint-level):** every request to the GraphQL endpoint that carries no usable query string is
answered with a client-safe JSON error (an `InvalidRequest`, category `requesterror`). It is never an HTTP 500, and
it isn't logged as a server ERROR.

---

## The bug

### Reproduction (verified on a stock OXID 7.4 SDK shop with graphql-base `b-7.4.x`)

| Request to `/widget.php?cl=graphql&skipSession=1` | Response today |
|---|---|
| `POST`, `Content-Type: application/json`, body `{}` | **HTTP 500** `{"errors":[{"message":"Internal server error"}]}` |
| `POST`, `Content-Type: application/json`, body `not-json` | **HTTP 500** (same) |
| `GET` with no parameters | **HTTP 500** (same) |
| `POST`, `Content-Type: application/json`, body `{"query":123}` | **HTTP 500** (same) |

```bash
curl -i -X POST -H 'Content-Type: application/json' -d '{}' \
  'http://<shop>/widget.php?cl=graphql&skipSession=1'
```

Each request also writes an ERROR with a full stack trace to `oxideshop.log`:

```
OXID Logger.ERROR: GraphQL\Language\Source::__construct(): Argument #1 ($body) must be of type string, null given,
called in vendor/webonyx/graphql-php/src/GraphQL.php on line 140
  #2 graphql-base/src/Framework/GraphQLQueryHandler.php(70): GraphQL\GraphQL::executeQuery(Schema, NULL, …)
  #3 graphql-base/src/Framework/GraphQLQueryHandler.php(36): …->executeQuery(Array)
  #4 graphql-base/src/Component/Widget/GraphQL.php(44): …->executeGraphQLQuery()
```

In production the triggers are browsers opening the endpoint, uptime checks, scanners and misconfigured clients. These
are client mistakes, yet they show up as server errors in logs and monitoring.

### Root cause

1. `Framework/RequestReader::getGraphQLRequestData()` returns `'query' => $data['query'] ?? null`. The value is `null`
   when the body is empty, not JSON (`getJsonData()` falls back to `[]`), or a GET without `query`. It's an
   arbitrary JSON value (int, array, …) when a client sends one.
2. `Framework/GraphQLQueryHandler::executeQuery()` passes `$queryData['query']` unchecked to
   `GraphQL::executeQuery()`. webonyx's `Source::__construct(string $body)` throws a `TypeError`.
3. `TypeError` isn't a GraphQL `Error`, so `Component/Widget/GraphQL::init()` reaches its `catch (Throwable)` branch:
   it logs at ERROR and answers 500.
4. Static analysis can't see this: `executeQuery()` documents `@param array{query: string, …}`, but the reader
   documents no shape, so the `null` / `mixed` never meets the `string` annotation.

### Proposed behaviour

Follow the existing precedent in the same widget: the "PHP session spotted" check throws
`Exception\InvalidRequest`, and `GraphQL::init()` renders it as a JSON error
(`testQueryWithoutSkipSession` in `tests/Codeception/Acceptance/GraphQLCest.php`). A missing query is the same kind of
problem — a malformed request — so it gets the same treatment:

```json
{"errors":[{"message":"The request contains no GraphQL query."}]}
```

(`InvalidRequest::getCategory()` is `requesterror` internally; webonyx's `FormattedError` doesn't serialise the
category unless it is passed as an extension, which matches how the session error is rendered today.)

## Out of scope
- Changing the HTTP status convention of the widget (it answers GraphQL `Error`s with 200). See the open question below;
  this sprint keeps the existing convention.
- Request-body size limits, rate limiting or method restrictions (GET vs POST) on the endpoint.
- How `GraphQLQueryHandler::getErrorFormatter()` logs client-safe errors (it logs every GraphQL error at ERROR). That
  is a separate improvement; this sprint only makes sure the malformed-request case no longer produces a 500.

## Open question for the team
- **200 or 400?** The widget answers every GraphQL `Error` with HTTP 200. The GraphQL-over-HTTP spec allows 200 for
  `application/json` responses, and requires 400 for a malformed request only with
  `application/graphql-response+json`. Recommendation: keep 200 here for consistency with the session-error case and
  decide 400 separately for all request errors. If the team prefers 400, only the expected status in Story 2 changes.

## Risks & unknowns
- **Clients that rely on the 500.** Unlikely, since the body only ever said "Internal server error", but mention it in the
  CHANGELOG under *Changed*/*Fixed*.
- **`query: ""` (empty string)** currently ends in a webonyx syntax error (`Unexpected <EOF>`, not a 500). The guard
  below also treats an empty or whitespace-only query as "no query" for a clearer message. Drop that clause if the team
  prefers to keep webonyx's syntax error for it.

---

## Story 1 — Reject a request without a usable query string with `InvalidRequest`

**Why:** removes the `TypeError` → 500 path at its source and makes the request contract explicit in types.
**Estimate:** S

**Tests first (TDD)** — new `tests/Unit/Framework/GraphQLQueryHandlerTest.php`. Construct the handler with a mocked
`RequestReader` (returning the request data below) and a `ResponseWriter` mock that must **not** be called:
- `testExecuteGraphQLQueryWithoutQueryThrowsInvalidRequest` (`query => null`)
- `testExecuteGraphQLQueryWithNonStringQueryThrowsInvalidRequest` (data provider: `123`, `['a']`, `true`)
- `testExecuteGraphQLQueryWithBlankQueryThrowsInvalidRequest` (data provider: `''`, `"  \n"`)
- `testInvalidRequestForMissingQueryIsClientSafeWithRequestErrorCategory` — `isClientSafe() === true`,
  `getCategory() === ErrorCategories::REQUESTERROR`

Existing `tests/Unit/Framework/RequestReaderTest.php` stays green unchanged. `testGetGraphQLRequestDataWithEmptyRequest`
already documents that the reader returns `query => null`; the reader keeps that job and doesn't start validating.

**Implementation steps:**
1. `GraphQLQueryHandler::executeGraphQLQuery()`: pass the reader's data through a new private
   `assertQueryPresent(mixed $query): string` guard (≈6 lines, early return). If `$query` is not a string, or is blank
   after `trim()`, it throws `InvalidRequest('The request contains no GraphQL query.')`.
2. Pass the returned string to `executeQuery()`. Correct the docblocks to the real shapes:
   `RequestReader::getGraphQLRequestData(): array{query: mixed, variables: mixed, operationName: mixed}`;
   `executeQuery()` takes the validated `string $query` plus the rest, so phpstan now checks the path end to end.
3. CHANGELOG (current major file, *Fixed*): "A GraphQL request without a query string now returns a request error
   instead of HTTP 500 and is no longer logged as a server error."

**SOLID/Clean check:**
- SRP: `RequestReader` reads the HTTP request; `GraphQLQueryHandler` decides what can be executed. The guard lives in the
  handler, which is where the `string` requirement comes from.
- DIP: no new dependencies; reuses the existing `InvalidRequest` (no new exception class — `InvalidRequest` already
  means "malformed request" in this module).
- DRY: rendering, status code and category come from the widget's existing `catch (Error)` branch; no response code is
  added.
- Clean: no `else`; one guard method; no inline `\Exception`; message is a client-safe sentence.
- No overengineering: no request value object or validator service, because there's one field to check and one caller.

**DevOps gate:**
- `composer phpcs` ✓
- `composer phpstan` ✓ (no new baseline entries; the corrected array shapes must pass without suppressions)
- `composer phpmd` ✓
- `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit` ✓
- `vendor/bin/phpunit -c tests/phpunit.xml --testsuite Integration` ✓ (`GraphQLQueryHandlerTest`,
  `GraphQLQueryHandlerFileUploadTest` unchanged and green — multipart requests still work)

**Definition of Done:**
- No input to `executeGraphQLQuery()` can reach webonyx with a non-string query; proven by the unit tests and by phpstan.

---

## Story 2 — Prove it over HTTP: no 500, JSON request error, nothing logged as a server error

**Why:** the bug only shows up end to end (widget catch branches, status code, log). Unit tests on the handler alone
don't prove the widget behaviour.
**Estimate:** S

**Tests first (TDD)** — `tests/Codeception/Acceptance/GraphQLCest.php` (next to `testQueryWithoutSkipSession`), using
`/widget.php?cl=graphql&lang=0&shp=1&skipSession=1`:
- `testPostWithEmptyJsonBodyReturnsRequestError` — body `{}`
- `testPostWithInvalidJsonBodyReturnsRequestError` — raw body `not-json`
- `testGetWithoutQueryReturnsRequestError`
- `testPostWithNonStringQueryReturnsRequestError` — body `{"query":123}`

Each asserts: `seeResponseCodeIs(200)` (see open question), `seeResponseIsJson()`, and
`seeResponseMatchesJsonType(['errors' => [['message' => 'string:=The request contains no GraphQL query.']]])`.

Plus, in one of them, assert that `oxideshop.log` gained no `Source::__construct` / `TypeError` line during the request.
Use the suite's existing log helpers if there are any, otherwise compare file size before and after.

**Implementation steps:**
1. Write the four Cests; they fail against the current code (status 500).
2. They go green with Story 1 alone. If one doesn't, that request type reaches the handler by another path; fix that
   path, not the test.

**SOLID/Clean check:**
- DRY: the four Cests share a private `sendRawToGraphQL(string $method, ?string $body)` helper inside the Cest only if
  the setup is identical; otherwise keep them explicit (four short tests beat a clever helper).
- No production code in this story.

**DevOps gate:**
- Codeception Acceptance suite ✓ (`vendor/bin/codecept run Acceptance -c tests/codeception.yml`)
- Full Unit + Integration + phpcs/phpstan/phpmd as in Story 1 ✓

**Definition of Done:**
- All four reproduction requests from the table above get a JSON request error instead of an HTTP 500, verified by
  the acceptance suite in CI.

---

## Suggested order
1. Story 2's Cests first (red) — they lock in the externally visible behaviour.
2. Story 1 — unit tests red → guard → green; Story 2 turns green with it.
3. Settle the 200/400 question before release and adjust the one expected status if needed.
