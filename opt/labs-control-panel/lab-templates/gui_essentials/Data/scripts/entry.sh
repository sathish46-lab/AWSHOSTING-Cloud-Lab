#!/bin/bash
# entry.sh - Container startup for GUI Essentials Lab (KasmVNC)

# 1. Regenerate unique SSH host keys
echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_*
yes | ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q
yes | ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q
yes | ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q
echo "[✓] SSH host keys regenerated"

# 2. Start SSH
service ssh start

# 3. Configure WireGuard if config exists
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

# 4. Start KasmVNC web server (nginx on port 6901 serves web client)
#    kasmvncserver is started later by linkuser.sh with user config
if [ -f /dockerstartup/vnc_startup.sh ]; then
    echo "[*] Starting KasmVNC web server (nginx)..."
    /dockerstartup/vnc_startup.sh &
    sleep 2
fi

# Keep container running
tail -f /dev/null
