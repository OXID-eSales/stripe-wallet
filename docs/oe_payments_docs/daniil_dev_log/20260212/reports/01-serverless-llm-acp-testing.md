# Report: Testing ACP Checkout with Serverless LLMs

**Date:** 2026-02-12
**Context:** Sprint 47 (ACP + UCP + MCP Support)
**Goal:** Enable human testers to validate the ACP checkout flow using a free/serverless LLM as a real AI agent — no local GPU, no paid API keys required.

---

## Problem Statement

Our ACP checkout exposes 6 MCP tools (`list_products`, `create_checkout`, `get_checkout`, `update_checkout`, `submit_checkout`, `cancel_checkout`). Unit and integration tests validate the PHP backend, but we need a way for a **human tester** to:

1. Point a real LLM at our MCP server
2. Give it a natural-language instruction ("buy a red t-shirt")
3. Watch the LLM discover tools, call them in the right order, and complete checkout
4. Verify the contract reaches `COMMITTED` / `FULFILLED` state

This requires a **tool-calling LLM** that can run serverlessly (no local GPU) and an **MCP client** that connects to our MCP endpoint.

---

## Approach Overview

```
┌─────────────────────────────────────────────────────────────┐
│  Human Tester's Machine (no GPU needed)                      │
│                                                               │
│  ┌─────────────────┐     ┌──────────────────────────────┐   │
│  │ Terminal / CLI    │     │ Serverless LLM (HuggingFace)  │   │
│  │ (tiny-agents)    │◄───►│ Qwen2.5-72B / Llama-3-70B     │   │
│  └────────┬────────┘     └──────────────────────────────┘   │
│           │ MCP JSON-RPC                                      │
│           ▼                                                   │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ Our MCP Server (https://shop.example.com/stripemcp)       │ │
│  │ tools/list → 6 ACP tools                                  │ │
│  │ tools/call → create_checkout, submit_checkout, etc.       │ │
│  └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

Three practical options, ranked by ease of setup:

| # | Option | Setup Time | LLM Cost | Best For |
|---|--------|-----------|----------|----------|
| 1 | **HuggingFace Tiny Agents** (recommended) | ~5 min | Free tier | Quick validation, CI smoke tests |
| 2 | **MCP Inspector + manual tool calls** | ~2 min | None | Debugging tool schemas, protocol verification |
| 3 | **Custom Python agent loop** | ~15 min | Free tier | Custom scenarios, regression suites |

---

## Option 1: HuggingFace Tiny Agents (Recommended)

### What It Is

[Tiny Agents](https://huggingface.co/blog/tiny-agents) is a minimal MCP-powered agent built into the `huggingface_hub` Python package. It's ~70 lines of code internally — a while loop that alternates between LLM tool-calling and MCP tool execution until the task is complete.

### Prerequisites

```bash
pip install "huggingface_hub[mcp]>=0.32.0"
```

Free HuggingFace account + API token from [huggingface.co/settings/tokens](https://huggingface.co/settings/tokens).

### Setup

Create an agent config directory (e.g., `tests/e2e/acp-agent/`):

**`agent.json`:**
```json
{
    "model": "Qwen/Qwen2.5-72B-Instruct",
    "provider": "nebius",
    "servers": [
        {
            "type": "http",
            "url": "https://shop.example.com/stripemcp"
        }
    ]
}
```

**`PROMPT.md`** (system prompt for the agent):
```markdown
You are a shopping assistant testing an e-commerce checkout.

Your goal: complete a purchase using the available MCP tools.

Steps:
1. Call list_products to see what's available
2. Pick a product and call create_checkout with it
3. Call get_checkout to verify the session
4. Call submit_checkout to complete the purchase
5. Report the final checkout status

Always use the tools — never guess product IDs or prices.
```

### Running

```bash
# Set your free HuggingFace token
export HF_TOKEN="hf_..."

# Run the agent
tiny-agents run ./tests/e2e/acp-agent/

# Then type your instruction:
# > "Buy the cheapest available product"
```

The agent will:
1. Connect to the MCP server at `https://shop.example.com/stripemcp`
2. Call `tools/list` to discover the 6 ACP tools
3. Use the LLM to decide which tools to call and in what order
4. Stream results back to the terminal in real-time

### Supported Models (Free Tier)

| Model | Provider | Tool-Calling Quality | Notes |
|-------|----------|---------------------|-------|
| `Qwen/Qwen2.5-72B-Instruct` | nebius | Excellent | Recommended — best open-source tool caller |
| `meta-llama/Llama-3.3-70B-Instruct` | nebius/together | Good | Solid alternative |
| `deepseek-ai/DeepSeek-R1-0528` | novita | Good | Reasoning model, may be verbose |
| `mistralai/Mixtral-8x22B-Instruct-v0.1` | together | Good | Fast, decent tool calling |

