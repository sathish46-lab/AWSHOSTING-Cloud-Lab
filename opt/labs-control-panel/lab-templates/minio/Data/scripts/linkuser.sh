#!/bin/bash
# linkuser.sh - Professional MinIO Lab Setup with SSH Key-Only Enforcement
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
USER_EMAIL=$8
VPS_DOCKER_IP=${10}

# Compute hash from email (matches Python hashlib.md5)
USER_HASH=$(echo -n "$USER_EMAIL" | md5sum | cut -d' ' -f1)

# THE SHARED PATH: physically at /home/sathish46/.labs/miniostorage
S3_DATA_DIR="/home/$USER_NAME/.labs/miniostorage"

echo "[*] Initializing Professional MinIO Environment for $USER_NAME..."

# 1. User Setup & SSH Key Injection
if ! id "$USER_NAME" &>/dev/null; then
    # Delete default ubuntu user that steals UID 1000 in newer Ubuntu images
    if id -u ubuntu >/dev/null 2>&1; then userdel -r ubuntu || true; fi
    # Ensure /home symlink target exists
    mkdir -p /var/labsstorage/home
    useradd -m -s /bin/bash -u 1000 "$USER_NAME" 2>/dev/null || useradd -m -s /bin/bash "$USER_NAME"
    usermod -aG sudo "$USER_NAME"
fi

# Set the password (used for sudo and MinIO UI, but SSH will ignore it)
echo "$USER_NAME:$CODE_PASS" | chpasswd

# --- SSH KEY INJECTION ---
USER_SSH_DIR="/home/$USER_NAME/.ssh"
mkdir -p "$USER_SSH_DIR"

if [ -n "$PUB_KEYS" ]; then
    # %b handles backslash escapes (like \n) in the keys string
    printf "%b\n" "$PUB_KEYS" > "$USER_SSH_DIR/authorized_keys"
    
    # Critical Permissions for SSH Daemon
    chmod 700 "$USER_SSH_DIR"
    chmod 600 "$USER_SSH_DIR/authorized_keys"
    chown -R "$USER_NAME":"$USER_NAME" "$USER_SSH_DIR" 2>&1 || true
    
    # Disable StrictModes for shared volume mounts and restart SSH
    sed -i 's/^#\?StrictModes .*/StrictModes no/' /etc/ssh/sshd_config
    service ssh restart || systemctl restart ssh || /etc/init.d/ssh restart || true
    
    echo "[✓] SSH Public Keys deployed."
else
    echo "[!] WARNING: No SSH keys provided. Key-only login will fail!"
fi

# 2. Storage Setup
mkdir -p "$S3_DATA_DIR"
# Ensure the user owns their entire home directory and S3 storage
chown -R "$USER_NAME":"$USER_NAME" "/home/$USER_NAME" 2>&1 || true
chmod -R 755 "$S3_DATA_DIR"

# 3. WireGuard Mesh Networking
if [ -n "$LAB_PRIV_KEY" ]; then
    echo "[*] Configuring WireGuard Interface..."
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
    # Bring up interface; 2>/dev/null prevents 'already up' spam
    wg-quick up wg0 2>/dev/null || true
fi

# 4. Service Initialization (MinIO)
echo "[*] Starting MinIO Server..."
pkill -9 minio 2>/dev/null || true

# Run MinIO as the specific user to ensure correct file ownership on S3 uploads
sudo -u "$USER_NAME" -H bash -c "
    export MINIO_ROOT_USER='$USER_NAME' && \
    export MINIO_ROOT_PASSWORD='$CODE_PASS' && \
    export MINIO_SITE_NAME='Tom-Labs-Cloud' && \
    export MINIO_SITE_REGION='us-east-1' && \
    nohup /usr/local/bin/minio server '$S3_DATA_DIR' \
        --address ':9000' \
        --console-address ':9001' \
        >> '/home/$USER_NAME/.minio.log' 2>&1 &
"

echo "[✓] Setup Complete."
echo "[i] SSH: ssh $USER_NAME@$TUNNEL_IP"
echo "[i] MinIO Console: http://$TUNNEL_IP:9001"