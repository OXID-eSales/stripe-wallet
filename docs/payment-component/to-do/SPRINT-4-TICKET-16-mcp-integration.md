# SPRINT-4 TICKET-16: MCP (Model Context Protocol) Integration

**Priority:** 🔵 LOW (Optional)
**Estimated Effort:** 8-10 hours
**Sprint:** Sprint 4 (Advanced Features)
**Depends On:** TICKET-08, TICKET-09, TICKET-10
**Blocks:** AI-powered commerce automation

---

## 📋 Overview

Implement Model Context Protocol (MCP) server to enable AI agents (like Claude) to interact with the payment system. This enables AI-powered customer service, order management, and commerce automation.

**Why This Matters:**
- AI agents can handle customer payment inquiries
- Automated order management via natural language
- Claude Code and other AI tools can integrate
- Future-proof for AI-powered commerce

---

## 🎯 Goals

### Primary Objectives
1. MCP server implementation
2. MCP tools for payment operations
3. MCP resources for querying payment data
4. MCP prompts for common workflows
5. Integration examples with Claude/AI services

### Success Criteria
- ✅ MCP server running and accessible
- ✅ Tools implemented for all payment operations
- ✅ Resources provide payment/order data
- ✅ Prompts guide AI agents through workflows
- ✅ Example integrations documented

---

## 🏗️ Architecture

### MCP Server Structure

```typescript
// MCP Server provides:
{
  "tools": [
    {
      "name": "get_payment_status",
      "description": "Get status of a payment by ID",
      "inputSchema": {
        "type": "object",
        "properties": {
          "paymentId": {"type": "string"}
        }
      }
    },
    {
      "name": "capture_payment",
      "description": "Capture an authorized payment",
      "inputSchema": {
        "type": "object",
        "properties": {
          "paymentId": {"type": "string"},
          "amount": {"type": "number", "optional": true}
        }
      }
    },
    {
      "name": "refund_payment",
      "description": "Refund a captured payment",
      "inputSchema": {
        "type": "object",
        "properties": {
          "paymentId": {"type": "string"},
          "amount": {"type": "number"},
          "reason": {"type": "string"}
        }
      }
    }
  ],
  "resources": [
    {
      "uri": "payment://recent",
      "name": "Recent Payments",
      "description": "List of recent payments"
    },
    {
      "uri": "payment://{id}",
      "name": "Payment Details",
      "description": "Detailed information about a specific payment"
    }
  ],
  "prompts": [
    {
      "name": "investigate_payment_issue",
      "description": "Investigate why a payment failed",
      "arguments": [{"name": "paymentId", "required": true}]
    },
    {
      "name": "process_refund_request",
      "description": "Handle a customer refund request",
      "arguments": [{"name": "orderId", "required": true}]
    }
  ]
}
```

---

## 📝 Implementation

### MCP Server (Node.js/TypeScript)

**File:** `mcp/server.ts`

