import os
import json
import time
import secrets
import string
import subprocess
from src.router import Command

BASE_DIR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.realpath(__file__))))


class LabCmd(Command):
    """labsctl lab — legacy deployed_labs operations."""

    name = "lab"
    description = "Base template operations (system only)"
    usage = "labsctl lab <build|deploy|stop|start|remove|info|generate-keys> [options]"

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
        }

    def _get_deploy_data(self, instance_id):
        if self.db is not None:
            return self.db.deployed_labs.find_one({"instance_hash": instance_id})
        return None

    def _set_deploy_field(self, instance_id, field, value):
        if self.db is not None:
            self.db.deployed_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": {field: value}}
            )

    def _fail_deploy(self, instance_id, msg):
        self.log(msg, "error")
        self._set_deploy_field(instance_id, "status", "error")

    # ── Generate SSH Host Keys ───────────────────────────────────

    def _generate_keys(self, args):
        """Generate fixed SSH host keys for templates."""
        target = args.get(1)
        templates_dir = self.cfg.templates_dir

        # Use local path if production path not writable
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

            created = []
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
                    created.append(key_type)
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

        lab_data = self._get_deploy_data(instance_id)
        if not lab_data:
            self._fail_deploy(instance_id, f"Lab not found: {instance_id}")
            return

        if not username:
            username = lab_data.get("username")
        if not username:
            self._fail_deploy(instance_id, "Missing --user and no user in DB")
            return

        template_name = lab_data.get("lab_type", "essentials")
        self.log(f"Deploying {template_name} for {username}...")

        # Load template config
        tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
        if not os.path.exists(tpl_path):
            self._fail_deploy(instance_id, f"Template config missing: {tpl_path}")
            return

        with open(tpl_path) as f:
            lab_spec = json.load(f)

        # IPs
        base_ip = lab_data.get("internal_ip", "")
        last_octet = base_ip.split(".")[-1] if base_ip else "10"
        docker_ip = f"{self.cfg.docker_ip}{last_octet}"
        tunnel_ip = f"{self.cfg.tunnel_ip}{last_octet}"

        # Resources
        res = lab_spec.get("resources", {})
        mem = res.get("memory", "512m")
        cpu = res.get("cpus", "0.2")
        mount_target = lab_spec.get("storage", {}).get("mount_target", "/home/{user}").replace("{user}", username)
        storage_path = lab_data.get("storage_path", "")

        # Cleanup existing
        self.log("Checking for existing containers...")
        self.docker_stop(instance_id)

        # Storage
        if storage_path.startswith("/"):
            os.makedirs(storage_path, exist_ok=True)

        # WireGuard
        credentials = lab_data.get("credentials", {})
        lab_pub_key = credentials.get("wg_pubkey")
        lab_priv_key = credentials.get("wg_privkey")

        if not lab_priv_key or not lab_pub_key:
            self.log("Generating WireGuard keys...")
            wg_script = os.path.join(self.cfg.templates_dir, template_name, "Data/scripts/wgconfig.py")
            try:
                code, wg_out = self.run(f"python3 {wg_script} {tunnel_ip}", capture=True)
                lab_priv_key, lab_pub_key = wg_out.split("|")
            except Exception:
                self._fail_deploy(instance_id, "WireGuard key generation failed")
                return
        else:
            self.log("Reusing existing WireGuard keys...")
            self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
            self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

        # Docker run
        self.log(f"Starting container: {mem} RAM, {cpu} CPU")
        docker_network = self.cfg.docker_network
        host_name = f"{lab_spec.get('network', {}).get('hostname', 'essentials')}.{instance_id}.{self.cfg.code_domain}"

        docker_cmd = self.cfg.get("docker_run", "")
        mapping = {
            "lab_name": instance_id,
            "memory": mem,
            "cpus": cpu,
            "storage": storage_path,
            "mount_target": mount_target,
            "user": username,
            "image": lab_data.get("image", f"{template_name}:lab"),
            "ip": docker_ip,
            "vps_docker_ip": docker_ip,
            "tunnel_gw": f"{self.cfg.tunnel_ip}1",
            "vpn_domain": self.cfg.vpn_domain,
            "host_name": host_name,
            "network_name": docker_network,
        }

        if "--add-host" not in docker_cmd:
            docker_cmd = docker_cmd.replace(
                "--cap-add=NET_ADMIN",
                f"--add-host {self.cfg.vpn_domain}:{mapping['tunnel_gw']} --cap-add=NET_ADMIN"
            )

        from src.DockerHelper import DockerHelper
        docker = DockerHelper()
        result = docker.run_command(docker_cmd, mapping)
        if not result:
            self._fail_deploy(instance_id, "Container failed to start")
            return

        # Wait for container
        for _ in range(10):
            if self.docker_running(instance_id):
                break
            time.sleep(0.5)

        # Routing
        self.log("Configuring routing...")
        bridge = self.detect_bridge(docker_network)
        self.configure_routing(tunnel_ip, docker_ip, bridge)

        # Traefik
        traefik_yaml = self._gen_traefik(instance_id, docker_ip, lab_spec, lab_data)
        self.write_traefik(instance_id, traefik_yaml)

        # Credentials
        code_domain = args.flag("vsc_domain") or lab_data.get("code_domain") or f"{instance_id}.{self.cfg.code_domain}"
        credentials.update({
            "ssh": f"ssh {username}@{tunnel_ip}",
            "docker_ip": docker_ip,
            "tunnel_ip": tunnel_ip,
            "code_server_url": f"https://{code_domain}",
            "wg_pubkey": lab_pub_key,
            "wg_privkey": lab_priv_key,
        })

        self._set_deploy_fields(instance_id, {"status": "running", "credentials": credentials})
        self.log("Deployment complete.", "success")
        self.log(f"Access: https://{code_domain}")

    def _set_deploy_fields(self, instance_id, fields):
        if self.db is not None:
            self.db.deployed_labs.update_one(
                {"instance_hash": instance_id},
                {"$set": fields}
            )

    def _gen_traefik(self, instance_id, docker_ip, lab_spec, lab_data):
        """Generate Traefik YAML config."""
        services_spec = lab_spec.get("services", {})
        routers = ""
        services = ""

        for svc_name, svc_spec in services_spec.items():
            port = svc_spec["port"]
            domain = f"{svc_name}-{instance_id}.{self.cfg.code_domain}"

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

        # Custom domains
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

        # HTTP proxies
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

    # ── Stop ────────────────────────────────────────────────────

    def _stop(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Stopping {instance_id}...")
        lab_data = self._get_deploy_data(instance_id)
        if lab_data:
            tunnel_ip = lab_data.get("credentials", {}).get("tunnel_ip")
            if tunnel_ip:
                self.remove_route(tunnel_ip)

        self.docker_stop(instance_id)
        self._set_deploy_field(instance_id, "status", "stopped")
        self.remove_traefik(instance_id)
        self.log("Lab stopped.", "success")

    # ── Start ───────────────────────────────────────────────────

    def _start(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        if not self.docker_exists(instance_id):
            self.log(f"Container not found: {instance_id}", "error")
            return

        self.log(f"Starting {instance_id}...")
        self.run(f"docker start {instance_id}")

        lab_data = self._get_deploy_data(instance_id)
        if lab_data:
            creds = lab_data.get("credentials", {})
            tunnel_ip = creds.get("tunnel_ip")
            docker_ip = creds.get("docker_ip")
            lab_pub_key = creds.get("wg_pubkey")
            template_name = lab_data.get("lab_type")

            if tunnel_ip and lab_pub_key:
                self.log("Re-applying WireGuard peer...")
                self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
                self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

            if tunnel_ip and docker_ip:
                self.log("Re-applying routing...")
                bridge = self.detect_bridge()
                self.configure_routing(tunnel_ip, docker_ip, bridge)

            if template_name:
                tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
                if os.path.exists(tpl_path):
                    with open(tpl_path) as f:
                        lab_spec = json.load(f)
                    yaml = self._gen_traefik(instance_id, docker_ip, lab_spec, lab_data)
                    self.write_traefik(instance_id, yaml)

        self._set_deploy_field(instance_id, "status", "running")
        self.log("Lab started.", "success")

    # ── Remove ──────────────────────────────────────────────────

    def _remove(self, args):
        self._stop(args)

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
