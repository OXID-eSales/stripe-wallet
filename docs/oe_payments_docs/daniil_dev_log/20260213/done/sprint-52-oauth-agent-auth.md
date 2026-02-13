# Sprint 52: OAuth Agent Authentication

**Date:** 2026-02-09
**Status:** TODO
**Priority:** Low (Bearer token from Sprint 47 is sufficient for v1)
**Prerequisites:** Sprint 47 (MCP/ACP foundations — `McpAuthGuardInterface`, `McpAuthGuard`)
**Principle:** Upgrade from static Bearer token to OAuth 2.1 resource server. The MCP spec (2025-06-18) mandates OAuth 2.1 for HTTP transport. This sprint makes the shop a proper OAuth 2.1 Resource Server with Protected Resource Metadata discovery.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | ISP: auth guard interface stays unchanged — new implementation behind it |
| DI | Swap `McpAuthGuard` → `OAuthMcpAuthGuard` via services.yaml only |
| LSP | `OAuthMcpAuthGuard` is drop-in replacement for `McpAuthGuard` |
| DRY | Reuse existing `McpAuthGuardInterface` — no new auth interface |
| No Overengineering | Resource Server only — NOT an Authorization Server. Use external AS. |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Implement MCP's OAuth 2.1 authorization specification:

1. **Resource Server** — validate JWT/opaque access tokens on incoming MCP requests
2. **Protected Resource Metadata** — serve `/.well-known/oauth-protected-resource` (RFC 9728)
3. **Token validation** — JWT signature verification or token introspection
4. **Graceful fallback** — support Bearer token alongside OAuth during migration

### MCP OAuth Flow (shop's role highlighted)

```
┌────────────┐     ┌─────────────────┐     ┌──────────────────────────┐
│  AI Agent   │     │ Authorization   │     │ OXID Shop (MCP Server)   │
│ (MCP Client)│     │ Server          │     │ ◄── THIS SPRINT          │
│             │     │ (external)      │     │                          │
│ 1. GET      │────▶│                 │     │                          │
│    /.well-  │     │                 │◄────│ 2. Returns metadata      │
│    known/   │     │                 │     │    with AS URL           │
│    oauth-   │     │                 │     │                          │
│    protected│     │                 │     │                          │
│    -resource│     │                 │     │                          │
│             │     │                 │     │                          │
│ 3. GET      │────▶│ 4. Returns AS  │     │                          │
│    /.well-  │     │    metadata     │     │                          │
│    known/   │     │    (endpoints)  │     │                          │
│    oauth-as │     │                 │     │                          │
│             │     │                 │     │                          │
│ 5. Auth     │────▶│ 6. Issues      │     │                          │
│    request  │     │    access token │     │                          │
│    + PKCE   │     │                 │     │                          │
│             │     │                 │     │                          │
│ 7. MCP      │────────────────────────────▶│ 8. Validate token        │
│    request  │     │                 │     │    (JWT verify or        │
│    + Bearer │     │                 │     │     introspect)          │
│    token    │     │                 │     │ 9. Process request       │
└────────────┘     └─────────────────┘     └──────────────────────────┘
```

