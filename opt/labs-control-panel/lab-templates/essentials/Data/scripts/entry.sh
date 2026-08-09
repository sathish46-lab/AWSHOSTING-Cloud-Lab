#!/bin/bash
# entry.sh - Container startup

# 1. Start SSH (keys already exist from image build)
service ssh start

# 2. Configure WireGuard if config exists (will be reconfigured by linkuser.sh)
if [ -f /etc/wireguard/wg0.conf ]; then
    echo "[*] Starting WireGuard..."
    ip link delete dev wg0 2>/dev/null || true
    
    for i in {1..5}; do
        if wg-quick up wg0 2>/dev/null; then
            echo "[+] WireGuard started successfully on attempt $i."
            break
        else
            echo "[-] WireGuard attempt $i failed, retrying in 2s..."
            ip link delete dev wg0 2>/dev/null || true
            sleep 2
        fi
    done
    
    TUNNEL_PREFIX=$(echo "${VPS_DOCKER_IP:-172.30.0.1}" | awk -F. '{print $1"."$2"."$3"."}')
    ip route add ${TUNNEL_PREFIX}0/16 dev wg0 metric 10 2>/dev/null || true
fi

# Keep container running
tail -f /dev/null