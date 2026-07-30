#!/bin/bash

# Migrate.sh - Universal Setup (VPS & Docker)
# usage: sudo ./Migrate.sh

# Exit on error
set -e
export COMPOSER_ALLOW_SUPERUSER=1

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

log() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Professional Progress Bar Function
run_with_progress() {
    local cmd="$1"
    local desc="$2"
    
    # Run command in background
    eval "$cmd" > /tmp/migrate_cmd.log 2>&1 &
    local pid=$!
    
    local width=40
    local prog=0
    
    while kill -0 $pid 2>/dev/null; do
        prog=$((prog + 3))
        if [ $prog -gt 99 ]; then prog=99; fi
        
        local fill=$(( prog * width / 100 ))
        local empty=$(( width - fill ))
        
        printf "\r${GREEN}[INFO]${NC} %-35s [" "$desc"
        printf "%${fill}s" "" | tr ' ' '#'
        printf "%${empty}s" "" | tr ' ' '-'
        printf "] %3d%%" $prog
        
        sleep 0.2
    done
    
    wait $pid
    local status=$?
    
    if [ $status -eq 0 ]; then
        printf "\r${GREEN}[INFO]${NC} %-35s [" "$desc"
        printf "%${width}s" "" | tr ' ' '#'
        printf "] 100%%\n"
    else
        echo -e "\n${RED}[ERROR]${NC} Task failed! Check /tmp/migrate_cmd.log"
        cat /tmp/migrate_cmd.log
        exit 1
    fi
}

# Check for root privileges
if [ "$EUID" -ne 0 ]; then
  error "Please run as root"
  exit 1
fi

# Detect if we are already running inside the auto-vps mode (from Docker exec)
if [ "$1" == "--auto-vps" ]; then
    if [ -f "/tmp/migrate.env" ]; then
        source /tmp/migrate.env
    fi
    MODE="${MODE:-VPS}"
    AUTO=1
else
    # Interactive Prompts
    echo "=================================================="
    echo "      Migrate.sh - Universal Setup"
    echo "=================================================="
    echo "Select Setup Type:"
    echo "  [1] VPS Bare-Metal Server Setup"
    echo "  [2] Docker Container Local Setup"
    read -p "Enter choice (1 or 2): " SETUP_CHOICE </dev/tty

    if [ "$SETUP_CHOICE" == "2" ]; then
        MODE="DOCKER"
    else
        MODE="VPS"
    fi
    AUTO=0

    read -p "Enter Main Domain (default: tomweb.in): " MAIN_DOMAIN </dev/tty
    export MAIN_DOMAIN=${MAIN_DOMAIN:-tomweb.in}

    read -p "Enter VPN API Domain (default: vpn.tomweb.in): " VPN_DOMAIN </dev/tty
    export VPN_DOMAIN=${VPN_DOMAIN:-vpn.tomweb.in}

    read -p "Enter RabbitMQ Domain (default: mq.tomweb.in): " MQS_DOMAIN </dev/tty
    export MQS_DOMAIN=${MQS_DOMAIN:-mq.tomweb.in}

    read -p "Enter Code Server Domain (default: tomweb.shop): " CODE_DOMAIN </dev/tty
    export CODE_DOMAIN=${CODE_DOMAIN:-tomweb.shop}

    read -p "Enter Work Domain (default: work.tomweb.in): " WORK_DOMAIN </dev/tty
    export WORK_DOMAIN=${WORK_DOMAIN:-work.tomweb.in}

    read -p "Enter Email for SSL generation (e.g., admin@example.com): " SSL_EMAIL </dev/tty
    export SSL_EMAIL=${SSL_EMAIL:-admin@example.com}

    echo "--------------------------------------------------"
    echo "Database & Services Credentials"
    echo "--------------------------------------------------"
    read -s -p "Enter Universal Database Root Password (MongoDB/MySQL/Postgres) (default: tomlabs_secret): " DB_PASS </dev/tty
    echo ""
    export DB_PASS=${DB_PASS:-tomlabs_secret}

    read -s -p "Enter RabbitMQ Admin Password (Broker) (default: tomlabs_mq_secret): " MQ_PASS </dev/tty
    echo ""
    export MQ_PASS=${MQ_PASS:-tomlabs_mq_secret}


    echo "--------------------------------------------------"
    echo "Configuration Summary:"
    echo "  Mode:        $MODE"
    echo "  Main Domain: $MAIN_DOMAIN"
    echo "  VPN Domain:  $VPN_DOMAIN"
    echo "  MQS Domain:  $MQS_DOMAIN"
    echo "  Code Domain: $CODE_DOMAIN"
    echo "  SSL Email:   $SSL_EMAIL"
    echo "  DB Password: (hidden)"
    echo "  MQ Password: (hidden)"
    echo "--------------------------------------------------"
    read -p "Is this correct? (y/n): " CONFIRM </dev/tty
    if [[ "$CONFIRM" != "y" ]]; then
        echo "Aborted."
        exit 0
    fi
fi

# ==========================================
# DOCKER MODE
# ==========================================
if [ "$MODE" == "DOCKER" ]; then
    log "Starting Docker Setup Mode..."

    # Create local directories for mapping if they don't exist
    mkdir -p ./traefik-conf/dynamic_conf ./opt/labs-control-panel ./vpn-api ./labs ./wireguard-conf ./rabbitmq-data ./mongo-data ./apache-logs
    
    # Extract sample.json as env.json in local dir (will map to /var/www)
    if [ ! -f "./env.json" ]; then
        if [ -f "./sample.json" ]; then
            cp "./sample.json" ./env.json
        elif [ -f "./labs/sample.json" ]; then
            cp "./labs/sample.json" ./env.json
        else
            echo "{}" > ./env.json
        fi
    fi

    if [ -f ./env.json ]; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s/DB_PASS_PLACEHOLDER/$DB_PASS/g" ./env.json
            sed -i '' "s/MQ_PASS_PLACEHOLDER/$MQ_PASS/g" ./env.json
            sed -i '' "s/DB_HOST_PLACEHOLDER/mongodb/g" ./env.json
            sed -i '' "s|VPN_URL_PLACEHOLDER|http://127.0.0.1:8082/api|g" ./env.json
        else
            sed -i "s/DB_PASS_PLACEHOLDER/$DB_PASS/g" ./env.json
            sed -i "s/MQ_PASS_PLACEHOLDER/$MQ_PASS/g" ./env.json
            sed -i "s/DB_HOST_PLACEHOLDER/mongodb/g" ./env.json
            sed -i "s|VPN_URL_PLACEHOLDER|http://127.0.0.1:8082/api|g" ./env.json
        fi
    fi
    
    # Extract session.json in local dir (will map to /var/www)
    if [ -f "$TEMP_WEB/session.json" ]; then
        cp "$TEMP_WEB/session.json" ./session.json
    elif [ -f "$TEMP_WEB/labs/session.json" ]; then
        cp "$TEMP_WEB/labs/session.json" ./session.json
    else
        echo "{}" > ./session.json
    fi
    
    rm -rf "$TEMP_WEB"

    log "Generating Dockerfile..."
    cat <<'OUTER_EOF_DOCKER' > Dockerfile
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV container docker

# 1. System Updates & Core Dependencies
RUN apt-get update && \
    apt-get install -y systemd systemd-sysv sudo iputils-ping curl wget nano iptables iproute2 kmod tzdata software-properties-common gnupg2 jq \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Delete systemd targets that prevent running cleanly in a container
RUN cd /lib/systemd/system/sysinit.target.wants/ || exit; \
    for i in *; do [ $i = systemd-tmpfiles-setup.service ] || rm -f $i; done; \
    rm -f /lib/systemd/system/multi-user.target.wants/*; \
    rm -f /etc/systemd/system/*.wants/*; \
    rm -f /lib/systemd/system/local-fs.target.wants/*; \
    rm -f /lib/systemd/system/sockets.target.wants/*udev*; \
    rm -f /lib/systemd/system/sockets.target.wants/*initctl*; \
    rm -f /lib/systemd/system/basic.target.wants/*; \
    rm -f /lib/systemd/system/anaconda.target.wants/*;

# 2. Add Repositories (PHP, Docker, MongoDB)
RUN add-apt-repository -y ppa:ondrej/php && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes && \
    chmod a+r /etc/apt/keyrings/docker.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu noble stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    curl -fsSL https://www.mongodb.org/static/pgp/server-8.0.asc | sudo gpg -o /usr/share/keyrings/mongodb-server-8.0.gpg --dearmor --yes && \
    echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse" | sudo tee /etc/apt/sources.list.d/mongodb-org-8.0.list

# 3. Install Software Stack
RUN apt-get update && apt-get install -y \
    git curl unzip \
    apache2 libapache2-mod-php8.4 \
    php8.4 php8.4-cli php8.4-common php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd php8.4-mongodb php8.4-amqp php8.4-mysql php8.4-pgsql php8.4-redis \
    rabbitmq-server \
    wireguard wireguard-tools \
    python3 python3-pip python3-pymongo python3-docker python3-redis python3-pika python3-psutil \
    docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin \
    ufw fail2ban nmap mongodb-org mysql-server postgresql redis-server \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Python packages not available via apt
RUN pip3 install --break-system-packages google-generativeai requests pymongo

# Create symlink for labsctl
RUN ln -sf /opt/labs-control-panel/labsctl.py /usr/local/bin/labsctl && \
    chmod +x /opt/labs-control-panel/labsctl.py 2>/dev/null || true

# 4. Install Traefik
RUN wget https://github.com/traefik/traefik/releases/download/v2.10.6/traefik_v2.10.6_linux_amd64.tar.gz && \
    tar -zxvf traefik_v2.10.6_linux_amd64.tar.gz && \
    mv traefik /usr/local/bin/ && \
    chmod +x /usr/local/bin/traefik && \
    rm traefik_v2.10.6_linux_amd64.tar.gz

# 5. Install Node.js, Composer, Grunt
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    npm install -g grunt-cli

# 6. Configure Base Services
# MongoDB Auth
RUN sed -i 's/#security:/security:\n  authorization: enabled/' /etc/mongod.conf && \
    sed -i 's/bindIp: 127.0.0.1/bindIp: 0.0.0.0/' /etc/mongod.conf

# MySQL Bind Address
RUN sed -i 's/bind-address\s*=\s*127.0.0.1/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf || true

# PostgreSQL Config
RUN for conf in /etc/postgresql/*/main/postgresql.conf; do sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/" "$conf"; done && \
    for conf in /etc/postgresql/*/main/pg_hba.conf; do echo "host all all 0.0.0.0/0 md5" >> "$conf"; done && \
    mkdir -p /etc/systemd/system/postgresql@.service.d && \
    echo "[Service]\nPIDFile=" > /etc/systemd/system/postgresql@.service.d/override.conf

