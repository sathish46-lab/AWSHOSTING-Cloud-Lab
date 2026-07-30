import json
import shlex
from src.router import Command


class ContainerCmd(Command):
    """labsctl container — Docker container management."""

    name = "container"
    description = "Docker container management"
    usage = "labsctl container <list|stats|logs|exec|prune> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "list":  (self._list,  "List all lab containers",    "labsctl container list"),
            "stats": (self._stats, "Live resource usage",        "labsctl container stats"),
            "logs":  (self._logs,  "Tail container logs",        "labsctl container logs --hash=HASH"),
            "exec":  (self._exec,  "Execute command in container","labsctl container exec --hash=HASH --cmd='ls'"),
            "prune": (self._prune, "Remove stopped containers",  "labsctl container prune"),
        }

    def _list(self, args):
        code, out = self.run(
            "docker ps -a --format '{{.Names}}\t{{.Status}}\t{{.Image}}\t{{.Ports}}' "
            "-f name=^(instance_|ctf-|lab-)",
            capture=True
        )
        print("\n  Lab Containers:")
        print("  " + "-" * 80)
        print(f"  {'Name':<35} {'Status':<20} {'Image':<20}")
        print("  " + "-" * 80)

        if out:
            for line in out.split("\n"):
                parts = line.split("\t")
                if len(parts) >= 3:
                    print(f"  {parts[0]:<35} {parts[1]:<20} {parts[2]:<20}")
        else:
            print("  No lab containers found.")

        print("  " + "-" * 80 + "\n")

    def _stats(self, args):
        code, out = self.run(
            "docker stats --no-stream --format '{{json .}}'",
            capture=True
        )
        if not out:
            self.log("No running containers.", "warn")
            return

        print("\n  Container Stats:")
        print("  " + "-" * 80)
        print(f"  {'Name':<25} {'CPU':<10} {'Mem Usage':<25} {'Net I/O':<20}")
        print("  " + "-" * 80)

        for line in out.split("\n"):
            if not line.strip():
                continue
            try:
                s = json.loads(line)
                name = s.get("Name", "?")[:24]
                cpu = s.get("CPUPerc", "?")
                mem = s.get("MemUsage", "?")
                net = s.get("NetIO", "?")
                print(f"  {name:<25} {cpu:<10} {mem:<25} {net:<20}")
            except json.JSONDecodeError:
                continue

        print("  " + "-" * 80 + "\n")

    def _logs(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        lines = args.flag("lines", "50")
        self.run(f"docker logs --tail {lines} -f {instance_id}")

    def _exec(self, args):
        instance_id = args.hash
        cmd = args.flag("cmd")
        if not instance_id or not cmd:
            self.log("Usage: labsctl container exec --hash=HASH --cmd='command'", "error")
            return
        # Validate inputs to prevent command injection
        if not instance_id or not shlex.quote(instance_id).strip("'"):
            self.log("Invalid container hash", "error")
            return
        # Note: --cmd is intentionally unrestricted for admin CLI use
        self.run(f"docker exec {shlex.quote(instance_id)} {cmd}")

    def _prune(self, args):
        self.log("Stopping all stopped lab containers...")
        code, out = self.run(
            "docker ps -a --format '{{.Names}}' -f status=exited -f name=^(instance_|ctf-|lab-)",
            capture=True
        )
        if out:
            for name in out.split("\n"):
                if name.strip():
                    self.run(f"docker rm -f {name.strip()} 2>/dev/null")
                    self.log(f"Removed: {name.strip()}", "success")

        self.log("Pruning unused images...")
        self.run("docker image prune -f")
        self.log("Prune complete.", "success")
