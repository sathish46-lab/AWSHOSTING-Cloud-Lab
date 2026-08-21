#!/bin/bash
set -euo pipefail

# TomCloudLab Configuration Migration Script
# Usage: docker exec TomCloudLab bash /host_www/migrate.sh

echo "[MIGRATE] Starting configuration migration..."

# ── 1. Apache ports.conf ─────────────────────────────────────────────
echo "[MIGRATE] Writing /etc/apache2/ports.conf..."
cat <<'EOF' > /etc/apache2/ports.conf
Listen 80
Listen 8080
Listen 8081
Listen 8082
<IfModule ssl_module>
    Listen 4431
</IfModule>
EOF

# ── 2. Traefik dynamic_conf.yml ──────────────────────────────────────
echo "[MIGRATE] Writing /etc/traefik/dynamic_conf/dynamic_conf.yml..."

# Read domains from env or defaults
MAIN_DOMAIN="${MAIN_DOMAIN:-tomweb.in}"
VPN_DOMAIN="${VPN_DOMAIN:-vpn.tomweb.in}"
MQS_DOMAIN="${MQS_DOMAIN:-mq.tomweb.in}"
CODE_DOMAIN="${CODE_DOMAIN:-tomweb.shop}"
WORK_DOMAIN="${WORK_DOMAIN:-work.tomweb.in}"

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

    mqs-ws-router:
      rule: "Host(\`$MQS_DOMAIN\`) && (PathPrefix(\`/ws\`) || PathPrefix(\`/stats-ws\`))"
      service: mqs-service
      entryPoints:
        - web

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

# ── 3. Remove stale bridge_dev.yml if present ────────────────────────
rm -f /etc/traefik/dynamic_conf/bridge_dev.yml

# ── 4. Reload Apache ────────────────────────────────────────────────
echo "[MIGRATE] Reloading Apache..."
apachectl graceful 2>/dev/null || true

# ── 5. Verify ───────────────────────────────────────────────────────
echo ""
echo "[MIGRATE] Verifying..."

# Check Apache listens on 80
if ss -tlnp | grep -q ":80 "; then
    echo "  [OK] Apache listening on port 80"
else
    echo "  [FAIL] Apache NOT listening on port 80"
fi

# Check Traefik has labs-router
if grep -q "labs-router" /etc/traefik/dynamic_conf/dynamic_conf.yml; then
    echo "  [OK] Traefik labs-router present"
else
    echo "  [FAIL] Traefik labs-router missing"
fi

# Check custom-errors middleware
if grep -q "custom-errors" /etc/traefik/dynamic_conf/dynamic_conf.yml; then
    echo "  [OK] Traefik custom-errors middleware present"
else
    echo "  [FAIL] Traefik custom-errors middleware missing"
fi

# Test labs.tomweb.in
STATUS=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: $MAIN_DOMAIN" http://127.0.0.1/ 2>/dev/null || echo "000")
if [ "$STATUS" = "200" ]; then
    echo "  [OK] labs.tomweb.in → HTTP $STATUS"
else
    echo "  [WARN] labs.tomweb.in → HTTP $STATUS (may need Traefik reload)"
fi

echo ""
echo "[MIGRATE] Done. Traefik auto-reloads file changes."