```typescript
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import axios from "axios";

const SHOP_API_URL = process.env.SHOP_API_URL || "http://localhost:8000";
const API_KEY = process.env.API_KEY || "";

const server = new Server(
  {
    name: "oxid-stripe-payment",
    version: "1.0.0",
  },
  {
    capabilities: {
      tools: {},
      resources: {},
      prompts: {},
    },
  }
);

// Tool: Get Payment Status
server.setRequestHandler("tools/call", async (request) => {
  const { name, arguments: args } = request.params;

  switch (name) {
    case "get_payment_status": {
      const response = await axios.get(
        `${SHOP_API_URL}/api/payment/${args.paymentId}`,
        { headers: { Authorization: `Bearer ${API_KEY}` } }
      );

      return {
        content: [
          {
            type: "text",
            text: JSON.stringify(response.data, null, 2),
          },
        ],
      };
    }

    case "capture_payment": {
      const response = await axios.post(
        `${SHOP_API_URL}/api/payment/${args.paymentId}/capture`,
        { amount: args.amount },
        { headers: { Authorization: `Bearer ${API_KEY}` } }
      );

      return {
        content: [
          {
            type: "text",
            text: `Payment captured successfully. Capture ID: ${response.data.captureId}`,
          },
        ],
      };
    }

    case "refund_payment": {
      const response = await axios.post(
        `${SHOP_API_URL}/api/payment/${args.paymentId}/refund`,
        { amount: args.amount, reason: args.reason },
        { headers: { Authorization: `Bearer ${API_KEY}` } }
      );

      return {
        content: [
          {
            type: "text",
            text: `Refund processed successfully. Refund ID: ${response.data.refundId}`,
          },
        ],
      };
    }

    case "list_pending_payments": {
      const response = await axios.get(
        `${SHOP_API_URL}/api/payments?status=pending`,
        { headers: { Authorization: `Bearer ${API_KEY}` } }
      );

      return {
        content: [
          {
            type: "text",
            text: `Found ${response.data.length} pending payments:\n` +
                  response.data.map((p: any) =>
                    `- ${p.id}: €${p.amount} (${p.createdAt})`
                  ).join('\n'),
          },
        ],
      };
    }

    default:
      throw new Error(`Unknown tool: ${name}`);
  }
});

// Resource: Payment Details
server.setRequestHandler("resources/read", async (request) => {
  const { uri } = request.params;

  if (uri.startsWith("payment://")) {
    const paymentId = uri.replace("payment://", "");

    const response = await axios.get(
      `${SHOP_API_URL}/api/payment/${paymentId}`,
      { headers: { Authorization: `Bearer ${API_KEY}` } }
    );

    return {
      contents: [
        {
          uri,
          mimeType: "application/json",
          text: JSON.stringify(response.data, null, 2),
        },
      ],
    };
  }

  throw new Error(`Unknown resource: ${uri}`);
});

// Prompt: Investigate Payment Issue
server.setRequestHandler("prompts/get", async (request) => {
  const { name, arguments: args } = request.params;

  switch (name) {
    case "investigate_payment_issue": {
      return {
        messages: [
          {
            role: "user",
            content: {
              type: "text",
              text: `Please investigate why payment ${args?.paymentId} failed. Check:
1. Payment status and error messages
2. Fraud check results
3. Payment provider logs
4. Customer's payment history

Provide a summary and recommended next steps.`,
            },
          },
        ],
      };
    }

    case "process_refund_request": {
      return {
        messages: [
          {
            role: "user",
            content: {
              type: "text",
              text: `A customer has requested a refund for order ${args?.orderId}. Please:
1. Verify the order and payment details
2. Check refund eligibility (time limits, return policy)
3. Calculate refund amount (including shipping if applicable)
4. Process the refund if approved
5. Send confirmation to customer`,
            },
          },
        ],
      };
    }

    default:
      throw new Error(`Unknown prompt: ${name}`);
  }
});

// List available tools
server.setRequestHandler("tools/list", async () => {
  return {
    tools: [
      {
        name: "get_payment_status",
        description: "Get detailed status and information about a payment",
        inputSchema: {
          type: "object",
          properties: {
            paymentId: {
              type: "string",
              description: "The payment contract ID",
            },
          },
          required: ["paymentId"],
        },
      },
      {
        name: "capture_payment",
        description: "Capture an authorized payment (charge the customer)",
        inputSchema: {
          type: "object",
          properties: {
            paymentId: {
              type: "string",
              description: "The payment contract ID",
            },
            amount: {
              type: "number",
              description: "Amount to capture (optional, defaults to full authorization)",
            },
          },
          required: ["paymentId"],
        },
      },
      {
        name: "refund_payment",
        description: "Issue a refund for a captured payment",
        inputSchema: {
          type: "object",
          properties: {
            paymentId: {
              type: "string",
              description: "The payment contract ID",
            },
            amount: {
              type: "number",
              description: "Amount to refund",
            },
            reason: {
              type: "string",
              description: "Reason for refund",
            },
          },
          required: ["paymentId", "amount"],
        },
      },
      {
        name: "list_pending_payments",
        description: "List all payments pending capture or review",
        inputSchema: {
          type: "object",
          properties: {},
        },
      },
    ],
  };
});

// Start server
const transport = new StdioServerTransport();
await server.connect(transport);
console.error("OXID Stripe Payment MCP Server running");
```