All available via HuggingFace Inference Providers free tier — no credit card required.

### Alternative: Run via Python Script

```python
import asyncio
from huggingface_hub.inference._mcp import Agent

async def main():
    agent = Agent(
        model="Qwen/Qwen2.5-72B-Instruct",
        provider="nebius",
        api_key="hf_...",
        servers=[{
            "type": "http",
            "url": "https://shop.example.com/stripemcp"
        }],
        prompt="You are a shopping assistant. Use the available tools to complete purchases."
    )

    await agent.load_tools()

    async for event in agent.run("Buy the cheapest available product"):
        if hasattr(event, 'content') and event.content:
            print(event.content, end="", flush=True)

asyncio.run(main())
```

---

## Option 2: MCP Inspector (Protocol Debugging)

### What It Is

[MCP Inspector](https://github.com/modelcontextprotocol/inspector) is Anthropic's official visual testing tool for MCP servers. It provides a web UI to call `tools/list`, `tools/call`, and inspect JSON-RPC messages — **no LLM needed**.

### When to Use

- Verify tool schemas are correct before connecting an LLM
- Debug individual tool calls with specific parameters
- Validate JSON-RPC request/response format
- Check auth (Bearer token) handling

### Setup

```bash
# Use version >= 0.14.1 (CVE-2025-49596 patched)
npx @modelcontextprotocol/inspector@latest
```

Opens a web UI at `http://localhost:6274`. Configure:
- **Transport:** Streamable HTTP
- **URL:** `https://shop.example.com/stripemcp`
- **Headers:** `Authorization: Bearer <agent-api-key>`

### Usage

1. Click **"List Tools"** — should show 6 ACP tools with JSON Schema parameters
2. Click any tool (e.g., `create_checkout`) — fill in parameters, execute
3. Inspect the raw JSON-RPC request and response
4. Walk through the full checkout flow manually

### Limitation

MCP Inspector is for **protocol verification**, not agentic testing. The human decides which tools to call and in what order — there's no LLM reasoning.

---

## Option 3: Custom Python Agent Loop

### When to Use

- Need custom validation logic between tool calls
- Building a regression test suite
- Want to use a specific LLM provider (Groq, Together AI)

### Architecture

```python
import json
from openai import OpenAI

# Use HuggingFace Inference API (free tier, OpenAI-compatible)
client = OpenAI(
    base_url="https://router.huggingface.co/v1",
    api_key="hf_..."  # Free HuggingFace token
)

# Or use Groq (free tier, very fast)
# client = OpenAI(
#     base_url="https://api.groq.com/openai/v1",
#     api_key="gsk_..."  # Free Groq API key
# )

MODEL = "Qwen/Qwen2.5-72B-Instruct"  # HuggingFace
# MODEL = "llama-3.3-70b-versatile"    # Groq
```

### MCP Client Setup

```python
from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client

async def connect_to_mcp(server_url: str, bearer_token: str):
    """Connect to our MCP server and discover tools."""
    headers = {"Authorization": f"Bearer {bearer_token}"}

    read, write, _ = await streamablehttp_client(server_url, headers=headers)

    async with ClientSession(read, write) as session:
        await session.initialize()

        # Discover tools
        tools_response = await session.list_tools()
        tools = tools_response.tools

        # Convert to OpenAI tool format
        openai_tools = []
        for tool in tools:
            openai_tools.append({
                "type": "function",
                "function": {
                    "name": tool.name,
                    "description": tool.description,
                    "parameters": tool.input_schema
                }
            })

        return session, openai_tools
```

### Agent Loop

```python
async def run_agent(user_instruction: str, server_url: str, token: str):
    """Run a complete agent loop: LLM ↔ MCP tools."""
    session, tools = await connect_to_mcp(server_url, token)

    messages = [
        {"role": "system", "content": "You are a shopping assistant. Use the tools to complete purchases."},
        {"role": "user", "content": user_instruction}
    ]

    max_turns = 10  # Safety limit
    for _ in range(max_turns):
        # 1. Ask LLM what to do
        response = client.chat.completions.create(
            model=MODEL,
            messages=messages,
            tools=tools,
            tool_choice="auto"
        )

        assistant_message = response.choices[0].message

        # 2. If no tool calls, we're done
        if not assistant_message.tool_calls:
            print(f"Agent: {assistant_message.content}")
            return assistant_message.content

        # 3. Execute each tool call via MCP
        messages.append(assistant_message)
        for tool_call in assistant_message.tool_calls:
            name = tool_call.function.name
            args = json.loads(tool_call.function.arguments)

            print(f"  → Calling {name}({json.dumps(args, indent=2)})")

            # Execute via MCP session
            result = await session.call_tool(name, args)

            print(f"  ← {result.content[:200]}...")

            messages.append({
                "role": "tool",
                "tool_call_id": tool_call.id,
                "name": name,
                "content": json.dumps(result.content)
            })

    print("Agent: Max turns reached")
```

### Running

```bash
pip install openai "mcp[streamable-http]"

python acp_test_agent.py "Buy a red t-shirt, size M"
```

---

## Free LLM Provider Comparison

| Provider | Free Tier | Tool-Calling Models | Speed | Setup |
|----------|----------|---------------------|-------|-------|
| **HuggingFace Inference** | Monthly credits (generous) | Qwen2.5-72B, Llama-3.3-70B, DeepSeek-R1 | Medium | `pip install huggingface_hub` |
| **Groq** | Free API key | Llama-3-Groq-70B-Tool-Use, Llama-3.3-70B | Very fast | `pip install groq` |
| **Together AI** | $25 startup credits | Llama-3.3-70B, Mixtral-8x22B | Fast | `pip install together` |

All three expose OpenAI-compatible APIs, so switching between them requires only changing `base_url` and `api_key`.

### HuggingFace Inference Providers (Primary Recommendation)

- **Free tier:** Monthly inference credits, no credit card
- **Pro tier:** $2/month for more credits + pay-as-you-go
- **API:** OpenAI-compatible (`https://router.huggingface.co/v1`)
- **Key feature:** Built-in MCP support via Responses API — can make remote MCP calls natively
- **Models:** Routes to backend providers (Nebius, Together, Novita, etc.) automatically

### Groq (Best for Speed)

- **Free tier:** Free API key with rate limits
- **Specialty:** Ultra-fast inference (tokens/second leader)
- **Models:** `llama-3-groq-70b-tool-use` — specifically fine-tuned for tool calling (89% on BFCL benchmark)
- **API:** OpenAI-compatible (`https://api.groq.com/openai/v1`)

---

## Recommended Testing Workflow

### For Quick Validation (Human Tester)

```
1. Set up MCP Inspector          → Verify tools/list returns 6 tools correctly
2. Set up Tiny Agents            → Point at MCP server with Qwen2.5-72B
3. Give natural language prompts  → "Buy the cheapest product", "Add 2 red shirts to cart"
4. Observe tool call sequence     → Verify LLM calls tools in correct order
5. Check shop admin               → Verify contract/order created in OXID
```

### For Regression Testing (CI/Scripted)

```
1. Custom Python agent (Option 3) → Script specific scenarios
2. Assert tool call sequence       → Verify create_checkout called before submit_checkout
3. Assert final state              → Verify contract reaches COMMITTED
4. Run with multiple LLMs          → Groq for speed, HuggingFace for variety
```

### Test Scenarios

| Scenario | User Instruction | Expected Tool Flow |
|----------|------------------|--------------------|
| Happy path | "Buy the cheapest product" | `list_products` → `create_checkout` → `submit_checkout` |
| Product discovery | "What products do you have?" | `list_products` → (text response) |
| Update before submit | "Buy a red shirt, size L" | `list_products` → `create_checkout` → `update_checkout` → `submit_checkout` |
| Cancel flow | "Start a checkout then cancel it" | `create_checkout` → `cancel_checkout` |
| Status check | "What's the status of checkout X?" | `get_checkout` → (text response) |
| Error handling | "Buy product XYZ-999" (invalid) | `create_checkout` → (error response) → LLM explains error |

---

## File Placement

When implementing, place the agent config and test scripts here:

```
tests/
├── e2e/
│   └── acp-agent/                     # Tiny Agents config
│       ├── agent.json                 # Model + MCP server config
│       └── PROMPT.md                  # System prompt
├── Fixture/
│   └── AcpAgentFixture.php            # Shared test data
└── Integration/
    └── Acp/
        └── AcpAgentScenarioTest.php   # Scripted Python agent assertions
```

---

## Security Notes

- Agent API keys should be test-only tokens with limited scope
- Never expose Stripe live keys through the MCP server
- The MCP server should validate `Authorization: Bearer` on every `tools/call`
- MCP Inspector versions < 0.14.1 have a critical RCE vulnerability (CVE-2025-49596) — always use latest

---

## Summary

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Primary tool | HuggingFace Tiny Agents | Zero-config MCP client, free LLM, 5-min setup |
| Primary model | Qwen/Qwen2.5-72B-Instruct | Best open-source tool caller, free on HuggingFace |
| Protocol debugging | MCP Inspector | Official Anthropic tool, visual JSON-RPC inspection |
| Speed testing | Groq free tier | Ultra-fast inference, Llama-3-Groq-70B-Tool-Use |
| Custom scenarios | Python agent loop | Full control, scriptable, CI-friendly |
| No local GPU needed | All options are serverless | Free tiers sufficient for testing |
