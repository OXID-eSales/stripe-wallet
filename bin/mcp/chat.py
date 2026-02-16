#!/usr/bin/env python3
"""
MCP Chat Client — test OXID Stripe ACP tools via LLM conversation.

Usage:
    python chat.py                  # interactive chat
    python chat.py "buy me glasses" # single prompt then interactive

The LLM has access to MCP tools (list_products, create_checkout, etc.)
registered on the OXID shop's MCP endpoint. It calls them as needed
to fulfill your shopping requests.

Supports two LLM providers (set LLM_PROVIDER in .env):
    - "anthropic" — Anthropic Messages API (Claude)
    - "openai"    — OpenAI-compatible API (OpenAI, DeepSeek, OpenRouter, etc.)
"""

import json
import os
import sys
import urllib.request
import urllib.error
from abc import ABC, abstractmethod
from pathlib import Path

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------


def load_env(path: str) -> None:
    """Load .env file into os.environ (simple key=value parser)."""
    env_path = Path(path)
    if not env_path.exists():
        return
    for line in env_path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        key, _, value = line.partition("=")
        os.environ.setdefault(key.strip(), value.strip())


load_env(str(Path(__file__).parent / ".env"))

LLM_PROVIDER = os.environ.get("LLM_PROVIDER", "anthropic")
LLM_API_ENDPOINT = os.environ.get("LLM_API_ENDPOINT", "")
LLM_API_KEY = os.environ.get("LLM_API_KEY", "")
LLM_MODEL = os.environ.get("LLM_MODEL", "")
MCP_SERVER_URL = os.environ.get("MCP_SERVER_URL", "")
MCP_BEARER_TOKEN = os.environ.get("MCP_BEARER_TOKEN", "")
SHOP_URL = os.environ.get("SHOP_URL", "")

# ---------------------------------------------------------------------------
# MCP client helpers
# ---------------------------------------------------------------------------

_jsonrpc_id = 0


def next_id() -> int:
    global _jsonrpc_id
    _jsonrpc_id += 1
    return _jsonrpc_id


