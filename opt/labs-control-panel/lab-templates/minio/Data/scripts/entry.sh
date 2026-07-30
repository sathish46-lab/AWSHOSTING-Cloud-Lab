#!/bin/bash
# entry.sh - MinIO S3 Container Startup

echo "[*] Initializing MinIO S3 Lab Environment..."

# 0. Regenerate unique SSH host keys
echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_*
ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q
ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q
ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q

# 1. Start System Services
# SSH is required for administrative shell access and SFTP
service ssh start

# 2. Configure WireGuard Mesh Networking
if [ -f /etc/wireguard/wg0.conf ]; then
    echo "[*] Activating WireGuard Mesh..."
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
    
    # Ensure all traffic within the lab network is routed through the tunnel
    TUNNEL_PREFIX=$(echo "${VPS_DOCKER_IP:-172.30.0.1}" | awk -F. '{print $1"."$2"."$3"."}')
    ip route add ${TUNNEL_PREFIX}0/16 dev wg0 metric 10 2>/dev/null || true
fi

# 3. Handle MinIO Specifics
# We don't start MinIO here because linkuser.sh handles the initial start with 
# dynamic credentials. However, we ensure the data directory exists.
mkdir -p /mnt/data
chmod 777 /mnt/data

# 4. Optional: Apache for Documentation
# If you want to show a "Welcome" page or S3 instructions on Port 80
if command -v apache2 >/dev/null 2>&1; then
    echo "[*] Starting Apache for lab instructions..."
    find /etc/apache2/sites-available -name "*.conf" -exec a2ensite {} +
    service apache2 restart
fi

# 5. Keep the container alive
echo "[✓] MinIO Lab is ready."
tail -f /dev/null