# Cloudflare Tunnel DNS Resolution Fix

**Date:** 2025-12-01
**Status:** Resolved
**Issue:** Cloudflare Tunnel container in restart loop, unable to reach host

## Problem Description

The Cloudflare tunnel at `daniil.oxiddev.de` was unreachable with Error 1033:
```
Cloudflare Tunnel error
The host (daniil.oxiddev.de) is configured as a Cloudflare Tunnel,
and Cloudflare is currently unable to resolve it.
```

## Root Cause Analysis

Docker container logs revealed DNS resolution failures:

```
ERR Failed to fetch features error="lookup cfd-features.argotunnel.com on 127.0.0.11:53: server misbehaving"
ERR Initiating shutdown error="Couldn't resolve SRV record &{region1.v2.argotunnel.com. 7844 1 1}: lookup region1.v2.argotunnel.com. on 127.0.0.11:53: server misbehaving"
```

The container was using Docker's internal DNS resolver (`127.0.0.11:53`) which was not functioning correctly, causing the `cloudflared` process to fail to resolve Cloudflare's edge servers.

## Solution

Added explicit external DNS servers to the docker-compose configuration:

### File Modified
`/home/dtkachev/osc/cf-tunnel-template/docker-compose.yaml`

### Change
```yaml
services:
  cloudflared:
    image: cloudflare/cloudflared:latest
    command: tunnel --config /etc/cloudflared/config/config.yaml run
    restart: always
    volumes:
      - "./config.yaml:/etc/cloudflared/config/config.yaml"
      - "./credentials.json:/etc/cloudflared/credentials.json"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    dns:                    # <-- Added
      - 1.1.1.1             # Cloudflare DNS
      - 8.8.8.8             # Google DNS (fallback)
```

## Resolution Steps

1. Identified container in restart loop: `docker ps -a | grep cloud`
2. Examined logs: `docker logs cf-tunnel-template-cloudflared-1 --tail 50`
3. Added DNS configuration to docker-compose.yaml
4. Restarted container: `docker compose down && docker compose up -d`

## Verification

After restart, logs confirmed successful connection:

```
INF Initial protocol quic
INF Registered tunnel connection connIndex=0 location=fra08 protocol=quic
INF Registered tunnel connection connIndex=1 location=fra13 protocol=quic
INF Registered tunnel connection connIndex=2 location=fra12 protocol=quic
INF Registered tunnel connection connIndex=3 location=muc01 protocol=quic
```

Four tunnel connections established to Frankfurt and Munich edge servers.

## Why This Happened

Docker's internal DNS (`127.0.0.11`) relies on the host's DNS configuration being properly propagated. This can fail due to:
- Network configuration changes
- Docker daemon issues
- Host DNS resolver problems
- Container network isolation issues

Using explicit external DNS servers (Cloudflare `1.1.1.1` and Google `8.8.8.8`) bypasses Docker's internal DNS and provides reliable resolution.

## Prevention

For production Cloudflare tunnel deployments, always specify explicit DNS servers in the docker-compose configuration to avoid dependency on Docker's internal DNS resolver.