def mcp_call(method: str, params: dict | None = None) -> dict:
    """Send a JSON-RPC 2.0 request to the MCP server."""
    payload = {
        "jsonrpc": "2.0",
        "id": next_id(),
        "method": method,
        "params": params or {},
    }
    data = json.dumps(payload).encode()
    req = urllib.request.Request(
        MCP_SERVER_URL,
        data=data,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {MCP_BEARER_TOKEN}",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            body = resp.read().decode()
            return json.loads(body)
    except urllib.error.HTTPError as e:
        body = e.read().decode() if e.fp else ""
        return {"error": {"code": e.code, "message": body}}
    except Exception as e:
        return {"error": {"code": -1, "message": str(e)}}


def mcp_initialize() -> dict:
    """Initialize MCP session."""
    return mcp_call("initialize", {
        "protocolVersion": "2025-06-18",
        "capabilities": {},
        "clientInfo": {"name": "mcp-chat-client", "version": "1.0.0"},
    })


def mcp_list_tools() -> list[dict]:
    """Get available MCP tools with their schemas."""
    resp = mcp_call("tools/list")
    result = resp.get("result", {})
    return result.get("tools", [])


def mcp_call_tool(tool_name: str, arguments: dict) -> dict:
    """Execute an MCP tool and return the response."""
    return mcp_call("tools/call", {"name": tool_name, "arguments": arguments})


# ---------------------------------------------------------------------------
# LLM Provider Interface
# ---------------------------------------------------------------------------


class LlmProvider(ABC):
    """Abstract interface for LLM API providers.

    All providers accept messages in canonical (Anthropic-like) format and
    return a normalized response dict:
        {"stop_reason": "end_turn"|"tool_use", "content": [blocks]}

    Content blocks use the same canonical format:
        {"type": "text", "text": "..."}
        {"type": "tool_use", "id": "...", "name": "...", "input": {...}}
    """

    def __init__(self, api_endpoint: str, api_key: str, model: str):
        self.api_endpoint = api_endpoint
        self.api_key = api_key
        self.model = model

    @abstractmethod
    def chat(self, messages: list[dict], mcp_tools: list[dict], system: str) -> dict:
        """Send a chat request and return a normalized response."""


# ---------------------------------------------------------------------------
# Anthropic Provider (Claude)
# ---------------------------------------------------------------------------


class AnthropicProvider(LlmProvider):
    """Anthropic Messages API provider."""

    def __init__(self, api_endpoint: str, api_key: str, model: str):
        endpoint = api_endpoint or "https://api.anthropic.com/v1/messages"
        if "/v1/messages" not in endpoint:
            endpoint = endpoint.rstrip("/") + "/v1/messages"
        super().__init__(endpoint, api_key, model or "claude-sonnet-4-5-20250929")

    def chat(self, messages: list[dict], mcp_tools: list[dict], system: str) -> dict:
        tools = [
            {
                "name": t["name"],
                "description": t.get("description", ""),
                "input_schema": t.get("inputSchema", {"type": "object", "properties": {}}),
            }
            for t in mcp_tools
        ]

        payload = {
            "model": self.model,
            "max_tokens": 4096,
            "system": system,
            "messages": messages,
            "tools": tools,
        }

        data = json.dumps(payload).encode()
        req = urllib.request.Request(
            self.api_endpoint,
            data=data,
            headers={
                "Content-Type": "application/json",
                "x-api-key": self.api_key,
                "anthropic-version": "2023-06-01",
            },
            method="POST",
        )

        with urllib.request.urlopen(req, timeout=120) as resp:
            response = json.loads(resp.read().decode())

        return {
            "stop_reason": response.get("stop_reason", "end_turn"),
            "content": response.get("content", []),
        }


# ---------------------------------------------------------------------------
# OpenAI-compatible Provider (OpenAI, DeepSeek, OpenRouter, etc.)
# ---------------------------------------------------------------------------


class OpenAiProvider(LlmProvider):
    """OpenAI-compatible chat completions API provider."""

    def __init__(self, api_endpoint: str, api_key: str, model: str):
        endpoint = api_endpoint or "https://api.openai.com"
        if "/chat/completions" not in endpoint:
            endpoint = endpoint.rstrip("/") + "/v1/chat/completions"
        super().__init__(endpoint, api_key, model or "gpt-4o")

    def chat(self, messages: list[dict], mcp_tools: list[dict], system: str) -> dict:
        tools = [
            {
                "type": "function",
                "function": {
                    "name": t["name"],
                    "description": t.get("description", ""),
                    "parameters": t.get("inputSchema", {"type": "object", "properties": {}}),
                },
            }
            for t in mcp_tools
        ]

        oai_messages: list[dict] = [{"role": "system", "content": system}]
        for msg in messages:
            oai_messages.extend(self._convert_message(msg))

        payload: dict = {
            "model": self.model,
            "max_tokens": 4096,
            "messages": oai_messages,
        }
        if tools:
            payload["tools"] = tools

        data = json.dumps(payload).encode()
        req = urllib.request.Request(
            self.api_endpoint,
            data=data,
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.api_key}",
            },
            method="POST",
        )

        with urllib.request.urlopen(req, timeout=120) as resp:
            response = json.loads(resp.read().decode())

        return self._normalize_response(response)

    @staticmethod
    def _convert_message(msg: dict) -> list[dict]:
        """Convert a canonical message to OpenAI format (may produce multiple messages)."""
        role = msg["role"]
        content = msg["content"]

        if role == "user":
            if isinstance(content, str):
                return [{"role": "user", "content": content}]
            # Tool results: canonical format has tool_result blocks in user message
            if isinstance(content, list):
                results = []
                for block in content:
                    if block.get("type") == "tool_result":
                        results.append({
                            "role": "tool",
                            "tool_call_id": block["tool_use_id"],
                            "content": block.get("content", ""),
                        })
                if results:
                    return results
                return [{"role": "user", "content": json.dumps(content)}]

        if role == "assistant":
            if isinstance(content, str):
                return [{"role": "assistant", "content": content}]
            if isinstance(content, list):
                text_parts = []
                tool_calls = []
                for block in content:
                    if block.get("type") == "text" and block.get("text"):
                        text_parts.append(block["text"])
                    elif block.get("type") == "tool_use":
                        tool_calls.append({
                            "id": block["id"],
                            "type": "function",
                            "function": {
                                "name": block["name"],
                                "arguments": json.dumps(block["input"]),
                            },
                        })
                msg_out: dict = {"role": "assistant"}
                msg_out["content"] = "\n".join(text_parts) if text_parts else None
                if tool_calls:
                    msg_out["tool_calls"] = tool_calls
                return [msg_out]

        return [{"role": role, "content": str(content)}]

    @staticmethod
    def _normalize_response(response: dict) -> dict:
        """Convert OpenAI response to canonical format."""
        choice = response["choices"][0]
        message = choice["message"]
        finish_reason = choice.get("finish_reason", "stop")

        content_blocks: list[dict] = []

        if message.get("content"):
            content_blocks.append({"type": "text", "text": message["content"]})

        for tc in message.get("tool_calls") or []:
            fn = tc["function"]
            try:
                arguments = json.loads(fn["arguments"])
            except (json.JSONDecodeError, TypeError):
                arguments = {}
            content_blocks.append({
                "type": "tool_use",
                "id": tc["id"],
                "name": fn["name"],
                "input": arguments,
            })

        stop_reason = "tool_use" if finish_reason == "tool_calls" else "end_turn"

        return {"stop_reason": stop_reason, "content": content_blocks}


