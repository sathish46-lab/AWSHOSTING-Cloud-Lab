import os
import json
import time
import secrets
import string
import sys
import base64
import shlex
from pymongo import MongoClient
from src.BaseOrchestrator import BaseOrchestrator

class Lab(BaseOrchestrator):
    def __init__(self, args, session_hash=None, is_instance=False):
        super().__init__(args, session_hash)
        self.is_instance = is_instance
        if is_instance and self.db is not None:
            self.instances_col = self.db['machine_labs']
        else:
            self.instances_col = None

    def _get_deploy_data(self, instance_id):
        """Get deploy data from machine_labs (flat structure)."""
        if self.db is not None:
            doc = self.db.machine_labs.find_one({"instance_hash": instance_id})
            if doc:
                return doc
        return None

    def _set_deploy_field(self, instance_id, field, value):
        """Write a single deploy field to machine_labs (flat structure)."""
        if self.is_instance and self.instances_col:
            result = self.instances_col.update_one(
                {"deploy.instance_hash": instance_id},
                {"$set": {f"deploy.{field}": value, "updated_at": time.time()}}
            )
            if result.matched_count == 0:
                self.instances_col.update_one(
                    {"instance_hash": instance_id},
                    {"$set": {f"deploy.{field}": value, "updated_at": time.time()}}
                )
        else:
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": {field: value}}
            )

    def _set_deploy_fields(self, instance_id, fields):
        """Write multiple deploy fields at once to machine_labs (flat structure)."""
        if self.is_instance and self.instances_col:
            set_fields = {}
            for k, v in fields.items():
                set_fields[f"deploy.{k}"] = v
            set_fields["updated_at"] = time.time()
            result = self.instances_col.update_one(
                {"instance_hash": instance_id},
                {"$set": set_fields}
            )
            if result.matched_count == 0:
                self.instances_col.update_one(
                    {"deploy.instance_hash": instance_id},
                    {"$set": set_fields}
                )
        else:
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": fields}
            )

    def _fail_deploy(self, instance_id, message):
        """Set deploy status to error — called on any failure during deploy()."""
        self.log(message, "error", "init")
        now = time.time()
        if self.is_instance and self.instances_col:
            error_doc = {
                "deploy.status": "error",
                "deploy.last_error": message,
                "deploy.error_at": now,
                "status": "error",
                "updated_at": now
            }
            result = self.instances_col.update_one(
                {"deploy.instance_hash": instance_id},
                {"$set": error_doc}
            )
            if result.matched_count == 0:
                self.instances_col.update_one(
                    {"instance_hash": instance_id},
                    {"$set": error_doc}
                )
        else:
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": {"status": "error", "last_error": message, "error_at": now}}
            )
        sys.exit(1)

    def _get_instance_doc(self, instance_id):
        """For instances, get the full instances document."""
        if self.is_instance and self.instances_col:
            doc = self.instances_col.find_one({"instance_hash": instance_id})
            if doc:
                return doc
            return self.instances_col.find_one({"deploy.instance_hash": instance_id})
        return None

    def build(self):
        """Build a Docker image from a specified template"""
        if len(sys.argv) < 3:
            self.log("Usage: labsctl build <template:tag>", "error", "init")
            return

        image_tag = sys.argv[2]
        template_name = image_tag.split(':')[0]
        
        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        template_path = os.path.join(templates_dir, template_name)
        
        if not os.path.exists(template_path):
            self.log(f"Template path not found: {template_path}", "error", "init")
            return

        self.log(f"Building image {image_tag}...", "info", "build")
        build_cmd = self.config.get('docker_build', "docker build -t {image_tag} {path}")
        
        # Enable BuildKit for performance
        build_cmd = build_cmd.replace("docker build", "DOCKER_BUILDKIT=1 docker build")

        if self.args.hasFlag('no-cache'):
            build_cmd = build_cmd.replace("docker build", "docker build --no-cache")

        mapping = {"image_tag": image_tag, "path": template_path}
        exit_status, _ = self.run(build_cmd.format(**mapping))
        
        if exit_status == 0:
            self.log(f"Image {image_tag} built successfully.", "success", "done")
            self.log("Cleaning up old image layers...", "info", "cleanup")
            self.run("docker image prune -f")
        else:
            self.log(f"Build failed with exit code {exit_status}", "error", "done")

    def generate_traefik_config(self, instance_id, docker_ip, lab_spec, lab_data):
        """Build Traefik config from template services definition."""
        services_spec = lab_spec.get('services', {})
        base_domain = self.cfg.code_domain

        # TLS on/off (config.json tls_enabled). Dev=false → plain-HTTP routers.
        tls_enabled = bool(self.config.get('tls_enabled', True))
        _entrypoints = "[web, websecure]" if tls_enabled else "[web]"
        _tls = "      tls: {}\n" if tls_enabled else ""

        routers = ""
        services = ""
        
        # A. Always add VS Code if 'code' is in services_spec
        for svc_name, svc_spec in services_spec.items():
            port = svc_spec['port']
            
            # Determine domain
            domain_flag = svc_spec.get('domain_flag')
            domain_prefix = svc_spec.get('domain_prefix', '')
            
            if domain_flag:
                custom = self.args.getFlagValue(domain_flag)
                # Sanitize inputs (prevent "default" strings from UI bugs)
                if custom and ('default' in custom):
                    custom = None
                domain = custom if custom else f"{domain_prefix}{instance_id}.{base_domain}"
            elif svc_name == 'code':
                # Code domain logic from arguments or DB
                db_domain = lab_data.get('code_domain')
                selected_code_domain = self.args.getFlagValue('vsc_domain') or db_domain or f"code-{instance_id}.{base_domain}"
                domain = selected_code_domain
            elif svc_name == 'web':
                # Web domains are handled separately below from 'domains' array
                continue
            else:
                domain = f"{svc_name}-{instance_id}.{base_domain}"
            
            router_key = f"router-{instance_id}-{svc_name}"
            service_key = f"service-{instance_id}-{svc_name}"
            
            routers += f"    {router_key}:\n"
            routers += f"      rule: \"Host(`{domain}`)\"\n"
            routers += f"      service: {service_key}\n"
            routers += f"      entryPoints: {_entrypoints}\n{_tls}"
            routers += f"      priority: 100\n"
            mw_list = ['custom-errors']
            if svc_spec.get('middlewares'):
                mw_list.extend(svc_spec['middlewares'])
            routers += f"      middlewares: [{', '.join(mw_list)}]\n"
            
            services += f"    {service_key}:\n"
            services += f"      loadBalancer:\n"
            services += f"        servers: [{{url: \"http://{docker_ip}:{port}\"}}]\n"
        
        # B. Handle custom domains (expose_web)
        user_domains = lab_data.get('domains', [])
        is_exposed = lab_data.get('expose_web', False)
        if is_exposed and user_domains and 'web' in services_spec:
            web_service_key = f"service-{instance_id}-web"
            web_port = services_spec.get('web', {}).get('port', 80)
            
            services += f"    {web_service_key}:\n"
            services += f"      loadBalancer:\n"
            services += f"        servers: [{{url: \"http://{docker_ip}:{web_port}\"}}]\n"
            
            for idx, domain in enumerate(user_domains):
                routers += f"    router-{instance_id}-custom-{idx}:\n"
                routers += f"      rule: \"Host(`{domain}`)\"\n"
                routers += f"      service: {web_service_key}\n"
                routers += f"      entryPoints: {_entrypoints}\n{_tls}"
                routers += f"      priority: 100\n"
                routers += f"      middlewares: [custom-errors]\n"
        
        # C. Handle HTTP Proxies
        http_proxies = lab_data.get('http_proxies', [])
        for idx, proxy in enumerate(http_proxies):
            p_port = proxy.get('port')
            p_domain = proxy.get('domain')
            if p_port and p_domain:
                proxy_router = f"router-{instance_id}-proxy-{idx}"
                proxy_service = f"service-{instance_id}-proxy-{idx}"
                
                routers += f"    {proxy_router}:\n"
                routers += f"      rule: \"Host(`{p_domain}`)\"\n"
                routers += f"      service: {proxy_service}\n"
                routers += f"      entryPoints: {_entrypoints}\n{_tls}"
                routers += f"      priority: 150\n" # Higher priority to override wildcard web
                routers += f"      middlewares: [custom-errors]\n"
                
                services += f"    {proxy_service}:\n"
                services += f"      loadBalancer:\n"
                services += f"        servers: [{{url: \"http://{docker_ip}:{p_port}\"}}]\n"

        yaml_str = "http:\n  routers:\n" + routers + "\n  services:\n" + services
        return yaml_str

    def deploy(self):
        """Deploy a container with WireGuard mesh networking and Template-based Config"""
        self.log("Deployment initiated (WireGuard Mesh Mode)...", "info", "init")
        
        # Phase 1: INIT
        docker_network = self.config.get('docker_network_name')
        if not docker_network:
            self.log("FATAL: 'docker_network_name' not set in config.", "error", "init")
            return
        code, _ = self.run(f"docker network inspect {shlex.quote(docker_network)} > /dev/null 2>&1", capture=True)
        if code != 0:
            self.log(f"FATAL: Docker network {docker_network} not found. Is docker-compose up?", "error", "init")
            return

        instance_id = self.session_hash
        if not instance_id:
            self.log("FATAL: session_hash is empty. Cannot deploy without a valid instance hash.", "error", "init")
            raise ValueError("session_hash is required for deployment")
        # Validate instance_id to prevent command injection
        instance_id = shlex.quote(instance_id)
        username = self.args.getFlagValue('user')
        if username:
            username = shlex.quote(username)
        
        if self.db is None:
            self.log("Database connection failed. Aborting.", "error", "init")
            return

        self.log("Fetching lab metadata from database...", "info", "init")
        lab_data = self._get_deploy_data(instance_id)
        
        if not lab_data:
            self._fail_deploy(instance_id, f"Metadata missing for {instance_id}")
            return

        if not username:
            username = lab_data.get('username')
            if not username:
                self._fail_deploy(instance_id, "FATAL: --user flag missing and no user found in database.")
                return

        self.log(f"Starting deployment for user: {username}", "info", "init")
        self.log(f"Instance ID: {instance_id}", "info", "init")

        # Load the specific Lab Template Configuration
        template_name = lab_data['lab_type']
        # Validate template name — only allow safe characters
        if not template_name or not shlex.quote(template_name).strip("'"):
            self._fail_deploy(instance_id, "Invalid template name in database")
            return
        template_name_safe = shlex.quote(template_name)
        template_config_path = os.path.join(self.config.get('templates_dir'), template_name, 'config.json')
        
        if not os.path.exists(template_config_path):
            self._fail_deploy(instance_id, f"Template config missing: {template_config_path}")
            return

        with open(template_config_path, 'r') as f:
            lab_spec = json.load(f)

        link_script = lab_spec.get('scripts', {}).get('linkuser', '/var/labsdata/scripts/linkuser.sh')

        # Extract Resources & IPs
        res = lab_spec.get('resources', {})
        mem = res.get('memory', '512m')
        cpu = res.get('cpus', '0.2')
        pids = res.get('pids_limit', 100)
        mount_target = lab_spec.get('storage', {}).get('mount_target', '/var/labsstorage/home/{user}').replace('{user}', username)

        base_ip = lab_data['internal_ip'] 
        ip_parts = base_ip.split('.')
        last_octet = ip_parts[3]
        
        docker_prefix = self.config.get('docker_ip')
        if not docker_prefix:
            self._fail_deploy(instance_id, "FATAL: 'docker_ip' not set in config.")
            return
        tunnel_prefix = self.config.get('tunnel_ip')
        if not tunnel_prefix:
            self._fail_deploy(instance_id, "FATAL: 'tunnel_ip' not set in config.")
            return

        docker_ip = f"{docker_prefix}{last_octet}"
        tunnel_ip = f"{tunnel_prefix}{last_octet}"
        
        orchestrator = self.config.get('orchestrator_container')
        if not orchestrator:
            self._fail_deploy(instance_id, "FATAL: 'orchestrator_container' not set in config.")
            return
        code, vps_docker_ip = self.run(
            f"docker inspect {orchestrator} --format '{{{{.NetworkSettings.Networks.{docker_network}.IPAddress}}}}' 2>/dev/null", capture=True
        )
        if not vps_docker_ip or vps_docker_ip == "<no value>":
            vps_docker_ip = f"{docker_prefix}2"
            self.log(f"WARNING: Could not detect VPS container IP, using fallback: {vps_docker_ip}", "warn", "network")
        
        self.log(f"Assigned Docker IP (eth0): {docker_ip}", "info", "network")
        self.log(f"Assigned Tunnel IP (wg0): {tunnel_ip}", "info", "network")
        
        storage_path = lab_data['storage_path']
        
        # Phase 2: CLEANUP
        self.log("Checking for conflicting containers...", "info", "cleanup")
        if self.cleanup_container(instance_id, self.config.get('docker_network_name', 'bridge')):
            self.log("Container removed.", "success", "cleanup")
        else:
            self.log("No existing container found.", "info", "cleanup")
        
        # Phase 3: STORAGE
        if storage_path.startswith('/'):
            if not os.path.exists(storage_path):
                self.log("Setting up storage...", "info", "storage")
                os.makedirs(storage_path, exist_ok=True)
            else:
                self.log("Volume verified.", "success", "storage")
        else:
            self.log("Using Docker named volume.", "success", "storage")
        
        # Phase 4: NETWORK
        self.log(f"Clearing stale VPN sessions for {tunnel_ip}...", "info", "network")
        wgfree_script = os.path.join(self.config.get('templates_dir'), template_name, "Data/scripts/wgfree.sh")
        if os.path.exists(wgfree_script):
            self.run(f"bash {wgfree_script} {tunnel_ip}")
        
        credentials = lab_data.get('credentials', {})
        lab_pub_key = credentials.get('wg_pubkey')
        lab_priv_key = credentials.get('wg_privkey')

        if not lab_priv_key or not lab_pub_key:
            self.log("Generating fresh WireGuard keys...", "info", "network")
            wg_script = os.path.join(self.config.get('templates_dir'), template_name, "Data/scripts/wgconfig.py")
            try:
                code, wg_output = self.run(f"python3 {wg_script} {tunnel_ip}", capture=True)
                lab_priv_key, lab_pub_key = wg_output.split('|')
            except Exception as e:
                self._fail_deploy(instance_id, "WireGuard key generation failed")
                return
        else:
            self.log("Reusing existing keys for stable connection...", "info", "network")
            self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
            self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")
            
            code, check = self.run(f"wg show wg0 allowed-ips | grep '{lab_pub_key}'", capture=True)
            if check:
                self.log(f"Peer re-registered: {tunnel_ip}", "success", "network")
            else:
                self.log(f"WARNING: Peer registration may have failed for {tunnel_ip}", "warn", "network")
            
        code, server_pub_key = self.run("wg show wg0 public-key 2>/dev/null", capture=True)
        if not server_pub_key:
            self.log("WARNING: Could not get server WireGuard public key", "warn", "network")
            server_pub_key = "" # It will fail in linkuser without this, let's keep going but it will likely fail.

        # Phase 5: CONTAINER
        self.log(f"Provisioning {lab_spec.get('lab_name', template_name)}: {mem} RAM, {cpu} CPU", "info", "container")
        tunnel_prefix = self.config.get('tunnel_ip', '172.30.0.')
        tunnel_gw = f"{tunnel_prefix}1"
        vpn_domain = os.environ.get('VPN_DOMAIN', 'vpn.tomweb.in')
        
        # Host-side base path for Docker volume mount
        import hashlib
        user_email = lab_data.get("email", "")
        user_hash = hashlib.md5(user_email.encode()).hexdigest() if user_email else username
        
        mapping = {
            "lab_name": instance_id, 
            "memory": mem,
            "cpus": cpu,
            "pids": pids,
            "storage": storage_path, 
            "storage_path": storage_path,
            "storage_host_path": f"{self.config.get('storage_base', '/var/tomlabs/storage')}/{user_hash}",
            "mount_target": mount_target,
            "user": username, 
            "image": lab_data.get('image', f"{template_name}:lab"), 
            "ip": docker_ip, 
            "vps_docker_ip": vps_docker_ip,
            "tunnel_gw": tunnel_gw,
            "vpn_domain": vpn_domain,
            "host_name": f"{lab_spec.get('network', {}).get('hostname', 'essentials')}.{instance_id}.{os.environ.get('CODE_DOMAIN', 'tomweb.fun')}",
            'network_name': self.config.get('docker_network_name', 'bridge')
        }
        
        docker_run_cmd = self.config.get('docker_run')
        if '--add-host' not in docker_run_cmd and 'vpn_domain' in mapping and 'tunnel_gw' in mapping:
            docker_run_cmd = docker_run_cmd.replace('--cap-add=NET_ADMIN', f'--add-host {{vpn_domain}}:{{tunnel_gw}} --cap-add=NET_ADMIN')
        result = self.docker.run_command(docker_run_cmd, mapping)
        if not result:
            self._fail_deploy(instance_id, "FATAL: Container failed to start (docker run failed).")
            return
        
        self.log("Waiting for container services to initialize...", "info", "container")
        for i in range(10):
            if self.docker.is_container_running(instance_id):
                break
            time.sleep(0.5)
        
        # Phase 6: ROUTING
        self.log("Configuring network routing and firewall...", "info", "routing")
        bridge_id = self.detect_bridge(docker_network)
        self.configure_routing(tunnel_ip, docker_ip, bridge_id)
        self.log("Routing and firewall configured.", "success", "routing")
        
        # Phase 7: CONFIGURE
        self.log("Optimizing Apache for single-user environment...", "info", "configure")
        apache_opt_cmd = (
            f"docker exec {instance_id} bash -c \""
            "cat <<EOF > /etc/apache2/mods-available/mpm_event.conf\n"
            "<IfModule mpm_event_module>\n"
            "        StartServers             1\n"
            "        MinSpareThreads          2\n"
            "        MaxSpareThreads          5\n"
            "        ThreadsPerChild          10\n"
            "        MaxRequestWorkers        20\n"
            "        MaxConnectionsPerChild   0\n"
            "</IfModule>\n"
            "EOF\n"
            "service apache2 reload\""
        )
        self.run(apache_opt_cmd)

        self.log(f"Configuring user environment for {username}...", "info", "configure")
        
        # NOTE: SSH from= IP restrictions are NOT used because iptables MASQUERADE
        # on the VPS rewrites the client's source IP before packets reach the container.
        # The container always sees the VPS bridge IP, never the client VPN IP.
        # Security is enforced at the network layer (WireGuard VPN + Docker isolation).

        user_keys = list(self.db.ssh_keys.find({"username": username}))
        auth_content = "\\n".join([k['public_key'] for k in user_keys if 'public_key' in k])

        import string, secrets
        staged_creds = lab_data.get('staged_preferences', {})
        existing_creds = lab_data.get('credentials', {})

        if staged_creds.get('code_server_pass'):
            dynamic_pass = staged_creds['code_server_pass']
        elif existing_creds.get('code_server_pass'):
            dynamic_pass = existing_creds['code_server_pass']
        elif existing_creds.get('password'):
            dynamic_pass = existing_creds['password']
        else:
            dynamic_pass = ''.join(secrets.choice(string.ascii_letters + string.digits) for i in range(12))
            
        if staged_creds.get('su_pass'):
            su_pass = staged_creds['su_pass']
        else:
            su_pass = existing_creds.get('su_pass', '') or ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(16))
        
        # Custom Domain Logic for n8n
        selected_n8n_domain = None
        if template_name == 'n8n':
            custom_n8n = self.args.getFlagValue('n8n-domain')
            # Sanitize inputs
            if custom_n8n and (custom_n8n == 'default_n8n' or 'default' in custom_n8n):
                custom_n8n = None
            base_domain = self.cfg.code_domain
            selected_n8n_domain = custom_n8n if custom_n8n else f"n8n-{instance_id}.{base_domain}"

        # Pass email to linkuser.sh (8th argument)
        user_profile = self.db.users.find_one({"username": username.strip("'")})
        user_email = user_profile.get('email', username.strip("'")) if user_profile else username.strip("'")
        
        # Pass n8n Domain (9th argument) for Webhook URL
        n8n_domain_arg = selected_n8n_domain if selected_n8n_domain else ""
        escaped_auth_content = auth_content.replace('"', '\\"')
        link_cmd = (
            f'docker exec {instance_id} {link_script} '
            f'{shlex.quote(username)} {shlex.quote(escaped_auth_content)} '
            f'{shlex.quote(docker_ip)} {shlex.quote(dynamic_pass)} '
            f'{shlex.quote(lab_priv_key)} {shlex.quote(tunnel_ip)} '
            f'{shlex.quote(server_pub_key)} {shlex.quote(user_email)} '
            f'{shlex.quote(n8n_domain_arg)} {shlex.quote(vps_docker_ip)} '
            f'{shlex.quote(su_pass)}'
        )
        
        code, _ = self.run(link_cmd, capture=False)
        if code != 0:
            self._fail_deploy(instance_id, "linkuser.sh failed. Check output above for details.")
            return
        
        # Phase 7.5: Post-link WireGuard Routing & DNS Fix
        # 1. Fix AllowedIPs and kernel routing inside the lab container so VPN traffic is routed correctly
        # 2. Map VPN_DOMAIN to tunnel_gw in /etc/hosts for internal VPN access without NAT loopback
        if tunnel_ip:
            tunnel_subnet = ".".join(tunnel_ip.split(".")[:2]) + ".0.0/16"
            tunnel_gw = ".".join(tunnel_ip.split(".")[:3]) + ".1"
            self.log(f"[*] Applying WireGuard routing and DNS fix for subnet {tunnel_subnet}...", "info", "configure")
            
            vpn_domain = os.environ.get('VPN_DOMAIN', 'vpn.tomweb.in')
            
            fix_cmd = (
                f'sed -i \'s#AllowedIPs.*#AllowedIPs = {tunnel_subnet}#g\' /etc/wireguard/wg0.conf 2>/dev/null || true; '
                f'wg set wg0 peer {server_pub_key} allowed-ips {tunnel_subnet} 2>/dev/null || true; '
                f'ip route add {tunnel_subnet} dev wg0 metric 10 2>/dev/null || true; '
                f'grep -q "::1" /etc/hosts || echo -e "::1\\tlocalhost ip6-localhost ip6-loopback\\nfe00::0\\tip6-localnet\\nff00::0\\tip6-mcastprefix\\nff02::1\\tip6-allnodes\\nff02::2\\tip6-allrouters" >> /etc/hosts 2>/dev/null || true; '
                f'grep -q "{vpn_domain}" /etc/hosts || echo "{tunnel_gw}\t{vpn_domain}" >> /etc/hosts 2>/dev/null || true'
            )
            self.run(f'docker exec {instance_id} bash -c "{fix_cmd}"', capture=False)
        
        # Phase 8: TRAEFIK
        self.log("[*] Finalizing Traefik routing...", "info", "traefik")
        traefik_dict = self.generate_traefik_config(instance_id, docker_ip, lab_spec, lab_data)
        self.write_traefik_config(instance_id, traefik_dict)

        # Phase 9: METADATA
        self.log("[*] Finalizing routing metadata...", "info", "metadata")
        
        base_domain = self.cfg.code_domain
        db_domain = lab_data.get('code_domain')
        selected_code_domain = self.args.getFlagValue('vsc_domain') or db_domain or f"{instance_id}.{base_domain}"
        code_server_url = f"https://{selected_code_domain}"
        
        credentials = {
            "ssh": f"ssh {username}@{tunnel_ip}",
            "password": dynamic_pass,
            "docker_ip": docker_ip,
            "tunnel_ip": tunnel_ip,
            "port": 22,
            "sshKey": len(user_keys) > 0,
            "code_server_url": code_server_url,
            "wg_pubkey": lab_pub_key,
            "wg_privkey": lab_priv_key
        }

        # Dynamically build credentials from template
        cred_template = lab_spec.get('credentials_template', {})
        if cred_template:
            # Prepare format string args
            fmt_args = {
                "username": username,
                "password": dynamic_pass,
                "email": user_email,
                "su_pass": su_pass
            }
            # Dynamically pull domain values
            for svc_name, svc_spec in lab_spec.get('services', {}).items():
                domain_flag = svc_spec.get('domain_flag')
                domain_prefix = svc_spec.get('domain_prefix', '')
                if domain_flag:
                    custom = self.args.getFlagValue(domain_flag)
                    if custom and ('default' in custom): custom = None
                    domain = custom if custom else f"{domain_prefix}{instance_id}.{base_domain}"
                    # e.g., if domain_flag is "n8n-domain", key is "n8n_domain"
                    fmt_args[domain_flag.replace('-', '_')] = domain
                    # e.g., if service is "s3-ui", allow replacing "{s3-ui_domain}"
                    fmt_args[f"{svc_name}_domain"] = domain
            
            for key, val in cred_template.items():
                if isinstance(val, str):
                    credentials[key] = val.format(**fmt_args)
                else:
                    credentials[key] = val

        self._set_deploy_fields(instance_id, {
            "status": "running",
            "credentials": credentials,
        })
        
        # Phase 10: INIT SCRIPT
        self.log("Running init script...", "info", "init_script")
        self.run_script(lab_data=lab_data)

        # Phase 11: DONE
        self.log("Deployment Complete. Ready for connections.", "success", "done")
        self.log(f"Access URL: {code_server_url}", "info", "done")
        self.log(f"VPN Access: ssh {username}@{tunnel_ip}", "info", "done")

    def stop(self):
        """Stop container and clean up runtime networking without releasing the IP pool."""
        lab_name = self.session_hash
        self.log(f"Initiating graceful shutdown for: {lab_name}", "info", "init")
        
        lab_data = self._get_deploy_data(lab_name)
        
        if lab_data:
            credentials = lab_data.get('credentials', {})
            tunnel_ip = credentials.get('tunnel_ip')
            
            if tunnel_ip:
                self.log(f"Cleaning host route: {tunnel_ip}", "info", "routing")
                self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")

        if self.docker.container_exists(lab_name):
            self.log(f"Stopping Docker container...", "warn", "cleanup")
            self.run(f"docker stop {lab_name} && docker rm -f {lab_name}")
            
            self._set_deploy_field(lab_name, "status", "stopped")
            self.log("Container and process-space cleared.", "success", "cleanup")

        self.remove_traefik_config(lab_name)
        self.log(f"Lab {lab_name} is now offline. IP remains reserved.", "success", "done")

    def start(self):
        """Start a stopped container and re-apply routing & Traefik."""
        lab_name = self.session_hash
        self.log(f"Starting container: {lab_name}", "info", "init")
        
        if self.docker.container_exists(lab_name):
            self.run(f"docker start {lab_name}")
            self.log("Container started.", "success", "container")
            
            if self.db is not None:
                self._set_deploy_field(lab_name, "status", "running")
                
            lab_data = self._get_deploy_data(lab_name)
            if lab_data:
                credentials = lab_data.get('credentials', {})
                tunnel_ip = credentials.get('tunnel_ip')
                docker_ip = credentials.get('docker_ip')
                lab_pub_key = credentials.get('wg_pubkey')
                template_name = lab_data.get('lab_type')
                
                # Re-apply WireGuard
                if tunnel_ip and lab_pub_key:
                    self.log("Re-applying WireGuard peer...", "info", "network")
                    self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
                    self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

                # Re-apply Routing
                if tunnel_ip and docker_ip:
                    self.log("Re-applying network routes...", "info", "routing")
                    docker_network = self.config.get('docker_network_name')
                    if not docker_network:
                        self.log("FATAL: 'docker_network_name' not set in config.", "error", "network")
                        return
                    bridge_id = self.detect_bridge(docker_network)
                    self.configure_routing(tunnel_ip, docker_ip, bridge_id)
                
                # Re-apply Traefik
                if template_name:
                    self.log("Re-applying Traefik configuration...", "info", "traefik")
                    template_config_path = os.path.join(self.config.get('templates_dir'), template_name, 'config.json')
                    if os.path.exists(template_config_path):
                        with open(template_config_path, 'r') as f:
                            lab_spec = json.load(f)
                        traefik_dict = self.generate_traefik_config(lab_name, docker_ip, lab_spec, lab_data)
                        self.write_traefik_config(lab_name, traefik_dict)
            
            self.log("Lab start sequence complete.", "success", "done")
        else:
            self.log(f"Container {lab_name} not found", "error", "init")

    def remove(self):
        """Remove a container completely"""
        self.stop()

    def shell(self):
        """Drop into a container shell"""
        import subprocess
        lab_name = self.session_hash
        subprocess.run(["docker", "exec", "-it", lab_name, "/bin/bash"], check=False)

    def info(self):
        """Show lab metadata"""
        lab_name = self.session_hash
        
        if self.db is None:
            self.log("Database connection failed", "error")
            return
        
        lab_data = self._get_deploy_data(lab_name)
        
        if lab_data:
            import pprint
            pprint.pprint(lab_data)
        else:
            self.log(f"No metadata found for {lab_name}", "error")

    def sync_user(self, username):
        """Synchronize SSH keys and permissions for a user's running labs"""
        self.log(f"Syncing permissions for user: {username}...", "info")
        
        if self.db is None:
            self.log("Database connection failed", "error")
            return

        # 1. Fetch User Profile to get Email
        user_profile = self.db.users.find_one({'username': username})
        search_emails = [username]
        if user_profile and 'email' in user_profile:
            search_emails.append(user_profile['email'])
            self.log(f"Linked email found: {user_profile['email']}", "info")

        # NOTE: SSH from= IP restrictions are NOT used because iptables MASQUERADE
        # on the VPS rewrites the client's source IP before packets reach the container.
        # Security is enforced at the network layer (WireGuard VPN + Docker isolation).

        # 2. Fetch User's SSH Keys
        user_keys = list(self.db.ssh_keys.find({"username": username}))
        if not user_keys:
            self.log(f"No SSH keys found for {username}", "warn")
            
        # 3. Generate Authorized Keys Content (no from= restriction)
        auth_content = "\n".join([k['public_key'] for k in user_keys if 'public_key' in k])
        
        # 5. Update Storage for All Deployments
        # We update the central storage location for the user, which is mounted into containers
        
        # We need to know the storage path. We can search for any lab owned by this user to find the storage path
        # or use the template default.
        
        # Checking one of the user's labs to get the storage path
        user_lab = self.db.machine_labs.find_one({"username": username})
        if user_lab:
            storage_path = user_lab.get('storage_path')
        else:
            storage_path = f"/var/tomlabs/storage/{username}/home/{username}"
            
        ssh_dir = os.path.join(storage_path, ".ssh")
        auth_file = os.path.join(ssh_dir, "authorized_keys")
        
        if os.path.exists(storage_path):
            if not os.path.exists(ssh_dir):
                os.makedirs(ssh_dir, mode=0o700, exist_ok=True)
                # potentially chown if we knew the uid/gid, but usually host root owns it or it's permissive
                
            try:
                with open(auth_file, "w") as f:
                    f.write(auth_content)
                os.chmod(auth_file, 0o600)
                self.log(f"Updated {auth_file}", "success", "system")
            except Exception as e:
                self.log(f"Failed to write authorized_keys: {e}", "error", "system")
        else:
             self.log(f"Storage path {storage_path} does not exist. No labs deployed?", "warn", "system")

    def ensure_codeserver(self):
        """Ensure code-server is running inside the container"""
        lab_name = self.session_hash
        username = self.args.getFlagValue('user')

        # Configurable idle timeout; in-container home is the username-based mount
        # Guard against a non-numeric env value so a bad CODE_SERVER_IDLE_SECS never
        # aborts the whole ensure (matches the defensive parse in commands/lab.py).
        try:
            idle_limit = int(os.environ.get('CODE_SERVER_IDLE_SECS', '600'))
        except (TypeError, ValueError):
            idle_limit = 600

        self.log(f"Ensuring code-server is running for {lab_name}...", "info", "system")
        
        if not self.docker.container_exists(lab_name):
            self.log(f"Container {lab_name} not found", "error", "system")
            return

        # 1. ALWAYS dynamically create and inject the idle monitor script
        # NOTE: code-server refreshes its own heartbeat continuously, so heartbeat
        # staleness never signals "idle". Stop the server when there has been NO
        # browser connection to its port for IDLE_LIMIT seconds.
        monitor_script_content = f"""#!/bin/bash
USER=$1
IDLE_LIMIT={idle_limit}
PORT=8080
LAST_SEEN=$(date +%s)

while true; do
    sleep 30

    # Exit if code-server was killed manually
    if ! pgrep -u "$USER" -f code-server > /dev/null 2>&1; then
        exit 0
    fi

    CONNS=$(ss -tn state established 2>/dev/null | awk '{{print $4}}' | grep -c ":${{PORT}}$" || true)
    NOW=$(date +%s)
    if [ "${{CONNS:-0}}" -gt 0 ]; then
        LAST_SEEN=$NOW
    elif [ $((NOW - LAST_SEEN)) -ge "$IDLE_LIMIT" ]; then
        pkill -u "$USER" -f code-server
        exit 0
    fi
done
"""
        # Write the script into the container using base64 to avoid shell escaping issues
        b64_script = base64.b64encode(monitor_script_content.encode()).decode()
        self.run(f"docker exec {lab_name} mkdir -p /var/labsdata/scripts")
        self.run(f"docker exec {lab_name} bash -c 'echo {b64_script} | base64 -d > /var/labsdata/scripts/monitor_codeserver.sh'")
        self.run(f"docker exec {lab_name} chmod +x /var/labsdata/scripts/monitor_codeserver.sh")

        # 2. Start monitor if not running (using detached mode!)
        monitor_check = f"docker exec {lab_name} pgrep -f monitor_codeserver"
        mcode, _ = self.run(monitor_check, capture=True)
        if mcode != 0:
            docker_monitor = f"docker exec -d {lab_name} bash /var/labsdata/scripts/monitor_codeserver.sh {username}"
            self.run(docker_monitor)
            self.log("Idle monitor started (2min timeout for testing).", "success", "system")

        # 3. Check code-server
        check_cmd = f"docker exec {lab_name} pgrep -u {username} -f code-server"
        code, _ = self.run(check_cmd, capture=True)
        if code == 0:
            self.log("Code-server is already running.", "success", "done")
            return

        self.log("Starting code-server...", "info", "system")
        
        user_home = f"/var/labsstorage/home/{username}"
        config_file = f"{user_home}/.config/code-server/config.yaml"
        log_file = f"{user_home}/.code-server.log"
        
        start_cmd = f"nohup code-server --disable-telemetry --disable-update-check --config {config_file} > {log_file} 2>&1 &"
        docker_cmd = f"docker exec -d -u {username} {lab_name} bash -c '{start_cmd}'"
        
        code, _ = self.run(docker_cmd)
        if code == 0:
            time.sleep(2) 
            code, _ = self.run(check_cmd, capture=True)
            if code == 0:
                self.log("Code-server started successfully.", "success", "done")
            else:
                self.log("Code-server failed to stay running.", "error", "system")
        else:
            self.log("Failed to start code-server.", "error", "system")

    def apply_preferences(self):
        """Re-generate Traefik routing rules without full redeploy, and execute init.sh"""
        self.log("Applying preferences (Hot Reload)...", "info", "system")
        username = self.args.getFlagValue('user')
        instance_hash = self.args.getFlagValue('hash')
        
        if not username or not instance_hash:
            self.log("Missing --user or --hash", "error", "system")
            return
            
        lab_data = self._get_deploy_data(instance_hash)
        
        if not lab_data:
            self.log("Lab not found in database.", "error", "system")
            return
            
        lab_name = instance_hash
        
        # 1. Check if running
        check_cmd = f"docker inspect -f '{{{{.State.Running}}}}' {lab_name}"
        rc, out = self.run(check_cmd, capture=True)
        if rc != 0 or out.strip() != "true":
            self.log("Lab is not running. Start it first.", "error", "system")
            return
            
        # 2. Get docker IP
        ip_cmd = f"docker inspect -f '{{{{range.NetworkSettings.Networks}}}}{{{{.IPAddress}}}}{{{{end}}}}' {lab_name}"
        rc, docker_ip = self.run(ip_cmd, capture=True)
        docker_ip = docker_ip.strip()
        
        if not docker_ip:
            self.log("Could not resolve docker IP for Traefik routing.", "error", "system")
            return
            
        # 3. Reload Traefik config
        template_name = lab_data['lab_type']
        template_config_path = os.path.join(self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates'), template_name, 'config.json')
        
        if not os.path.exists(template_config_path):
            self.log(f"Template config missing: {template_config_path}", "error", "system")
            return
            
        with open(template_config_path, 'r') as f:
            lab_spec = json.load(f)
        
        yaml_content = self.generate_traefik_config(instance_hash, docker_ip, lab_spec, lab_data)
        traefik_conf_path = f"/etc/traefik/dynamic_conf/{instance_hash}.yml"
        
        with open(traefik_conf_path, 'w') as f:
            f.write(yaml_content)
            
        self.log(f"Traefik configuration reloaded.", "success", "system")
        
        # 4. Run the init.sh script
        self.run_script(lab_data=lab_data)

    def run_script(self, lab_data=None):
        """Write init_script to container and execute it"""
        self.log("Executing startup script...", "info", "system")
        username = self.args.getFlagValue('user')
        instance_hash = self.args.getFlagValue('hash')
        
        if not username or not instance_hash:
            self.log("Missing --user or --hash", "error", "system")
            return
            
        if not lab_data:
            lab_data = self._get_deploy_data(instance_hash)
            
        if not lab_data:
            self.log("Lab not found in database.", "error", "system")
            return
            
        lab_name = instance_hash
        
        # 1. Check if running
        check_cmd = f"docker inspect -f '{{{{.State.Running}}}}' {lab_name}"
        rc, out = self.run(check_cmd, capture=True)
        if rc != 0 or out.strip() != "true":
            self.log("Lab is not running. Start it first.", "error", "system")
            return
            
        # 2. Get script content
        script_content = lab_data.get('init_script', '#!/bin/bash\n')
        
        # 3. Write script to container using base64 (docker cp won't work from orchestrator container)
        b64_content = base64.b64encode(script_content.encode()).decode()
        
        self.log(f"Injecting script to /var/labsstorage/home/{username}/init.sh...", "info", "system")
        rc, _ = self.run(f"docker exec {lab_name} bash -c 'echo {b64_content} | base64 -d > /var/labsstorage/home/{username}/init.sh'", capture=True)
        if rc != 0:
            self.log("Failed to inject script into container.", "error", "system")
            return
            
        self.run(f"docker exec {lab_name} chmod +x /var/labsstorage/home/{username}/init.sh")
        self.run(f"docker exec {lab_name} chown {username}:{username} /var/labsstorage/home/{username}/init.sh")
        
        self.log("Running script (output below)...", "info", "system")
        # Run inside bash
        rc, out = self.run(f"docker exec -u {username} {lab_name} bash /var/labsstorage/home/{username}/init.sh", capture=False)
        
        if rc == 0:
            self.log("Script executed successfully.", "success", "system")
        else:
            self.log(f"Script failed with exit code {rc}.", "error", "system")

    def list_images(self):
        """List all lab templates and their build status"""
        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        if not os.path.exists(templates_dir):
            self.log("Templates directory not found.", "error")
            return

        templates = [d for d in os.listdir(templates_dir) if os.path.isdir(os.path.join(templates_dir, d)) and not d.startswith('__')]
        
        self.log("Checking Lab Image Status:", "info")
        print("-" * 70)
        print(f"{'Template Name':<20} | {'Image Tag':<15} | {'Image ID':<15} | {'Status'}")
        print("-" * 70)

        for t in templates:
            tag = f"{t}:lab"
            code, img_id_out = self.run(f"docker images -q {tag}", capture=True)
            img_id = img_id_out.strip() if img_id_out else "N/A"
            if len(img_id) > 12:
                img_id = img_id[:12]
            
            status = "✅ Built" if self.docker.image_exists(tag) else "❌ Missing"
            print(f"{t:<20} | {tag:<15} | {img_id:<15} | {status}")
        print("-" * 70 + "\n")
    
    def get_workers(self):
        """Check how many Python worker processes are currently alive via systemd."""
        self.log("Querying systemd for active labs-worker instances...", "info", "system")
        try:
            cmd = "systemctl list-units --type=service --state=running | grep 'labs-worker' | awk '{print $1}'"
            code, output = self.run(cmd, capture=True)
            
            if not output:
                self.log("No active workers found. (Check if 'systemctl start labs-worker@1' was run)", "warn", "system")
                return

            workers = output.split('\n')
            count = len(workers)
            
            self.log(f"Total Active Workers: {count}", "success", "system")
            
            print("-" * 40)
            print(f"{'Worker Instance Name':<25} | {'Status'}")
            print("-" * 40)
            for w in workers:
                print(f"{w:<25} | 🟢 Alive")
            print("-" * 40 + "\n")
            
        except Exception as e:
            self.log(f"Failed to check workers: {e}", "error", "system")