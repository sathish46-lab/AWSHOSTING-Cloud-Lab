# <span style="color: #ff9800;">Labs Infrastructure Migration Guide</span>

Professional deployment and orchestration suite for the Labs environment. Use this guide to safely migrate your environment to a fresh server.

---

> [!TIP]
> **Security:** The setup script will securely prompt you for your desired Database and RabbitMQ passwords during execution. No hardcoded passwords will be used in your configuration!

---

## <span style="color: #ff9800;">🚀 Migration Process</span>

We provide two distinct migration paths. Choose the one that fits your environment.

### <span style="color: #ff9800;">Option 1: Bare-Metal Server Installation</span>
For direct deployment on a VPS or dedicated Ubuntu server.

**Prerequisites:**
* A fresh Ubuntu 24.04 (Noble) server.
* Root or sudo privileges.
* Domains pointed to your server's IP via DNS A records (Main, VPN, RabbitMQ, Code, Work).

**Installation Steps:**
1. **Execute the Universal Orchestrator:**
```bash
curl -fsSL https://raw.githubusercontent.com/sathish46-lab/tom-cloud-labs/master/Migrate.sh | sudo bash
```
3. When prompted, select **[1] VPS Bare-Metal Server Setup**.
4. Follow the interactive prompts to enter your domains, database passwords, and Git credentials.
5. After the script finishes building the server, proceed directly to the **Configuration Setup (`ip.json`)** section below.

---

### <span style="color: #ff9800;">Option 2: Container-Based Installation (Docker + Cloudflare Tunnel)</span>
For deploying locally (Mac, Windows, Linux) without exposing your machine to the public internet, using Cloudflare Zero Trust Tunnels.

**Prerequisites:**
* Docker and Docker Compose installed locally.
* A Cloudflare account with a Zero Trust Tunnel created.

**Installation Steps:**

1. **Execute the Universal Orchestrator:**
```bash
curl -fsSL https://raw.githubusercontent.com/sathish46-lab/tom-cloud-labs/master/Migrate.sh | sudo bash
```
2. When prompted, select **[2] Docker Container Local Setup**.
3. Follow the interactive prompts to enter your domains.
4. The script will automatically download your codebase and generate your `docker-compose.yml` and `.env` files.
5. Open the newly generated `.env` file and paste your Cloudflare **`TUNNEL_TOKEN`**.
6. Run `docker compose up -d` to boot the entire environment securely to the internet!

**Cloudflare Tunnel Configuration:**
To make your local Docker lab accessible via your domains, configure your Cloudflare Zero Trust tunnel as follows:

![Cloudflare Tunnel Setup Example](docs/images/cloudflare-tunnel-setup.png)
*(Example Image: Cloudflare Tunnel Dashboard)*

1. In the Cloudflare Zero Trust Dashboard, go to **Access > Tunnels** and configure your tunnel.
2. Under **Public Hostnames**, create entries for ALL your domains (`labs.yourdomain.com`, `vpn.yourdomain.com`, `mq.yourdomain.com`, `work.yourdomain.com`).
3. For the **Service**, set the Type to `HTTP` and the URL to point to port 80 of your local machine (e.g., `localhost:8080` if mapped, or `localhost:80` depending on your setup).
*(Note: Traefik is pre-configured to accept HTTP traffic from Cloudflare Tunnels and will automatically route the domains to the correct internal containers).*

---

### <span style="color: #ff9800;">Interactive Setup (Common for Both Methods)</span>

Whether using Option 1 or Option 2, the script will prompt for:
* **Domains**: Configure your FQDNs for Labs, VPN API, RabbitMQ, and Code Server. For the Docker setup with Cloudflare, ensure these match your public hostnames configured in your tunnel.
* **SSL Email**: Used for Let's Encrypt certificate generation via Traefik.
* **Git Credentials**: Required for private repository cloning.


### <span style="color: #ff9800;">✅ Post-Migration Success</span>

Upon successful completion, the script will output the following confirmation:

```text
[INFO] ==================================================
[INFO]  Migration Complete! 
[INFO] ==================================================
Verify URLs:
  https://labs.tomweb.in
  https://vpn.tomweb.in
  https://mq.tomweb.in

```

---

## <span style="color: #ff9800;">⚙️ Control Panel Configuration</span>

The Labs Control Panel relies on a `config.json` file to dictate networking and Docker subnets. Since this file is uniquely tied to your local or production environment, it is excluded from Git tracking.

Before proceeding, you **must** create your configuration file:

