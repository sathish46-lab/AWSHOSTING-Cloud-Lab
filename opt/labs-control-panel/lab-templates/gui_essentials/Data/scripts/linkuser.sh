#!/bin/bash
# linkuser.sh — User setup for GUI Essentials: password, SSH, WireGuard, storage, bash config
# $1=Username, $2=PublicKeys, $3=DockerIP, $4=CodePassword
# $5=LabPrivateKey, $6=TunnelIP, $7=ServerPublicKey
# $8=UserEmail, $9=N8nDomain, $10=VPSDockerIP, $11=SuPass, $12=VncPass

set -e
trap 'echo "[!] linkuser.sh failed at line $LINENO: $BASH_COMMAND"' ERR

# Source service flags (set by entry.sh)
[ -f /var/labsdata/.service_flags ] && source /var/labsdata/.service_flags

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
# Use user-set VNC password if provided, otherwise generate random
if [ -n "$VNC_PASS" ]; then
    VNC_PASSWORD="$VNC_PASS"
    echo "[*] Using user-set VNC password"
else
    VNC_PASSWORD=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 12)
    echo "[*] Generated random VNC password: $VNC_PASSWORD"
fi

echo "[*] Starting user configuration..."
echo "[*] Username: $USER_NAME"
echo "[*] Docker IP: $DOCKER_IP"
echo "[*] Tunnel IP: $TUNNEL_IP"

# ── 1. User Setup ─────────────────────────────────────────────
if ! id "$USER_NAME" &>/dev/null; then
    rm -rf /home/kasm-user /home/kasm-default-profile 2>/dev/null || true
    id -u ubuntu >/dev/null 2>&1 && userdel -r ubuntu 2>/dev/null || true
    id -u kasm-user >/dev/null 2>&1 && userdel kasm-user 2>/dev/null || true
    useradd -m -s /bin/bash -u 1000 "$USER_NAME" 2>/dev/null || useradd -m -s /bin/bash "$USER_NAME" 2>/dev/null || true
    usermod -aG sudo "$USER_NAME" 2>/dev/null || true
    usermod -aG video "$USER_NAME" 2>/dev/null || true
    echo "[*] User $USER_NAME created"
else
    echo "[*] User $USER_NAME already exists"
fi

echo "$USER_NAME:$SYSTEM_PASS" | chpasswd 2>/dev/null || true
echo "[✓] System password set"

# Allow sudo without password
echo "$USER_NAME ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/"$USER_NAME"
chmod 440 /etc/sudoers.d/"$USER_NAME"

# Lock /root — normal user should not access (industry standard)
chmod 700 /root 2>/dev/null || true

echo "[✓] Sudo configured"

# ── 1b. Cleanup KasmVNC defaults ──────────────────────────────
rm -rf /home/kasm-user /home/kasm-default-profile 2>/dev/null || true
echo "[✓] Cleaned up KasmVNC default directories"

# ── 2. SSH Keys ───────────────────────────────────────────────
USER_HOME="/home/$USER_NAME"
mkdir -p "$USER_HOME/.ssh"
printf "%b" "$PUB_KEYS" > "$USER_HOME/.ssh/authorized_keys"
chmod 700 "$USER_HOME/.ssh"
chmod 600 "$USER_HOME/.ssh/authorized_keys"
chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME"

echo "[*] Regenerating SSH host keys..."
rm -f /etc/ssh/ssh_host_* 2>/dev/null || true
yes | ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q 2>/dev/null || true
yes | ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q 2>/dev/null || true
yes | ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q 2>/dev/null || true

sed -i 's/^#\?StrictModes .*/StrictModes no/' /etc/ssh/sshd_config 2>/dev/null || true
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
    fi
else
    echo "[!] Missing WireGuard parameters, skipping tunnel setup"
fi

# ── 5. Persistent Storage Links (Apache) ────────────────────────
if [ "${ENABLE_APACHE:-true}" = "true" ]; then
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
echo "[✓] Apache/storage links configured"
else
    echo "[*] Apache disabled — skipping storage links"
fi

