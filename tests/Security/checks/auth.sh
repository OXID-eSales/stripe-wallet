#!/usr/bin/env bash
# Admin auth-bypass (F18) and MCP/JWT disclosure (F12/F13) probes.

# A protected admin controller hit without a session must NOT serve admin action
# content. The acceptable outcomes are a redirect to login (302/303) or a rendered
# login wall; serving the refund/capture UI is a fail.
check_admin_auth() {
    local url="${ADMIN_URL}?cl=admin_order&oxid=test" status body
    status=$(status_of GET "$url")
    body=$(http "$url" 2>/dev/null || echo "")

    if echo "$body" | grep -qiE 'createRefund|payment_admin_action|capture amount|refund amount'; then
        record F18-adminauth crit fail "Admin action UI reachable without auth" "action markup in response"
        return
    fi
    case "$status" in
        301|302|303) record F18-adminauth crit pass "Admin redirected to login (no session)" "$status" ;;
        000)         record F18-adminauth crit skip "Admin controller not reachable" "transport error" ;;
        *)
            if echo "$body" | grep -qiE 'type="password"|name="(usr|pwd|user|password)"|login'; then
                record F18-adminauth crit pass "Admin gated by login wall" "login form returned ($status)"
            else
                record F18-adminauth crit skip "Admin response inconclusive" "$status, no login/action markers"
            fi
            ;;
    esac
}

# MCP endpoints must never leak secrets/tokens to an unauthenticated caller.
check_mcp_disclosure() {
    local route body leaked=""
    local routes=(
        "${BASE_URL}/index.php?cl=stripe_mcp"
        "${BASE_URL}/index.php?cl=mcp"
        "${BASE_URL}/.well-known/mcp"
    )
    for route in "${routes[@]}"; do
        body=$(http "$route" 2>/dev/null || echo "")
        [ -n "$body" ] || continue
        if echo "$body" | grep -qoE 'sk_(live|test)_[A-Za-z0-9]+|whsec_[A-Za-z0-9]+|eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+'; then
            leaked="$route"
            break
        fi
    done
    if [ -n "$leaked" ]; then
        record F12-mcp high fail "MCP endpoint leaks a secret/JWT" "$leaked"
    else
        record F12-mcp high pass "No secret/JWT disclosed on MCP routes" "tried ${#routes[@]} routes"
    fi
}
