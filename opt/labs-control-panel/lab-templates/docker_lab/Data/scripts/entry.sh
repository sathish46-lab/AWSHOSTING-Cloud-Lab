#!/bin/bash
# entry.sh - Container startup

# 1. Wait for linkuser.sh to finish if it's running (prevents race conditions)
sleep 2

# 1.5. Regenerate unique SSH host keys
echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_*
ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q
ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q
ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q

# 2. Force Apache to recognize symlinked configs
echo "[*] Enabling persistent Apache sites..."
find /etc/apache2/sites-available -name "*.conf" -exec a2ensite {} +

# 3. Start services
service ssh start
service apache2 restart
service docker start

# Configure WireGuard if config exists
if [ -f /etc/wireguard/wg0.conf ]; then
    echo "[*] Starting WireGuard..."
    # Clean up any stale/broken interface from previous boot states
    ip link delete dev wg0 2>/dev/null || true
    
    # Retry loop to handle networking race conditions during boot
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
    
    # Ensure the container knows to route VPN traffic to the VPS container
    TUNNEL_PREFIX=$(echo "${VPS_DOCKER_IP:-172.30.0.1}" | awk -F. '{print $1"."$2"."$3"."}')
    ip route add ${TUNNEL_PREFIX}0/16 dev wg0 metric 10 2>/dev/null || true
fi

# Keep container running
tail -f /dev/null