# ── 6. KasmVNC Password ──────────────────────────────────────
echo "[*] Setting KasmVNC password for $USER_NAME..."
mkdir -p /root/.vnc
rm -f /root/.kasmpasswd 2>/dev/null
TMP_KASMPW=$(mktemp)
printf '%s\n%s\n' "$VNC_PASSWORD" "$VNC_PASSWORD" > "$TMP_KASMPW"
kasmvncpasswd -u "$USER_NAME" -wo /root/.kasmpasswd < "$TMP_KASMPW" 2>/dev/null || true
printf '%s\n%s\n' "$VNC_PASSWORD" "$VNC_PASSWORD" > "$TMP_KASMPW"
kasmvncpasswd -u "kasm_user" -wo /root/.kasmpasswd < "$TMP_KASMPW" 2>/dev/null || true
rm -f "$TMP_KASMPW"
chmod 600 /root/.kasmpasswd 2>/dev/null || true
echo "[✓] KasmVNC password configured (random: $VNC_PASSWORD)"
echo "[VNC_PASS_RESULT]$VNC_PASSWORD[/VNC_PASS_RESULT]"

# ── 6b. SSL Cert in user home ────────────────────────────────
echo "[*] Generating SSL cert in $USER_HOME/.vnc/..."
mkdir -p "$USER_HOME/.vnc"
if [ ! -f "$USER_HOME/.vnc/self.pem" ]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$USER_HOME/.vnc/self.pem" -out "$USER_HOME/.vnc/self.pem" \
        -subj "/CN=localhost" 2>/dev/null
    chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.vnc"
    echo "[✓] SSL cert generated in $USER_HOME/.vnc/"
else
    echo "[✓] SSL cert already exists in $USER_HOME/.vnc/"
fi

# ── 7. Desktop Bash Config ────────────────────────────────────
echo "[*] Configuring desktop for $USER_NAME..."

# System-wide bashrc — auto-switch to lab user
cat <<'BASHRC_EOF' > /etc/bash.bashrc
BASHRC_EOF

