#!/bin/bash
# linkuser.sh - Fixed version with server public key parameter
# $1=Username, $2=PublicKeys, $3=DockerIP, $4=CodePassword
# $5=LabPrivateKey, $6=TunnelIP, $7=ServerPublicKey
# $8=UserEmail, $9=N8nDomain, $10=VPSDockerIP

USER_NAME=$1
PUB_KEYS=$2
DOCKER_IP=$3
CODE_PASS=$4
LAB_PRIV_KEY=$5
TUNNEL_IP=$6
SERVER_PUBKEY=$7
VPS_DOCKER_IP=${10}
SU_PASS=${11}

SYSTEM_PASS="${SU_PASS:-${USER_NAME}@098}"

echo "[*] Starting user configuration..."
echo "    Username: $USER_NAME"
echo "    Docker IP: $DOCKER_IP"
echo "    Tunnel IP: $TUNNEL_IP"

# 1. User Setup
if ! id "$USER_NAME" &>/dev/null; then
    # Delete default ubuntu user that steals UID 1000 in newer Ubuntu images
    if id -u ubuntu >/dev/null 2>&1; then userdel -r ubuntu || true; fi
    useradd -m -s /bin/bash -u 1000 "$USER_NAME" 2>/dev/null || useradd -m -s /bin/bash "$USER_NAME"
    usermod -aG sudo "$USER_NAME"
    echo "[*] User $USER_NAME created"
else
    echo "[*] User $USER_NAME already exists"
fi

echo "$USER_NAME:$SYSTEM_PASS" | chpasswd
echo "[✓] System password set"

# 2. SSH Keys
USER_HOME="/home/$USER_NAME"
mkdir -p "$USER_HOME/.ssh"
printf "%b" "$PUB_KEYS" > "$USER_HOME/.ssh/authorized_keys"
chmod 700 "$USER_HOME/.ssh"
chmod 600 "$USER_HOME/.ssh/authorized_keys"
chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME"

# Disable StrictModes for shared volume mounts and restart SSH
sed -i 's/^#\?StrictModes .*/StrictModes no/' /etc/ssh/sshd_config
service ssh restart || systemctl restart ssh || /etc/init.d/ssh restart || true

