# Sprint 52 Completion Report — OAuth Agent Authentication

**Sprint:** 52
**Priority:** Low
**Status:** DONE
**Date:** 2026-02-13
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Summary

Implemented OAuth 2.1 Resource Server authentication for MCP endpoints. Supports JWT structural validation, RFC 7662 token introspection, and RFC 9728 protected resource metadata. Backward compatible with Sprint 47's static Bearer token auth.

## Files Created

### payment-component
- `src/Mcp/Auth/TokenValidatorInterface.php` — `validate(string $token): TokenValidationResult`
- `src/Mcp/Auth/TokenValidationResult.php` — Readonly VO: `valid()`, `invalid()` factories with subject, clientId, scopes, expiresAt
- `src/Mcp/Auth/JwtTokenValidator.php` — JWT format validation (header.payload.signature), checks issuer, audience, expiry (structural only, no JWKS signature verification)
- `src/Mcp/Auth/IntrospectionTokenValidator.php` — RFC 7662 token introspection via HTTP POST with Basic auth
- `src/Mcp/Auth/OAuthMcpAuthGuard.php` — Implements McpAuthGuardInterface, tries static token first (backward compat), then OAuth; records auth_method in AgentContext metadata
- `src/Mcp/Auth/ProtectedResourceMetadata.php` — RFC 9728: resource, authorization_servers, scopes_supported, bearer_methods_supported

### stripe
- `src/Stripe/Mcp/Controller/McpResourceMetadataController.php` — Serves `/.well-known/oauth-protected-resource` JSON with Cache-Control header

### Tests (5 files, 65 tests, 146 assertions)
- `tests/Unit/Mcp/Auth/TokenValidationResultTest.php` — 14 tests
- `tests/Unit/Mcp/Auth/JwtTokenValidatorTest.php` — 20 tests
- `tests/Unit/Mcp/Auth/IntrospectionTokenValidatorTest.php` — 9 tests
- `tests/Unit/Mcp/Auth/OAuthMcpAuthGuardTest.php` — 11 tests
- `tests/Unit/Mcp/Auth/ProtectedResourceMetadataTest.php` — 11 tests

### services.yaml additions
- `TokenValidatorInterface` (JWT by default)
- `ProtectedResourceMetadata` with server URL and authorization server params
- Parameters: `stripe.oauth.issuer`, `stripe.oauth.audience`, `stripe.oauth.jwks_uri`, `stripe.oauth.authorization_server`, `stripe.mcp.server_url`

## Key Design Decisions
- JWT validation is structural only (no JWKS signature verification) — production should use introspection or add JWKS support
- Static token auth preserved for backward compat (Sprint 47 deployments)
- OAuthMcpAuthGuard tries static token first (timing-safe comparison), then OAuth validator
- Protected resource metadata cached for 1 hour via Cache-Control header
- Scopes: `mcp:tools`, `mcp:resources` (extensible)