# ---------------------------------------------------------------------------
# Provider Factory
# ---------------------------------------------------------------------------


def create_llm_provider(provider: str, endpoint: str, api_key: str, model: str) -> LlmProvider:
    """Create the appropriate LLM provider based on config."""
    providers: dict[str, type[LlmProvider]] = {
        "anthropic": AnthropicProvider,
        "openai": OpenAiProvider,
    }
    cls = providers.get(provider)
    if cls is None:
        supported = ", ".join(sorted(providers.keys()))
        raise ValueError(f"Unknown LLM_PROVIDER '{provider}'. Supported: {supported}")
    return cls(endpoint, api_key, model)


# ---------------------------------------------------------------------------
# Chat loop
# ---------------------------------------------------------------------------

SYSTEM_PROMPT = f"""You are a shopping assistant for an OXID eShop store at {SHOP_URL}.
You help users browse products and complete purchases using the available MCP tools.

Workflow:
1. Use list_products to find products the user wants.
2. Use create_checkout to start a checkout with the selected items.
3. Share the checkout details with the user.
4. If the user confirms, use complete_checkout to finalize (you'll need payment details).

Always show product names, prices, and IDs when listing products.
When creating a checkout, show the checkout ID and total amount.
Be concise and helpful."""


def print_colored(text: str, color: str = "green") -> None:
    colors = {"green": "\033[92m", "blue": "\033[94m", "yellow": "\033[93m", "red": "\033[91m", "reset": "\033[0m"}
    print(f"{colors.get(color, '')}{text}{colors.get('reset', '')}")


