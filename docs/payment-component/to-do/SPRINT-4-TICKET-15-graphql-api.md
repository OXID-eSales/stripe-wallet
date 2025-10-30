# SPRINT-4 TICKET-15: GraphQL API Integration

**Priority:** 🔵 LOW (Optional)
**Estimated Effort:** 12-16 hours
**Sprint:** Sprint 4 (Advanced Features)
**Depends On:** TICKET-08, TICKET-09, TICKET-10
**Blocks:** Headless commerce support

---

## 📋 Overview

Implement GraphQL API for payment operations to enable headless commerce scenarios. This allows frontend applications (React, Vue, mobile apps) to integrate with the payment system via a modern API.

**Why This Matters:**
- Headless commerce is growing trend
- Mobile apps need API access
- Single-page apps (SPA) require JSON API
- GraphQL provides flexible querying

---

## 🎯 Goals

### Primary Objectives
1. GraphQL schema for payment operations
2. Mutations for payment lifecycle (initiate, authorize, capture, refund)
3. Queries for payment status and order details
4. Subscriptions for real-time updates (optional)
5. API authentication and authorization
6. API rate limiting
7. Comprehensive API documentation

### Success Criteria
- ✅ GraphQL endpoint accessible at `/graphql`
- ✅ Mutations work for all payment operations
- ✅ Queries return payment and order data
- ✅ API secured with authentication tokens
- ✅ 20+ API tests passing
- ✅ Documentation generated automatically

---

## 🏗️ Architecture

### GraphQL Schema

```graphql
type Query {
  payment(id: ID!): Payment
  payments(userId: ID!, limit: Int, offset: Int): [Payment!]!
  order(id: ID!): Order
  orders(userId: ID!, limit: Int, offset: Int): [Order!]!
}

type Mutation {
  initiatePayment(input: InitiatePaymentInput!): InitiatePaymentPayload!
  authorizePayment(input: AuthorizePaymentInput!): AuthorizePaymentPayload!
  capturePayment(input: CapturePaymentInput!): CapturePaymentPayload!
  refundPayment(input: RefundPaymentInput!): RefundPaymentPayload!
}

type Subscription {
  paymentStatusChanged(paymentId: ID!): Payment!
}

type Payment {
  id: ID!
  userId: ID!
  amount: Float!
  currency: String!
  status: PaymentStatus!
  providerOrderId: String
  orderId: ID
  createdAt: DateTime!
  updatedAt: DateTime!
}

enum PaymentStatus {
  DRAFT
  PENDING
  AUTHORIZED
  CAPTURED
  REFUNDED
  FAILED
  CANCELLED
}

input InitiatePaymentInput {
  userId: ID!
  amount: Float!
  currency: String!
  basketItems: [BasketItemInput!]!
  returnUrl: String!
  cancelUrl: String!
}

type InitiatePaymentPayload {
  payment: Payment!
  clientSecret: String!
  errors: [Error!]
}
```

---

## 📝 Implementation Phases

### Phase 1: GraphQL Schema & Types

**Goal:** Define GraphQL types and schema

**File:** `src/GraphQL/schema.graphql`

---

### Phase 2: Resolvers (TDD)

**Test File:** `tests/Unit/GraphQL/PaymentResolverTest.php`

**Test Specifications:**
```php
class PaymentResolverTest extends TestCase
{
    // 1. Query payment by ID
    public function testQueryPaymentById(): void
    {
        // Given: Payment exists
        // When: Query { payment(id: "123") }
        // Then: Returns payment data
    }

    // 2. Initiate payment mutation
    public function testInitiatePaymentMutation(): void
    {
        // Given: Valid payment input
        // When: Mutation { initiatePayment(...) }
        // Then: Creates payment, returns client secret
    }

    // 3. Capture payment mutation
    public function testCapturePaymentMutation(): void
    {
        // Given: Authorized payment
        // When: Mutation { capturePayment(id: "123") }
        // Then: Captures payment, returns success
    }

    // 4. Unauthorized access denied
    public function testUnauthorizedAccessDenied(): void
    {
        // Given: No auth token
        // When: Query { payments }
        // Then: Returns authentication error
    }
}
```

