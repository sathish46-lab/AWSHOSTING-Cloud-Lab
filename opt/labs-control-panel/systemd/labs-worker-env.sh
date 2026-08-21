#!/bin/bash
# labs-worker-env.sh — reads credentials from env.json at runtime (no hardcoding)
export DOCKER_HOST=unix:///var/docker.sock
export RABBITMQ_HOST=127.0.0.1
export RABBITMQ_PORT=5672
export MONGO_HOST=127.0.0.1
export MONGO_PORT=27018

if [ -f /var/www/env.json ]; then
    export RABBITMQ_USER=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['amqp_user'])")
    export RABBITMQ_PASS=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['amqp_pass'])")
    export MONGO_USER=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['mongo_user'])")
    export MONGO_PASS=$(python3 -c "import json; print(json.load(open('/var/www/env.json'))['mongo_pass'])")
fi

# MCP server: use restricted credentials (readWrite on tom_labs_db only)
export MCP_MONGO_URI="mongodb://mcp_server:mcp_secure_2026@TomCloudLab_mongodb:27017/tom_labs_db?authSource=admin"

exec /usr/bin/python3 -u /var/www/labs/worker/labs_worker.py