# 3. Bash Configuration
cat << 'BASHRC_EOF' > "$USER_HOME/.bashrc"
export force_color_prompt=yes
export TERM=xterm-256color
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;32m\]\u\[\033[00m\]@\[\033[38;5;208m\]\h\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
alias ls='ls --color=auto'
alias ll='ls -alF'
BASHRC_EOF

echo '[[ -f ~/.bashrc ]] && . ~/.bashrc' > "$USER_HOME/.bash_profile"
chown "$USER_NAME":"$USER_NAME" "$USER_HOME/.bashrc" "$USER_HOME/.bash_profile"

# 4. WireGuard Configuration
if [ -n "$LAB_PRIV_KEY" ] && [ -n "$SERVER_PUBKEY" ]; then
    echo "[*] Configuring WireGuard tunnel..."
    echo "    Tunnel IP: $TUNNEL_IP"
    echo "    Server Key: ${SERVER_PUBKEY:0:20}..."
    
    mkdir -p /etc/wireguard
    
    # Use the VPS container's Docker network IP as the WireGuard endpoint
    # This is reachable from sibling containers on the same Docker bridge network
    WG_ENDPOINT="${VPS_DOCKER_IP:-172.30.0.1}"
    TUNNEL_PREFIX=$(echo "$WG_ENDPOINT" | awk -F. '{print $1"."$2"."$3"."}')
    
    cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
PrivateKey = $LAB_PRIV_KEY
Address = $TUNNEL_IP/32
MTU = 1420
Table = off

[Peer]
PublicKey = $SERVER_PUBKEY
Endpoint = ${WG_ENDPOINT}:51820
AllowedIPs = ${TUNNEL_PREFIX}0/16
PersistentKeepalive = 25
EOF
    
    chmod 600 /etc/wireguard/wg0.conf
    
    # Start WireGuard
    wg-quick down wg0 2>/dev/null || true
    sleep 1
    wg-quick up wg0
    
    # Verify
    if wg show wg0 &>/dev/null; then
        ACTUAL_IP=$(ip addr show wg0 2>/dev/null | grep "inet " | awk '{print $2}')
        echo "[✓] WireGuard configured: $ACTUAL_IP"
    else
        echo "[!] WireGuard failed to start"
    fi
else
    echo "[!] Missing WireGuard parameters, skipping tunnel setup"
fi

# 4.5 Internal service resolution is now handled natively by Docker DNS!
USER_HOME="/home/$USER_NAME"
HTDOCS="$USER_HOME/htdocs"
HTCONFIG="$USER_HOME/htconfig"

echo "[*] Configuring persistent storage links..."

# 1. Initialize folders in Persistent Home if they don't exist
if [ ! -d "$HTDOCS" ]; then
    mkdir -p "$HTDOCS"
    # Copy default "It Works" page only on first ever deploy
    cp /var/www/html/index.html "$HTDOCS/" 2>/dev/null || echo "<h2>Tom Lab</h2>" > "$HTDOCS/index.html"
fi

if [ ! -d "$HTCONFIG" ]; then
    mkdir -p "$HTCONFIG"
    # Move current active configs to persistent storage to "save" them
fi

# ALWAYS ensure the default config points to /var/www (which is symlinked to htdocs)
# We do this on the persistent HTCONFIG folder directly since it might already exist
sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www|g' "$HTCONFIG/000-default.conf" 2>/dev/null || true

# 2. SYMLINK: Professional Web Root Persistence
echo "[*] Linking htdocs directly to /var/www..."

# Remove the existing /var/www directory and all its content (like the 'html' folder)
# to make room for our symlink.
if [ -d "/var/www" ] && [ ! -L "/var/www" ]; then
    rm -rf /var/www
fi

# Link your home htdocs folder as the new /var/www
# Now ~/htdocs/be physically exists at /var/www/be
ln -sfn "$HTDOCS" /var/www

# 3. SYMLINK: Apache Config Persistence (Remains the same)
echo "[*] Linking htconfig to /etc/apache2/sites-available..."
if [ -d "/etc/apache2/sites-available" ] && [ ! -L "/etc/apache2/sites-available" ]; then
    rm -rf /etc/apache2/sites-available
fi
ln -sfn "$HTCONFIG" /etc/apache2/sites-available

# 4. Permissions Fix
chown -R "$USER_NAME:$USER_NAME" "$HTDOCS" "$HTCONFIG"
chmod -R 755 "$HTDOCS"
chmod -R 755 "$HTCONFIG"

# Reload Apache to apply any config changes
service apache2 reload || true

# 5. Code-Server Setup
echo "[*] Setting up Code-Server..."
pkill -9 -u "$USER_NAME" -f code-server 2>/dev/null || true
fuser -k 8080/tcp 2>/dev/null || true
sleep 2

USER_CONFIG="$USER_HOME/.config/code-server/config.yaml"
mkdir -p "$(dirname "$USER_CONFIG")"

cat <<CODE_CONFIG > "$USER_CONFIG"
bind-addr: 0.0.0.0:8080
auth: password
password: $CODE_PASS
cert: false
CODE_CONFIG

chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME/.config"
chmod 644 "$USER_CONFIG"

# Optimized code-server startup with performance flags
sudo -u "$USER_NAME" -H bash -c "nohup code-server \
    --config $USER_CONFIG \
    --disable-telemetry \
    --disable-update-check \
    > $USER_HOME/.code-server.log 2>&1 &"
sleep 2

if pgrep -u "$USER_NAME" -f code-server > /dev/null; then
    echo "[✓] Code-server started"
else
    echo "[!] Code-server failed to start"
fi

echo "[✓] User configuration complete"