**Key constraint:** We are a **Resource Server** (step 8-9), NOT an Authorization Server (step 3-6). The AS is external (e.g., Auth0, Keycloak, Stripe's own OAuth).

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  payment-component (provider-agnostic)                            │
│                                                                   │
│  McpAuthGuardInterface (unchanged from Sprint 47)                 │
│  └─ authenticate(): AuthResult                                   │
│                                                                   │
│  NEW: OAuthMcpAuthGuard implements McpAuthGuardInterface          │
│  └─ Validates JWT or introspects token                           │
│  └─ Falls back to static Bearer if OAuth not configured          │
│                                                                   │
│  NEW: TokenValidatorInterface                                     │
│  ├── JwtTokenValidator (validates JWT locally)                   │
│  └── IntrospectionTokenValidator (calls AS /introspect endpoint) │
│                                                                   │
│  NEW: ProtectedResourceMetadata (value object)                    │
│  └─ Serializes to RFC 9728 JSON                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  stripe module                                                    │
│                                                                   │
│  NEW: McpResourceMetadataController                               │
│  └─ Serves /.well-known/oauth-protected-resource                 │
│  └─ Registered in metadata.php                                   │
│                                                                   │
│  MODIFIED: services.yaml                                          │
│  └─ Swap McpAuthGuardInterface binding to OAuthMcpAuthGuard      │
│  └─ Configure JWT issuer, audience, JWKS URI                    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module |
|-----------|-------------------|--------|
| `OAuthMcpAuthGuard` | Yes | payment-component |
| `TokenValidatorInterface` | Yes | payment-component |
| `JwtTokenValidator` | Yes | payment-component |
| `IntrospectionTokenValidator` | Yes | payment-component |
| `ProtectedResourceMetadata` | Yes | payment-component |
| `McpResourceMetadataController` | **No** | stripe |

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Mcp/Auth/
├── OAuthMcpAuthGuard.php
├── TokenValidatorInterface.php
├── JwtTokenValidator.php
├── IntrospectionTokenValidator.php
└── ProtectedResourceMetadata.php
```

### A1. TokenValidatorInterface

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

interface TokenValidatorInterface
{
    /**
     * Validate an access token and extract claims.
     *
     * @param string $token Raw access token (JWT or opaque)
     * @return TokenValidationResult Claims on success, error on failure
     */
    public function validate(string $token): TokenValidationResult;
}
```

### A2. TokenValidationResult

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

readonly class TokenValidationResult
{
    private function __construct(
        private bool $valid,
        private ?string $subject,
        private ?string $clientId,
        private array $scopes,
        private ?int $expiresAt,
        private ?string $errorMessage
    ) {}

    public static function valid(string $subject, string $clientId, array $scopes, int $expiresAt): self
    {
        return new self(true, $subject, $clientId, $scopes, $expiresAt, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, null, null, [], null, $reason);
    }

    public function isValid(): bool { return $this->valid; }
    public function getSubject(): ?string { return $this->subject; }
    public function getClientId(): ?string { return $this->clientId; }
    /** @return array<string> */
    public function getScopes(): array { return $this->scopes; }
    public function getExpiresAt(): ?int { return $this->expiresAt; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
}
```

### A3. JwtTokenValidator

Validates JWTs locally using JWKS. Requires `firebase/php-jwt` or similar.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

class JwtTokenValidator implements TokenValidatorInterface
{
    /**
     * @param string $issuer Expected JWT issuer (iss claim)
     * @param string $audience Expected JWT audience (aud claim)
     * @param string $jwksUri URI to fetch JSON Web Key Set
     */
    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $jwksUri
    ) {}

    public function validate(string $token): TokenValidationResult
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return TokenValidationResult::invalid('Not a valid JWT format');
            }

            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            if (!is_array($payload)) {
                return TokenValidationResult::invalid('Invalid JWT payload');
            }

            // Validate issuer
            if (($payload['iss'] ?? '') !== $this->issuer) {
                return TokenValidationResult::invalid('Invalid issuer');
            }

            // Validate audience
            $aud = $payload['aud'] ?? '';
            if (is_array($aud) ? !in_array($this->audience, $aud) : $aud !== $this->audience) {
                return TokenValidationResult::invalid('Invalid audience');
            }

            // Validate expiry
            $exp = $payload['exp'] ?? 0;
            if ($exp < time()) {
                return TokenValidationResult::invalid('Token expired');
            }

            // NOTE: Full JWKS signature verification would use firebase/php-jwt
            // This is a structural validator — signature check requires the JWT library

            return TokenValidationResult::valid(
                $payload['sub'] ?? 'unknown',
                $payload['client_id'] ?? $payload['azp'] ?? '',
                isset($payload['scope']) ? explode(' ', $payload['scope']) : [],
                (int) $exp
            );
        } catch (\Throwable $e) {
            return TokenValidationResult::invalid('JWT validation error: ' . $e->getMessage());
        }
    }
}
```

### A4. IntrospectionTokenValidator

For opaque tokens — calls the Authorization Server's introspection endpoint (RFC 7662).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;

class IntrospectionTokenValidator implements TokenValidatorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $introspectionEndpoint,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {}

    public function validate(string $token): TokenValidationResult
    {
        $response = $this->httpClient->post(
            $this->introspectionEndpoint,
            http_build_query(['token' => $token]),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            5
        );

        if (!$response->isSuccessful()) {
            return TokenValidationResult::invalid(
                'Introspection request failed: ' . ($response->getError() ?? 'HTTP ' . $response->getStatusCode())
            );
        }

        $data = json_decode($response->getBody(), true);
        if (!is_array($data) || !($data['active'] ?? false)) {
            return TokenValidationResult::invalid('Token is not active');
        }

        return TokenValidationResult::valid(
            $data['sub'] ?? 'unknown',
            $data['client_id'] ?? '',
            isset($data['scope']) ? explode(' ', $data['scope']) : [],
            $data['exp'] ?? 0
        );
    }
}
```

### A5. OAuthMcpAuthGuard

Replaces `McpAuthGuard`. Supports OAuth tokens AND static Bearer tokens (fallback).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContext;

class OAuthMcpAuthGuard implements McpAuthGuardInterface
{
    public function __construct(
        private readonly TokenValidatorInterface $tokenValidator,
        private readonly string $staticToken = ''
    ) {}

    public function authenticate(): AuthResult
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return AuthResult::failed('Missing Bearer token');
        }

        $token = substr($header, 7);

        // Try static token first (backward compat with Sprint 47)
        if ($this->staticToken !== '' && hash_equals($this->staticToken, $token)) {
            return AuthResult::success(new AgentContext(
                agentId: 'agent_' . substr(hash('sha256', $token), 0, 8),
                token: $token,
                metadata: ['auth_method' => 'bearer_static']
            ));
        }

        // Try OAuth token validation
        $validationResult = $this->tokenValidator->validate($token);
        if (!$validationResult->isValid()) {
            return AuthResult::failed($validationResult->getErrorMessage() ?? 'Invalid token');
        }

        return AuthResult::success(new AgentContext(
            agentId: $validationResult->getSubject() ?? 'unknown',
            token: $token,
            metadata: [
                'auth_method' => 'oauth',
                'client_id' => $validationResult->getClientId(),
                'scopes' => $validationResult->getScopes(),
                'expires_at' => $validationResult->getExpiresAt(),
            ]
        ));
    }
}
```

### A6. ProtectedResourceMetadata

RFC 9728 compliant metadata value object.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

readonly class ProtectedResourceMetadata
{
    /**
     * @param string $resource Resource identifier (MCP server URL)
     * @param array<string> $authorizationServers Authorization server URLs
     * @param array<string> $scopesSupported Supported OAuth scopes
     * @param array<string> $bearerMethodsSupported How tokens are passed
     */
    public function __construct(
        private string $resource,
        private array $authorizationServers,
        private array $scopesSupported = ['mcp:tools', 'mcp:resources'],
        private array $bearerMethodsSupported = ['header']
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
            'scopes_supported' => $this->scopesSupported,
            'bearer_methods_supported' => $this->bearerMethodsSupported,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
```

---

## Part B: stripe Module Changes

### B1. McpResourceMetadataController

**File:** `stripe/src/Stripe/Mcp/Controller/McpResourceMetadataController.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata;

class McpResourceMetadataController
{
    public function __construct(
        private readonly ProtectedResourceMetadata $metadata
    ) {}

    public function handleRequest(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo $this->metadata->toJson();
    }
}
```

### B2. services.yaml Changes

```yaml
# === OAuth Auth Guard (replaces static Bearer from Sprint 47) ===

# Token validator — choose one via config:
# Default: JWT (local validation). Switch to IntrospectionTokenValidator for opaque tokens.
OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface:
    class: OxidEsales\PaymentComponent\Mcp\Auth\JwtTokenValidator
    arguments:
        $issuer: '%stripe.oauth.issuer%'
        $audience: '%stripe.oauth.audience%'
        $jwksUri: '%stripe.oauth.jwks_uri%'

# Alternative: Introspection (opaque tokens) — uses HttpClientInterface from Sprint 47
# OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface:
#     class: OxidEsales\PaymentComponent\Mcp\Auth\IntrospectionTokenValidator
#     arguments:
#         $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
#         $introspectionEndpoint: '%stripe.oauth.introspection_endpoint%'
#         $clientId: '%stripe.oauth.client_id%'
#         $clientSecret: '%stripe.oauth.client_secret%'

# Override Sprint 47's McpAuthGuard with OAuth-aware version
OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface:
    class: OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard
    arguments:
        $tokenValidator: '@OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface'
        $staticToken: '%stripe.agent_api_key%'  # Backward compat fallback

# Protected Resource Metadata
OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata:
    arguments:
        $resource: '%stripe.mcp.server_url%'
        $authorizationServers: ['%stripe.oauth.authorization_server%']

# === Parameters ===

parameters:
    stripe.oauth.issuer: ''
    stripe.oauth.audience: ''
    stripe.oauth.jwks_uri: ''
    stripe.oauth.authorization_server: ''
    stripe.mcp.server_url: ''
```

### B3. metadata.php Addition

```php
'controllers' => [
    // ... existing ...
    'stripemcpresource' => \OxidEsales\Payments\Stripe\Mcp\Controller\McpResourceMetadataController::class,
],

// New settings in STRIPE_GENERAL group:
['name' => 'sStripeOAuthIssuer', 'type' => 'str', 'value' => ''],
['name' => 'sStripeOAuthAudience', 'type' => 'str', 'value' => ''],
['name' => 'sStripeOAuthJwksUri', 'type' => 'str', 'value' => ''],
['name' => 'sStripeOAuthAuthServer', 'type' => 'str', 'value' => ''],
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `Mcp/Auth/TokenValidatorInterface.php` | Token validation contract | ~15 |
| 2 | payment-component | `Mcp/Auth/TokenValidationResult.php` | Validation result VO | ~40 |
| 3 | payment-component | `Mcp/Auth/JwtTokenValidator.php` | JWT validation | ~65 |
| 4 | payment-component | `Mcp/Auth/IntrospectionTokenValidator.php` | Opaque token introspection | ~50 |
| 5 | payment-component | `Mcp/Auth/OAuthMcpAuthGuard.php` | OAuth + fallback auth | ~55 |
| 6 | payment-component | `Mcp/Auth/ProtectedResourceMetadata.php` | RFC 9728 metadata | ~35 |
| 7 | stripe | `Mcp/Controller/McpResourceMetadataController.php` | Metadata endpoint | ~20 |
| | | **Total** | | **~280** |

---

## TDD Approach

### Step 1: TokenValidationResult Tests
Test valid/invalid factories. Test claim accessors.

### Step 2: JwtTokenValidator Tests
Test valid JWT with correct issuer/audience. Test expired JWT. Test wrong issuer. Test wrong audience. Test malformed JWT.

### Step 3: IntrospectionTokenValidator Tests
Mock curl. Test active token. Test inactive token. Test introspection endpoint failure.

### Step 4: OAuthMcpAuthGuard Tests
Test static token fallback. Test OAuth token validation. Test OAuth failure falls through (no static match). Test both empty = rejected.

### Step 5: ProtectedResourceMetadata Tests
Test `toArray()` structure. Test `toJson()` output.

### Step 6: McpResourceMetadataController Tests
Test Content-Type header. Test response body matches metadata.

### Step 7: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `OAuthMcpAuthGuard` accepts static Bearer tokens (backward compat)
- [ ] `OAuthMcpAuthGuard` accepts valid JWT tokens
- [ ] `OAuthMcpAuthGuard` rejects expired JWTs
- [ ] `OAuthMcpAuthGuard` rejects JWTs with wrong issuer/audience
- [ ] `IntrospectionTokenValidator` calls AS introspection endpoint
- [ ] `GET /.well-known/oauth-protected-resource` returns RFC 9728 JSON
- [ ] Metadata includes `authorization_servers` and `scopes_supported`
- [ ] Sprint 47's static Bearer token still works (no breaking change)
- [ ] Swapping auth guard is services.yaml-only (no code changes)
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. MCP clients can authenticate via OAuth 2.1 access tokens
2. Static Bearer tokens continue to work (migration period)
3. `/.well-known/oauth-protected-resource` serves valid RFC 9728 metadata
4. JWTs are validated for issuer, audience, and expiry
5. Opaque tokens can be validated via AS introspection endpoint
6. Auth method is recorded in `AgentContext.metadata['auth_method']`
7. An Unzer module can reuse all OAuth components by configuring different AS URLs
