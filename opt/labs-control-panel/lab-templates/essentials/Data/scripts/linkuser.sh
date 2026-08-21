#!/bin/bash
# linkuser.sh — Full user setup: password, SSH, WireGuard, storage symlinks, code-server
# $1=Username, $2=PublicKeys, $3=DockerIP, $4=CodePassword
# $5=LabPrivateKey, $6=TunnelIP, $7=ServerPublicKey
# $8=UserEmail, $9=N8nDomain, $10=VPSDockerIP, $11=SuPass

set -e

USER_NAME=$1
PUB_KEYS=$2
DOCKER_IP=$3
CODE_PASS=$4
LAB_PRIV_KEY=$5
TUNNEL_IP=$6
SERVER_PUBKEY=$7
USER_EMAIL=$8
VPS_DOCKER_IP=${10}
SU_PASS=${11}

# Compute hash from email (matches Python hashlib.md5)
USER_HASH=$(echo -n "$USER_EMAIL" | md5sum | cut -d' ' -f1)
SYSTEM_PASS="${SU_PASS:-${USER_NAME}@098}"

echo "[*] Starting user configuration..."
echo "[*] Username: $USER_NAME"
echo "[*] User Hash: $USER_HASH"
echo "[*] Docker IP: $DOCKER_IP"
echo "[*] Tunnel IP: $TUNNEL_IP"

# ── 1. User Setup ─────────────────────────────────────────────
if ! id "$USER_NAME" &>/dev/null; then
    if id -u ubuntu >/dev/null 2>&1; then userdel -r ubuntu || true; fi
    useradd -m -s /bin/bash -u 1000 "$USER_NAME" 2>/dev/null || useradd -m -s /bin/bash "$USER_NAME"
    usermod -aG sudo "$USER_NAME"
    echo "[*] User $USER_NAME created"
else
    echo "[*] User $USER_NAME already exists"
fi

echo "$USER_NAME:$SYSTEM_PASS" | chpasswd
echo "[✓] System password set"

# ── 2. SSH Keys ───────────────────────────────────────────────
USER_HOME="/home/$USER_NAME"
mkdir -p "$USER_HOME/.ssh"
printf "%b" "$PUB_KEYS" > "$USER_HOME/.ssh/authorized_keys"
chmod 700 "$USER_HOME/.ssh"
chmod 600 "$USER_HOME/.ssh/authorized_keys"
chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME" 2>/dev/null || true

sed -i 's/^#\?StrictModes .*/StrictModes no/' /etc/ssh/sshd_config
# Restart SSH without systemd (service works in containers)
service ssh reload 2>/dev/null || service ssh restart 2>/dev/null || /etc/init.d/ssh reload 2>/dev/null || /etc/init.d/ssh restart 2>/dev/null || true
echo "[✓] SSH configured and reloaded"

