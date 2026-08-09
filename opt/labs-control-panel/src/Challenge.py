import os
import json
import time
import secrets
import string
import sys
from src.BaseOrchestrator import BaseOrchestrator

class Challenge(BaseOrchestrator):
    """
    Challenge Manager for CTF-style challenge deployments.
    Handles build, deploy, remove, stop, and start for challenge containers.
    Challenges are lightweight containers accessible only via WireGuard VPN overlay.
    """
    def __init__(self, args, session_hash=None):
        super().__init__(args, session_hash)

    # ─────────────────────────────────────────────
    # BUILD
    # ─────────────────────────────────────────────
    def build(self):
        """Build a challenge Docker image from a lab-template."""
        if len(sys.argv) < 4:
            self.log("Usage: labsctl challenge build <template:tag>", "error")
            self.log("  Example: labsctl challenge build sql_injection:lab", "error")
            return

        image_tag = sys.argv[3]
        template_name = image_tag.split(':')[0]

        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        template_path = os.path.join(templates_dir, template_name)

        if not os.path.exists(template_path):
            self.log(f"Template path not found: {template_path}", "error")
            return

        self.log(f"Building challenge image {image_tag}...", "info", "build")
        build_cmd = self.config.get('docker_build', "docker build -t {image_tag} {path}")

        # Enable BuildKit for performance
        build_cmd = build_cmd.replace("docker build", "DOCKER_BUILDKIT=1 docker build")

        if self.args.hasFlag('no-cache'):
            build_cmd = build_cmd.replace("docker build", "docker build --no-cache")

        mapping = {"image_tag": image_tag, "path": template_path}
        exit_status, _ = self.run(build_cmd.format(**mapping))

        if exit_status == 0:
            self.log(f"Challenge image {image_tag} built successfully.", "success", "done")
            self.run("docker image prune -f")
        else:
            self.log(f"Build failed with exit code {exit_status}", "error", "done")

    # ─────────────────────────────────────────────
    # DEPLOY
    # ─────────────────────────────────────────────
    def deploy(self):
        """Deploy a challenge container with WireGuard VPN networking."""
        self.log("Challenge deployment initiated...", "info", "init")

        # 0. Verify Docker Network exists
        docker_network = self.config.get('docker_network_name')
        if not docker_network:
            self.log("FATAL: 'docker_network_name' not set in config.", "error", "init")
            return
        code, _ = self.run(f"docker network inspect {docker_network} > /dev/null 2>&1", capture=True)
        if code != 0:
            self.log(f"FATAL: Docker network {docker_network} not found. Is docker-compose up?", "error", "init")
            return

        instance_id = self.session_hash
        if not instance_id:
            self.log("FATAL: --hash is required. Cannot deploy without a valid instance hash.", "error", "init")
            return

        username = self.args.getFlagValue('user')
        if not username:
            self.log("FATAL: --user is required.", "error", "init")
            return

        challenge_id = self.args.getFlagValue('challenge')
        if not challenge_id:
            self.log("FATAL: --challenge is required. Example: --challenge=sql_injection", "error", "init")
            return

        self.log(f"Deploying challenge '{challenge_id}' for user: {username}", "info", "init")
        self.log(f"Instance ID: {instance_id}", "info", "init")

        if self.db is None:
            self.log("Database connection failed. Aborting.", "error", "init")
            return

        # 1. Load Challenge Template Config
        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        template_config_path = os.path.join(templates_dir, challenge_id, 'config.json')

        if not os.path.exists(template_config_path):
            self.log(f"Challenge template config missing: {template_config_path}", "error", "init")
            return

        with open(template_config_path, 'r') as f:
            challenge_spec = json.load(f)

        # 2. Fetch or create challenge assignment in DB
        challenge_data = self.db.challenge_instances.find_one({"instance_hash": instance_id})

        if not challenge_data:
            self.log("No existing assignment found. Creating new challenge assignment...", "info", "init")

            # Allocate an IP from the VPN network pool
            vpn_db_name = self.env.get('vpn_db', 'tom_labs_vpn')
            vpn_db = self.mongo_client[vpn_db_name]

            # Find next available IP in the challenge IP range (172.30.1.x)
            challenge_ip = self._allocate_challenge_ip(vpn_db, username, challenge_id)
            if not challenge_ip:
                self.log("FATAL: Could not allocate IP for challenge.", "error", "init")
                return

            # Generate a unique flag for this user's challenge instance
            flag = f"CTF{{{secrets.token_hex(16)}}}"

            challenge_data = {
                "instance_hash": instance_id,
                "username": username,
                "challenge_id": challenge_id,
                "internal_ip": challenge_ip,
                "status": "deploying",
                "flag": flag,
                "credentials": {
                    "docker_ip": f"{self.config.get('docker_ip')}{challenge_ip.split('.')[3]}",
                    "tunnel_ip": challenge_ip,
                    "access_url": f"http://{challenge_ip}"
                },
                "created_at": time.time(),
                "updated_at": time.time()
            }
            self.db.challenge_instances.insert_one(challenge_data)
            self.log(f"Challenge assigned. IP: {challenge_ip}", "success", "network")
        else:
            self.log("Reusing existing challenge assignment.", "info", "init")
            challenge_ip = challenge_data['internal_ip']

        # 3. Calculate Docker and Tunnel IPs
        ip_parts = challenge_ip.split('.')
        last_octet = ip_parts[3]

        docker_prefix = self.config.get('docker_ip')
        if not docker_prefix:
            self.log("FATAL: 'docker_ip' not set in config.", "error", "init")
            return
        tunnel_prefix = self.config.get('tunnel_ip')
        if not tunnel_prefix:
            self.log("FATAL: 'tunnel_ip' not set in config.", "error", "init")
            return

        docker_ip = f"{docker_prefix}{last_octet}"
        tunnel_ip = challenge_ip  # Already a tunnel IP (172.30.x.x)

        self.log(f"Docker IP (eth0): {docker_ip}", "info", "network")
        self.log(f"Tunnel IP (wg0):  {tunnel_ip}", "info", "network")

        # 4. Determine VPS container IP for WireGuard endpoint
        orchestrator = self.config.get('orchestrator_container')
        if not orchestrator:
            self.log("FATAL: 'orchestrator_container' not set in config.", "error", "init")
            return
        code, vps_docker_ip = self.run(
            f"docker inspect {orchestrator} --format '{{{{.NetworkSettings.Networks.{docker_network}.IPAddress}}}}' 2>/dev/null", capture=True
        )
        if not vps_docker_ip:
            vps_docker_ip = f"{docker_prefix}2"
            self.log(f"WARNING: Using fallback VPS IP: {vps_docker_ip}", "warn", "network")

        # 5. Cleanup existing container
        self.log("Checking for conflicting containers...", "info", "cleanup")
        container_name = f"ctf-{instance_id}"
        if self.cleanup_container(container_name, docker_network):
            self.log("Old challenge container removed.", "success", "cleanup")

        # 6. Generate the unique flag file content
        flag = challenge_data.get('flag', f"CTF{{{secrets.token_hex(16)}}}")

        # 7. Run Challenge Container (lightweight, no WG inside the container)
        res = challenge_spec.get('resources', {})
        mem = res.get('memory', '128m')
        cpu = res.get('cpus', '0.1')
        hostname = challenge_spec.get('network', {}).get('hostname', 'ctf-challenge')

        vps_docker_ip = f"{self.config.get('tunnel_ip')}1"
        run_cmd = (
            f"docker run --detach --name {container_name} "
            f"--memory='{mem}' --cpus='{cpu}' "
            f"--network {docker_network} --ip {docker_ip} "
            f"-e VPS_DOCKER_IP={vps_docker_ip} "
            f"--hostname {hostname} "
            f"{challenge_id}:lab"
        )

        self.log(f"Provisioning {challenge_spec.get('lab_name', challenge_id)}: {mem} RAM, {cpu} CPU", "info", "container")
        code, _ = self.run(run_cmd)

        if code != 0:
            self.log("Container failed to start.", "error", "container")
            return

        # 8. Wait for container initialization
        self.log("Waiting for container to start...", "info", "container")
        for _ in range(10):
            if self.docker.is_container_running(container_name):
                break
            time.sleep(0.5)

        # 9. Inject the unique flag into the container
        self.log("Injecting unique flag into challenge...", "info", "configure")
        self.run(f"docker exec {container_name} bash -c 'echo \"{flag}\" > /var/www/html/flag.txt'")
        self.run(f"docker exec {container_name} chown www-data:www-data /var/www/html/flag.txt")

        # 10. Configure Host-Side Routing (WireGuard → Docker)
        self.log("Configuring WireGuard routing...", "info", "routing")
        bridge_id = self.detect_bridge(docker_network)
        self.configure_routing(tunnel_ip, docker_ip, bridge_id, dnat=True)
        self.log("Routing and firewall configured.", "success", "routing")

        # 10.5 Find duration from challenges.json
        duration_minutes = 15
        try:
            challenges_json_path = '/var/www/labs/htdocs/src/config/challenges.json'
            if os.path.exists(challenges_json_path):
                with open(challenges_json_path, 'r') as f:
                    challenges_list = json.load(f)
                    for item in challenges_list:
                        if item.get('lab_id') == challenge_id or item.get('lab_id') == challenge_id.replace('_', '-'):
                            # Find difficulty from tags
                            difficulty = 'easy'
                            tags = item.get('tags', [])
                            if isinstance(tags, list):
                                for tag in tags:
                                    if isinstance(tag, dict):
                                        t_text = str(tag.get('text', '')).lower()
                                        if t_text in ['easy', 'medium', 'hard']:
                                            difficulty = t_text
                                            break
                            duration_map = {
                                'easy': 15,
                                'medium': 30,
                                'hard': 45
                            }
                            duration_minutes = int(item.get('duration', duration_map.get(difficulty, 15)))
                            break
        except Exception as e:
            self.log(f"Error reading challenges.json for duration: {e}", "warn")

        # 11. Update Database
        self.db.challenge_instances.update_one(
            {"instance_hash": instance_id},
            {"$set": {
                "status": "running",
                "container_name": container_name,
                "credentials": {
                    "docker_ip": docker_ip,
                    "tunnel_ip": tunnel_ip,
                    "access_url": f"http://{tunnel_ip}"
                },
                "mission_started": False,
                "duration": duration_minutes,
                "expires_at": time.time() + (duration_minutes * 60),
                "created_at": time.time(),
                "updated_at": time.time()
            }}
        )

        self.log("Challenge Deployment Complete!", "success", "done")
        self.log(f"  Challenge:  {challenge_spec.get('lab_name', challenge_id)}", "info", "done")
        self.log(f"  VPN Access: http://{tunnel_ip}", "info", "done")
        self.log(f"  Docker IP:  {docker_ip} (internal)", "info", "done")
        self.log(f"  Container:  {container_name}", "info", "done")

    # ─────────────────────────────────────────────
    # STOP
    # ─────────────────────────────────────────────
    def stop(self):
        """Stop a challenge container and clean up runtime networking."""
        instance_id = self.session_hash
        if not instance_id:
            self.log("FATAL: --hash is required.", "error", "init")
            return

        container_name = f"ctf-{instance_id}"
        self.log(f"Stopping challenge: {container_name}", "info", "init")

        # Fetch metadata
        challenge_data = self.db.challenge_instances.find_one({"instance_hash": instance_id}) if self.db else None

        if challenge_data:
            tunnel_ip = challenge_data.get('credentials', {}).get('tunnel_ip') or challenge_data.get('tunnel_ip')
            docker_ip = challenge_data.get('credentials', {}).get('docker_ip')
            if tunnel_ip:
                self.log(f"Cleaning host route: {tunnel_ip}", "info", "routing")
                self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")
                if docker_ip:
                    self.run(f"iptables -t nat -D PREROUTING -d {tunnel_ip} -j DNAT --to-destination {docker_ip} 2>/dev/null || true")
                    self.run(f"iptables -t nat -D OUTPUT -d {tunnel_ip} -j DNAT --to-destination {docker_ip} 2>/dev/null || true")

        if self.docker.container_exists(container_name):
            self.run(f"docker rm -f {container_name}")

            if self.db:
                self.db.challenge_instances.update_one(
                    {"instance_hash": instance_id},
                    {"$set": {"status": "stopped", "updated_at": time.time()}}
                )
            self.log("Challenge container stopped and removed.", "success", "done")
        else:
            self.log(f"Container {container_name} not found.", "warn", "done")

    # ─────────────────────────────────────────────
    # START
    # ─────────────────────────────────────────────
    def start(self):
        """Start a stopped challenge container."""
        instance_id = self.session_hash
        if not instance_id:
            self.log("FATAL: --hash is required.", "error", "init")
            return

        container_name = f"ctf-{instance_id}"
        self.log(f"Starting challenge: {container_name}", "info", "init")

        if self.docker.container_exists(container_name):
            self.run(f"docker start {container_name}")
            self.log("Challenge container started.", "success", "container")
            
            # Re-apply routing rules on start
            challenge_data = self.db.challenge_instances.find_one({"instance_hash": instance_id}) if self.db else None
            if challenge_data:
                tunnel_ip = challenge_data.get('credentials', {}).get('tunnel_ip')
                docker_ip = challenge_data.get('credentials', {}).get('docker_ip')
                if tunnel_ip and docker_ip:
                    self.log("Re-applying WireGuard routing...", "info", "routing")
                    docker_network = self.config.get('docker_network_name')
                    if not docker_network:
                        self.log("FATAL: 'docker_network_name' not set in config.", "error", "network")
                        return
                    bridge_id = self.detect_bridge(docker_network)
                    self.configure_routing(tunnel_ip, docker_ip, bridge_id, dnat=True)

            if self.db:
                self.db.challenge_instances.update_one(
                    {"instance_hash": instance_id},
                    {"$set": {"status": "running", "updated_at": time.time()}}
                )
            self.log("Challenge start sequence complete.", "success", "done")
        else:
            self.log(f"Container {container_name} not found. Deploy first.", "error", "init")

    # ─────────────────────────────────────────────
    # REMOVE
    # ─────────────────────────────────────────────
    def remove(self):
        """Fully remove a challenge container and its DB record."""
        instance_id = self.session_hash
        if not instance_id:
            self.log("FATAL: --hash is required.", "error", "init")
            return

        self.stop()

        if self.db:
            result = self.db.challenge_instances.delete_one({"instance_hash": instance_id})
            if result.deleted_count > 0:
                self.log("Challenge assignment removed from database.", "success", "done")
            else:
                self.log("No challenge record found in database.", "warn", "done")

        self.log("Challenge fully removed.", "success", "done")

    # ─────────────────────────────────────────────
    # HELPERS
    # ─────────────────────────────────────────────
    def _allocate_challenge_ip(self, vpn_db, username, challenge_id):
        """
        Allocate a unique IP address for a challenge from the VPN network pool.
        Challenge IPs use the 10.20.0.x range to separate them from device VPN IPs (172.30.0.x).
        """
        tunnel_prefix = self.config.get('tunnel_ip')

        # Use a challenge-specific sub-range: 10.20.0.x (start at .10 to leave room)
        # Check existing allocations to find the next free IP
        existing = list(vpn_db.networks.find({
            "service_type": "challenge",
            "challenge_id": challenge_id
        }))

        used_octets = set()
        for alloc in existing:
            ip = alloc.get('ip_addr', '')
            if ip:
                parts = ip.split('.')
                if len(parts) == 4:
                    used_octets.add(int(parts[3]))

        # Find next available IP in range 10-254
        next_octet = None
        for candidate in range(10, 255):
            if candidate not in used_octets:
                next_octet = candidate
                break

        if next_octet is None:
            self.log("No IPs available in challenge pool!", "error", "network")
            return None

        # Build the challenge tunnel IP: 10.20.0.<octet>
        challenge_ip = f"10.20.0.{next_octet}"

        # Register in the VPN network pool
        challenge_user = self.db.users.find_one({"username": username}) if self.db else None
        challenge_email = challenge_user.get('email', username) if challenge_user else username
        vpn_db.networks.insert_one({
            "email": challenge_email,
            "ip_addr": challenge_ip,
            "service_type": "challenge",
            "challenge_id": challenge_id,
            "allocated": True,
            "created_at": time.time()
        })

        self.log(f"Allocated challenge IP: {challenge_ip}", "success", "network")
        return challenge_ip