# Redis Config
RUN sed -i 's/bind 127.0.0.1 ::1/bind 0.0.0.0/' /etc/redis/redis.conf && \
    sed -i 's/protected-mode yes/protected-mode no/' /etc/redis/redis.conf

# 7. Create Directories
RUN mkdir -p /var/www/labs /var/www/vpn-api /opt/labs-control-panel /etc/traefik/dynamic_conf /etc/wireguard /var/www/adminer

# Install Adminer
RUN wget -O /var/www/adminer/index.php https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php && \
    echo '<VirtualHost *:8080>\n    ServerName adminer.tomweb.in\n    DocumentRoot /var/www/adminer\n    <Directory /var/www/adminer>\n        AllowOverride All\n        Require all granted\n    </Directory>\n    ErrorLog ${APACHE_LOG_DIR}/adminer_error.log\n</VirtualHost>' > /etc/apache2/sites-available/adminer.conf && \
    a2ensite adminer
# 8. Static Configurations
# Apache Ports
RUN echo "Listen 8080\nListen 8081\nListen 8082\n<IfModule ssl_module>\n    Listen 4431\n</IfModule>" > /etc/apache2/ports.conf
RUN touch /etc/apache2/code_server_map.txt

# Traefik configuration
RUN touch /etc/traefik/acme.json && chmod 600 /etc/traefik/acme.json
RUN echo "entryPoints:\n  web:\n    address: \":80\"\n  websecure:\n    address: \":443\"\nproviders:\n  file:\n    directory: \"/etc/traefik/dynamic_conf\"\n    watch: true" > /etc/traefik/traefik.yml

# Traefik Systemd Service
RUN echo "[Unit]\nDescription=Traefik Edge Router\nAfter=network-online.target\n[Service]\nRestart=on-failure\nExecStart=/usr/local/bin/traefik --configFile=/etc/traefik/traefik.yml\nLimitNOFILE=65536\n[Install]\nWantedBy=multi-user.target" > /etc/systemd/system/traefik.service
RUN systemctl enable traefik

# Container Setup Systemd Service (runs after DBs)
RUN echo "[Unit]\nDescription=Container Setup Script\nAfter=mongod.service rabbitmq-server.service mysql.service postgresql.service redis-server.service network.target\n[Service]\nType=oneshot\nTimeoutStartSec=infinity\nExecStart=/usr/local/bin/init-services.sh\nRemainAfterExit=yes\n[Install]\nWantedBy=multi-user.target" > /etc/systemd/system/init-services.service
RUN systemctl enable mongod.service rabbitmq-server.service mysql.service postgresql.service redis-server.service init-services.service

# Fix PostgreSQL systemd bug in containers (refusing PIDFile)
RUN mkdir -p /etc/systemd/system/postgresql@.service.d/ && \
    printf "[Service]\nPIDFile=\n" > /etc/systemd/system/postgresql@.service.d/override.conf

# Sudoers & Git config
RUN echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/python3 /opt/labs-control-panel/labsctl.py *" > /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/labsctl *" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/docker" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/ip" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/iptables" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/wg" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/wg-quick" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/cat /etc/wireguard/*" >> /etc/sudoers.d/labs-www-data && \
    echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap" >> /etc/sudoers.d/labs-www-data && \
    chmod 440 /etc/sudoers.d/labs-www-data && \
    git config --system --add safe.directory /var/www && \
    git config --system --add safe.directory /var/www/labs

# Copy scripts
# Avoid internal docker conflict and ensure socket symlink persists
RUN systemctl mask docker.service docker.socket && \
    echo "L+ /run/docker.sock - - - - /var/docker.sock" > /etc/tmpfiles.d/docker-socket.conf

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY init-services.sh /usr/local/bin/init-services.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/init-services.sh

VOLUME [ "/sys/fs/cgroup" ]

# Set Entrypoint script up to run before systemd execution
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

OUTER_EOF_DOCKER

    log "Generating entrypoint.sh..."
    cat <<'OUTER_EOF_ENTRYPOINT' > entrypoint.sh
#!/bin/bash

# Configuration settings (Defaults or from ENV)
export MAIN_DOMAIN=${MAIN_DOMAIN:-tomweb.in}
export VPN_DOMAIN=${VPN_DOMAIN:-vpn.tomweb.in}
export MQS_DOMAIN=${MQS_DOMAIN:-mq.tomweb.in}
export CODE_DOMAIN=${CODE_DOMAIN:-tomweb.shop}
export WORK_DOMAIN=${WORK_DOMAIN:-work.tomweb.in}
export SSL_EMAIL=${SSL_EMAIL:-admin@example.com}

echo "[INFO] Running Pre-boot Initialization in Entrypoint..."

# 1. WireGuard Configuration (Idempotent - always fixes Interface, preserves Peers)
echo "[INFO] Configuring WireGuard..."

# Enable IP forwarding (required for WireGuard routing)
sysctl -w net.ipv4.ip_forward=1 > /dev/null 2>&1

if [ ! -f /etc/wireguard/privatekey ]; then
    echo "[INFO] Generating new WireGuard keys..."
    PRIVATE_KEY=$(wg genkey)
    PUBLIC_KEY=$(echo "$PRIVATE_KEY" | wg pubkey)
    echo "$PRIVATE_KEY" > /etc/wireguard/privatekey
    echo "$PUBLIC_KEY" > /etc/wireguard/publickey
    chmod 600 /etc/wireguard/privatekey
else
    PRIVATE_KEY=$(cat /etc/wireguard/privatekey)
    PUBLIC_KEY=$(cat /etc/wireguard/publickey)
    echo "[INFO] Using existing WireGuard keys."
fi

# Extract existing [Peer] blocks from current config (if any)
EXISTING_PEERS=""
if [ -f /etc/wireguard/wg0.conf ]; then
    EXISTING_PEERS=$(awk '/^\[Peer\]/,0' /etc/wireguard/wg0.conf)
fi

# Detect Docker network name from config.json or env.json
DOCKER_NETWORK=$(jq -r '.docker_network_name // empty' /opt/labs-control-panel/config.json 2>/dev/null)
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK=$(jq -r '.docker_network_name // empty' /var/www/env.json 2>/dev/null)
fi
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK=$(docker network ls --format '{{.Name}}' | grep -E "(dev_lab_frontend|Dev_lab)" | head -n 1)
fi
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK="dev_lab_frontend"
fi

# Detect Docker bridge interface for forwarding rules
DOCKER_BRIDGE=$(docker network inspect "$DOCKER_NETWORK" --format '{{.Id}}' 2>/dev/null | cut -c1-12)
if [ -n "$DOCKER_BRIDGE" ]; then
    BRIDGE_IF="br-${DOCKER_BRIDGE}"
else
    BRIDGE_IF=""
fi

# Fetch tunnel prefix from config or env.json
TUNNEL_PREFIX=$(jq -r '.tunnel_ip // empty' /opt/labs-control-panel/config.json 2>/dev/null)
if [ -z "$TUNNEL_PREFIX" ] || [ "$TUNNEL_PREFIX" = "null" ]; then
    TUNNEL_PREFIX=$(jq -r '.tunnel_ip // empty' /var/www/env.json 2>/dev/null)
fi
if [ -z "$TUNNEL_PREFIX" ] || [ "$TUNNEL_PREFIX" = "null" ]; then
    echo "FATAL: tunnel_ip not set in config.json or env.json"
    exit 1
fi
TUNNEL_IP="${TUNNEL_PREFIX}1/16"

# Always regenerate the [Interface] section (self-healing)
# NOTE: No SaveConfig - peers are managed by wg set commands
cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
Address = $TUNNEL_IP
PostUp = iptables -A FORWARD -i wg0 -o eth0 -j ACCEPT
PostUp = iptables -t nat -I POSTROUTING -o eth0 -j MASQUERADE
${BRIDGE_IF:+PostUp = iptables -A FORWARD -i wg0 -o $BRIDGE_IF -j ACCEPT}
${BRIDGE_IF:+PostUp = iptables -t nat -I POSTROUTING -o $BRIDGE_IF -j MASQUERADE}
PreDown = iptables -D FORWARD -i wg0 -o eth0 -j ACCEPT
PreDown = iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE
${BRIDGE_IF:+PreDown = iptables -D FORWARD -i wg0 -o $BRIDGE_IF -j ACCEPT}
${BRIDGE_IF:+PreDown = iptables -t nat -D POSTROUTING -o $BRIDGE_IF -j MASQUERADE}
ListenPort = 51820
PrivateKey = $PRIVATE_KEY
EOF