# ── 3. Bash Configuration ─────────────────────────────────────
cat << 'BASHRC_EOF' > "$USER_HOME/.bashrc"
export force_color_prompt=yes
export TERM=xterm-256color
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;32m\]\u\[\033[00m\]@\[\033[38;5;208m\]\h\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
alias ls='ls --color=auto'
alias ll='ls -alF'
BASHRC_EOF

echo '[[ -f ~/.bashrc ]] && . ~/.bashrc' > "$USER_HOME/.bash_profile"
chown "$USER_NAME":"$USER_NAME" "$USER_HOME/.bashrc" "$USER_HOME/.bash_profile"
echo "[✓] Bash environment configured"

# ── 4. WireGuard Configuration ────────────────────────────────
if [ -n "$LAB_PRIV_KEY" ] && [ -n "$SERVER_PUBKEY" ]; then
    echo "[*] Configuring WireGuard tunnel..."
    echo "[*] Tunnel IP: $TUNNEL_IP"
    echo "[*] Server Key: ${SERVER_PUBKEY:0:20}..."

    mkdir -p /etc/wireguard

    WG_ENDPOINT="${VPS_DOCKER_IP:-172.31.0.1}"
    TUNNEL_PREFIX=$(echo "$WG_ENDPOINT" | awk -F. '{print $1"."$2"."$3"."}')

    # Clean up existing wg0 interface if present
    wg-quick down wg0 2>/dev/null || true
    ip link delete wg0 2>/dev/null || true
    sleep 1

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

    wg-quick up wg0

    if wg show wg0 &>/dev/null; then
        ACTUAL_IP=$(ip addr show wg0 2>/dev/null | grep "inet " | awk '{print $2}')
        echo "[✓] WireGuard configured: $ACTUAL_IP"
    else
        echo "[!] WireGuard failed to start"
        exit 1
    fi
else
    echo "[!] Missing WireGuard parameters, skipping tunnel setup"
fi

# ── 5. Persistent Storage Links ───────────────────────────────
HTDOCS="$USER_HOME/htdocs"
HTCONFIG="$USER_HOME/htconfig"

echo "[*] Configuring persistent storage links..."

# Initialize htdocs folder if it doesn't exist
if [ ! -d "$HTDOCS" ]; then
    mkdir -p "$HTDOCS"
    cp /var/www/html/index.html "$HTDOCS/" 2>/dev/null || echo "<h2>Tom Lab</h2>" > "$HTDOCS/index.html"
fi

# Initialize htconfig folder and copy default Apache configs if empty
if [ ! -d "$HTCONFIG" ]; then
    mkdir -p "$HTCONFIG"
fi

# Always copy 000-default.conf if it doesn't exist in htconfig
if [ ! -f "$HTCONFIG/000-default.conf" ]; then
    if [ -f /etc/apache2/sites-available/000-default.conf ]; then
        cp /etc/apache2/sites-available/000-default.conf "$HTCONFIG/000-default.conf"
    else
        cat <<'DEFAULTCONF' > "$HTCONFIG/000-default.conf"
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
DEFAULTCONF
    fi
    echo "[*] Created 000-default.conf in htconfig"
fi

# Ensure DocumentRoot points to /var/www (not /var/www/html)
sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www|g' "$HTCONFIG/000-default.conf" 2>/dev/null || true

# Copy sites-enabled symlinks if missing
mkdir -p "$HTCONFIG/sites-enabled"
if [ ! -L "$HTCONFIG/sites-enabled/000-default.conf" ] && [ -f "$HTCONFIG/000-default.conf" ]; then
    ln -sf "$HTCONFIG/000-default.conf" "$HTCONFIG/sites-enabled/000-default.conf"
fi

# SYMLINK: /var/www -> ~/htdocs
echo "[*] Linking htdocs directly to /var/www..."
if [ -d "/var/www" ] && [ ! -L "/var/www" ]; then
    rm -rf /var/www
fi
ln -sfn "$HTDOCS" /var/www

# SYMLINK: /etc/apache2/sites-available -> ~/htconfig
echo "[*] Linking htconfig to /etc/apache2/sites-available..."
if [ -d "/etc/apache2/sites-available" ] && [ ! -L "/etc/apache2/sites-available" ]; then
    rm -rf /etc/apache2/sites-available
fi
ln -sfn "$HTCONFIG" /etc/apache2/sites-available

# SYMLINK: /etc/apache2/sites-enabled -> ~/htconfig/sites-enabled
if [ -d "/etc/apache2/sites-enabled" ] && [ ! -L "/etc/apache2/sites-enabled" ]; then
    rm -rf /etc/apache2/sites-enabled
fi
ln -sfn "$HTCONFIG/sites-enabled" /etc/apache2/sites-enabled

# NOTE: Do NOT symlink mods-enabled or conf-enabled — Apache needs original system modules
# Restore mods-enabled if it was previously symlinked to htconfig
if [ -L "/etc/apache2/mods-enabled" ]; then
    rm -f /etc/apache2/mods-enabled
    # The original mods-enabled was a directory with symlinks to mods-available
    # We can't restore it perfectly, but we can ensure the essential modules are enabled
    mkdir -p /etc/apache2/mods-enabled
    for mod in mpm_event authz_core authz_host dir log_config rewrite ssl mime socache_shmcb; do
        if [ -f "/etc/apache2/mods-available/${mod}.load" ]; then
            ln -sf "/etc/apache2/mods-available/${mod}.load" "/etc/apache2/mods-enabled/${mod}.load" 2>/dev/null || true
        fi
        if [ -f "/etc/apache2/mods-available/${mod}.conf" ]; then
            ln -sf "/etc/apache2/mods-available/${mod}.conf" "/etc/apache2/mods-enabled/${mod}.conf" 2>/dev/null || true
        fi
    done
    echo "[*] Restored Apache mods-enabled from mods-available"
fi

# Permissions
chown -R "$USER_NAME:$USER_NAME" "$HTDOCS" "$HTCONFIG" 2>/dev/null || true
chmod -R 755 "$HTDOCS"
chmod -R 755 "$HTCONFIG"

# Verify 000-default is enabled
a2ensite 000-default.conf 2>/dev/null || true
service apache2 restart || true
echo "[✓] Storage links configured"

# ── 6. Code-Server Setup ──────────────────────────────────────
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

chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME/.config" 2>/dev/null || true
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

# Install the TomCloud Lab status-bar stats extension (CPU / memory / net I/O)
EXT_SRC="/var/labsdata/code-extensions/tomlabs.tomlabs-stats"
EXT_DST="$USER_HOME/.local/share/code-server/extensions/tomlabs.tomlabs-stats"
if [ -d "$EXT_SRC" ]; then
    mkdir -p "$EXT_DST"
    cp -r "$EXT_SRC/." "$EXT_DST/"
    chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME/.local/share/code-server"
    echo "[✓] TomCloud Lab stats extension installed"
else
    echo "[!] Stats extension source not found in image (skip)"
fi

echo "[✓] User configuration complete"

# ── 7. Firewall: Block access to server infrastructure ──────────
echo "[*] Applying firewall rules..."
# Block access to Dev_lab container (172.30.0.2) — prevents access to MongoDB, RabbitMQ, etc.
iptables -A OUTPUT -d 172.30.0.2 -j DROP 2>/dev/null || true
# Allow DNS resolution (Docker's internal DNS at 127.0.0.11)
iptables -A OUTPUT -d 127.0.0.11 -j ACCEPT 2>/dev/null || true
# Allow WireGuard traffic (UDP 51820)
iptables -A OUTPUT -p udp --dport 51820 -j ACCEPT 2>/dev/null || true
# Allow loopback
iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT 2>/dev/null || true
echo "[✓] Firewall rules applied"
