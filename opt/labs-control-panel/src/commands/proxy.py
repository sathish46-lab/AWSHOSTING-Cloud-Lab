import os
import json
from src.router import Command


class ProxyCmd(Command):
    """labsctl proxy — Traefik proxy management."""

    name = "proxy"
    description = "Traefik proxy management"
    usage = "labsctl proxy <generate|apply|remove|list> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "generate": (self._generate, "Generate traefik YAML",    "labsctl proxy generate --hash=HASH"),
            "apply":    (self._apply,    "Write + reload traefik",   "labsctl proxy apply --hash=HASH"),
            "remove":   (self._remove,   "Delete traefik config",    "labsctl proxy remove --hash=HASH"),
            "list":     (self._list,     "List active proxy routes",  "labsctl proxy list"),
        }

    def _list(self, args):
        conf_dir = self.cfg.traefik_conf_dir
        if not os.path.exists(conf_dir):
            self.log(f"Traefik config dir not found: {conf_dir}", "error")
            return

        files = [f for f in os.listdir(conf_dir) if f.endswith(".yml")]
        print(f"\n  Active Proxy Routes ({len(files)}):")
        print("  " + "-" * 60)

        if not files:
            print("  No proxy configurations found.")
        else:
            for f in sorted(files):
                instance_id = f.replace(".yml", "")
                path = os.path.join(conf_dir, f)
                size = os.path.getsize(path)
                print(f"  {instance_id:<40} {size:>6} bytes")

        print("  " + "-" * 60 + "\n")

    def _generate(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        # Get lab data from DB — try machine_labs first, fall back to machine_labs
        lab_data = None
        if self.db is not None:
            lab_data = self.db.machine_labs.find_one({"deploy.instance_hash": instance_id})
            if lab_data:
                lab_data = lab_data.get('deploy', {})
            else:
                lab_data = self.db.machine_labs.find_one({"instance_hash": instance_id})
                if lab_data:
                    lab_data = lab_data.get('deploy', lab_data)
        if not lab_data:
            self.log(f"Lab not found: {instance_id}", "error")
            return

        docker_ip = lab_data.get("credentials", {})
        if not isinstance(docker_ip, dict):
            docker_ip = {}
        docker_ip = docker_ip.get("docker_ip")
        if not docker_ip:
            self.log("No docker_ip in credentials", "error")
            return

        template_name = lab_data.get("lab_type", "essentials")
        tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
        if not os.path.exists(tpl_path):
            self.log(f"Template config not found: {tpl_path}", "error")
            return

        with open(tpl_path) as f:
            lab_spec = json.load(f)

        # Generate YAML
        from src.commands.lab import LabCmd
        lab_cmd = LabCmd(None)
        lab_cmd.db = self.db
        lab_cmd.cfg = self.cfg
        yaml = lab_cmd._gen_traefik(instance_id, docker_ip, lab_spec, lab_data)

        print(f"\n  Generated Traefik YAML for {instance_id}:")
        print("  " + "-" * 60)
        for line in yaml.split("\n"):
            print(f"  {line}")
        print("  " + "-" * 60 + "\n")

    def _apply(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        lab_data = None
        if self.db is not None:
            lab_data = self.db.machine_labs.find_one({"deploy.instance_hash": instance_id})
            if lab_data:
                lab_data = lab_data.get('deploy', {})
            else:
                lab_data = self.db.machine_labs.find_one({"instance_hash": instance_id})
                if lab_data:
                    lab_data = lab_data.get('deploy', lab_data)
        if not lab_data:
            self.log(f"Lab not found: {instance_id}", "error")
            return

        creds = lab_data.get("credentials", {})
        if not isinstance(creds, dict):
            creds = {}
        docker_ip = creds.get("docker_ip")
        if not docker_ip:
            self.log("No docker_ip in credentials", "error")
            return

        template_name = lab_data.get("lab_type", "essentials")
        tpl_path = os.path.join(self.cfg.templates_dir, template_name, "config.json")
        if not os.path.exists(tpl_path):
            self.log(f"Template config not found: {tpl_path}", "error")
            return

        with open(tpl_path) as f:
            lab_spec = json.load(f)

        from src.commands.lab import LabCmd
        lab_cmd = LabCmd(None)
        lab_cmd.db = self.db
        lab_cmd.cfg = self.cfg
        yaml = lab_cmd._gen_traefik(instance_id, docker_ip, lab_spec, lab_data)
        self.write_traefik(instance_id, yaml)
        self.log(f"Traefik applied for {instance_id}", "success")

    def _remove(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return
        self.remove_traefik(instance_id)