def run_chat(initial_message: str | None = None) -> None:
    # Validate config
    if not LLM_API_KEY or LLM_API_KEY in ("your-api-key-here", "your-anthropic-api-key-here"):
        print_colored("ERROR: Set LLM_API_KEY in bin/mcp/.env", "red")
        sys.exit(1)
    if not MCP_SERVER_URL:
        print_colored("ERROR: Set MCP_SERVER_URL in bin/mcp/.env", "red")
        sys.exit(1)

    # Create LLM provider
    try:
        llm = create_llm_provider(LLM_PROVIDER, LLM_API_ENDPOINT, LLM_API_KEY, LLM_MODEL)
    except ValueError as e:
        print_colored(f"ERROR: {e}", "red")
        sys.exit(1)

    print_colored(f"LLM: {LLM_PROVIDER} / {llm.model}", "green")

    # Initialize MCP
    print_colored("Connecting to MCP server...", "yellow")
    init_resp = mcp_initialize()
    if "error" in init_resp:
        print_colored(f"MCP init failed: {json.dumps(init_resp['error'], indent=2)}", "red")
        sys.exit(1)

    server_info = init_resp.get("result", {}).get("serverInfo", {})
    print_colored(f"Connected to {server_info.get('name', '?')} v{server_info.get('version', '?')}", "green")

    # Get tools
    mcp_tools = mcp_list_tools()
    tool_names = [t["name"] for t in mcp_tools]
    print_colored(f"Available tools: {', '.join(tool_names)}", "green")
    print()

    messages: list[dict] = []

    if initial_message:
        print(f"You: {initial_message}")
        messages.append({"role": "user", "content": initial_message})
    else:
        print_colored("Type your message (Ctrl+C to quit):", "yellow")

    while True:
        # Get user input if no pending message
        if not messages or messages[-1]["role"] != "user":
            try:
                user_input = input("\nYou: ").strip()
            except (KeyboardInterrupt, EOFError):
                print("\nBye!")
                break
            if not user_input:
                continue
            if user_input.lower() in ("quit", "exit", "q"):
                print("Bye!")
                break
            messages.append({"role": "user", "content": user_input})

        # Call LLM
        try:
            response = llm.chat(messages, mcp_tools, SYSTEM_PROMPT)
        except urllib.error.HTTPError as e:
            body = e.read().decode() if e.fp else ""
            print_colored(f"LLM API error {e.code}: {body[:500]}", "red")
            messages.pop()  # remove failed user message so they can retry
            continue
        except Exception as e:
            print_colored(f"LLM error: {e}", "red")
            messages.pop()
            continue

        # Process response
        stop_reason = response.get("stop_reason", "")
        content_blocks = response.get("content", [])

        # Collect assistant message
        messages.append({"role": "assistant", "content": content_blocks})

        # Handle tool use
        if stop_reason == "tool_use":
            tool_results = []
            for block in content_blocks:
                if block.get("type") == "text" and block["text"].strip():
                    print_colored(f"\nAssistant: {block['text']}", "blue")

                if block.get("type") == "tool_use":
                    tool_name = block["name"]
                    tool_input = block["input"]
                    tool_use_id = block["id"]

                    print_colored(f"\n  [Calling tool: {tool_name}]", "yellow")
                    print_colored(f"  Input: {json.dumps(tool_input, indent=2)[:500]}", "yellow")

                    # Execute via MCP
                    mcp_resp = mcp_call_tool(tool_name, tool_input)
                    print_colored(f"  Response: {json.dumps(mcp_resp, indent=2)[:1000]}", "yellow")

                    # Extract result text from MCP response
                    if "result" in mcp_resp:
                        content_items = mcp_resp["result"].get("content", [])
                        result_text = ""
                        for item in content_items:
                            if item.get("type") == "text":
                                result_text += item["text"]
                        tool_results.append({
                            "type": "tool_result",
                            "tool_use_id": tool_use_id,
                            "content": result_text,
                        })
                    elif "error" in mcp_resp:
                        error_data = mcp_resp["error"]
                        error_msg = error_data.get("message", "Unknown error")
                        # Include data field if present (exception details)
                        if "data" in error_data:
                            error_msg += f" | Details: {json.dumps(error_data['data'])}"
                        tool_results.append({
                            "type": "tool_result",
                            "tool_use_id": tool_use_id,
                            "content": f"Error: {error_msg}",
                            "is_error": True,
                        })

            if tool_results:
                messages.append({"role": "user", "content": tool_results})
            # Loop back to get LLM's next response with tool results
            continue

        # Handle text response (end_turn)
        for block in content_blocks:
            if block.get("type") == "text" and block["text"].strip():
                print(f"\nAssistant: {block['text']}")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    initial = " ".join(sys.argv[1:]) if len(sys.argv) > 1 else None
    run_chat(initial)