# Re-append preserved [Peer] entries (skip any orphan peers with no AllowedIPs)
if [ -n "$EXISTING_PEERS" ]; then
    echo "" >> /etc/wireguard/wg0.conf
    echo "$EXISTING_PEERS" | awk '
        /^\[Peer\]/ { peer_block="[Peer]"; has_allowed=0; next }
        peer_block != "" && /^AllowedIPs/ { has_allowed=1; peer_block=peer_block"\n"$0; next }
        peer_block != "" && /^PublicKey/ { peer_block=peer_block"\n"$0; next }
        peer_block != "" && /^PersistentKeepalive/ { peer_block=peer_block"\n"$0; next }
        peer_block != "" && /^Endpoint/ { peer_block=peer_block"\n"$0; next }
        peer_block != "" && /^\[/ { if (has_allowed) print peer_block"\n"; peer_block="[Peer]"; has_allowed=0; next }
        peer_block != "" && /^$/ { if (has_allowed) print peer_block"\n"; peer_block=""; has_allowed=0; next }
        END { if (peer_block != "" && has_allowed) print peer_block }
    ' >> /etc/wireguard/wg0.conf
fi

sed -i '/^$/{ N; /^\n$/d }' /etc/wireguard/wg0.conf
chmod 600 /etc/wireguard/wg0.conf
echo "[INFO] WireGuard config regenerated (peers preserved)."

# WireGuard Endpoint Resolution (Docker vs VPS mode)
WG_MODE="${WIREGUARD_MODE:-docker}"
WG_PORT=$(jq -r '.wireguard_endpoint_port // 51820' /var/www/env.json 2>/dev/null || echo "51820")

if [ "$WG_MODE" = "vps" ]; then
    # VPS mode: prefer VPS_PUBLIC_IP env var, fallback to auto-detect
    if [ -n "$VPS_PUBLIC_IP" ]; then
        WG_ENDPOINT="${VPS_PUBLIC_IP}:${WG_PORT}"
        echo "[INFO] WireGuard Mode: VPS (using VPS_PUBLIC_IP=${VPS_PUBLIC_IP})"
    else
        DETECTED_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || echo "")
        if [ -n "$DETECTED_IP" ]; then
            WG_ENDPOINT="${DETECTED_IP}:${WG_PORT}"
            echo "[INFO] WireGuard Mode: VPS (auto-detected IP=${DETECTED_IP})"
        else
            WG_ENDPOINT="${VPN_DOMAIN}:${WG_PORT}"
            echo "[WARN] WireGuard Mode: VPS — IP detection failed, falling back to VPN_DOMAIN (${VPN_DOMAIN})"
        fi
    fi
else
    # Docker mode: use DDNS domain (Cloudflare)
    WG_ENDPOINT="${VPN_DOMAIN}:${WG_PORT}"
    echo "[INFO] WireGuard Mode: Docker/DDNS (Endpoint: ${WG_ENDPOINT})"
fi

export WG_ENDPOINT

# 2. Generate Apache Configuration Files
echo "[INFO] Generating Apache VirtualHosts..."
cat <<EOF > /etc/apache2/sites-available/labs.conf
<VirtualHost *:8081>
    ServerAdmin $SSL_EMAIL
    DocumentRoot "/var/www/labs/htdocs"
    ServerName $MAIN_DOMAIN

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory "/var/www/labs">
            Options -Indexes +FollowSymLinks -ExecCGI +Includes
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
EOF

cat <<EOF > /etc/apache2/sites-available/mqs.conf
<VirtualHost *:8081>
    ServerName $MQS_DOMAIN

    ProxyRequests Off
    ProxyPreserveHost On

    # 1. Handle the Native WebSocket for Overview Stats
    ProxyPass /stats-ws ws://127.0.0.1:8085/
    ProxyPassReverse /stats-ws ws://127.0.0.1:8085/

    # 1.5 Handle the STOMP WebSocket for Deployment Logs
    ProxyPass /ws ws://127.0.0.1:15674/ws
    ProxyPassReverse /ws ws://127.0.0.1:15674/ws

    # 2. Handle standard RabbitMQ Management traffic
    ProxyPass / http://127.0.0.1:15672/
    ProxyPassReverse / http://127.0.0.1:15672/

    Header set Sec-WebSocket-Protocol "v10.stomp, v11.stomp, v12.stomp"
</VirtualHost>
EOF

cat <<EOF > /etc/apache2/sites-available/wg-api.conf
<VirtualHost *:8082>
    ServerAdmin $SSL_EMAIL
    DocumentRoot "/var/www/vpn-api"
    ServerName $VPN_DOMAIN

    ErrorLog \${APACHE_LOG_DIR}/vpn_error.log
    CustomLog \${APACHE_LOG_DIR}/vpn_access.log combined

    <Directory "/var/www/vpn-api">
            Options Indexes FollowSymLinks ExecCGI Includes
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
EOF

cat <<EOF > /etc/apache2/sites-available/code.conf
RewriteMap code_map "txt:/etc/apache2/code_server_map.txt"
ProxyTimeout 600
ProxyBadHeader Ignore

<VirtualHost *:8081>
    ServerName $CODE_DOMAIN
    ServerAlias *.$CODE_DOMAIN
    
    RewriteEngine On

    RewriteCond %{HTTP_HOST} ^([a-z0-9]+)\.$CODE_DOMAIN$ [NC]
    RewriteRule ^ - [E=HASH:%1]
    RewriteCond \${code_map:%{ENV:HASH}} ^(.+)$
    RewriteRule ^ - [E=TARGET_IP:%1]

    RewriteCond %{ENV:TARGET_IP} .
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/(.*)$ ws://%{ENV:TARGET_IP}:8080/\$1 [P,L]

    RewriteCond %{ENV:TARGET_IP} .
    RewriteRule ^/(.*)$ http://%{ENV:TARGET_IP}:8080/\$1 [P,L]

    ProxyPreserveHost On
    ProxyRequests Off
    RequestHeader set X-Forwarded-Proto "https"

    RewriteCond %{ENV:TARGET_IP} ^$
    RewriteRule ^ - [L,R=404]
</VirtualHost>
EOF

cat <<EOF > /etc/apache2/sites-available/work.conf
<VirtualHost *:8081>
    ServerAdmin $SSL_EMAIL
    DocumentRoot "/var/www/work"
    ServerName $WORK_DOMAIN

    ErrorLog \${APACHE_LOG_DIR}/work_error.log
    CustomLog \${APACHE_LOG_DIR}/work_access.log combined

    <Directory "/var/www/work">
            Options -Indexes +FollowSymLinks -ExecCGI +Includes
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
EOF

# 3. Generate Traefik Dynamic config
echo "[INFO] Generating Traefik Configuration..."

# Generate static Traefik config with certResolver
cat <<EOF > /etc/traefik/traefik.yml
entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"

certificatesResolvers:
  myresolver:
    acme:
      email: $SSL_EMAIL
      storage: /etc/traefik/acme.json
      httpChallenge:
        entryPoint: web

providers:
  file:
    directory: "/etc/traefik/dynamic_conf"
    watch: true
EOF

cat <<EOF > /etc/traefik/dynamic_conf/dynamic_conf.yml
http:
  middlewares:
    code-headers:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"
    vpn-headers:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"
        accessControlAllowMethods: ["GET", "POST", "OPTIONS"]
        accessControlAllowOriginList: ["*"]

  routers:
    labs-router:
      rule: "Host(\`$MAIN_DOMAIN\`)"
      service: apache-service
      entryPoints:
        - web
        - websecure

    vpns-router:
      rule: "Host(\`$VPN_DOMAIN\`)"
      service: vpn-api-service
      middlewares:
        - vpn-headers
      entryPoints:
        - web
        - websecure

    mqs-router:
      rule: "Host(\`$MQS_DOMAIN\`)"
      service: mqs-service
      entryPoints:
        - web
        - websecure

    code-server-router:
      rule: "HostRegexp(\`{subdomain:.+}.$CODE_DOMAIN\`)"
      service: code-server-service
      middlewares:
        - code-headers
      entryPoints:
        - web
        - websecure

    work-router:
      rule: "Host(\`$WORK_DOMAIN\`)"
      service: apache-service
      entryPoints:
        - web
        - websecure

  services:
    apache-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081"
    mqs-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081"
    vpn-api-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8082"
    code-server-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081"
EOF

# 4. Configure env.json
echo "[INFO] Configuring env.json..."
if [ ! -f "/var/www/env.json" ]; then
    echo "[INFO] /var/www/env.json not found! Generating default configuration..."
    cat <<EOF > /var/www/env.json
{
    "database": {
        "host": "127.0.0.1",
        "user": "root",
        "password": "",
        "dbname": "labs"
    },
    "app_cache": "/var/cache/labs",
    "domains": {
        "main": "labs.tomweb.fun",
        "vpns": "vpns.tomweb.fun",
        "mqs": "mqs.tomweb.fun"
    },
    "rabbitmq": {
        "host": "127.0.0.1",
        "port": 5672,
        "user": "admin",
        "password": "${MQ_PASS}"
    }
}
EOF
    chown www-data:www-data /var/www/env.json
fi

if [ -f "/var/www/env.json" ]; then
    sed -i "s/labs.tomweb.fun/$MAIN_DOMAIN/g" /var/www/env.json
    sed -i "s/vpns.tomweb.fun/$VPN_DOMAIN/g" /var/www/env.json
    sed -i "s/mqs.tomweb.fun/$MQS_DOMAIN/g" /var/www/env.json
    
    # Dynamically inject WireGuard Public Key if it exists
    if [ -f /etc/wireguard/publickey ]; then
        WG_PUBKEY=$(cat /etc/wireguard/publickey)
        echo "[INFO] Injecting WireGuard Public Key into env.json..."
        # Use jq to update/add the key
        jq --arg key "$WG_PUBKEY" '.wireguard_public_key = $key' /var/www/env.json > /var/www/env.json.tmp && \
        mv /var/www/env.json.tmp /var/www/env.json
        chown www-data:www-data /var/www/env.json
    fi

    # Dynamically inject WireGuard Endpoint & Mode into env.json
    if [ -n "$WG_ENDPOINT" ]; then
        echo "[INFO] Injecting WireGuard Endpoint ($WG_ENDPOINT) into env.json..."
        jq --arg ep "$WG_ENDPOINT" --arg mode "$WG_MODE" --arg port "$WG_PORT" \
           '.wireguard_endpoint = $ep | .wireguard_mode = $mode | .wireguard_endpoint_port = ($port | tonumber)' \
           /var/www/env.json > /var/www/env.json.tmp && \
        mv /var/www/env.json.tmp /var/www/env.json
        chown www-data:www-data /var/www/env.json
    fi