---

### Configuration

**File:** `mcp/package.json`

```json
{
  "name": "oxid-stripe-payment-mcp",
  "version": "1.0.0",
  "type": "module",
  "bin": {
    "oxid-stripe-payment-mcp": "./dist/server.js"
  },
  "scripts": {
    "build": "tsc",
    "start": "node dist/server.js"
  },
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.0.0",
    "axios": "^1.6.0"
  },
  "devDependencies": {
    "@types/node": "^20.0.0",
    "typescript": "^5.3.0"
  }
}
```

**File:** `mcp/tsconfig.json`

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "Node16",
    "moduleResolution": "Node16",
    "outDir": "./dist",
    "rootDir": "./",
    "strict": true,
    "esModuleInterop": true
  },
  "include": ["*.ts"]
}
```

---

### Claude Desktop Configuration

**File:** `~/.config/claude-desktop/config.json` (for users)

```json
{
  "mcpServers": {
    "oxid-stripe-payment": {
      "command": "node",
      "args": ["/path/to/mcp/dist/server.js"],
      "env": {
        "SHOP_API_URL": "https://your-shop.com",
        "API_KEY": "your-api-key-here"
      }
    }
  }
}
```

---

## 📊 Usage Examples

### Example 1: Check Payment Status

**User to Claude:**
> "Can you check the status of payment payment_abc123?"

**Claude uses tool:**
```
get_payment_status(paymentId: "payment_abc123")
```

**Response:**
```json
{
  "id": "payment_abc123",
  "status": "pending",
  "amount": 99.99,
  "currency": "EUR",
  "createdAt": "2025-10-30T10:30:00Z"
}
```

---

### Example 2: Process Refund

**User to Claude:**
> "Customer wants a full refund for order ORD-123. The payment is payment_xyz789."

**Claude uses prompt:**
```
process_refund_request(orderId: "ORD-123")
```

**Claude then uses tool:**
```
refund_payment(
  paymentId: "payment_xyz789",
  amount: 99.99,
  reason: "Customer requested refund"
)
```

---

## ✅ Acceptance Criteria

### Functional Requirements
- [ ] MCP server runs and responds to requests
- [ ] All payment tools implemented
- [ ] Resources provide payment data
- [ ] Prompts guide AI workflows
- [ ] Example integrations documented

### Integration Requirements
- [ ] Works with Claude Desktop
- [ ] Works with Claude Code CLI
- [ ] API authentication configured
- [ ] Error handling robust

---

## 📁 Files to Create

### MCP Server Files (4)
```
mcp/
├── server.ts                                  (300 lines)
├── package.json                               (25 lines)
├── tsconfig.json                              (15 lines)
└── README.md                                  (150 lines)
```

### Documentation (2)
```
docs/mcp/
├── setup-guide.md                             (100 lines)
└── usage-examples.md                          (200 lines)
```

**Total Lines:** ~790 (server: ~340, docs: ~450)

---

## 🚀 Implementation Order

### Day 1 (4-5 hours)
1. MCP server skeleton (1 hour)
2. Implement tools (2-3 hours)
3. Test with Claude Desktop (1 hour)

### Day 2 (4-5 hours)
1. Implement resources and prompts (2 hours)
2. Write documentation (2 hours)
3. Create usage examples (1 hour)

---

## 📋 Definition of Done

- [x] MCP server implemented
- [x] All 4 tools working
- [x] Resources implemented
- [x] Prompts defined
- [x] Documentation complete
- [x] Tested with Claude Desktop

---

**Estimated Completion:** 8-10 hours (1-1.5 days)
**Priority:** 🔵 LOW (Optional - AI Integration)
**Next Ticket:** TICKET-17 (Comprehensive Testing)

*Created: 2025-10-30*
*Version: 1.0*
