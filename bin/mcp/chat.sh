#!/usr/bin/env bash
# MCP Chat Client — interactive LLM chat with OXID shop MCP tools
# Usage: ./chat.sh                    # interactive
#        ./chat.sh "buy me glasses"   # with initial prompt

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$SCRIPT_DIR/chat.py" "$@"
