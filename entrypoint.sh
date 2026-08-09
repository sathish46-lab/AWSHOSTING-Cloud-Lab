#!/bin/bash
set -u

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
echo "[INFO] Generating Apache Configuration..."

# Generate ports.conf to ensure all required ports are listening
cat <<EOF > /etc/apache2/ports.conf
Listen 80
Listen 8080
Listen 8081
Listen 8082
<IfModule ssl_module>
    Listen 4431
</IfModule>
EOF

echo "[INFO] Generating Apache VirtualHosts..."
cat <<EOF > /etc/apache2/sites-available/labs.conf
<VirtualHost *:80>
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
<VirtualHost *:80>
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
<VirtualHost *:80>
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

<VirtualHost *:80>
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
<VirtualHost *:80>
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
  websecure:
    address: ":443"

certificatesResolvers:
  myresolver:
    acme:
      email: $SSL_EMAIL
      storage: /etc/traefik/acme.json
      tlsChallenge: {}

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
    compress-responses:
      compress: {}
    vpn-headers:
      headers:
        customRequestHeaders:
          X-Forwarded-Proto: "https"
        accessControlAllowMethods: ["GET", "POST", "OPTIONS"]
        accessControlAllowOriginList: ["*"]
    custom-errors:
      errors:
        status: ["502", "503", "504"]
        query: "/api/labs/error_service.php?backend_status={status}"

  services:
    apache-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:80"
    mqs-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:15672"
    vpn-api-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8082"
    code-server-service:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:8080"

  routers:
    labs-router:
      rule: "Host(\`$MAIN_DOMAIN\`)"
      service: apache-service
      entryPoints:
        - websecure

    vpns-router:
      rule: "Host(\`$VPN_DOMAIN\`)"
      service: vpn-api-service
      middlewares:
        - vpn-headers
      entryPoints:
        - websecure

    mqs-router:
      rule: "Host(\`$MQS_DOMAIN\`)"
      service: mqs-service
      entryPoints:
        - websecure

    code-server-router:
      rule: "HostRegexp(\`{subdomain:.+}.$CODE_DOMAIN\`)"
      service: code-server-service
      middlewares:
        - code-headers
      entryPoints:
        - websecure

    work-router:
      rule: "Host(\`$WORK_DOMAIN\`)"
      service: apache-service
      entryPoints:
        - websecure
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
        "user": "${RABBITMQ_USER:-admin}",
        "password": "${RABBITMQ_PASS}"
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

# 10. Ensure RabbitMQ admin user exists
echo "[INFO] Ensuring RabbitMQ admin user exists..."
cat <<'SCRIPT' > /usr/local/bin/rabbitmq-create-user.sh
#!/bin/bash
until rabbitmqctl await_online_nodes 1 2>/dev/null; do sleep 1; done
MQ_USER=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['amqp_user'])" 2>/dev/null || echo 'admin')
MQ_PASS=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['amqp_pass'])" 2>/dev/null || echo '')
rabbitmqctl add_user "$MQ_USER" "$MQ_PASS" 2>/dev/null || true
rabbitmqctl set_user_tags "$MQ_USER" administrator 2>/dev/null
rabbitmqctl set_permissions -p / "$MQ_USER" ".*" ".*" ".*" 2>/dev/null
SCRIPT
chmod +x /usr/local/bin/rabbitmq-create-user.sh

cat <<EOF > /etc/systemd/system/rabbitmq-init.service
[Unit]
Description=Create RabbitMQ admin user
After=rabbitmq-server.service
Requires=rabbitmq-server.service

[Service]
Type=oneshot
ExecStart=/usr/local/bin/rabbitmq-create-user.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
systemctl enable rabbitmq-init.service || true

echo "[INFO] Handing over control to systemd!"
exec /lib/systemd/systemd
