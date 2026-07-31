import os
from datetime import datetime
from src.router import Command


class SystemCmd(Command):
    """labsctl system — system health and maintenance."""

    name = "system"
    description = "System health, workers, cleanup"
    usage = "labsctl system <status|workers|images|db|clean> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "status":  (self._status,  "Overall health check",  "labsctl system status"),
            "workers": (self._workers, "Check active workers",  "labsctl system workers"),
            "images":  (self._images,  "List built images",     "labsctl system images"),
            "db":      (self._db,      "Check MongoDB",         "labsctl system db"),
            "clean":   (self._clean,   "Full cleanup",          "labsctl system clean"),
        }

    def _status(self, args):
        print("\n  System Status")
        print("  " + "=" * 60)

        # MongoDB
        if self.db is not None:
            try:
                self.mongo_client.admin.command("ping")
                print("  MongoDB:     ✓ connected")
            except Exception:
                print("  MongoDB:     ✗ disconnected")
        else:
            print("  MongoDB:     ✗ not configured")

        # Docker
        code, _ = self.run("docker info >/dev/null 2>&1", capture=True)
        print(f"  Docker:      {'✓ running' if code == 0 else '✗ not running'}")

        # WireGuard
        code, _ = self.run("wg show wg0 >/dev/null 2>&1", capture=True)
        print(f"  WireGuard:   {'✓ active' if code == 0 else '✗ not active'}")

        # Traefik
        traefik_dir = self.cfg.traefik_conf_dir
        if os.path.exists(traefik_dir):
            configs = len([f for f in os.listdir(traefik_dir) if f.endswith(".yml")])
            print(f"  Traefik:     ✓ {configs} configs")
        else:
            print("  Traefik:     ✗ config dir not found")

        # Containers
        code, out = self.run(
            "docker ps --format '{{.Names}}' -f name=^(instance_|ctf-|lab-) | wc -l",
            capture=True
        )
        running = int(out.strip()) if out and out.strip().isdigit() else 0
        print(f"  Containers:  {running} running")

        # Workers
        code, out = self.run(
            "systemctl list-units --type=service --state=running 2>/dev/null | grep -c labs-worker",
            capture=True
        )
        workers = int(out.strip()) if out and out.strip().isdigit() else 0
        print(f"  Workers:     {workers} active")

        print("  " + "=" * 60 + "\n")

    def _workers(self, args):
        code, out = self.run(
            "systemctl list-units --type=service --state=running 2>/dev/null | grep 'labs-worker' | awk '{print $1}'",
            capture=True
        )
        print("\n  Active Workers:")
        print("  " + "-" * 40)
        if out:
            for w in out.split("\n"):
                if w.strip():
                    print(f"  {w.strip():<30} ✓ alive")
        else:
            print("  No active workers found.")
        print("  " + "-" * 40 + "\n")

    def _images(self, args):
        templates_dir = self.cfg.templates_dir
        if not os.path.exists(templates_dir):
            self.log(f"Templates dir not found: {templates_dir}", "error")
            return

        templates = [d for d in os.listdir(templates_dir)
                     if os.path.isdir(os.path.join(templates_dir, d)) and not d.startswith("__")]

        GREEN = "\033[92m"
        RED = "\033[91m"
        RESET = "\033[0m"

        print("\n  Lab Images:")
        print("  " + "-" * 85)
        print(f"  {'Template':<20} {'Image Tag':<20} {'Status':<18} {'Size':<12} {'Last Built'}")
        print("  " + "-" * 85)

        for t in templates:
            tag = f"{t}:lab"
            exists = self.docker_image_exists(tag)
            size = ""
            last_built = ""
            if exists:
                _, size_out = self.run(f"docker image inspect {tag} --format '{{{{.Size}}}}'", capture=True)
                if size_out and size_out.strip().isdigit():
                    size_bytes = int(size_out.strip())
                    size = f"{size_bytes / 1048576:.1f}MB"
                _, created_out = self.run(f"docker image inspect {tag} --format '{{{{.Created}}}}'", capture=True)
                if created_out and created_out.strip():
                    try:
                        created_str = created_out.strip()
                        dt = datetime.fromisoformat(created_str.replace('Z', '+00:00'))
                        ist = dt.strftime("%d %b %I:%M %p")
                        last_built = ist
                    except Exception:
                        last_built = created_out.strip()[:16]
            if exists:
                status = f"{GREEN}✓ Built{RESET}"
            else:
                status = f"{RED}✗ Missing{RESET}"
            print(f"  {t:<20} {tag:<20} {status:<28} {size:<12} {last_built}")

        print("  " + "-" * 85 + "\n")

    def _db(self, args):
        if not self.db:
            self.log("Database not connected", "error")
            return

        try:
            self.mongo_client.admin.command("ping")
            print("\n  MongoDB Status:")
            print("  " + "-" * 40)
            print(f"  Connection:  ✓ OK")

            db = self.mongo_client[self.cfg.main_db]
            collections = db.list_collection_names()
            print(f"  Database:    {self.cfg.main_db}")
            print(f"  Collections: {len(collections)}")

            for col_name in sorted(collections):
                count = db[col_name].count_documents({})
                print(f"    {col_name:<25} {count:>8} docs")

            print("  " + "-" * 40 + "\n")
        except Exception as e:
            self.log(f"DB check failed: {e}", "error")

    def _clean(self, args):
        self.log("Running full system cleanup...")

        # Stopped containers
        self.log("Removing stopped lab containers...")
        code, out = self.run(
            "docker ps -a --format '{{.Names}}' -f status=exited -f name=^(instance_|ctf-|lab-)",
            capture=True
        )
        if out:
            for name in out.split("\n"):
                if name.strip():
                    self.run(f"docker rm -f {name.strip()} 2>/dev/null")

        # Unused images
        self.log("Pruning unused images...")
        self.run("docker image prune -f")

        # Docker volumes
        self.log("Pruning unused volumes...")
        self.run("docker volume prune -f")

        self.log("Cleanup complete.", "success")
