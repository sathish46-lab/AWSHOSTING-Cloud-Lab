#!/bin/bash
# linkuser.sh - n8n Version
# $1=Username, $2=PublicKeys, $3=DockerIP, $4=CodePassword/N8NPassword
# $5=LabPrivateKey, $6=TunnelIP, $7=ServerPublicKey, $8=UserEmail
# $9=WebhookDomain, $10=VPSDockerIP

USER_NAME=$1
PUB_KEYS=$2
DOCKER_IP=$3
CODE_PASS=$4
LAB_PRIV_KEY=$5
TUNNEL_IP=$6
SERVER_PUBKEY=$7
USER_EMAIL=$8
WEBHOOK_DOMAIN=$9
VPS_DOCKER_IP=${10}
SU_PASS=${11}

# Fallback for email if empty (legacy support)
if [ -z "$USER_EMAIL" ]; then
    USER_EMAIL="$USER_NAME@tomlabs.shop"
fi

SYSTEM_PASS="${SU_PASS:-${USER_NAME}@098}"

echo "[*] Starting n8n user configuration..."
echo "    Username: $USER_NAME"
echo "    Email: $USER_EMAIL"

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
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;32m\]\u\[\033[00m\]@\[\033[38;5;208m\]n8n-lab\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
alias ls='ls --color=auto'
alias ll='ls -alF'
BASHRC_EOF
chown "$USER_NAME":"$USER_NAME" "$USER_HOME/.bashrc"

# 4. WireGuard Configuration
if [ -n "$LAB_PRIV_KEY" ] && [ -n "$SERVER_PUBKEY" ]; then
    mkdir -p /etc/wireguard
    cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
PrivateKey = $LAB_PRIV_KEY
Address = $TUNNEL_IP/32
MTU = 1420
Table = off
[Peer]
PublicKey = $SERVER_PUBKEY
Endpoint = ${VPS_DOCKER_IP:-172.30.0.1}:51820
TUNNEL_PREFIX=$(echo "${VPS_DOCKER_IP:-172.30.0.1}" | awk -F. '{print $1"."$2"."$3"."}')
AllowedIPs = ${TUNNEL_PREFIX}0/16
PersistentKeepalive = 25
EOF
    chmod 600 /etc/wireguard/wg0.conf
    wg-quick down wg0 2>/dev/null || true
    wg-quick up wg0
fi

# 5. n8n Setup
echo "[*] Setting up n8n..."
pkill -u "$USER_NAME" -f n8n 2>/dev/null || true

# Start n8n as user with Basic Auth
# We use nohup and redirect logs
# We export variables specifically for this process
sudo -u "$USER_NAME" -H bash -c "export N8N_BASIC_AUTH_ACTIVE=true; export N8N_BASIC_AUTH_USER='$USER_EMAIL'; export N8N_BASIC_AUTH_PASSWORD='$CODE_PASS'; export WEBHOOK_URL='https://n8n-$(hostname).tomweb.shop'; nohup n8n start > $USER_HOME/.n8n.log 2>&1 &"

echo "[✓] n8n started (User: $USER_EMAIL)"

# 6. Code-Server Setup (Optional but included)
echo "[*] Setting up Code-Server..."
pkill -u "$USER_NAME" -f code-server 2>/dev/null || true
fuser -k 8080/tcp 2>/dev/null || true

USER_CONFIG="$USER_HOME/.config/code-server/config.yaml"
mkdir -p "$(dirname "$USER_CONFIG")"
cat <<CODE_CONFIG > "$USER_CONFIG"
bind-addr: 0.0.0.0:8080
auth: password
password: $CODE_PASS
cert: false
CODE_CONFIG
chown -R "$USER_NAME":"$USER_NAME" "$USER_HOME/.config"

# Optimized code-server startup with performance flags
sudo -u "$USER_NAME" -H bash -c "nohup code-server \
    --config $USER_CONFIG \
    --disable-telemetry \
    --disable-update-check \
    --max-memory 512 \
    > $USER_HOME/.code-server.log 2>&1 &"
echo "[✓] Code-server started"

# 7. Persistent Linking (Clean Structure)
# The user wants "n8n_data" directly in their storage root.
# Docker mounts storage to /home/$USER_NAME.
# So we just need to ensure /home/$USER_NAME/n8n_data exists and ~/.n8n symlinks to it.

PERSISTENT_DIR="$USER_HOME/n8n_data"
mkdir -p "$PERSISTENT_DIR"
chown -R "$USER_NAME":"$USER_NAME" "$PERSISTENT_DIR"

# Handle existing .n8n directory (first run or if user manually messed with it)
if [ -d "$USER_HOME/.n8n" ] && [ ! -L "$USER_HOME/.n8n" ]; then
    echo "[*] Migrating existing .n8n data..."
    cp -r "$USER_HOME/.n8n/." "$PERSISTENT_DIR/" 2>/dev/null
    rm -rf "$USER_HOME/.n8n"
fi

# Force Update Symlink (Fixes previous htdocs link)
rm -rf "$USER_HOME/.n8n"
ln -s "$PERSISTENT_DIR" "$USER_HOME/.n8n"
echo "[*] Linked ~/.n8n to $PERSISTENT_DIR"
chown -h "$USER_NAME":"$USER_NAME" "$USER_HOME/.n8n"

# 5. n8n Setup
echo "[*] Setting up n8n..."
pkill -u "$USER_NAME" -f n8n 2>/dev/null || true

# 5. n8n Setup
echo "[*] Setting up n8n..."
pkill -u "$USER_NAME" -f n8n 2>/dev/null || true

# Direct DB Update Script (Force Password Sync)
# Since CLI commands are missing/unreliable, we update SQLite directly using n8n's node environment.
DB_PATH="$PERSISTENT_DIR/database.sqlite"
UPDATE_SCRIPT="$USER_HOME/update_n8n_pass.js"

cat <<NODE_EOF > "$UPDATE_SCRIPT"
const sqlite3 = require('sqlite3').verbose();
const bcrypt = require('bcryptjs'); // n8n has this dependency
const path = require('path');

const dbPath = '$DB_PATH';
const email = '$USER_EMAIL';
const password = '$CODE_PASS';

if (!require('fs').existsSync(dbPath)) {
    console.log('No database found, skipping update.');
    process.exit(0);
}

const db = new sqlite3.Database(dbPath);

console.log('Updating password for:', email);

const hash = bcrypt.hashSync(password, 10);

db.serialize(() => {
    // Check if user exists
    db.get("SELECT id FROM user WHERE email = ?", [email], (err, row) => {
        if (err) {
            console.error('Error checking user:', err);
            process.exit(1);
        }
        
        if (row) {
            // Update existing user
            db.run("UPDATE user SET password = ? WHERE email = ?", [hash, email], function(err) {
                if (err) {
                    console.error('Update failed:', err);
                    db.close();
                    process.exit(1);
                }
                console.log('Password updated successfully. Rows affected:', this.changes);
                db.close();
            });
        } else {
            console.log('User not found. n8n might prompt for setup.');
            db.close();
            // Optional: Insert user if we want to auto-provision? 
            // Needs firstname, lastname, etc. Too risky without schema knowledge.
        }
    });
});
NODE_EOF

chown "$USER_NAME":"$USER_NAME" "$UPDATE_SCRIPT"

    # Run the update script if DB exists
if [ -f "$DB_PATH" ]; then
    echo "[*] Runnning password update script..."
    # We need to run this with n8n's node_modules available
    # n8n is likely in /usr/lib/node_modules/n8n
    NODE_MODULES_PATH="/usr/lib/node_modules/n8n/node_modules"
    
    # Check if sqlite3 module is available (it should be in n8n/node_modules)
    # If not, we might need to rely on the user setting it up manually as fallback.
    # Pass NODE_PATH explicitly to sudo environment
    sudo -u "$USER_NAME" NODE_PATH="$NODE_MODULES_PATH" -H node "$UPDATE_SCRIPT" || echo "[!] Password update failed (missing modules?), please login with old credentials or reset."
fi

# Start n8n
sudo -u "$USER_NAME" -H bash -c "\
export WEBHOOK_URL='$FINAL_WEBHOOK_URL'; \
export EXECUTIONS_DATA_PRUNE=true; \
export EXECUTIONS_DATA_MAX_AGE=1; \
export DB_SQLITE_VACUUM_ON_STARTUP=true; \
export EXECUTIONS_PROCESS=main; \
export N8N_DIAGNOSTICS_ENABLED=false; \
export N8N_VERSION_NOTIFICATIONS_ENABLED=false; \
export N8N_PERSONALIZATION_ENABLED=false; \
nohup n8n start > $USER_HOME/.n8n.log 2>&1 &"

echo "[✓] n8n started (User: $USER_EMAIL)"



echo "[✓] Persistence configured (n8n_data)"
