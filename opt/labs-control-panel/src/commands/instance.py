import os
import time
import tempfile
import shutil
from src.router import Command


class InstanceCmd(Command):
    """labsctl instance — instances collection operations."""

    name = "instance"
    description = "Instance operations (instances collection)"
    usage = "labsctl instance <build|deploy|stop|start|remove|status> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "build":  (self._build,  "Build instance image",  "labsctl instance build --hash=HASH"),
            "deploy": (self._deploy, "Deploy instance",       "labsctl instance deploy --hash=HASH"),
            "stop":   (self._stop,   "Stop instance",         "labsctl instance stop --hash=HASH"),
            "start":  (self._start,  "Start instance",        "labsctl instance start --hash=HASH"),
            "remove": (self._remove, "Remove instance",       "labsctl instance remove --hash=HASH"),
            "status": (self._status, "Show instance status",  "labsctl instance status --hash=HASH"),
        }

    def _inst_col(self):
        idb = self.instances_db()
        return idb.instances if idb else None

    def _get(self, instance_id):
        col = self._inst_col()
        return col.find_one({"instance_hash": instance_id}) if col else None

    def _set_status(self, instance_id, status):
        col = self._inst_col()
        if col:
            col.update_one(
                {"instance_hash": instance_id},
                {"$set": {"status": status, "updated_at": time.time()}}
            )

    def _set_deploy_field(self, instance_id, field, value):
        col = self._inst_col()
        if col:
            col.update_one(
                {"instance_hash": instance_id},
                {"$set": {f"deploy.{field}": value, "updated_at": time.time()}}
            )

    def _resolve_template(self, data):
        for field in ["template", "lab_type", "type"]:
            name = data.get(field, "")
            if name and os.path.isdir(os.path.join(self.cfg.templates_dir, name)):
                return name
        return "essentials"

    # ── Build ───────────────────────────────────────────────────

    def _build(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        template_name = self._resolve_template(data)
        image_tag = f"instance_{instance_id}:latest"
        self.log(f"Building {image_tag} from {template_name}...")

        self._set_status(instance_id, "building")

        build_dir = tempfile.mkdtemp(prefix=f"build_{instance_id[:8]}_")
        try:
            # Copy base template
            src = os.path.join(self.cfg.templates_dir, template_name)
            if not os.path.isdir(src):
                self.log(f"Template not found: {src}", "error")
                self._set_status(instance_id, "error")
                return

            for item in os.listdir(src):
                s = os.path.join(src, item)
                d = os.path.join(build_dir, item)
                if os.path.isdir(s):
                    shutil.copytree(s, d)
                else:
                    shutil.copy2(s, d)

            # Overlay user files from DB
            files_db = self.mongo_client["tom_labs_files_db"] if self.mongo_client else None
            if files_db:
                user_doc = files_db.files.find_one({"instance_id": instance_id})
                if user_doc:
                    count = 0
                    for fpath, fdata in user_doc.get("files", {}).items():
                        if isinstance(fdata, dict) and fdata.get("is_dir"):
                            continue
                        content = fdata.get("content", "") if isinstance(fdata, dict) else ""
                        if not content:
                            continue
                        content = content.replace("\r\n", "\n")
                        full = os.path.join(build_dir, fpath)
                        os.makedirs(os.path.dirname(full), exist_ok=True)
                        with open(full, "w") as f:
                            f.write(content)
                        count += 1
                    self.log(f"Overlaid {count} user files")

            # Docker build
            cmd = "DOCKER_BUILDKIT=1 docker build"
            if args.has("no-cache"):
                cmd += " --no-cache"
            cmd += f" -t {image_tag} {build_dir}"

            code, _ = self.run(cmd)
            if code == 0:
                self.log(f"Image built: {image_tag}", "success")
                self._set_status(instance_id, "built")
                # Store build info
                col = self._inst_col()
                if col:
                    col.update_one(
                        {"instance_hash": instance_id},
                        {"$set": {"build": {
                            "image_tag": image_tag,
                            "built_at": time.time(),
                            "template": template_name,
                        }}}
                    )
                self.run("docker image prune -f")
            else:
                self.log(f"Build failed (exit {code})", "error")
                self._set_status(instance_id, "error")
        finally:
            shutil.rmtree(build_dir, ignore_errors=True)

    # ── Deploy ──────────────────────────────────────────────────

    def _deploy(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        deploy_data = data.get("deploy", {})
        if not deploy_data:
            self.log("No deploy data. Run deploy_instance.php first.", "error")
            return

        # Check for pre-built image
        image_tag = f"instance_{instance_id}:latest"
        if self.docker_image_exists(image_tag):
            self.log(f"Using pre-built image: {image_tag}")
            self._set_deploy_field(instance_id, "image", image_tag)

        self._set_status(instance_id, "deploying")
        self._set_deploy_field(instance_id, "status", "deploying")

        # Delegate to LabCmd deploy logic
        from src.commands.lab import LabCmd
        lab_cmd = LabCmd(None)
        lab_cmd.db = self.db
        lab_cmd.base = self
        lab_cmd.cfg = self.cfg

        # Build args for lab deploy
        lab_args = type("Args", (), {
            "hash": instance_id,
            "user": data.get("username"),
            "flag": lambda self, n, d=None: args.flag(n, d),
            "has": lambda self, n: args.has(n),
            "get": lambda self, n=0: None,
        })()

        lab_cmd._deploy(lab_args)

        # Update final status
        final = self._get(instance_id)
        deploy_status = final.get("deploy", {}).get("status", "error") if final else "error"
        final_status = "running" if deploy_status == "running" else "error"
        self._set_status(instance_id, final_status)
        self._set_deploy_field(instance_id, "status", final_status)

    # ── Stop ────────────────────────────────────────────────────

    def _stop(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Stopping instance {instance_id}...")
        self._set_status(instance_id, "stopping")
        self._set_deploy_field(instance_id, "status", "stopping")

        data = self._get(instance_id)
        if data:
            tunnel_ip = data.get("deploy", {}).get("credentials", {}).get("tunnel_ip")
            if tunnel_ip:
                self.remove_route(tunnel_ip)

        self.docker_stop(instance_id)
        self._set_status(instance_id, "stopped")
        self._set_deploy_field(instance_id, "status", "stopped")
        self.remove_traefik(instance_id)
        self.log("Instance stopped.", "success")

    # ── Start ───────────────────────────────────────────────────

    def _start(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Starting instance {instance_id}...")
        self._set_status(instance_id, "starting")
        self._set_deploy_field(instance_id, "status", "starting")

        if not self.docker_exists(instance_id):
            self.log(f"Container not found: {instance_id}", "error")
            self._set_status(instance_id, "error")
            return

        self.run(f"docker start {instance_id}")

        data = self._get(instance_id)
        if data:
            creds = data.get("deploy", {}).get("credentials", {})
            tunnel_ip = creds.get("tunnel_ip")
            docker_ip = creds.get("docker_ip")
            lab_pub_key = creds.get("wg_pubkey")

            if tunnel_ip and lab_pub_key:
                self.log("Re-applying WireGuard peer...")
                self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
                self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

            if tunnel_ip and docker_ip:
                self.log("Re-applying routing...")
                bridge = self.detect_bridge()
                self.configure_routing(tunnel_ip, docker_ip, bridge)

        self._set_status(instance_id, "running")
        self._set_deploy_field(instance_id, "status", "running")
        self.log("Instance started.", "success")

    # ── Remove ──────────────────────────────────────────────────

    def _remove(self, args):
        self._stop(args)

    # ── Status ──────────────────────────────────────────────────

    def _status(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        status = data.get("status", "unknown")
        deploy = data.get("deploy", {})
        creds = deploy.get("credentials", {})

        print(f"\n  Instance: {instance_id}")
        print(f"  Status:   {status}")
        print(f"  Type:     {data.get('type', 'unknown')}")
        print(f"  Template: {data.get('template', 'unknown')}")
        if creds.get("tunnel_ip"):
            print(f"  VPN IP:   {creds['tunnel_ip']}")
        if creds.get("docker_ip"):
            print(f"  Docker IP:{creds['docker_ip']}")
        if creds.get("code_server_url"):
            print(f"  Code URL: {creds['code_server_url']}")
        print()