1. Navigate to the configuration directory:
```bash
cd opt/labs-control-panel/
```
2. Copy the example configuration:
```bash
cp config.example.json config.json
```
3. Open `config.json` and adjust the variables based on your target environment:
   * **Production:** Use the production subnet (`10.20.128.`) and Docker network (`TomCloudLab_backend`).
   * **Local Development:** Match your local `docker-compose.yml` settings (e.g., `10.20.144.` and `TomCloudLab`).

---

## <span style="color: #ff9800;">⚙️ Configuration Setup</span>

After the migration completes, there are **two critical configuration files** you need to manage:

### <span style="color: #ff9800;">1. Docker Environment (`.env`)</span>
Located at the root of your project (`/.env`), this file is automatically generated by the `Migrate.sh` script. It injects your domains into Docker Compose and Traefik so that the traffic router knows where to send requests.

```env
MAIN_DOMAIN=labs.tomweb.in
VPN_DOMAIN=vpn.tomweb.in
MQS_DOMAIN=mq.tomweb.in
CODE_DOMAIN=tomweb.shop
WORK_DOMAIN=work.tomweb.in
SSL_EMAIL=admin@example.com
```
*Note: If you ever change your domains in this `.env` file, you must run `docker compose up -d` to restart the router and apply the changes.*

---

### <span style="color: #ff9800;">2. Application Configuration (`ip.json`)</span>
Located at `/var/www/labs/htdocs/ip.json` (or `/var/www/ip.json` directly on a bare-metal server). This file controls the backend application settings, API keys, and database credentials. Ensure these values match your specific deployment!

```json
{
  "amqp_host": "127.0.0.1",
  "amqp_port": 5672,
  "amqp_user": "admin",
  "amqp_pass": "YOUR_RABBITMQ_PASSWORD",
  "google_oauth": {
    "client_id": "*****************.apps.googleusercontent.com",
    "client_secret": "********************",
    "redirect_uri": "https://labs.tomweb.in/signin",
    "metadata_url": "https://accounts.google.com/.well-known/openid-configuration"
  },
  "smtp": {
    "host": "smtp.gmail.com",
    "port": 465,
    "user": "sathishp3223@gmail.com",
    "pass": "*************"
  },
  "exception_path": "/var/www/labs/htdocs/src/lib/exceptions/",
  "app_log": "/var/log/labs/log.txt",
  "app_cache": "/var/cache/labs",
  "database_file": "mongodb://admin:YOUR_MONGO_PASSWORD@127.0.0.1:27017/tom_labs_db?authSource=admin",
  "main_db": "tom_labs_db",
  "vpn_db": "tom_labs_vpn",
  "vpn_url": "https://vpn.tomweb.in/api",
  "api_secret": "your-super-secret-token-here",
  "wireguard": {
    "conf_path": "/etc/wireguard/",
    "interface": "wg0"
  },
  "s3": {
    "endpoint": "https://api-0a0fa067832614bb98336cde4230ad3b.tomweb.shop",
    "region": "us-east-1",
    "bucket": "labassets",
    "access_key": "YOUR_S3_ACCESS_KEY",
    "secret_key": "YOUR_S3_SECRET_KEY",
    "use_path_style": true
  },
  "allowed_hosts": [
    "labs.tomweb.in"
  ]
}

```

---

## <span style="color: #ff9800;">🏗️ Lab Image Building</span>

Manage and build your lab environments using the `labsctl` utility located at `/opt/labs-control-panel/labsctl.py`.

| Lab Type | Build Command |
| --- | --- |
| **Essentials Lab** | `sudo labsctl build essentials:lab` |
| **n8n Automation** | `sudo labsctl build n8n:lab` |
| **MinIO S3 Storage** | `sudo labsctl build minio:lab` |

---

## <span style="color: #ff9800;">🔑 Security & Key Management</span>

To ensure proper VPN handshakes, you must update the **Server Public Key** in the following critical locations:

1. **VPN Download Script**:
Update `$serverPubKey` in `/var/www/labs/htdocs/api/vpn/download.php`.
2. **Labs Control Panel**:
Ensure the public key is updated within the orchestration logic at `/opt/labs-control-panel/`.

---

## <span style="color: #ff9800;">🛠️ Service Overview</span>

Once completed, the following stack will be active:

* **Traefik**: Edge router handling SSL (Let's Encrypt) and routing to services.
* **Apache2**: Backend web server (listening on 8081/8082) serving Labs and VPN API.
* **RabbitMQ**: Message broker with STOMP enabled for real-time log streaming.
* **MongoDB 8.0**: Primary data store with authentication enabled.
* **WireGuard**: Secure VPN tunnel for container networking.

---

*Version: 1.0.0 - Major Infrastructure & Security Alignment*
