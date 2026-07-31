#!/bin/bash
# linkuser.sh — Full user setup for GUI Essentials: password, SSH, WireGuard, storage, code-server, KasmVNC
# $1=Username, $2=PublicKeys, $3=DockerIP, $4=CodePassword
# $5=LabPrivateKey, $6=TunnelIP, $7=ServerPublicKey
# $8=UserEmail, $9=N8nDomain, $10=VPSDockerIP, $11=SuPass, $12=VncPass

set -e

USER_NAME=$1
PUB_KEYS=$2
DOCKER_IP=$3
CODE_PASS=$4
LAB_PRIV_KEY=$5
TUNNEL_IP=$6
SERVER_PUBKEY=$7
VPS_DOCKER_IP=${10}
SU_PASS=${11}
VNC_PASS=${12}

SYSTEM_PASS="${SU_PASS:-${USER_NAME}@098}"
VNC_PASSWORD="${VNC_PASS:-${USER_NAME}@098}"

echo "[*] Starting user configuration..."
echo "[*] Username: $USER_NAME"
echo "[*] Docker IP: $DOCKER_IP"
echo "[*] Tunnel IP: $TUNNEL_IP"

# ── 1. User Setup ─────────────────────────────────────────────
if ! id "$USER_NAME" &>/dev/null; then
    if id -u ubuntu >/dev/null 2>&1; then userdel -r ubuntu || true; fi
    if id -u kasm-user >/dev/null 2>&1; then userdel -r kasm-user || true; fi
    useradd -m -s /bin/bash -u 1000 "$USER_NAME" 2>/dev/null || useradd -m -s /bin/bash "$USER_NAME"
    usermod -aG sudo "$USER_NAME"
    usermod -aG video "$USER_NAME"
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
chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME"

echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_*
yes | ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q
yes | ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q
yes | ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q

sed -i 's/^#\?StrictModes .*/StrictModes no/' /etc/ssh/sshd_config
service ssh restart 2>/dev/null || /etc/init.d/ssh restart 2>/dev/null || true
echo "[✓] SSH configured and restarted"

# ── 3. Bash Configuration ─────────────────────────────────────
cat << 'BASHRC_EOF' > "$USER_HOME/.bashrc"
export force_color_prompt=yes
export TERM=xterm-256color
export DISPLAY=:1
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

    mkdir -p /etc/wireguard

    WG_ENDPOINT="${VPS_DOCKER_IP:-172.31.0.1}"
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

    ip link delete dev wg0 2>/dev/null || true
    sleep 1
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

if [ ! -d "$HTDOCS" ]; then
    mkdir -p "$HTDOCS"
    cp /var/www/html/index.html "$HTDOCS/" 2>/dev/null || echo "<h2>Tom Lab</h2>" > "$HTDOCS/index.html"
fi

if [ ! -d "$HTCONFIG" ]; then
    mkdir -p "$HTCONFIG"
fi

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

sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www|g' "$HTCONFIG/000-default.conf" 2>/dev/null || true

mkdir -p "$HTCONFIG/sites-enabled"
if [ ! -L "$HTCONFIG/sites-enabled/000-default.conf" ] && [ -f "$HTCONFIG/000-default.conf" ]; then
    ln -sf "$HTCONFIG/000-default.conf" "$HTCONFIG/sites-enabled/000-default.conf"
fi

echo "[*] Linking htdocs directly to /var/www..."
if [ -d "/var/www" ] && [ ! -L "/var/www" ]; then
    rm -rf /var/www
fi
ln -sfn "$HTDOCS" /var/www

echo "[*] Linking htconfig to /etc/apache2/sites-available..."
if [ -d "/etc/apache2/sites-available" ] && [ ! -L "/etc/apache2/sites-available" ]; then
    rm -rf /etc/apache2/sites-available
fi
ln -sfn "$HTCONFIG" /etc/apache2/sites-available

if [ -d "/etc/apache2/sites-enabled" ] && [ ! -L "/etc/apache2/sites-enabled" ]; then
    rm -rf /etc/apache2/sites-enabled
fi
ln -sfn "$HTCONFIG/sites-enabled" /etc/apache2/sites-enabled

if [ -L "/etc/apache2/mods-enabled" ]; then
    rm -f /etc/apache2/mods-enabled
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

chown -R "$USER_NAME:$USER_NAME" "$HTDOCS" "$HTCONFIG"
chmod -R 755 "$HTDOCS"
chmod -R 755 "$HTCONFIG"

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

chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME/.config"
chmod 644 "$USER_CONFIG"

# Optimized code-server startup
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

# ── 7. KasmVNC Server Setup ──────────────────────────────────
echo "[*] Setting up KasmVNC..."
pkill -9 -u "$USER_NAME" -f kasmvncserver 2>/dev/null || true
pkill -9 -u "$USER_NAME" -f Xvnc 2>/dev/null || true
pkill -9 -u "$USER_NAME" -f XvncProcess 2>/dev/null || true
sleep 1

# Clean stale X lock files
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true

# Set VNC password for KasmVNC
VNC_DIR="$USER_HOME/.vnc"
mkdir -p "$VNC_DIR"
echo -e "${VNC_PASSWORD}\n${VNC_PASSWORD}\n" | vncpasswd "$VNC_DIR/passwd" 2>/dev/null || true
chmod 600 "$VNC_DIR/passwd" 2>/dev/null || true
chown -R "$USER_NAME":"$USER_NAME" "$VNC_DIR"

# Set environment for KasmVNC
echo "export KASMVNC_BASE_PORT=5900" >> "$USER_HOME/.bashrc"
echo "export KASMVNC_WEBSOCKET_PORT=6901" >> "$USER_HOME/.bashrc"
chown "$USER_NAME":"$USER_NAME" "$USER_HOME/.bashrc"

# Start KasmVNC with web client on port 6901
sudo -u "$USER_NAME" -H bash -c "DISPLAY=:1 nohup kasmvncserver :1 \
    -geometry 1280x720 \
    -depth 16 \
    -rfbauth $VNC_DIR/passwd \
    > $USER_HOME/.kasmvnc.log 2>&1 &"
sleep 3

# Fallback: try Xvnc directly if kasmvncserver not found
if ! pgrep -u "$USER_NAME" -f kasmvncserver > /dev/null && ! pgrep -u "$USER_NAME" -f Xvnc > /dev/null; then
    echo "[*] Trying Xvnc directly..."
    rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true
    sudo -u "$USER_NAME" -H bash -c "DISPLAY=:1 nohup Xvnc :1 \
        -geometry 1280x720 \
        -depth 16 \
        -rfbauth $VNC_DIR/passwd \
        -rfbport 5900 \
        -SecurityTypes VncAuth \
        > $USER_HOME/.kasmvnc.log 2>&1 &"
    sleep 3
fi

if pgrep -u "$USER_NAME" -f kasmvncserver > /dev/null || pgrep -u "$USER_NAME" -f Xvnc > /dev/null; then
    echo "[✓] KasmVNC started on port 6901 (web client)"
else
    echo "[!] KasmVNC failed to start"
    cat "$USER_HOME/.kasmvnc.log" 2>/dev/null || true
fi

echo "[✓] User configuration complete"
