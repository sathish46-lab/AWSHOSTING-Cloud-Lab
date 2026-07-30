#!/bin/bash
# entry.sh - Container startup

# 1. Regenerate unique SSH host keys for this instance
echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_*
ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q
ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q
ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q
echo "[✓] SSH host keys regenerated"

# 2. Start SSH (Apache will be started by linkuser.sh after symlinks are configured)
service ssh start

# 3. Configure WireGuard if config exists (will be reconfigured by linkuser.sh)
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