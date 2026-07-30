#!/bin/bash
# Usage: ./wgfree.sh <internal_ip>

IP_TO_FREE=$1

if [ -z "$IP_TO_FREE" ]; then
    echo "Usage: $0 <internal_ip>"
    exit 1
fi

# 1. Find the Peer Public Key associated with this IP
PEER_KEY=$(wg show wg0 allowed-ips | grep "$IP_TO_FREE/32" | awk '{print $1}')

if [ -n "$PEER_KEY" ]; then
    echo "[*] Removing stale WireGuard peer $PEER_KEY for IP $IP_TO_FREE..."
    wg set wg0 peer "$PEER_KEY" remove
    echo "[✓] Peer removed."
else
    echo "[!] No active WireGuard peer found for IP $IP_TO_FREE. Clean."
fi

# 2. Flush any old routing cache to prevent ghost pings
ip route flush cache 2>/dev/null || true