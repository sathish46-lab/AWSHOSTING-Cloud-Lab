#!/bin/bash
# entry.sh — Container startup for GUI Essentials Lab
# Starts SSH immediately, waits for user deploy, then starts services

echo "[*] Starting GUI Essentials Lab..."

# ── 0. Service Flags ──────────────────────────────────────────
# Set to "true" to enable, "false" to disable
# To re-enable code-server: set ENABLE_CODESERVER=true
# To re-enable Apache: set ENABLE_APACHE=true
mkdir -p /var/labsdata
cat <<'ENVFLAGS' > /var/labsdata/.service_flags
ENABLE_CODESERVER=false
ENABLE_APACHE=false
ENVFLAGS
chmod 644 /var/labsdata/.service_flags
echo "[*] Service flags loaded (code-server=off, apache=off)"

# ── 1. SSH Host Keys ──────────────────────────────────────────
rm -f /etc/ssh/ssh_host_*
yes | ssh-keygen -t rsa -b 4096 -f /etc/ssh/ssh_host_rsa_key -N "" -q 2>/dev/null || true
yes | ssh-keygen -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key -N "" -q 2>/dev/null || true
yes | ssh-keygen -t ed25519 -f /etc/ssh/ssh_host_ed25519_key -N "" -q 2>/dev/null || true
service ssh start 2>/dev/null || true
echo "[✓] SSH started"

# ── 2. SSL cert ───────────────────────────────────────────────
# Generate in user's home for security (not root)
LAB_USER=$(awk -F: '$3 >= 1000 && $3 < 65534 && $1 != "nobody" {print $1; exit}' /etc/passwd 2>/dev/null || echo "sathish46")
USER_HOME=$(eval echo "~$LAB_USER")
mkdir -p "$USER_HOME/.vnc"
if [ ! -f "$USER_HOME/.vnc/self.pem" ]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$USER_HOME/.vnc/self.pem" -out "$USER_HOME/.vnc/self.pem" \
        -subj "/CN=localhost" 2>/dev/null
    chown -R "$LAB_USER:$LAB_USER" "$USER_HOME/.vnc"
fi
# Also keep in /root for backward compatibility
mkdir -p /root/.vnc
cp "$USER_HOME/.vnc/self.pem" /root/.vnc/self.pem 2>/dev/null || true

# ── 3. Wait for deploy (linkuser.sh) then start services ──────
# On first boot, linkuser.sh creates user + kasmpasswd + starts services
# On container restart, we start services directly
[ -f /var/labsdata/.service_flags ] && source /var/labsdata/.service_flags
MAX_WAIT=60
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if [ -f /root/.kasmpasswd ] && id sathish47 &>/dev/null; then
        break
    fi
    sleep 1
    WAITED=$((WAITED + 1))
done

if [ $WAITED -ge $MAX_WAIT ]; then
    echo "[!] Timed out waiting for deploy, starting services anyway"
fi

# Only start services if linkuser.sh hasn't already
if ! pgrep -f Xvnc > /dev/null 2>&1; then
    echo "[*] Starting KasmVNC..."
    rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null || true
    /usr/bin/kasmvncserver :1 -geometry 1920x1080 -depth 24 \
        -websocketPort 8444 -httpd /usr/share/kasmvnc/www \
        -interface 0.0.0.0 -noxstartup -select-de xfce \
        -SecurityTypes None -KasmPasswordFile /root/.kasmpasswd \
        -cert "$USER_HOME/.vnc/self.pem" -key "$USER_HOME/.vnc/self.pem" -sslOnly 1 \
        -auth /root/.Xauthority > /dev/null 2>&1 &
    sleep 2
    pgrep -f Xvnc > /dev/null && echo "[✓] KasmVNC started" || echo "[!] KasmVNC failed"
fi

if ! pgrep -f xfce4-session > /dev/null 2>&1; then
    echo "[*] Starting XFCE..."
    export DISPLAY=:1
    eval $(dbus-launch --sh-syntax)
    nohup xfwm4 --replace --compositor=off > /dev/null 2>&1 &
    sleep 1
    nohup xfdesktop > /dev/null 2>&1 &
    sleep 1
    nohup xfce4-panel > /dev/null 2>&1 &
    sleep 1
    nohup xfce4-session > /dev/null 2>&1 &
    sleep 2
    echo "[✓] XFCE started"
fi

LAB_USER=$(awk -F: '$3 >= 1000 && $3 < 65534 && $1 != "nobody" {print $1; exit}' /etc/passwd 2>/dev/null)
if [ "${ENABLE_CODESERVER:-true}" = "true" ] && [ -n "$LAB_USER" ] && ! pgrep -u "$LAB_USER" -f code-server > /dev/null 2>&1; then
    echo "[*] Starting code-server..."
    USER_HOME=$(eval echo "~$LAB_USER")
    mkdir -p "$USER_HOME/.config/code-server"
    # Extract VNC password from kasmpasswd (first line of kasmvncpasswd -o output)
if [ -f /root/.kasmpasswd ]; then
    CODE_PASS=$(kasmvncpasswd -o < /root/.kasmpasswd 2>/dev/null | head -1 || echo "${LAB_USER}@098")
else
    CODE_PASS="${LAB_USER}@098"
fi
cat <<EOF > "$USER_HOME/.config/code-server/config.yaml"
bind-addr: 0.0.0.0:8080
auth: password
password: ${CODE_PASS}
cert: false
EOF
    chown -R "$LAB_USER:$LAB_USER" "$USER_HOME/.config"
    sudo -u "$LAB_USER" -H bash -c "nohup code-server --config $USER_HOME/.config/code-server/config.yaml --disable-telemetry --disable-update-check > $USER_HOME/.code-server.log 2>&1 &"
    sleep 2
    pgrep -u "$LAB_USER" -f code-server > /dev/null && echo "[✓] Code-server started" || echo "[!] Code-server failed"
else
    echo "[*] Code-server disabled — skipping"
fi

echo "[✓] GUI Essentials Lab ready — SSH(22) KasmVNC(8444)"
tail -f /dev/null