**Implementation:** `src/GraphQL/Resolver/PaymentResolver.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\GraphQL\Resolver;

use OxidSolutionCatalysts\Payments\Service\PaymentService;

class PaymentResolver
{
    public function __construct(
        private PaymentService $paymentService
    ) {
    }

    public function payment(array $args): ?array
    {
        $payment = $this->paymentService->findById($args['id']);
        return $payment ? $this->formatPayment($payment) : null;
    }

    public function payments(array $args): array
    {
        $payments = $this->paymentService->findByUserId(
            $args['userId'],
            $args['limit'] ?? 10,
            $args['offset'] ?? 0
        );

        return array_map([$this, 'formatPayment'], $payments);
    }

    public function initiatePayment(array $args): array
    {
        try {
            $result = $this->paymentService->initiatePayment($args['input']);

            return [
                'payment' => $this->formatPayment($result['payment']),
                'clientSecret' => $result['clientSecret'],
                'errors' => [],
            ];
        } catch (\Exception $e) {
            return [
                'payment' => null,
                'clientSecret' => null,
                'errors' => [['message' => $e->getMessage()]],
            ];
        }
    }

    private function formatPayment($payment): array
    {
        return [
            'id' => $payment->getId(),
            'userId' => $payment->getUserId(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrency(),
            'status' => $payment->getStatus(),
            'providerOrderId' => $payment->getProviderOrderId(),
            'orderId' => $payment->getOrderId(),
            'createdAt' => $payment->getCreatedAt()->format('c'),
            'updatedAt' => $payment->getUpdatedAt()->format('c'),
        ];
    }
}
```

---

### Phase 3: Authentication Middleware

**Goal:** Secure API with JWT tokens

**Implementation:** `src/GraphQL/Middleware/AuthMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\GraphQL\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware
{
    public function __construct(
        private string $jwtSecret
    ) {
    }

    public function authenticate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function verifyAccess(array $user, string $resource): bool
    {
        // Implement role-based access control
        return in_array($resource, $user['permissions'] ?? []);
    }
}
```

---

### Phase 4: GraphQL Server Setup

**Goal:** Configure GraphQL endpoint

**File:** `src/Controller/GraphQLController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Controller;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use OxidEsales\Eshop\Application\Controller\FrontendController;

class GraphQLController extends FrontendController
{
    public function __construct(
        private Schema $schema,
        private AuthMiddleware $authMiddleware
    ) {
        parent::__construct();
    }

    public function handleRequest(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? [];

        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $user = $this->authMiddleware->authenticate($token);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['errors' => [['message' => 'Unauthorized']]]);
            exit;
        }

        try {
            $result = GraphQL::executeQuery($this->schema, $query, null, $user, $variables);
            $output = $result->toArray();
        } catch (\Exception $e) {
            $output = ['errors' => [['message' => $e->getMessage()]]];
        }

        header('Content-Type: application/json');
        echo json_encode($output);
        exit;
    }
}
```

---

## 📊 Test Summary

### Resolver Tests (12 tests)
1. PaymentResolver: 6 tests
2. OrderResolver: 4 tests
3. MutationResolver: 2 tests

### Integration Tests (8 tests)
1. Complete payment flow via GraphQL
2. Authentication/authorization
3. Error handling
4. Rate limiting

**Total: 20+ tests**

---

## ✅ Acceptance Criteria

### Functional Requirements
- [ ] GraphQL endpoint at `/graphql`
- [ ] All payment operations via API
- [ ] Authentication with JWT tokens
- [ ] Rate limiting active
- [ ] Documentation auto-generated

### Non-Functional Requirements
- [ ] Response time < 200ms
- [ ] All 20+ tests passing
- [ ] API versioned (v1)

---

## 📁 Files to Create

### Source Files (6)
```
src/GraphQL/
├── schema.graphql                             (200 lines)
├── Resolver/
│   ├── PaymentResolver.php                    (150 lines)
│   └── OrderResolver.php                      (120 lines)
├── Middleware/
│   └── AuthMiddleware.php                     (60 lines)
└── Type/
    ├── PaymentType.php                        (80 lines)
    └── OrderType.php                          (80 lines)

src/Controller/
└── GraphQLController.php                      (80 lines)
```

### Test Files (3)
```
tests/Unit/GraphQL/
├── PaymentResolverTest.php                    (200 lines)
├── OrderResolverTest.php                      (150 lines)
└── AuthMiddlewareTest.php                     (80 lines)
```

**Total Lines:** ~1,200 (source: ~770, tests: ~430)

---

## 🚀 Implementation Order

### Day 1-2 (8 hours)
1. Schema definition (2 hours)
2. Resolvers implementation (4 hours)
3. Write resolver tests (2 hours)

### Day 3 (4-8 hours)
1. Authentication middleware (2 hours)
2. GraphQL server setup (2 hours)
3. Integration tests (2-4 hours)
4. Documentation (1-2 hours)

---

## 📋 Definition of Done

- [x] GraphQL schema defined
- [x] All resolvers implemented
- [x] Authentication middleware
- [x] All 20+ tests passing
- [x] API documentation generated
- [x] Rate limiting configured

---

**Estimated Completion:** 12-16 hours (2-3 days)
**Priority:** 🔵 LOW (Optional - Headless Commerce)
**Next Ticket:** TICKET-16 (MCP Integration)

*Created: 2025-10-30*
*Version: 1.0*