# User's own bashrc
cat <<USER_BASHRC > "$USER_HOME/.bashrc"
export force_color_prompt=yes
export TERM=xterm-256color
export DISPLAY=:1
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;32m\]\u\[\033[00m\]@\[\033[38;5;208m\]\h\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
alias ls='ls --color=auto'
alias ll='ls -alF'
export PATH="$HOME/.local/bin:$PATH"
USER_BASHRC
chown "$USER_NAME":"$USER_NAME" "$USER_HOME/.bashrc"

# KasmVNC root bashrc — exec into the lab user
mkdir -p /home/kasm-user
cat <<KASM_BASHRC > /home/kasm-user/.bashrc
export DISPLAY=:1
export TERM=xterm-256color
if [ "\$(id -u)" -eq 0 ] && id "$USER_NAME" &>/dev/null && [ -z "\$_LAB_SHELL" ]; then
    export _LAB_SHELL=1
    exec sudo -u "$USER_NAME" env HOME=$USER_HOME DISPLAY=:1 TERM=xterm-256color /bin/bash --login
fi
KASM_BASHRC
chown root:root /home/kasm-user/.bashrc

cat <<ROOT_BASHRC > /root/.bashrc
export DISPLAY=:1
export TERM=xterm-256color
if [ -z "\$_SWITCHED" ] && [ "\$(id -u)" -eq 0 ] && id "$USER_NAME" &>/dev/null && [ -z "\$SUDO_USER" ]; then
    export _SWITCHED=1
    cd $USER_HOME && exec sudo -u "$USER_NAME" env HOME=$USER_HOME /bin/bash --login
fi
PS1="\u@\h:\w# "
ROOT_BASHRC

echo "[✓] Desktop configured for $USER_NAME (auto-switch enabled)"

# ── 7b. XFCE Terminal Default Directory ────────────────────────
mkdir -p "$USER_HOME/.config/xfce4/terminal"
cat <<TERMRC > "$USER_HOME/.config/xfce4/terminal/terminalrc"
[Configuration]
MiscDefaultWorkingDir=$USER_HOME
MiscMenubarVisible=true
MiscShowUnsafePasteDialog=false
TERMRC
mkdir -p /etc/xdg/xfce4/terminal
cat <<XDGTERMRC > /etc/xdg/xfce4/terminal/terminalrc
[Configuration]
MiscDefaultWorkingDir=$USER_HOME
MiscMenubarVisible=true
MiscShowUnsafePasteDialog=false
XDGTERMRC
chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.config/xfce4"
echo "[✓] Terminal configured to open as $USER_NAME in $USER_HOME"

# ── 7c. Thunar File Manager ───────────────────────────────────
mkdir -p "$USER_HOME/.config/Thunar"
cat <<THUNAR_EOF > "$USER_HOME/.config/Thunar/thunar-prefs.xml"
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <property name="last-show-hidden" type="bool" value="true"/>
</configuration>
THUNAR_EOF
sed -i "s|Exec=thunar|Exec=thunar $USER_HOME|" /usr/share/applications/thunar.desktop 2>/dev/null || true
sed -i "s|Exec=xfce4-file-manager|Exec=thunar $USER_HOME|" /usr/share/applications/xfce4-file-manager.desktop 2>/dev/null || true
chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.config/Thunar"
echo "[✓] File manager configured to open in $USER_HOME"

# ── 7d. Custom Wallpaper & Branding ──────────────────────────
ASSETS_DIR="/opt/labs-control-panel/lab-templates/gui_essentials/Data/assets"
if [ -d "$ASSETS_DIR" ] && [ "$(ls -A "$ASSETS_DIR" 2>/dev/null)" ]; then
    mkdir -p "$USER_HOME/.config/wallpaper"
    cp -r "$ASSETS_DIR"/* "$USER_HOME/.config/wallpaper/" 2>/dev/null || true
    chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.config/wallpaper"

    # Set wallpaper if image exists
    WALLPAPER=$(find "$USER_HOME/.config/wallpaper" -type f \( -name "*.png" -o -name "*.jpg" -o -name "*.jpeg" -o -name "*.svg" \) | head -1)
    if [ -n "$WALLPAPER" ]; then
        DISPLAY=:1 xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/workspace0/last-image -s "$WALLPAPER" 2>/dev/null || true
        DISPLAY=:1 xfconf-query -c xfce4-desktop -p /backdrop/screen0/monitor0/workspace0/image-style -s 5 2>/dev/null || true
        echo "[✓] Wallpaper set: $(basename "$WALLPAPER")"
    fi

    # Replace KasmVNC logo if custom logo exists
    if [ -f "$USER_HOME/.config/wallpaper/kasm-logo.png" ]; then
        cp "$USER_HOME/.config/wallpaper/kasm-logo.png" /usr/share/kasmvnc/www/images/kasm_logo.png 2>/dev/null || true
        echo "[✓] KasmVNC logo replaced"
    fi
else
    echo "[*] No custom assets found, using defaults"
fi

# ── 8. Start Services ─────────────────────────────────────────
echo "[*] Starting services..."

# Kill any stale Xvnc
pkill -f Xvnc 2>/dev/null || true
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true
sleep 1

# Start kasmvncserver with XFCE
/usr/bin/kasmvncserver :1 -geometry 1920x1080 -depth 24 \
    -websocketPort 8444 -httpd /usr/share/kasmvnc/www \
    -interface 0.0.0.0 -noxstartup -select-de xfce \
    -SecurityTypes None -KasmPasswordFile /root/.kasmpasswd \
    -cert "$USER_HOME/.vnc/self.pem" -key "$USER_HOME/.vnc/self.pem" -sslOnly 1 \
    -auth /root/.Xauthority > /dev/null 2>&1 &
sleep 2

if pgrep -f Xvnc > /dev/null; then
    echo "[✓] KasmVNC started on port 8444"
else
    echo "[!] KasmVNC failed to start"
fi

# Start XFCE desktop
export DISPLAY=:1
eval $(dbus-launch --sh-syntax)
/usr/bin/startxfce4 --replace > /dev/null 2>&1 &
sleep 5
echo "[✓] XFCE desktop started"

# ── 8b. XFCE Desktop Layout ──────────────────────────────────
XFCE_SETUP="/opt/labs-control-panel/lab-templates/gui_essentials/Data/scripts/xfce-desktop-setup.sh"
if [ -f "$XFCE_SETUP" ]; then
    bash "$XFCE_SETUP" "$USER_NAME" 2>/dev/null || echo "[!] XFCE layout setup skipped"
fi

# Start code-server
if [ "${ENABLE_CODESERVER:-true}" = "true" ]; then
CODE_CONFIG="$USER_HOME/.config/code-server/config.yaml"
mkdir -p "$(dirname "$CODE_CONFIG")"
cat <<CODE_CONFIG_EOF > "$CODE_CONFIG"
bind-addr: 0.0.0.0:8080
auth: password
password: ${VNC_PASSWORD}
cert: false
CODE_CONFIG_EOF
chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.config"

sudo -u "$USER_NAME" -H bash -c "nohup code-server --config $CODE_CONFIG --disable-telemetry --disable-update-check > $USER_HOME/.code-server.log 2>&1 &"
sleep 2

if pgrep -u "$USER_NAME" -f code-server > /dev/null; then
    echo "[✓] Code-server started on port 8080"
else
    echo "[!] Code-server failed to start"
fi
else
    echo "[*] Code-server disabled — skipping"
fi

echo "[✓] User configuration complete"