fi

# 5. Enable Apache Sites
echo "[INFO] Enabling Apache Sites..."
a2enmod rewrite proxy proxy_http proxy_wstunnel headers ssl
a2dissite 000-default.conf
a2ensite labs.conf mqs.conf wg-api.conf code.conf work.conf

# 6. Setup Directory Permissions & Log Rotation
echo "[INFO] Setting Directory Permissions..."
mkdir -p /var/log/labs && chown -R www-data:www-data /var/log/labs
mkdir -p /var/cache/labs && chown -R www-data:www-data /var/cache/labs
touch /var/log/labs_deploy.log && chown www-data:www-data /var/log/labs_deploy.log && chmod 664 /var/log/labs_deploy.log

# 7. Setup Labs Control Panel Links & Workers
if [ -f "/opt/labs-control-panel/labsctl.py" ]; then
    echo "[INFO] Linking Labs Control Panel..."
    chmod +x /opt/labs-control-panel/labsctl.py
    rm -f /usr/local/bin/labsctl
    ln -s /opt/labs-control-panel/labsctl.py /usr/local/bin/labsctl
fi

if [ -d "/opt/labs-control-panel/systemd/" ]; then
    echo "[INFO] Loading systemd units from Control Panel..."
    for service_file in /opt/labs-control-panel/systemd/*.service; do
        service_name=$(basename "$service_file")
        ln -sf "$service_file" "/etc/systemd/system/$service_name"
    done
    
    if [ -f "/opt/labs-control-panel/systemd/labs-worker@.service" ]; then
        ln -sf "/opt/labs-control-panel/systemd/labs-worker@.service" "/etc/systemd/system/labs-worker@.service"
        systemctl enable labs-worker@1 || true
    fi

    for service_file in /opt/labs-control-panel/systemd/*.service; do
        service_name=$(basename "$service_file")
        if [[ "$service_name" != *"@.service" ]]; then
            unit_name=$(basename "$service_file" .service)
            systemctl enable "$unit_name" || true
        fi
    done
fi
# 8. Setup AI Worker Service
echo "[INFO] Setting up AI Worker systemd service..."
cat <<EOF > /etc/systemd/system/ai-worker.service
[Unit]
Description=LearnAI Worker - AI Chat Processing
After=mongod.service rabbitmq-server.service network.target
Wants=mongod.service rabbitmq-server.service

[Service]
Type=simple
WorkingDirectory=/var/www/labs/worker
ExecStart=/usr/bin/python3 -u /var/www/labs/worker/ai_worker.py
Restart=always
RestartSec=5
StandardOutput=append:/var/www/labs/worker/ai_worker.log
StandardError=append:/var/www/labs/worker/ai_worker.log

[Install]
WantedBy=multi-user.target
EOF
systemctl enable ai-worker.service || true

# 9. Setup Native Stats Worker Service
echo "[INFO] Setting up Native Stats Worker systemd service..."
cat <<EOF > /etc/systemd/system/stats-worker.service
[Unit]
Description=Tom Labs Native Stats WebSocket Worker
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/labs/worker
ExecStartPre=/usr/bin/npm install
ExecStart=/usr/bin/node /var/www/labs/worker/stats-daemon.js
Restart=always
RestartSec=3
StandardOutput=append:/var/log/stats-worker.log
StandardError=append:/var/log/stats-worker.log

[Install]
WantedBy=multi-user.target
EOF
systemctl enable stats-worker.service || true

echo "[INFO] Handing over control to systemd!"
exec /lib/systemd/systemd

OUTER_EOF_ENTRYPOINT
    
    # Inject variables
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/tomweb.in/$MAIN_DOMAIN/g" entrypoint.sh
        sed -i '' "s/vpn.tomweb.in/$VPN_DOMAIN/g" entrypoint.sh
        sed -i '' "s/mq.tomweb.in/$MQS_DOMAIN/g" entrypoint.sh
        sed -i '' "s/tomweb.shop/$CODE_DOMAIN/g" entrypoint.sh
        sed -i '' "s/admin@example.com/$SSL_EMAIL/g" entrypoint.sh
    else
        sed -i "s/tomweb.in/$MAIN_DOMAIN/g" entrypoint.sh
        sed -i "s/vpn.tomweb.in/$VPN_DOMAIN/g" entrypoint.sh
        sed -i "s/mq.tomweb.in/$MQS_DOMAIN/g" entrypoint.sh
        sed -i "s/tomweb.shop/$CODE_DOMAIN/g" entrypoint.sh
        sed -i "s/admin@example.com/$SSL_EMAIL/g" entrypoint.sh
    fi

    log "Generating init-services.sh..."
    cat <<'OUTER_EOF_SETUP' > init-services.sh
#!/bin/bash
# /usr/local/bin/init-services.sh

# This script is executed by systemd after MongoDB and RabbitMQ start.

# Default values if not set
export MAIN_DOMAIN=${MAIN_DOMAIN:-tomweb.in}
export VPN_DOMAIN=${VPN_DOMAIN:-vpn.tomweb.in}
export MQS_DOMAIN=${MQS_DOMAIN:-mq.tomweb.in}

echo "[INFO] Running post-boot container setup..."

# Docker Socket Symlink (Ensures tools like labsctl find the API after systemd boot)
if [ -S /var/docker.sock ]; then
    echo "[INFO] Creating Docker socket symlink..."
    ln -sf /var/docker.sock /var/run/docker.sock
fi

# 1. RabbitMQ Configuration
if systemctl is-active --quiet rabbitmq-server; then
    echo "[INFO] Waiting for RabbitMQ to be fully ready..."
    until rabbitmqctl status >/dev/null 2>&1; do
        sleep 2
    done
    rabbitmq-plugins enable rabbitmq_management rabbitmq_stomp rabbitmq_web_stomp || true
    # Create RabbitMQ Admin User and verify it exists
    until rabbitmqctl list_users | grep -qw "^admin"; do
        echo "[INFO] Creating RabbitMQ Admin User..."
        rabbitmqctl add_user admin "${MQ_PASS}" || true
        sleep 2
    done

    # Set and verify permissions for the Admin User
    until rabbitmqctl list_permissions -p / | grep -qw "^admin"; do
        echo "[INFO] Setting RabbitMQ Admin Permissions..."
        rabbitmqctl set_user_tags admin administrator || true
        rabbitmqctl set_permissions -p / admin ".*" ".*" ".*" || true
        sleep 2
    done
    echo "[INFO] RabbitMQ Admin User verified and permissions applied."
fi

# 1.5. MySQL Configuration
if systemctl is-active --quiet mysql; then
    echo "[INFO] Configuring MySQL Root password..."
    # Set local root password
    mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'tomlabs_root_secret'; FLUSH PRIVILEGES;" 2>/dev/null || true
    # Create wildcard root user for access over Docker network
    mysql -u root -ptomlabs_root_secret -e "CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY 'tomlabs_root_secret'; GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;" 2>/dev/null || true
fi

# 1.6. MariaDB Configuration (Port 3307)
if systemctl is-active --quiet mariadb; then
    echo "[INFO] Configuring MariaDB Root password..."
    mysql --port=3307 -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'tomlabs_root_secret'; FLUSH PRIVILEGES;" 2>/dev/null || true
    mysql --port=3307 -u root -ptomlabs_root_secret -e "CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY 'tomlabs_root_secret'; GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;" 2>/dev/null || true
fi

# 1.7. PostgreSQL Configuration
if systemctl is-active --quiet postgresql; then
    echo "[INFO] Configuring PostgreSQL Admin password..."
    sudo -u postgres psql -c "CREATE ROLE tomlabs_admin WITH LOGIN SUPERUSER PASSWORD 'tomlabs_root_secret';" 2>/dev/null || true
    sudo -u postgres psql -c "ALTER ROLE tomlabs_admin WITH PASSWORD 'tomlabs_root_secret';" 2>/dev/null || true
fi

# 1.8. Redis Configuration
if systemctl is-active --quiet redis-server; then
    echo "[INFO] Configuring Redis Admin password..."
    redis-cli ACL SETUSER default on >tomlabs_redis_secret ~* +@all 2>/dev/null || true
    redis-cli ACL SAVE 2>/dev/null || true
fi

# 2. Build dependencies (if directories exist)
if [ -d "/var/www/labs/htdocs" ]; then
    echo "[INFO] Installing Composer dependencies for labs..."
    cd /var/www/labs/htdocs && composer install --no-interaction --optimize-autoloader
fi
if [ -d "/var/www/vpn-api" ]; then
    echo "[INFO] Installing Composer dependencies for vpn-api..."
    cd /var/www/vpn-api && composer install --no-interaction --optimize-autoloader
fi
if [ -d "/var/www/labs/workspace/grunt" ]; then
    echo "[INFO] Installing Node attributes for grunt..."
    cd /var/www/labs/workspace/grunt && npm install && grunt build
fi

# 3. Python Requirements
if [ -f "/opt/labs-control-panel/requirements.txt" ]; then
    echo "[INFO] Installing Python requirements..."
    pip3 install -r /opt/labs-control-panel/requirements.txt --break-system-packages
fi

# 4. VPN Network Sync
if [ -f "/var/www/vpn-api/syncnetwork.php" ]; then
    echo "[INFO] Initializing VPN Network Pool..."
    php /var/www/vpn-api/syncnetwork.php wg0 || echo "[WARN] Failed to sync network."
    if [ -f "/var/www/labs/workspace/tools/populate_ips.php" ]; then
        php /var/www/labs/workspace/tools/populate_ips.php || echo "[WARN] Failed to populate IPs."
    fi
fi

# 5. WireGuard Start & Health Check
if [ -f "/etc/wireguard/wg0.conf" ]; then
    echo "[INFO] Starting WireGuard (wg0)..."
    
    # Ensure IP forwarding is enabled
    sysctl -w net.ipv4.ip_forward=1 > /dev/null 2>&1
    
    # Bring up WireGuard
    systemctl start wg-quick@wg0 || true
    sleep 2
    
    # Health check - if wg0 isn't working, bounce it
    if ! wg show wg0 > /dev/null 2>&1; then
        echo "[WARN] WireGuard failed to start, bouncing interface..."
        wg-quick down wg0 2>/dev/null || true
        sleep 1
        wg-quick up wg0 || true
        sleep 2
    fi
    
    if wg show wg0 > /dev/null 2>&1; then
        echo "[INFO] WireGuard is running."
        wg show wg0 | head -4
    else
        echo "[ERROR] WireGuard failed to start after retry."
    fi
fi

# 6. Re-apply Host Routing for Existing Lab Peers
echo "[INFO] Recovering host routes for deployed labs..."

# Detect the bridge interface from config or env.json
DOCKER_NETWORK=$(jq -r '.docker_network_name // empty' /opt/labs-control-panel/config.json 2>/dev/null)
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK=$(jq -r '.docker_network_name // empty' /var/www/env.json 2>/dev/null)
fi
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK=$(docker network ls --format '{{.Name}}' | grep -E "(dev_lab_frontend|Dev_lab)" | head -n 1)
fi
if [ -z "$DOCKER_NETWORK" ] || [ "$DOCKER_NETWORK" = "null" ]; then
    DOCKER_NETWORK="dev_lab_frontend"
fi

BRIDGE_ID=$(docker network inspect "$DOCKER_NETWORK" -f '{{.Id}}' 2>/dev/null | cut -c1-12)
if [ -n "$BRIDGE_ID" ]; then
    BRIDGE_IF="br-${BRIDGE_ID}"
    
    TUNNEL_PREFIX=$(jq -r '.tunnel_ip // empty' /opt/labs-control-panel/config.json 2>/dev/null)
    if [ -z "$TUNNEL_PREFIX" ] || [ "$TUNNEL_PREFIX" = "null" ]; then
        TUNNEL_PREFIX=$(jq -r '.tunnel_ip // empty' /var/www/env.json 2>/dev/null)
    fi
    if [ -z "$TUNNEL_PREFIX" ] || [ "$TUNNEL_PREFIX" = "null" ]; then
        echo "FATAL: tunnel_ip not set in config.json or env.json"
        exit 1
    fi
    TUNNEL_SUBNET="${TUNNEL_PREFIX}0/16"

    # Ensure forwarding rules between wg0 and Docker bridge
    iptables -C FORWARD -i wg0 -o wg0 -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT 2>/dev/null
    iptables -C FORWARD -i wg0 -o "$BRIDGE_IF" -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i wg0 -o "$BRIDGE_IF" -j ACCEPT 2>/dev/null
    iptables -C FORWARD -i "$BRIDGE_IF" -o wg0 -j ACCEPT 2>/dev/null || \
        iptables -A FORWARD -i "$BRIDGE_IF" -o wg0 -j ACCEPT 2>/dev/null
    iptables -t nat -C POSTROUTING -s "$TUNNEL_SUBNET" -o eth0 -j MASQUERADE 2>/dev/null || \
        iptables -t nat -A POSTROUTING -s "$TUNNEL_SUBNET" -o eth0 -j MASQUERADE 2>/dev/null
    
    # For each WireGuard peer, re-create the host route to its Docker container
    # Peer AllowedIPs maps to Docker IP
    if wg show wg0 allowed-ips 2>/dev/null | grep -q '/32'; then
        wg show wg0 allowed-ips | while read -r _pubkey allowed_ip_cidr; do
            # Extract just the IP (strip /32)
            tunnel_ip=$(echo "$allowed_ip_cidr" | sed 's|/32||')
            if [ -n "$tunnel_ip" ] && [ "$tunnel_ip" != "${TUNNEL_PREFIX}1" ]; then
                # Derive Docker IP: last octet of tunnel IP -> {DOCKER_IP_PREFIX}{last_octet}
                DOCKER_IP_PREFIX=$(jq -r '.docker_ip // empty' /opt/labs-control-panel/config.json 2>/dev/null)
                if [ -z "$DOCKER_IP_PREFIX" ] || [ "$DOCKER_IP_PREFIX" = "null" ]; then
                    DOCKER_IP_PREFIX=$(jq -r '.docker_ip // empty' /var/www/env.json 2>/dev/null)
                fi
                if [ -z "$DOCKER_IP_PREFIX" ] || [ "$DOCKER_IP_PREFIX" = "null" ]; then
                    DOCKER_IP_PREFIX="172.19.0."
                fi
                last_octet=$(echo "$tunnel_ip" | awk -F. '{print $4}')
                docker_ip="${DOCKER_IP_PREFIX}${last_octet}"
                
                # Check if the container with this Docker IP exists and is running
                if docker network inspect "$DOCKER_NETWORK" --format '{{range .Containers}}{{.IPv4Address}} {{end}}' 2>/dev/null | grep -q "$docker_ip"; then
                    ip route del "$tunnel_ip/32" 2>/dev/null || true
                    ip route add "$tunnel_ip/32" via "$docker_ip" dev "$BRIDGE_IF" 2>/dev/null || true
                    echo "[INFO] Route restored: $tunnel_ip -> $docker_ip via $BRIDGE_IF"
                else
                    echo "[INFO] No container at $docker_ip for peer $tunnel_ip, skipping route."
                fi
            fi
        done
    fi
else
    echo "[WARN] $DOCKER_NETWORK bridge not found. Lab routes will be created at deploy time."
fi

# 7. Reload Traefik

echo "[INFO] Setup complete!"

OUTER_EOF_SETUP
    
    # Inject variables
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/tomweb.in/$MAIN_DOMAIN/g" init-services.sh
        sed -i '' "s/vpn.tomweb.in/$VPN_DOMAIN/g" init-services.sh
        sed -i '' "s/mq.tomweb.in/$MQS_DOMAIN/g" init-services.sh
    else
        sed -i "s/tomweb.in/$MAIN_DOMAIN/g" init-services.sh
        sed -i "s/vpn.tomweb.in/$VPN_DOMAIN/g" init-services.sh
        sed -i "s/mq.tomweb.in/$MQS_DOMAIN/g" init-services.sh
    fi

    log "Generating .env for Docker Compose..."
    cat <<OUTER_EOF_ENV > .env
MAIN_DOMAIN=$MAIN_DOMAIN
VPN_DOMAIN=$VPN_DOMAIN
MQS_DOMAIN=$MQS_DOMAIN
CODE_DOMAIN=$CODE_DOMAIN
WORK_DOMAIN=$WORK_DOMAIN
SSL_EMAIL=$SSL_EMAIL
DB_PASS=$DB_PASS
MQ_PASS=$MQ_PASS
TUNNEL_TOKEN=
OUTER_EOF_ENV

    log "Generating docker-compose.yml..."
    cat <<'OUTER_EOF_COMPOSE' > docker-compose.yml
services:
  vps_dev:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: TomCloudLab
    privileged: true
    environment:
      - MAIN_DOMAIN=${MAIN_DOMAIN}
      - VPN_DOMAIN=${VPN_DOMAIN}
      - MQS_DOMAIN=${MQS_DOMAIN}
      - CODE_DOMAIN=${CODE_DOMAIN}
      - WORK_DOMAIN=${WORK_DOMAIN}
      - SSL_EMAIL=${SSL_EMAIL}
      - DB_PASS=${DB_PASS}
      - MQ_PASS=${MQ_PASS}
      - DOCKER_HOST=unix:///var/docker.sock
    extra_hosts:
      - "${MAIN_DOMAIN}:127.0.0.1"
      - "${VPN_DOMAIN}:127.0.0.1"
      - "${MQS_DOMAIN}:127.0.0.1"
      - "${CODE_DOMAIN}:127.0.0.1"
      - "${WORK_DOMAIN}:127.0.0.1"
      - "mysql.tomweb.in:127.0.0.1"
    volumes:
      - /sys/fs/cgroup:/sys/fs/cgroup:rw
      - .:/var/www
      - /var/run/docker.sock:/var/docker.sock
      - ./opt/labs-control-panel:/opt/labs-control-panel
      # ⬇️ NEW PERSISTENT VOLUMES ⬇️
      - ./rabbitmq-data:/var/lib/rabbitmq
      - ./wireguard-conf:/etc/wireguard
      - ./apache-logs:/var/log/apache2
      - ./traefik-conf:/etc/traefik
    networks:
      tomcloudlab_net:
        aliases:
          - mysql.tomweb.in
          - adminer.tomweb.in
          - mongo.tomweb.in
          - code.tomweb.in
    ports:
      - "8080:80"
      - "8443:443"
      - "9081:8081"
      - "9082:8082"
      - "5673:5672"
      - "15673:15672"
      - "15674:15674"
      - "51821:51820/udp"
    stop_signal: SIGRTMIN+3

  cloudflared:
    image: cloudflare/cloudflared:latest
    container_name: TomCloudLab_cloudflare
    restart: unless-stopped
    command: tunnel --no-autoupdate run
    environment:
      - TUNNEL_TOKEN=${TUNNEL_TOKEN}
    networks:
      - tomcloudlab_net

  mongodb:
    image: mongo:8.0
    container_name: TomCloudLab_mongodb
    environment:
      MONGO_INITDB_ROOT_USERNAME: admin
      MONGO_INITDB_ROOT_PASSWORD: ${DB_PASS}
    volumes:
      - ./mongo-data:/data/db
    networks:
      - tomcloudlab_net
    ports:
      - "27018:27017"
    restart: always



  # gitlab:
  #   image: 'gitlab/gitlab-ce:latest'
  #   container_name: TomCloudLab_gitlab
  #   restart: always
  #   hostname: 'git.tomweb.in'
  #   environment:
  #     GITLAB_OMNIBUS_CONFIG: |
  #       external_url 'https://git.tomweb.in'
  #       nginx['listen_port'] = 80
  #       nginx['listen_https'] = false
  #   volumes:
  #     - './gitlab_config:/etc/gitlab'
  #     - './gitlab_logs:/var/log/gitlab'
  #     - './gitlab_data:/var/opt/gitlab'
  #   networks:
  #     - tomcloudlab_net

networks:
  tomcloudlab_net:
    name: TomCloudLab
    ipam:
      config:
        - subnet: 10.20.144.0/20
          gateway: 10.20.144.1
    driver: bridge

OUTER_EOF_COMPOSE

    # Fix ownership of all extracted/generated files so the user can edit them in their IDE
    if [ -n "$SUDO_USER" ]; then
        log "Fixing file ownership for user $SUDO_USER..."
        chown -R "$SUDO_USER" ./labs ./vpn-api ./env.json ./session.json ./opt ./traefik-conf ./wireguard-conf ./rabbitmq-data ./mongo-data ./apache-logs docker-compose.yml Dockerfile entrypoint.sh init-services.sh 2>/dev/null || true
    fi

    log "Generating config.json..."
    DOCKER_NETWORK_CONF=$(jq -r '.docker_network_name // empty' ./env.json 2>/dev/null)
    [ -z "$DOCKER_NETWORK_CONF" ] && DOCKER_NETWORK_CONF="dev_lab_frontend"
    DOCKER_IP_CONF=$(jq -r '.docker_ip // empty' ./env.json 2>/dev/null)
    [ -z "$DOCKER_IP_CONF" ] && DOCKER_IP_CONF="172.19.0."
    TUNNEL_IP_CONF=$(jq -r '.tunnel_ip // empty' ./env.json 2>/dev/null)
    [ -z "$TUNNEL_IP_CONF" ] && TUNNEL_IP_CONF="172.31.0."

    cat <<OUTER_EOF_CONFIG > ./opt/labs-control-panel/config.json
{
  "labctl_path": "/usr/local/bin/labsctl",
  "templates_dir": "/opt/labs-control-panel/lab-templates",
  "storage_path": "/var/tomlabs/storage/{user}",
  "app_log": "/var/log/labs/labctl.log",
  "config_path": "/etc/labs-control-panel/config.json",
  "docker_ip": "$DOCKER_IP_CONF",
  "tunnel_ip": "$TUNNEL_IP_CONF",
  "docker_network_name": "$DOCKER_NETWORK_CONF",
  "orchestrator_container": "$DOCKER_NETWORK_CONF",
  "docker_build": "docker build -t {image_tag} {path}",
  "docker_run": "docker run --detach --name {lab_name} --memory='{memory}' --cpus='{cpus}' --network {network_name} --ip {ip} --add-host {vpn_domain}:{tunnel_gw} --cap-add=NET_ADMIN --device=/dev/net/tun --sysctl net.ipv6.conf.all.disable_ipv6=1 -v {storage}:{mount_target} --hostname {host_name} {image}",
  "docker_stop_rm": "docker stop {lab_name} && sudo docker rm -f {lab_name}",
  "docker_ps": "docker ps --format '{{.Names}}'",
  "docker_exec": "docker exec -d {lab_name} {script}",
  "docker_drop_shell": "docker exec -it {lab_name} {shell}",
  "traefik_conf_dir": "/etc/traefik/dynamic_conf",
  "acme_json_path": "/etc/traefik/acme.json",
  "wg_interface": "wg0",
  "log_format": "json",
  "log_file": "/var/log/labs/labctl.log"
}
OUTER_EOF_CONFIG

    log "Bringing up Docker Container..."
    docker compose up -d --build

    log "Ensuring composer dependencies are installed..."
    docker exec TomCloudLab bash -c "cd /var/www/labs/htdocs && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader" || true
    docker exec TomCloudLab bash -c "cd /var/www/vpn-api && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --optimize-autoloader" || true

    echo -e ""
    echo -e "${GREEN}==================================================${NC}"
    echo -e "${GREEN} Docker Local Setup Complete! ${NC}"
    echo -e "${GREEN}==================================================${NC}"
    echo -e ""
    echo -e "${YELLOW}==================================================${NC}"
    echo -e "${YELLOW} REQUIRED NEXT STEPS BEFORE USING TOM LABS: ${NC}"
    echo -e "${YELLOW}==================================================${NC}"
    echo -e "${YELLOW}1. Installation Directory:${NC}"
    echo -e "   Your files are located here. Run: ${GREEN}cd $PWD${NC}"
    echo -e ""
    echo -e "${YELLOW}2. Configure Environment:${NC}"
    echo -e "   Edit 'env.json' and 'session.json' with your API keys and credentials."
    echo -e ""
    echo -e "${YELLOW}3. Check Tunnel:${NC}"
    echo -e "   Review 'docker-compose.yml' and ensure your Cloudflare Tunnel token is configured."
    echo -e ""
    echo -e "${YELLOW}4. Apply Changes:${NC}"
    echo -e "   Run ${GREEN}docker compose down && docker compose up -d${NC} to apply your configuration changes."
    echo -e ""
    echo -e "${GREEN}Verify URLs (Once DNS & Tunnel are configured):${NC}"
    echo -e "  Main App:      https://$MAIN_DOMAIN"
    echo -e "  VPN API:       https://$VPN_DOMAIN"
    echo -e "  RabbitMQ:      https://$MQS_DOMAIN"
    echo -e "  Code Server:   https://$CODE_DOMAIN"
    echo -e ""
    exit 0
fi

# ==========================================
# VPS MODE (or running inside Docker)
# ==========================================

# 1. System Updates & Dependencies
if command -v apt &> /dev/null; then
    log "Debian/Ubuntu detected ('apt' found). Updating system and installing dependencies..."
    export DEBIAN_FRONTEND=noninteractive
    apt update && apt upgrade -y
    apt install -y software-properties-common

# Add PHP PPA for latest versions (fixes outdated mongodb extension)
log "Adding Ondrej PHP PPA..."
add-apt-repository -y ppa:ondrej/php
apt update
    
# Add Docker Official Repo
log "Adding Docker Repository..."
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
apt update

# Add MongoDB 8.0 Repo (Ubuntu 24.04 / Noble)
log "Adding MongoDB 8.0 Repository..."
curl -fsSL https://www.mongodb.org/static/pgp/server-8.0.asc | sudo gpg -o /usr/share/keyrings/mongodb-server-8.0.gpg --dearmor --yes
echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu noble/mongodb-org/8.0 multiverse" | sudo tee /etc/apt/sources.list.d/mongodb-org-8.0.list
apt update

apt install -y \
    git curl unzip \
    apache2 libapache2-mod-php8.4 \
    php8.4 php8.4-cli php8.4-common php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd php8.4-mongodb php8.4-amqp \
    rabbitmq-server \
    wireguard wireguard-tools \
    python3 python3-pip \
    docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Configure Docker for Docker-in-Docker compatability
log "Configuring Docker daemon..."
mkdir -p /etc/docker
cat <<EOF > /etc/docker/daemon.json
{
  "storage-driver": "vfs",
  "features": {
    "buildkit": false
  }
}
EOF
systemctl restart docker
# WireGuard Setup
if [ ! -f "/etc/wireguard/wg0.conf" ]; then
    log "Configuring WireGuard (wg0)..."
    mkdir -p /etc/wireguard
    chmod 700 /etc/wireguard
    
    PRIVATE_KEY=$(wg genkey)
    PUBLIC_KEY=$(echo "$PRIVATE_KEY" | wg pubkey)
    
    echo "$PRIVATE_KEY" > /etc/wireguard/privatekey
    echo "$PUBLIC_KEY" > /etc/wireguard/publickey
    chmod 600 /etc/wireguard/privatekey

    # Detect Docker bridge interface for tom_net
    DOCKER_BRIDGE=$(docker network inspect tom_net --format '{{.Id}}' 2>/dev/null | cut -c1-12 || true)
    if [ -n "$DOCKER_BRIDGE" ]; then
        BRIDGE_IF="br-${DOCKER_BRIDGE}"
        log "Detected Docker bridge: $BRIDGE_IF"
    else
        BRIDGE_IF=""
        warn "tom_net not found. Docker bridge routing will not be configured."
    fi

    cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
Address = 172.30.0.1/16
SaveConfig = true
PostUp = iptables -A FORWARD -i wg0 -o eth0 -j ACCEPT
PostUp = iptables -t nat -I POSTROUTING -o eth0 -j MASQUERADE
${BRIDGE_IF:+PostUp = iptables -A FORWARD -i wg0 -o $BRIDGE_IF -j ACCEPT}
${BRIDGE_IF:+PostUp = iptables -t nat -I POSTROUTING -o $BRIDGE_IF -j MASQUERADE}
PreDown = iptables -D FORWARD -i wg0 -o eth0 -j ACCEPT
PreDown = iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE
${BRIDGE_IF:+PreDown = iptables -D FORWARD -i wg0 -o $BRIDGE_IF -j ACCEPT}
${BRIDGE_IF:+PreDown = iptables -t nat -D POSTROUTING -o $BRIDGE_IF -j MASQUERADE}
ListenPort = 51820
PrivateKey = $PRIVATE_KEY
EOF
    # Remove empty lines from conditional bridge expansion
    sed -i '/^$/d' /etc/wireguard/wg0.conf
    chmod 600 /etc/wireguard/wg0.conf
    systemctl enable wg-quick@wg0
    systemctl start wg-quick@wg0
    log "WireGuard configured with Public Key: $PUBLIC_KEY"
else
    log "WireGuard configuration already exists."
fi

apt install -y \
    ufw fail2ban \
    nmap

# Install Traefik
if ! command -v traefik &> /dev/null; then
    log "Installing Traefik..."
    # Download latest release (adjust version if needed)
    wget https://github.com/traefik/traefik/releases/download/v2.10.6/traefik_v2.10.6_linux_amd64.tar.gz
    tar -zxvf traefik_v2.10.6_linux_amd64.tar.gz
    mv traefik /usr/local/bin/
    chmod +x /usr/local/bin/traefik
    rm traefik_v2.10.6_linux_amd64.tar.gz
fi

# Install MongoDB
if ! command -v mongod &> /dev/null; then
    log "Installing MongoDB 8.0..."
    apt install -y mongodb-org
else
    log "MongoDB is already installed."
fi

# Ensure MongoDB is started before configuration
log "Starting MongoDB..."
systemctl start mongod || true
sleep 3

# Configure MongoDB Auth & User (Always run to ensure correct state)
log "Configuring MongoDB Auth..."
AUTH_CHANGED=0
if grep -q "#security:" /etc/mongod.conf; then
    sed -i 's/#security:/security:\n  authorization: enabled/' /etc/mongod.conf
    AUTH_CHANGED=1
elif ! grep -q "authorization:.*enabled" /etc/mongod.conf; then
    echo -e "security:\n  authorization: enabled" >> /etc/mongod.conf
    AUTH_CHANGED=1
fi

# Enable Remote Access (bindIp: 0.0.0.0)
if grep -q "bindIp: 127.0.0.1" /etc/mongod.conf; then
    log "Enabling MongoDB Remote Access (0.0.0.0)..."
    sed -i 's/bindIp: 127.0.0.1/bindIp: 0.0.0.0/' /etc/mongod.conf
    AUTH_CHANGED=1
fi

if [ "$AUTH_CHANGED" -eq 1 ]; then
    log "Restarting MongoDB to apply auth changes..."
    systemctl restart mongod
    sleep 5
fi

# Create Admin User (idempotent check)
log "Ensuring MongoDB Admin User exists..."
# Attempt to create user without auth first (localhost exception), then with auth if it fails
mongosh admin --eval 'try { db.createUser({user: "admin", pwd: "$DB_PASS", roles: [{ role: "root", db: "admin" }]}) } catch(e) { print("Creating without auth failed, trying with auth..."); try { db.getSiblingDB("admin").auth("admin", "$DB_PASS"); db.createUser({user: "admin", pwd: "$DB_PASS", roles: [{ role: "root", db: "admin" }]}) } catch(e2) { print("User likely already exists or auth failed: " + e2) } }'

# Install Node.js
if ! command -v node &> /dev/null; then
    log "Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt install -y nodejs
fi

# Install Composer
if ! command -v composer &> /dev/null; then
    log "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install Grunt CLI
log "Installing Grunt CLI..."
npm install -g grunt-cli || true

else
    warn "Unsupported OS or 'apt' not found. Skipping Linux system package installation."
    warn "Ensure dependencies (Docker, Apache, PHP, MongoDB, Node, etc.) are installed manually."
fi

# 2. Application Setup (Git Clone)
if [ ! -d "/var/www/labs" ] || [ -z "$(ls -A /var/www/labs 2>/dev/null)" ]; then
    log "Setting up application from Git..."

    # Create destinations
    mkdir -p /var/www/labs
    mkdir -p /var/www/vpn-api
    mkdir -p /opt/labs-control-panel
    
    # Copy from local repo directory (since install.sh already cloned it)
    LOCAL_REPO="$(cd "$(dirname "$0")" && pwd)"
    run_with_progress "cp -R \"$LOCAL_REPO/labs/\"* /var/www/labs/ 2>/dev/null || true" "Copying Application Files"
    run_with_progress "cp -R \"$LOCAL_REPO/vpn-api/\"* /var/www/vpn-api/ 2>/dev/null || true" "Copying VPN API Files"
    run_with_progress "cp -R \"$LOCAL_REPO/opt/labs-control-panel/\"* /opt/labs-control-panel/ 2>/dev/null || true" "Copying Control Panel Files"


    # Copy sample.json as env.json template
    if [ -f "$LOCAL_REPO/sample.json" ]; then
        cp "$LOCAL_REPO/sample.json" /var/www/env.json
    elif [ -f "$LOCAL_REPO/labs/sample.json" ]; then
        cp "$LOCAL_REPO/labs/sample.json" /var/www/env.json
    else
        warn "sample.json not found in repo. Please configure /var/www/env.json manually."
    fi

    if [ -f /var/www/env.json ]; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s/DB_PASS_PLACEHOLDER/$DB_PASS/g" /var/www/env.json
            sed -i '' "s/MQ_PASS_PLACEHOLDER/$MQ_PASS/g" /var/www/env.json
            sed -i '' "s/DB_HOST_PLACEHOLDER/127.0.0.1/g" /var/www/env.json
            sed -i '' "s|VPN_URL_PLACEHOLDER|https://$VPN_DOMAIN/api|g" /var/www/env.json
        else
            sed -i "s/DB_PASS_PLACEHOLDER/$DB_PASS/g" /var/www/env.json
            sed -i "s/MQ_PASS_PLACEHOLDER/$MQ_PASS/g" /var/www/env.json
            sed -i "s/DB_HOST_PLACEHOLDER/127.0.0.1/g" /var/www/env.json
            sed -i "s|VPN_URL_PLACEHOLDER|https://$VPN_DOMAIN/api|g" /var/www/env.json
        fi
    fi
    fi
else
    log "Auto VPS mode: Repositories are already mounted."
    # Ensure env.json exists since it was mapped
    if [ ! -f "/var/www/env.json" ]; then
        if [ -f "/var/www/labs/sample.json" ]; then
            cp "/var/www/labs/sample.json" "/var/www/env.json"
        fi
    fi
fi

# 3. GENERATE CONFIGURATION FILES (Self-Contained)
if command -v apt &> /dev/null; then
log "Generating Configuration Files..."

# Apache Ports
cat <<EOF > /etc/apache2/ports.conf
# /etc/apache2/ports.conf
Listen 8081
Listen 8082
<IfModule ssl_module>
    Listen 4431
</IfModule>
EOF

# Code Server Map
cat <<EOF > /etc/apache2/code_server_map.txt
# Placeholder - Update with real mappings
EOF

# Apache Sites
# labs.conf
cat <<EOF > /etc/apache2/sites-available/labs.conf
<VirtualHost *:8081>
    ServerAdmin $SSL_EMAIL
    DocumentRoot "/var/www/labs/htdocs"
    ServerName $MAIN_DOMAIN

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory "/var/www/labs">
            Options -Indexes +FollowSymLinks -ExecCGI +Includes
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
EOF

# mqs.conf
cat <<EOF > /etc/apache2/sites-available/mqs.conf
<VirtualHost *:8081>
    ServerName $MQS_DOMAIN

    ProxyRequests Off
    ProxyPreserveHost On

    # 1. Handle the Native WebSocket for Overview Stats
    ProxyPass /stats-ws ws://127.0.0.1:8085/
    ProxyPassReverse /stats-ws ws://127.0.0.1:8085/

    # 1.5 Handle the STOMP WebSocket for the Overview Stats
    ProxyPass /ws ws://127.0.0.1:15674/ws
    ProxyPassReverse /ws ws://127.0.0.1:15674/ws

    # 2. Handle standard RabbitMQ Management traffic
    ProxyPass / http://127.0.0.1:15672/
    ProxyPassReverse / http://127.0.0.1:15672/

    Header set Sec-WebSocket-Protocol "v10.stomp, v11.stomp, v12.stomp"
</VirtualHost>
EOF

# wg-api.conf
cat <<EOF > /etc/apache2/sites-available/wg-api.conf
<VirtualHost *:8082>
    ServerAdmin $SSL_EMAIL
    DocumentRoot "/var/www/vpn-api"
    ServerName $VPN_DOMAIN

    ErrorLog \${APACHE_LOG_DIR}/vpn_error.log
    CustomLog \${APACHE_LOG_DIR}/vpn_access.log combined

    <Directory "/var/www/vpn-api">
            Options Indexes FollowSymLinks ExecCGI Includes
            AllowOverride All
            Require all granted
    </Directory>
</VirtualHost>
EOF

# code.conf (For code-server logic via Apache)
cat <<EOF > /etc/apache2/sites-available/code.conf
RewriteMap code_map "txt:/etc/apache2/code_server_map.txt"
ProxyTimeout 600
ProxyBadHeader Ignore

# Note: This listens on 8081/HTTP, proxied by Traefik
<VirtualHost *:8081>
    ServerName $CODE_DOMAIN
    ServerAlias *.$CODE_DOMAIN
    
    # Logic adapted for non-SSL Apache (Traefik does SSL)
    RewriteEngine On

    # 1. Map subdomain hash -> IP
    RewriteCond %{HTTP_HOST} ^([a-z0-9]+)\.$CODE_DOMAIN$ [NC]
    RewriteRule ^ - [E=HASH:%1]
    RewriteCond \${code_map:%{ENV:HASH}} ^(.+)$
    RewriteRule ^ - [E=TARGET_IP:%1]

    # 2. WebSocket requests
    RewriteCond %{ENV:TARGET_IP} .
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/(.*)$ ws://%{ENV:TARGET_IP}:8080/\$1 [P,L]

    # 3. Normal HTTP requests
    RewriteCond %{ENV:TARGET_IP} .
    RewriteRule ^/(.*)$ http://%{ENV:TARGET_IP}:8080/\$1 [P,L]

    ProxyPreserveHost On
    ProxyRequests Off
    RequestHeader set X-Forwarded-Proto "https"

    # 4. Fallback 404 when no mapping
    RewriteCond %{ENV:TARGET_IP} ^$
    RewriteRule ^ - [L,R=404]
</VirtualHost>
EOF


# Traefik Config
mkdir -p /etc/traefik/dynamic_conf

# traefik.yml
cat <<EOF > /etc/traefik/traefik.yml
entryPoints:
  web:
    address: ":80"
  websecure:
    address: ":443"

providers:
  file:
    directory: "/etc/traefik/dynamic_conf"
    watch: true
EOF

# dynamic_conf.yml
cat <<EOF > /etc/traefik/dynamic_conf/dynamic_conf.yml
http:
  middlewares:
    code-headers:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"
    vpn-headers:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"
        accessControlAllowMethods: ["GET", "POST", "OPTIONS"]
        accessControlAllowOriginList: ["*"]

  routers:
    labs-router:
      rule: "Host(\`$MAIN_DOMAIN\`)"
      service: apache-service
      entryPoints:
        - web

    vpns-router:
      rule: "Host(\`$VPN_DOMAIN\`)"
      service: vpn-api-service
      middlewares:
        - vpn-headers
      entryPoints:
        - web

    mqs-router:
      rule: "Host(\`$MQS_DOMAIN\`)"
      service: mqs-service
      entryPoints:
        - web

    code-server-router:
      rule: "HostRegexp(\`{subdomain:.+}.$CODE_DOMAIN\`)"
      service: code-server-service
      middlewares:
        - code-headers
      entryPoints:
        - web

  services:
    apache-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081"
    mqs-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081"
    vpn-api-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8082"
    code-server-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8081" 
EOF

# Setup acme.json permission
touch /etc/traefik/acme.json
chmod 600 /etc/traefik/acme.json

# Traefik Service
cat <<EOF > /etc/systemd/system/traefik.service
[Unit]
Description=Traefik Edge Router
Documentation=https://doc.traefik.io/traefik/
After=network-online.target
Wants=network-online.target systemd-networkd-wait-online.service

[Service]
Restart=on-failure
ExecStart=/usr/local/bin/traefik --configFile=/etc/traefik/traefik.yml
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF

log "Reloading systemd and enabling Traefik..."
systemctl daemon-reload
systemctl enable traefik

else
    log "Skipping Apache/Traefik config generation on non-Ubuntu OS."
fi

# 4. Config Domain Replacement (env.json)
log "Configuring env.json..."
sed -i "s/labs.tomweb.fun/$MAIN_DOMAIN/g" /var/www/env.json
sed -i "s/vpns.tomweb.fun/$VPN_DOMAIN/g" /var/www/env.json
sed -i "s/mqs.tomweb.fun/$MQS_DOMAIN/g" /var/www/env.json

# 5. Enable Apache Configs
if command -v apt &> /dev/null; then
log "Enabling Apache Configs..."
# Ensure correct PHP version (8.4) is used
a2dismod php8.3 || true
a2enmod php8.4 || true

a2enmod rewrite proxy proxy_http proxy_wstunnel headers ssl
a2dissite 000-default.conf
a2ensite labs.conf mqs.conf wg-api.conf code.conf

# 6. Permissions & Final Setup
chown -R www-data:www-data /var/www/env.json
mkdir -p /var/log/labs && chown -R www-data:www-data /var/log/labs
mkdir -p /var/cache/labs && chown -R www-data:www-data /var/cache/labs
touch /var/log/labs_deploy.log && chown www-data:www-data /var/log/labs_deploy.log && chmod 664 /var/log/labs_deploy.log

# Log Rotation
log "Configuring log rotation..."
cat <<EOF > /etc/logrotate.d/labs-deploy
/var/log/labs_deploy.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 664 www-data www-data
}
EOF

# Create Sudoers for www-data
log "Configuring sudoers for www-data..."
cat <<EOF > /etc/sudoers.d/labs-www-data
www-data ALL=(ALL) NOPASSWD: /usr/bin/python3 /opt/labs-control-panel/labsctl.py *
www-data ALL=(ALL) NOPASSWD: /usr/bin/python3 /opt/labs-control-panel/labsctl.py
www-data ALL=(ALL) NOPASSWD: /usr/bin/docker
www-data ALL=(ALL) NOPASSWD: /usr/bin/ip
www-data ALL=(ALL) NOPASSWD: /usr/sbin/iptables
www-data ALL=(ALL) NOPASSWD: /usr/bin/wg
www-data ALL=(ALL) NOPASSWD: /usr/bin/wg-quick
www-data ALL=(ALL) NOPASSWD: /usr/bin/cat /etc/wireguard/*
www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap
EOF
chmod 440 /etc/sudoers.d/labs-www-data

else
    log "Skipping Apache enable and system permissions on non-Ubuntu OS."
fi

# Allow git operations for www-data in /var/www
git config --system --add safe.directory /var/www || true
git config --system --add safe.directory /var/www/labs || true

# Python
if [ -f "/opt/labs-control-panel/requirements.txt" ]; then
    pip3 install -r /opt/labs-control-panel/requirements.txt --break-system-packages || true
fi

# Labsctl
if [ -f "/opt/labs-control-panel/labsctl.py" ]; then
    chmod +x /opt/labs-control-panel/labsctl.py
    rm -f /usr/local/bin/labsctl
    ln -s /opt/labs-control-panel/labsctl.py /usr/local/bin/labsctl
fi

# Install systemd services from repo
if command -v apt &> /dev/null; then
log "Installing systemd services from /opt/labs-control-panel/systemd/..."
for service_file in /opt/labs-control-panel/systemd/*.service; do
    service_name=$(basename "$service_file")
    # Skip template units (files ending in @.service)
    if [[ "$service_name" == *"@.service" ]]; then
        continue
    fi
    ln -sf "$service_file" "/etc/systemd/system/$service_name"
    log "Linked $service_name"
done

# Handle Template Units specifically
if [ -f "/opt/labs-control-panel/systemd/labs-worker@.service" ]; then
    ln -sf "/opt/labs-control-panel/systemd/labs-worker@.service" "/etc/systemd/system/labs-worker@.service"
    log "Linked labs-worker@.service template"
    
    # Enable specific instances (e.g., 1)
    systemctl enable labs-worker@1
    systemctl start labs-worker@1
    log "Enabled and started labs-worker@1"
fi

systemctl daemon-reload

for service_file in /opt/labs-control-panel/systemd/*.service; do
    service_name=$(basename "$service_file")
    # Skip template units for direct start
    if [[ "$service_name" == *"@.service" ]]; then
        continue
    fi
    
    # Strip .service for systemctl commands
    unit_name=$(basename "$service_file" .service)
    
    systemctl enable "$unit_name"
    systemctl start "$unit_name"
    log "Enabled and started $unit_name"
done

else
    log "Skipping systemd service installation on non-Ubuntu OS."
fi

# Build
if [ -d "/var/www/labs/htdocs" ]; then
    cd /var/www/labs/htdocs
    COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --no-interaction --optimize-autoloader
fi
if [ -d "/var/www/vpn-api" ]; then
    cd /var/www/vpn-api
    COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --no-interaction --optimize-autoloader
fi
if [ -d "/var/www/labs/workspace/grunt" ]; then
    cd /var/www/labs/workspace/grunt
    npm install
    grunt build
fi

# Security
if command -v apt &> /dev/null; then
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8081/tcp
ufw allow 8082/tcp
ufw allow 5672/tcp
ufw allow 15672/tcp
ufw allow 27017/tcp
ufw allow 51820/udp

# Restart
systemctl restart apache2
systemctl start traefik

# 8. Configure RabbitMQ
log "Configuring RabbitMQ..."
systemctl enable rabbitmq-server
systemctl start rabbitmq-server

rabbitmq-plugins enable rabbitmq_management rabbitmq_stomp rabbitmq_web_stomp || true
# Create RabbitMQ Admin User
if ! rabbitmqctl list_users | grep -qw "^admin"; then
    log "Creating RabbitMQ Admin User..."
    rabbitmqctl add_user admin $MQ_PASS
    rabbitmqctl set_user_tags admin administrator
else
    log "RabbitMQ Admin User already exists."
fi
# Always ensure permissions and tags are correct
rabbitmqctl set_user_tags admin administrator
rabbitmqctl set_permissions -p / admin ".*" ".*" ".*"

systemctl enable mongod
systemctl start mongod

else
    log "Skipping UFW and service restarts on non-Ubuntu OS."
fi

# 9. Initialize VPN Network
if [ -f "/var/www/vpn-api/syncnetwork.php" ]; then
    log "Initializing VPN Network Pool..."
    php /var/www/vpn-api/syncnetwork.php wg0 || true
    php /var/www/labs/workspace/tools/populate_ips.php || true
fi

    echo -e ""
    echo -e "${GREEN}==================================================${NC}"
    echo -e "${GREEN} VPS Migration Complete! ${NC}"
    echo -e "${GREEN}==================================================${NC}"
    echo -e ""
    echo -e "${YELLOW}==================================================${NC}"
    echo -e "${YELLOW} REQUIRED NEXT STEPS BEFORE USING TOM LABS: ${NC}"
    echo -e "${YELLOW}==================================================${NC}"
    echo -e "${YELLOW}1. Installation Directory:${NC}"
    echo -e "   Your configuration files are in: ${GREEN}/var/www/env.json${NC}"
    echo -e ""
    echo -e "${YELLOW}2. Configure Environment:${NC}"
    echo -e "   Edit '/var/www/env.json' and '/var/www/labs/session.json' with your API keys and credentials."
    echo -e ""
    echo -e "${GREEN}Verify URLs:${NC}"
    echo -e "  Main App:      https://$MAIN_DOMAIN"
    echo -e "  VPN API:       https://$VPN_DOMAIN"
    echo -e "  RabbitMQ:      https://$MQS_DOMAIN"
    echo -e "  MongoDB:       mongodb://admin:$DB_PASS@127.0.0.1:27018/admin"
    echo -e ""