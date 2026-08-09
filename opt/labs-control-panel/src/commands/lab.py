import os
import sys
import json
import time
import secrets
import string
import base64
import subprocess
from src.router import Command

BASE_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.realpath(__file__))))


class LabCmd(Command):
    """labsctl lab — legacy machine_labs operations."""

    name = "lab"
    description = "Base template operations (system only)"
    usage = "labsctl lab <build|deploy|stop|start|remove|info|generate-keys|sync-user|redeploy|update> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "build":        (self._build,        "Build lab image",           "labsctl lab build <name:tag> --no-cache"),
            "deploy":       (self._deploy,       "Deploy lab",                "labsctl lab deploy --hash=HASH --user=USER"),
            "stop":         (self._stop,         "Stop lab",                  "labsctl lab stop --hash=HASH"),
            "start":        (self._start,        "Start lab",                 "labsctl lab start --hash=HASH"),
            "remove":       (self._remove,       "Remove lab",                "labsctl lab remove --hash=HASH"),
            "info":         (self._info,         "Show lab info",             "labsctl lab info --hash=HASH"),
            "shell":        (self._shell,        "Enter container",           "labsctl lab shell --hash=HASH"),
            "generate-keys":(self._generate_keys,"Generate SSH host keys",    "labsctl lab generate-keys [template]"),
            "sync-user":    (self._sync_user,    "Sync SSH keys for user",    "labsctl lab sync-user --user=USER"),
            "redeploy":     (self._redeploy,     "Redeploy lab",              "labsctl lab redeploy --hash=HASH --user=USER"),
            "update":       (self._update,       "Update lab image",          "labsctl lab update --hash=HASH --user=USER"),
        }

    def _get_deploy_data(self, instance_id):
        if self.db is not None:
            doc = self.db.machine_labs.find_one({"instance_hash": instance_id})
            return doc
        return None

    def _set_deploy_field(self, instance_id, field, value):
        if self.db is not None:
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": {field: value}}
            )

    def _set_deploy_fields(self, instance_id, fields):
        if self.db is not None:
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": fields}
            )

    def _fail_deploy(self, instance_id, msg):
        self.log(msg, "error")
        self._set_deploy_field(instance_id, "status", "error")
        if self.db is not None:
            now = time.time()
            self.db.machine_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": {"last_error": msg, "error_at": now, "status": "error"}}
            )
        sys.exit(1)

    def _cleanup_stale_docker_ips(self):
        """Release Docker IPs claimed by non-running labs."""
        docker_ip_col = self.db["docker_ip_registry"] if self.db else None
        if not docker_ip_col:
            return
        claimed = docker_ip_col.find({"status": "claimed"})
        released = 0
        for doc in claimed:
            iid = doc.get("instance_hash", "")
            if not iid:
                continue
            lab = self.db.machine_labs.find_one(
                {"instance_hash": iid},
                {"status": 1}
            )
            if not lab or lab.get("status") != "running":
                docker_ip_col.update_one(
                    {"_id": doc["_id"]},
                    {"$set": {"status": "available", "instance_hash": "", "user": "", "claimed_at": 0}}
                )
                released += 1
        if released:
            self.log(f"Released {released} stale Docker IP(s)", "info")

    # ── Detect VPS Docker IP ────────────────────────────────────

    def _detect_vps_docker_ip(self, docker_network):
        """Detect the orchestrator container's Docker IP on the shared network."""
        orchestrator = self.cfg.orchestrator_container
        if not orchestrator:
            fallback = f"{self.cfg.docker_ip}2"
            self.log(f"No orchestrator container configured, using fallback: {fallback}", "warn")
            return fallback

        code, ip = self.run(
            f"docker inspect {orchestrator} "
            f"--format '{{{{.NetworkSettings.Networks.{docker_network}.IPAddress}}}}' 2>/dev/null",
            capture=True
        )
        if ip and ip != "<no value>" and ip.strip():
            return ip.strip()

        fallback = f"{self.cfg.docker_ip}2"
        self.log(f"Could not detect VPS container IP, using fallback: {fallback}", "warn")
        return fallback

    # ── Storage Quota ─────────────────────────────────────────

    def _get_user_proj_id(self, username):
        import hashlib
        return int(hashlib.md5(username.encode()).hexdigest()[:8], 16) % 100000 + 1000

    def _set_user_quota(self, username, storage_path):
        storage_base = self.cfg.storage_base
        limit_gb = self.cfg.storage_limit_gb
        limit_soft = limit_gb - 1

        code, _ = self.run("which xfs_quota 2>/dev/null", capture=True)
        if code != 0:
            self.log(f"XFS quota not available, skipping quota setup for {username}", "warn")
            return

        code, mount_info = self.run(f"mount | grep {storage_base} | head -1", capture=True)
        if "prjquota" not in mount_info and "pquota" not in mount_info:
            self.log(f"Filesystem does not support prjquota, skipping quota for {username}", "warn")
            return

        proj_id = self._get_user_proj_id(username)
        self.run(f'grep -q "^{proj_id}:" /etc/projects 2>/dev/null || echo "{proj_id}:{storage_path}" >> /etc/projects')
        self.run(f'grep -q "^{username}:" /etc/projid 2>/dev/null || echo "{username}:{proj_id}" >> /etc/projid')
        self.run(f'xfs_quota -x -c "project -s {proj_id}" {storage_base}')
        self.run(f'xfs_quota -x -c "limit -p bsoft={limit_soft}g bhard={limit_gb}g {proj_id}" {storage_base}')
        self.log(f"Quota set: {limit_gb}GB limit for {username} (project {proj_id})", "success")

    def _release_user_quota(self, username):
        storage_base = self.cfg.storage_base
        code, _ = self.run("which xfs_quota 2>/dev/null", capture=True)
        if code != 0:
            return
        proj_id = self._get_user_proj_id(username)
        self.run(f'xfs_quota -x -c "limit -p bsoft=0 bhard=0 {proj_id}" {storage_base}')
        self.log(f"Quota released for {username}", "success")

    # ── Generate SSH Host Keys ───────────────────────────────────

    def _generate_keys(self, args):
        target = args.get(1)
        templates_dir = self.cfg.templates_dir

        if not os.access(templates_dir, os.W_OK):
            local_path = os.path.join(BASE_DIR, "lab-templates")
            if os.path.isdir(local_path) and os.access(local_path, os.W_OK):
                templates_dir = local_path
            else:
                self.log(f"Templates dir not writable: {templates_dir}", "error")
                return

        if target:
            templates = [target]
        else:
            templates = [d for d in os.listdir(templates_dir)
                        if os.path.isdir(os.path.join(templates_dir, d)) and not d.startswith("__")]

        GREEN = "\033[92m"
        RED = "\033[91m"
        RESET = "\033[0m"

        print(f"\n  Generating SSH Host Keys:")
        print("  " + "-" * 50)

        for tpl in templates:
            key_dir = os.path.join(templates_dir, tpl, "Data", "ssh_host_keys")
            os.makedirs(key_dir, exist_ok=True)

            keys = [
                ("ed25519", "ssh-keygen -t ed25519 -f {path} -N \"\" -q"),
                ("rsa", "ssh-keygen -t rsa -b 4096 -f {path} -N \"\" -q"),
                ("ecdsa", "ssh-keygen -t ecdsa -f {path} -N \"\" -q"),
            ]

            for key_type, cmd in keys:
                key_path = os.path.join(key_dir, f"ssh_host_{key_type}_key")
                if os.path.exists(key_path):
                    print(f"  {tpl:<25} {key_type:<10} {RED}exists{RESET}")
                else:
                    subprocess.run(cmd.format(path=key_path), shell=True, capture_output=True)
                    os.chmod(key_path, 0o600)
                    pub_path = key_path + ".pub"
                    if os.path.exists(pub_path):
                        os.chmod(pub_path, 0o644)
                    print(f"  {tpl:<25} {key_type:<10} {GREEN}created{RESET}")

        print("  " + "-" * 50)
        print(f"  {GREEN}Done.{RESET} Keys are in lab-templates/<name>/Data/ssh_host_keys/")
        print(f"  These are git-ignored. Generate after clone.\n")

    # ── Build ───────────────────────────────────────────────────

    def _build(self, args):
        image_tag = args.get(1)
        if not image_tag:
            self.log("Usage: labsctl lab build <template:tag>", "error")
            return

        template_name = image_tag.split(":")[0]
        template_path = os.path.join(self.cfg.templates_dir, template_name)

        if not os.path.exists(template_path):
            self.log(f"Template not found: {template_path}", "error")
            return

        self.log(f"Building image {image_tag}...")
        cmd = "DOCKER_BUILDKIT=1 docker build"
        if args.has("no-cache"):
            cmd += " --no-cache"
        cmd += f" -t {image_tag} {template_path}"

        code, _ = self.run(cmd)
        if code == 0:
            self.log(f"Image {image_tag} built.", "success")
            self.run("docker image prune -f")
        else:
            self.log(f"Build failed (exit {code})", "error")

    # ── Deploy ──────────────────────────────────────────────────

    def _deploy(self, args):
        instance_id = args.hash
        username = args.user

        if not instance_id:
            self.log("Missing --hash", "error")
            return

        # Clean up stale Docker IPs before allocating
        self._cleanup_stale_docker_ips()

        lab_data = self._get_deploy_data(instance_id)
        if not lab_data:
            self._fail_deploy(instance_id, f"Lab not found: {instance_id}")
            return

        if not username:
            username = lab_data.get("username")
        if not username:
            self._fail_deploy(instance_id, "Missing --user and no user in DB")
            return

        template_name = lab_data.get("template_name") or lab_data.get("lab_type")
        if not template_name:
            self._fail_deploy(instance_id, "Missing template_name/lab_type in DB record. Cannot determine lab image.")
            return

        # Phase: INIT
        self.log(f"Deployment initiated (WireGuard Mesh Mode)...")
        self.log(f"Fetching lab metadata from database...")
        self.log(f"Starting deployment for user: {username}")
        self.log(f"Instance ID: {instance_id}")

        # Load template config
        tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
        if not os.path.exists(tpl_path):
            self._fail_deploy(instance_id, f"Template config missing: {tpl_path}")
            return

        with open(tpl_path) as f:
            lab_spec = json.load(f)

        link_script = lab_spec.get("scripts", {}).get("linkuser", "/var/labsdata/scripts/linkuser.sh")

        # Validate Docker network
        docker_network = self.cfg.docker_network
        code, _ = self.run(f"docker network inspect {docker_network} > /dev/null 2>&1", capture=True)
        if code != 0:
            self._fail_deploy(instance_id, f"FATAL: Docker network {docker_network} not found.")
            return

        # IPs — IPManager-style allocation from lab_ips pool
        lab_ips_col = self.db["ip_registry"] if self.db else None
        instances_col = self.instances_db().instances if self.instances_db() else None

        # Get user email for IP registry
        user_profile = self.db.users.find_one({"username": username}) if self.db else None
        user_email = user_profile.get('email', username) if user_profile else username

        allocated_ip = None

        # Priority 1: Check machine_labs for existing IP (most reliable)
        existing_lab = self.db.machine_labs.find_one({"instance_hash": instance_id})
        if existing_lab and existing_lab.get("internal_ip"):
            allocated_ip = existing_lab["internal_ip"]
            self.log(f"Reusing existing lab IP from DB: {allocated_ip}")

        # Priority 2: Check ip_registry for IP allocated to this instance
        if not allocated_ip and lab_ips_col:
            existing = lab_ips_col.find_one({"allocated_to": instance_id})
            if existing:
                allocated_ip = existing["ip_addr"]
                self.log(f"Reusing allocated IP: {allocated_ip}")

        # Mark the IP as reserved in ip_registry
        if allocated_ip and lab_ips_col:
            lab_ips_col.update_one(
                {"ip_addr": allocated_ip},
                {"$set": {"status": "reserved", "allocated_to": instance_id, "email": user_email, "reserved_to": username}}
            )

        # Priority 3: Allocate new IP from pool
        if not allocated_ip and lab_ips_col:
            result = lab_ips_col.find_one_and_update(
                {"status": "available", "ip_numeric": {"$gt": 1}},
                {
                    "$set": {
                        "status": "reserved",
                        "allocated_to": instance_id,
                        "email": user_email,
                        "reserved_to": username,
                        "service_type": template_name,
                        "label": "{} Lab".format(template_name.title()),
                        "last_deploy": int(time.time())
                    }
                },
                sort=[("ip_numeric", 1)],
                return_document=True
            )
            if result:
                allocated_ip = result["ip_addr"]
                self.log(f"Allocated new IP: {allocated_ip}")
            else:
                self._fail_deploy(instance_id, "IP Pool Exhausted!")
                return

        if allocated_ip and instances_col:
            last_octet = allocated_ip.split(".")[-1]
            try:
                instances_col.update_one(
                    {"instance_hash": instance_id},
                    {"$set": {"internal_ip": allocated_ip}}
                )
            except Exception:
                pass
        elif not allocated_ip:
            import hashlib
            last_octet = str(int(hashlib.md5(instance_id.encode()).hexdigest()[:8], 16) % 200 + 21)
            allocated_ip = f"172.31.0.{last_octet}"
        else:
            last_octet = allocated_ip.split(".")[-1]

        tunnel_ip = allocated_ip  # tunnel_ip == internal_ip in flat structure

        # Resources
        res = lab_spec.get("resources", {})
        mem = res.get("memory", "512m")
        cpu = res.get("cpus", "0.2")
        mount_target = lab_spec.get("storage", {}).get("mount_target", "/var/labsstorage/home/{user}").replace("{user}", username)
        storage_path = lab_data.get("storage_path", "")

        # Phase: CLEANUP
        self.log("Checking for conflicting containers...")
        if self.docker_exists(instance_id):
            self.log("Removing existing container...")
            docker_network = self.cfg.docker_network
            self.run(f"docker network disconnect -f {docker_network} {instance_id} 2>/dev/null || true")
        else:
            self.log("No existing container found.")
        self.docker_stop(instance_id)

        # Phase: STORAGE — create SNA-style structure on first deploy, preserve on redeploy
        import hashlib
        storage_base = self.cfg.storage_base
        user_email = lab_data.get("email", "")
        user_hash = hashlib.md5(user_email.encode()).hexdigest() if user_email else username
        # SNA-style: storage/{hash}/home/{username}/ + cron/ + usr/
        user_storage = f"{storage_base}/{user_hash}"
        user_home = f"{user_storage}/home/{username}"
        if not os.path.exists(user_home):
            os.makedirs(user_home, exist_ok=True)
            # Create SNA-style root directories
            os.makedirs(f"{user_storage}/cron", exist_ok=True)
            os.makedirs(f"{user_storage}/usr", exist_ok=True)
            self.log(f"Created storage: {user_home}")
        else:
            self.log(f"Storage preserved: {user_home}")

        # Phase: NETWORK — Clear stale WireGuard peers
        self.log(f"Clearing stale VPN sessions for {tunnel_ip}...")
        wgfree_script = os.path.join(self.cfg.templates_dir, template_name, "Data/scripts/wgfree.sh")
        if os.path.exists(wgfree_script):
            self.run(f"bash {wgfree_script} {tunnel_ip}")

        # WireGuard keys
        credentials = lab_data.get("credentials", {})
        if not isinstance(credentials, dict):
            credentials = {}
        lab_pub_key = credentials.get("wg_pubkey")
        lab_priv_key = credentials.get("wg_privkey")

        if not lab_priv_key or not lab_pub_key:
            self.log("Generating fresh WireGuard keys...")
            wg_script = os.path.join(self.cfg.templates_dir, template_name, "Data/scripts/wgconfig.py")
            try:
                code, wg_out = self.run(f"python3 {wg_script} {tunnel_ip}", capture=True)
                lab_priv_key, lab_pub_key = wg_out.split("|")
            except Exception:
                self._fail_deploy(instance_id, "WireGuard key generation failed")
                return
        else:
            self.log("Reusing existing keys for stable connection...")
            self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
            self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

            code, check = self.run(f"wg show wg0 allowed-ips | grep '{lab_pub_key}'", capture=True)
            if check:
                self.log(f"Peer re-registered: {tunnel_ip}", "success")
            else:
                self.log(f"WARNING: Peer registration may have failed for {tunnel_ip}", "warn")

        self.log(f"Public Key: {lab_pub_key}")

        code, server_pub_key = self.run("wg show wg0 public-key 2>/dev/null", capture=True)
        if not server_pub_key:
            self.log("WARNING: Could not get server WireGuard public key", "warn")

        # Detect VPS Docker IP for WireGuard endpoint
        vps_docker_ip = self._detect_vps_docker_ip(docker_network)
        self.log(f"VPS Docker IP (WireGuard endpoint): {vps_docker_ip}")

        # Phase: CONTAINER
        self.log(f"Provisioning {template_name}: {mem} RAM, {cpu} CPU")
        tunnel_gw = f"{self.cfg.tunnel_ip}1"
        vpn_domain = self.cfg.vpn_domain

        # -- Atomic Docker IP Allocation via MongoDB --
        # findOneAndUpdate is atomic at document level -- only ONE process wins each claim.
        docker_ip_col = self.db["docker_ip_registry"] if self.db else None
        docker_ip = None

        if docker_ip_col:
            docker_ip_col.create_index("octet", unique=True, background=True)
            if docker_ip_col.count_documents({}) == 0:
                bulk = [{"octet": i, "ip": self.cfg.docker_ip + str(i), "status": "available"} for i in range(3, 255)]
                try:
                    docker_ip_col.insert_many(bulk, ordered=False)
                except Exception:
                    pass

            claimed = docker_ip_col.find_one_and_update(
                {"status": "available"},
                {"$set": {
                    "status": "claimed",
                    "instance_hash": instance_id,
                    "user": username,
                    "claimed_at": time.time(),
                }},
                sort=[("octet", 1)],
                return_document=True,
            )
            if claimed:
                docker_ip = claimed["ip"]
                self._set_deploy_field(instance_id, "docker_ip", docker_ip)
            else:
                self._fail_deploy(instance_id, "Docker IP pool exhausted!")
                return
        else:
            code, in_use = self.run(
                f"docker network inspect {docker_network} "
                f"-f '{{{{range .Containers}}}}{{{{.IPv4Address}}}}{{{{end}}}}' 2>/dev/null",
                capture=True
            )
            used_octets = {1, 2}
            if in_use:
                for addr in in_use.split("/"):
                    if addr.startswith(self.cfg.docker_ip):
                        try:
                            used_octets.add(int(addr.replace(self.cfg.docker_ip, "").strip()))
                        except ValueError:
                            pass
            docker_last_octet = int(last_octet)
            while docker_last_octet in used_octets:
                docker_last_octet += 1
            docker_ip = self.cfg.docker_ip + str(docker_last_octet)

        self.log(f"Assigned Docker IP (eth0): {docker_ip}", "info")

        docker_cmd = self.cfg.get("docker_run", "")
        # Host-side base path (Mac) for Docker volume mount
        STORAGE_HOST_BASE = "/Users/sathish/Development/Dev_lab/tomlabs/storage"
        mapping = {
            "lab_name": instance_id,
            "memory": mem,
            "cpus": cpu,
            "storage": user_storage,
            "storage_path": user_storage,
            "storage_host_path": f"{STORAGE_HOST_BASE}/{user_hash}",
            "mount_target": mount_target,
            "user": username,
            "image": lab_data.get("image", f"{template_name}:lab"),
            "ip": docker_ip,
            "vps_docker_ip": vps_docker_ip,
            "tunnel_gw": tunnel_gw,
            "vpn_domain": vpn_domain,
            "host_name": f"{lab_spec.get('network', {}).get('hostname', 'essentials')}.{instance_id}.{self.cfg.code_domain}"[:63],
            "network_name": docker_network,
        }

        if "--add-host" not in docker_cmd:
            docker_cmd = docker_cmd.replace(
                "--cap-add=NET_ADMIN",
                f"--add-host {vpn_domain}:{tunnel_gw} --cap-add=NET_ADMIN"
            )

        from src.DockerHelper import DockerHelper
        docker = DockerHelper()

        mapping["ip"] = docker_ip
        result = docker.run_command(docker_cmd, mapping)

        if not result:
            self._fail_deploy(instance_id, "Container failed to start")
            # Release the Docker IP we claimed
            docker_ip_col = self.db["docker_ip_registry"] if self.db else None
            if docker_ip_col:
                docker_ip_col.update_one(
                    {"instance_hash": instance_id, "status": "claimed"},
                    {"$set": {"status": "available", "instance_hash": "", "user": "", "claimed_at": 0}}
                )
            return

        self.log("Waiting for container services to initialize...")
        for _ in range(10):
            if self.docker_running(instance_id):
                break
            time.sleep(0.5)

        # Ensure /home symlink and proper ownership
        self.run(
            f"docker exec {instance_id} bash -c '"
            f"rm -rf /home && ln -s /var/labsstorage/home /home && "
            f"chown -R 1000:1000 /var/labsstorage/home/{username} 2>/dev/null'"
        )

        # Phase: ROUTING
        self.log("Configuring network routing and firewall...")
        bridge = self.detect_bridge(docker_network)
        self.configure_routing(tunnel_ip, docker_ip, bridge)
        self.log("Routing and firewall configured.", "success")

        # Add tunnel IP to container's eth0
        self.run(f"docker exec {instance_id} ip addr add {tunnel_ip}/32 dev eth0 2>/dev/null || true")

        # Phase: CONFIGURE — Apache MPM optimization
        self.log("Optimizing Apache for single-user environment...")
        apache_mpm = """#!/bin/bash
cat <<'EOF' > /etc/apache2/mods-available/mpm_event.conf
<IfModule mpm_event_module>
        StartServers             1
        MinSpareThreads          2
        MaxSpareThreads          5
        ThreadsPerChild          10
        MaxRequestWorkers        20
        MaxConnectionsPerChild   0
</IfModule>
EOF
service apache2 reload 2>/dev/null || true
"""
        import tempfile
        with tempfile.NamedTemporaryFile(mode='w', suffix='.sh', delete=False) as f:
            f.write(apache_mpm)
            tmp_mpm = f.name
        self.run(f"docker cp {tmp_mpm} {instance_id}:/tmp/mpm_opt.sh")
        os.unlink(tmp_mpm)
        self.run(f"docker exec {instance_id} bash /tmp/mpm_opt.sh")
        self.run(f"docker exec {instance_id} rm -f /tmp/mpm_opt.sh")

        # Phase: CONFIGURE — User environment
        self.log(f"Configuring user environment for {username}...")

        user_keys = list(self.db.ssh_keys.find({"username": username})) if self.db else []
        auth_content = "\n".join([k['public_key'] for k in user_keys if 'public_key' in k])
        ssh_enabled = len(user_keys) > 0
        self.log(f"Syncing ssh authorized_keys for {username} ({len(user_keys)} key(s))")

        # Staged preferences (password persistence across redeploy)
        staged_creds = lab_data.get("staged_preferences", {})
        existing_creds = lab_data.get("credentials", {})

        if staged_creds.get("code_server_pass"):
            dynamic_pass = staged_creds["code_server_pass"]
        elif existing_creds.get("password"):
            dynamic_pass = existing_creds["password"]
        else:
            dynamic_pass = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(12))

        if staged_creds.get("su_pass"):
            su_pass = staged_creds["su_pass"]
        else:
            su_pass = existing_creds.get("su_pass", f"{username}@098")

        self.log(f"sudo Password: {su_pass}")

        # Execute linkuser.sh
        self.log("Configuring user environment...")
        escaped_auth = auth_content.replace('"', '\\"').replace('$', '\\$')

        # Copy updated scripts into container (image may have stale copies)
        host_scripts_dir = os.path.join(self.cfg.templates_dir, template_name, "Data", "scripts")
        if os.path.isdir(host_scripts_dir):
            self.run(f"docker exec {instance_id} mkdir -p /var/labsdata/scripts")
            self.run(f"docker cp {host_scripts_dir}/. {instance_id}:/var/labsdata/scripts/")
            self.run(f"docker exec {instance_id} find /var/labsdata/scripts -name '*.sh' -exec chmod +x {{}} +")

        # Read staged preferences for passwords (user may have set these in the UI)
        staged = lab_data.get("staged_preferences", {})
        vnc_pass = staged.get("vnc_pass") or lab_data.get("credentials", {}).get("vnc_pass") or dynamic_pass

        link_cmd = (
            f'docker exec {instance_id} {link_script} '
            f'"{username}" "{escaped_auth}" "{docker_ip}" "{dynamic_pass}" '
            f'"{lab_priv_key}" "{tunnel_ip}" "{server_pub_key}" '
            f'"{user_email}" "" "{vps_docker_ip}" "{su_pass}" "{vnc_pass}"'
        )
        code, out = self.run(link_cmd)
        if code != 0:
            self._fail_deploy(instance_id, "linkuser.sh failed. Check output above for details.")
            return

        # Capture generated VNC password from linkuser.sh output
        import re
        vnc_match = re.search(r'\[VNC_PASS_RESULT\](.+?)\[/VNC_PASS_RESULT\]', out or "")
        if vnc_match:
            dynamic_pass = vnc_match.group(1).strip()
            self.log(f"Generated VNC password: {dynamic_pass}")

        # Phase: POST-LINK — WireGuard routing & DNS fix inside container
        if tunnel_ip:
            tunnel_subnet = ".".join(tunnel_ip.split(".")[:2]) + ".0.0/16"
            tunnel_gw_internal = ".".join(tunnel_ip.split(".")[:3]) + ".1"
            self.log(f"Applying WireGuard routing and DNS fix for subnet {tunnel_subnet}...")

            # Write fix script to container to avoid shell escaping issues
            fix_script = f"""#!/bin/bash
# Fix AllowedIPs to use full /16 subnet
sed -i 's#AllowedIPs.*#AllowedIPs = {tunnel_subnet}#g' /etc/wireguard/wg0.conf 2>/dev/null || true
wg set wg0 peer {server_pub_key} allowed-ips {tunnel_subnet} 2>/dev/null || true
ip route add {tunnel_subnet} dev wg0 metric 10 2>/dev/null || true

# Add IPv6 localhost entries if missing
grep -q "::1" /etc/hosts || echo "::1 localhost ip6-localhost ip6-loopback" >> /etc/hosts 2>/dev/null || true

# Add VPN domain to hosts for internal access
grep -q "{vpn_domain}" /etc/hosts || echo "{tunnel_gw_internal} {vpn_domain}" >> /etc/hosts 2>/dev/null || true
"""
            import tempfile
            with tempfile.NamedTemporaryFile(mode='w', suffix='.sh', delete=False) as f:
                f.write(fix_script)
                tmp_fix = f.name
            self.run(f"docker cp {tmp_fix} {instance_id}:/tmp/wg_fix.sh")
            os.unlink(tmp_fix)
            self.run(f"docker exec {instance_id} bash /tmp/wg_fix.sh")
            self.run(f"docker exec {instance_id} rm -f /tmp/wg_fix.sh")

        # Phase: TRAEFIK
        self.log("Finalizing Traefik routing...")
        traefik_yaml = self._gen_traefik(instance_id, docker_ip, lab_spec, lab_data, args)
        self.write_traefik(instance_id, traefik_yaml)
        self.log("Traefik configuration written.", "success")

        # Phase: APACHE ROUTES (for cloudflared tunnel routing)
        lab_type = lab_data.get("lab_type", "essentials")
        self.write_apache_routes(instance_id, docker_ip, lab_type)

        # Phase: METADATA
        self.log("Finalizing routing metadata...")
        code_domain = getattr(args, 'vsc_domain', None) or lab_data.get("code_domain") or f"code-{instance_id}.{self.cfg.code_domain}"
        gui_domain = getattr(args, 'gui_domain', None) or lab_data.get("gui_domain") or f"gui-{instance_id}.{self.cfg.code_domain}"
        credentials.update({
            "ssh": f"ssh {username}@{tunnel_ip}",
            "ssh_proxy": f'ssh -o "ProxyCommand=ssh -W %h:%p -i ~/.ssh/id_ed25519 root@127.0.0.1 -p 2222" {username}@{docker_ip}',
            "docker_ip": docker_ip,
            "tunnel_ip": tunnel_ip,
            "port": 22,
            "password": dynamic_pass,
            "su_pass": su_pass,
            "sshKey": ssh_enabled,
            "code_server_url": f"https://{code_domain}",
            "gui_url": f"https://{gui_domain}",
            "vnc_pass": vnc_pass,
            "wg_pubkey": lab_pub_key,
            "wg_privkey": lab_priv_key,
        })

        # Credentials template expansion
        cred_template = lab_spec.get("credentials_template", {})
        if cred_template:
            fmt_args = {
                "username": username,
                "password": dynamic_pass,
                "email": user_email,
                "su_pass": su_pass,
                "vnc_pass": vnc_pass,
            }
            for key, val in cred_template.items():
                if isinstance(val, str):
                    credentials[key] = val.format(**fmt_args)
                else:
                    credentials[key] = val

        # Save to database
        self.db.machine_labs.update_one(
            {"instance_hash": instance_id},
            {"$set": {
                "status": "running",
                "credentials": credentials
            }}
        )

        # Phase: CODE-SERVER MONITOR
        self._ensure_codeserver(instance_id, username)

        # Phase: DONE
        self.log("Deployment Complete. Ready for connections.", "success")
        self.log(f"Access URL: {code_domain}")
        self.log(f"VPN Access: ssh {username}@{tunnel_ip}")
        self.log("[*] reload")

    def _gen_traefik(self, instance_id, docker_ip, lab_spec, lab_data, args=None):
        """Generate Traefik YAML config."""
        services_spec = lab_spec.get("services", {})
        base_domain = self.cfg.code_domain
        db_domain = lab_data.get("code_domain")
        db_gui_domain = lab_data.get("gui_domain")
        vsc_domain = getattr(args, 'vsc_domain', None) if args else None
        gui_domain_arg = getattr(args, 'gui_domain', None) if args else None
        code_domain = vsc_domain or db_domain or f"code-{instance_id}.{base_domain}"
        gui_domain = gui_domain_arg or db_gui_domain or f"gui-{instance_id}.{base_domain}"

        # Extract base domain from code_domain for gui (e.g. "x.tomweb.shop" → "tomweb.shop")
        if db_domain and "." in db_domain:
            parts = db_domain.split(".")
            code_base = f"{parts[-2]}.{parts[-1]}"
        else:
            code_base = base_domain

        routers = ""
        services = ""

        for svc_name, svc_spec in services_spec.items():
            if svc_name == "web":
                continue
            port = svc_spec["port"]

            if svc_name == "code":
                domain = code_domain
            elif svc_name == "gui":
                domain = gui_domain
            else:
                domain = f"{svc_name}-{instance_id}.{base_domain}"

            router_key = f"router-{instance_id}-{svc_name}"
            service_key = f"service-{instance_id}-{svc_name}"

            routers += f"    {router_key}:\n"
            routers += f'      rule: "Host(`{domain}`)"\n'
            routers += f"      service: {service_key}\n"
            routers += f"      entryPoints: [web, websecure]\n"
            routers += f"      priority: 100\n"

            services += f"    {service_key}:\n"
            services += f"      loadBalancer:\n"
            services += f"        servers: [{{url: \"http://{docker_ip}:{port}\"}}]\n"
            # WebSocket support for code-server and gui (KasmVNC)
            if svc_name in ("code", "gui"):
                services += f"        passHostHeader: true\n"
                if svc_name == "code":
                    services += f"        healthCheck:\n"
                    services += f"          path: /\n"
                    services += f"          interval: 10s\n"

        user_domains = lab_data.get("domains", [])
        if lab_data.get("expose_web") and user_domains and "web" in services_spec:
            web_port = services_spec["web"]["port"]
            web_svc = f"service-{instance_id}-web"
            services += f"    {web_svc}:\n"
            services += f"      loadBalancer:\n"
            services += f"        servers: [{{url: \"http://{docker_ip}:{web_port}\"}}]\n"
            for idx, domain in enumerate(user_domains):
                routers += f"    router-{instance_id}-custom-{idx}:\n"
                routers += f'      rule: "Host(`{domain}`)"\n'
                routers += f"      service: {web_svc}\n"
                routers += f"      entryPoints: [web, websecure]\n"
                routers += f"      priority: 100\n"

        for idx, proxy in enumerate(lab_data.get("http_proxies", [])):
            p_port = proxy.get("port")
            p_domain = proxy.get("domain")
            if p_port and p_domain:
                routers += f"    router-{instance_id}-proxy-{idx}:\n"
                routers += f'      rule: "Host(`{p_domain}`)"\n'
                routers += f"      service: service-{instance_id}-proxy-{idx}\n"
                routers += f"      entryPoints: [web, websecure]\n"
                routers += f"      priority: 150\n"
                services += f"    service-{instance_id}-proxy-{idx}:\n"
                services += f"      loadBalancer:\n"
                services += f"        servers: [{{url: \"http://{docker_ip}:{p_port}\"}}]\n"

        return "http:\n  routers:\n" + routers + "\n  services:\n" + services

    # ── Code-Server Idle Monitor ────────────────────────────────

    def _ensure_codeserver(self, instance_id, username):
        """Inject and start code-server idle monitor."""
        monitor_script = f"""#!/bin/bash
USER=$1
IDLE_LIMIT=120

while true; do
    sleep 30
    if ! pgrep -u $USER -f code-server > /dev/null; then
        exit 0
    fi
    HEARTBEAT="/var/labsstorage/home/$USER/.local/share/code-server/heartbeat"
    if [ -f "$HEARTBEAT" ]; then
        LAST_MOD=$(stat -c %Y "$HEARTBEAT")
        NOW=$(date +%s)
        DIFF=$((NOW - LAST_MOD))
        if [ $DIFF -ge $IDLE_LIMIT ]; then
            pkill -u $USER -f code-server
            exit 0
        fi
    fi
done
"""
        b64_script = base64.b64encode(monitor_script.encode()).decode()
        self.run(f"docker exec {instance_id} mkdir -p /var/labsdata/scripts")
        self.run(f"docker exec {instance_id} bash -c 'echo {b64_script} | base64 -d > /var/labsdata/scripts/monitor_codeserver.sh'")
        self.run(f"docker exec {instance_id} chmod +x /var/labsdata/scripts/monitor_codeserver.sh")

        mcode, _ = self.run(f"docker exec {instance_id} pgrep -f monitor_codeserver", capture=True)
        if mcode != 0:
            self.run(f"docker exec -d {instance_id} bash /var/labsdata/scripts/monitor_codeserver.sh {username}")
            self.log("Idle monitor started (2min timeout).", "success")

    # ── Stop ────────────────────────────────────────────────────

    def _stop(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Initiating graceful shutdown for: {instance_id}")

        lab_data = self._get_deploy_data(instance_id)
        if lab_data:
            creds = lab_data.get("credentials", {})
            if not isinstance(creds, dict):
                creds = {}
            tunnel_ip = creds.get("tunnel_ip")
            if tunnel_ip:
                self.log(f"Cleaning host route: {tunnel_ip}")
                self.remove_route(tunnel_ip)

        if self.docker_exists(instance_id):
            self.log("Stopping Docker container...")
            self.run(f"docker stop {instance_id} && docker rm -f {instance_id}")
            self._set_deploy_field(instance_id, "status", "stopped")

            # Release Docker IP back to pool
            docker_ip_col = self.db["docker_ip_registry"] if self.db else None
            if docker_ip_col:
                docker_ip_col.update_one(
                    {"instance_hash": instance_id, "status": "claimed"},
                    {"$set": {"status": "available", "instance_hash": "", "user": "", "claimed_at": 0}}
                )
                self.log("Docker IP released to pool.")

            self.log("Container and process-space cleared.", "success")
        else:
            self.log("No container found, skipping stop.")

        self.remove_traefik(instance_id)
        self.remove_apache_routes(instance_id)
        self.log(f"Lab {instance_id} is now offline. IP remains reserved.", "success")

    # ── Start ───────────────────────────────────────────────────

    def _start(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        if not self.docker_exists(instance_id):
            self.log(f"Container not found: {instance_id}", "error")
            return

        self.log(f"Starting container: {instance_id}")
        self.run(f"docker start {instance_id}")
        self.log("Container started.", "success")

        lab_data = self._get_deploy_data(instance_id)
        if lab_data:
            creds = lab_data.get("credentials", {})
            if not isinstance(creds, dict):
                creds = {}
            tunnel_ip = creds.get("tunnel_ip")
            docker_ip = creds.get("docker_ip")
            lab_pub_key = creds.get("wg_pubkey")
            template_name = lab_data.get("template_name")

            if tunnel_ip and lab_pub_key:
                self.log("Re-applying WireGuard peer...")
                self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
                self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")
                self.log("WireGuard peer registered.", "success")

            if tunnel_ip and docker_ip:
                self.log("Re-applying network routes...")
                bridge = self.detect_bridge()
                self.configure_routing(tunnel_ip, docker_ip, bridge)
                self.log("Routing configured.", "success")

            if template_name:
                tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
                if os.path.exists(tpl_path):
                    with open(tpl_path) as f:
                        lab_spec = json.load(f)
                    yaml = self._gen_traefik(instance_id, docker_ip, lab_spec, lab_data)
                    self.write_traefik(instance_id, yaml)
                    self.log("Traefik configuration written.", "success")

        self._set_deploy_field(instance_id, "status", "running")
        self.log("Lab start sequence complete.", "success")

    # ── Remove ──────────────────────────────────────────────────

    def _remove(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        lab_data = self._get_deploy_data(instance_id)
        username = lab_data.get("username") if lab_data else None

        self._stop(args)

        if username and self.db:
            remaining = self.db.machine_labs.count_documents({
                "username": username,
                "instance_hash": {"$ne": instance_id}
            })
            if remaining == 0:
                self._release_user_quota(username)
                self.log(f"User {username} has no more labs, quota released")

    # ── Info ────────────────────────────────────────────────────

    def _info(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get_deploy_data(instance_id)
        if data:
            import pprint
            pprint.pprint(data)
        else:
            self.log(f"Lab not found: {instance_id}", "error")

    # ── Shell ───────────────────────────────────────────────────

    def _shell(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return
        os.system(f"docker exec -it {instance_id} /bin/bash")

    # ── Sync User ──────────────────────────────────────────────

    def _sync_user(self, args):
        username = args.user
        if not username:
            self.log("Missing --user", "error")
            return

        self.log(f"Syncing permissions for user: {username}...")

        if not self.db:
            self.log("Database connection failed", "error")
            return

        user_keys = list(self.db.ssh_keys.find({"username": username}))
        if not user_keys:
            self.log(f"No SSH keys found for {username}", "warn")
            return

        import hashlib
        user_profile = self.db.users.find_one({"username": username}) if self.db else None
        user_email = user_profile.get("email", username) if user_profile else username
        user_hash = hashlib.md5(user_email.encode()).hexdigest()

        auth_content = "\n".join([k['public_key'] for k in user_keys if 'public_key' in k])

        labs = list(self.db.machine_labs.find({"username": username, "status": "running"}))
        if not labs:
            self.log(f"No running labs found for {username}", "warn")
            return

        self.log(f"Found {len(labs)} running lab(s) for {username}")

        for lab in labs:
            iid = lab.get("instance_hash")
            if not iid:
                continue

            if not self.docker_running(iid):
                continue

            self.log(f"Updating SSH keys in {iid}...")

            import tempfile
            with tempfile.NamedTemporaryFile(mode='w', suffix='.pub', delete=False) as f:
                f.write(auth_content + "\n")
                tmp_key = f.name

            self.run(f"docker exec {iid} mkdir -p /var/labsstorage/home/{user_hash}/.ssh")
            self.run(f"docker cp {tmp_key} {iid}:/var/labsstorage/home/{user_hash}/.ssh/authorized_keys")
            os.unlink(tmp_key)
            self.run(f"docker exec {iid} chmod 700 /var/labsstorage/home/{user_hash}/.ssh")
            self.run(f"docker exec {iid} chmod 600 /var/labsstorage/home/{user_hash}/.ssh/authorized_keys")
            self.run(f"docker exec {iid} chown -R {username} /var/labsstorage/home/{user_hash}/.ssh 2>/dev/null || true")

            self.log(f"SSH keys updated in {iid}", "success")

        self.log(f"Sync complete for {username}", "success")

    # ── Redeploy ──────────────────────────────────────────────

    def _redeploy(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Checking for running instance with ID: {instance_id}")

        lab_data = self._get_deploy_data(instance_id)
        if not lab_data:
            self._fail_deploy(instance_id, f"Lab not found: {instance_id}")
            return

        if self.docker_exists(instance_id):
            self.log(f"An instance with name {instance_id} already exists. Removing and redeploying...")
            self._stop(args)
            time.sleep(2)
        else:
            self.log("No existing instance found. Deploying fresh...")

        self._deploy(args)

    # ── Update ────────────────────────────────────────────────

    def _update(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Updating {instance_id}...")

        lab_data = self._get_deploy_data(instance_id)
        if not lab_data:
            self._fail_deploy(instance_id, f"Lab not found: {instance_id}")
            return

        template_name = lab_data.get("template_name") or lab_data.get("lab_type")
        if not template_name:
            self._fail_deploy(instance_id, "Missing template_name/lab_type in DB record.")
            return
        image = lab_data.get("image", f"{template_name}:lab")

        self.log(f"Pulling latest image: {image}...")
        code, _ = self.run(f"docker pull {image}")
        if code != 0:
            self.log(f"Failed to pull image: {image}", "warn")

        self._stop(args)
        time.sleep(2)
        self._deploy(args)
