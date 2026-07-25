import os
import subprocess
import pymongo
from src.config import Config


class Base:
    """Shared foundation: DB, Docker, logging, shell execution."""

    def __init__(self):
        self.cfg = Config()
        self.db = None
        self.mongo_client = None
        self._connect_db()

    # ── Database ────────────────────────────────────────────────

    def _connect_db(self):
        uri = self.cfg.mongo_uri
        if uri:
            try:
                self.mongo_client = pymongo.MongoClient(uri, serverSelectionTimeoutMS=2000)
                self.mongo_client.admin.command('ping')
                self.db = self.mongo_client[self.cfg.main_db]
                return
            except Exception as e:
                self.log(f"Env connection failed: {e}", "warn")

        fallbacks = [
            "mongodb://admin:Tombootroot@TomCloudLab_mongodb:27017/?authSource=admin",
            "mongodb://admin:Tombootroot@localhost:27018/?authSource=admin",
        ]
        for uri in fallbacks:
            try:
                self.mongo_client = pymongo.MongoClient(uri, serverSelectionTimeoutMS=2000)
                self.mongo_client.admin.command('ping')
                self.db = self.mongo_client.tom_labs_db
                return
            except Exception:
                continue
        self.log("Database connection failed", "error")

    def col(self, name):
        """Get a collection from the main DB."""
        return self.db[name] if self.db else None

    def instances_db(self):
        """Get the instances database."""
        if self.mongo_client:
            return self.mongo_client['tom_labs_instances_db']
        return None

    # ── Logging ─────────────────────────────────────────────────

    def log(self, msg, level="info"):
        prefixes = {"info": "[*]", "success": "[✓]", "error": "[!]", "warn": "[!]"}
        prefix = prefixes.get(level, "[*]")
        if msg.startswith("[") and msg[1:2] in "*✓!":
            full = msg
        else:
            full = f"{prefix} {msg}"
        print(full, flush=True)

    # ── Shell ───────────────────────────────────────────────────

    def run(self, cmd, capture=False):
        try:
            if capture:
                r = subprocess.run(cmd, shell=True, capture_output=True, text=True)
                return r.returncode, r.stdout.strip()
            else:
                p = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE,
                                     stderr=subprocess.STDOUT, text=True)
                for line in p.stdout:
                    print(line, end="", flush=True)
                p.wait()
                return p.returncode, ""
        except Exception as e:
            self.log(f"Command error: {e}", "error")
            return -1, ""

    # ── Docker helpers ──────────────────────────────────────────

    def docker_exists(self, name):
        code, out = self.run(f"docker ps -a --format '{{{{.Names}}}}' -f name=^{name}$", capture=True)
        return out == name

    def docker_running(self, name):
        code, out = self.run(f"docker ps --format '{{{{.Names}}}}' -f name=^{name}$", capture=True)
        return out == name

    def docker_image_exists(self, tag):
        code, _ = self.run(f"docker image inspect {tag} >/dev/null 2>&1", capture=True)
        return code == 0

    def docker_stop(self, name):
        self.run(f"docker stop {name} 2>/dev/null || true")
        self.run(f"docker rm -f {name} 2>/dev/null || true")

    def docker_inspect_ip(self, container, network=None):
        fmt = "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}"
        if network:
            fmt = f"{{{{.NetworkSettings.Networks.{network}.IPAddress}}}}"
        code, out = self.run(f"docker inspect {container} --format '{fmt}' 2>/dev/null", capture=True)
        return out if out and out != "<no value>" else None

    # ── Network helpers ─────────────────────────────────────────

    def detect_bridge(self, network=None):
        network = network or self.cfg.docker_network
        code, bridge = self.run(
            f"docker network inspect {network} "
            f"-f '{{{{index .Options \"com.docker.network.bridge.name\"}}}}' 2>/dev/null",
            capture=True
        )
        if not bridge or bridge == "<no value>":
            code, br_id = self.run(
                f"docker network inspect {network} -f '{{{{.Id}}}}' 2>/dev/null | cut -c1-12",
                capture=True
            )
            bridge = f"br-{br_id}" if br_id else None

        if not bridge or bridge == "br-":
            bridge = "eth0"
        else:
            code, _ = self.run(f"ip link show {bridge} > /dev/null 2>&1", capture=True)
            if code != 0:
                bridge = "eth0"
        return bridge

    def configure_routing(self, tunnel_ip, docker_ip, bridge=None):
        bridge = bridge or self.detect_bridge()
        self.run("sysctl -w net.ipv4.ip_forward=1")
        self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")
        self.run(f"ip route add {tunnel_ip}/32 via {docker_ip} dev {bridge} 2>/dev/null || true")
        self.run("iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i wg0 -o {bridge} -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i {bridge} -o wg0 -j ACCEPT 2>/dev/null || true")
        tunnel_prefix = self.cfg.tunnel_ip
        self.run(
            f"iptables -t nat -C POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || "
            f"iptables -t nat -A POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || true"
        )

    def remove_route(self, tunnel_ip):
        self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")

    def wg_add_peer(self, pubkey, tunnel_ip):
        self.run(f"wg set wg0 peer {pubkey} allowed-ips {tunnel_ip}/32")

    def wg_remove_peer(self, pubkey):
        self.run(f"wg set wg0 peer {pubkey} remove 2>/dev/null || true")

    def wg_list_peers(self):
        code, out = self.run("wg show wg0 allowed-ips", capture=True)
        return out

    # ── Traefik helpers ─────────────────────────────────────────

    def write_traefik(self, instance_id, yaml_content):
        path = os.path.join(self.cfg.traefik_conf_dir, f"{instance_id}.yml")
        try:
            with open(path, "w") as f:
                f.write(yaml_content)
            self.log(f"Traefik config written: {path}", "success")
        except Exception as e:
            self.log(f"Traefik write failed: {e}", "error")

    def remove_traefik(self, instance_id):
        path = os.path.join(self.cfg.traefik_conf_dir, f"{instance_id}.yml")
        if os.path.exists(path):
            try:
                os.remove(path)
                self.log(f"Traefik config removed: {instance_id}", "success")
            except Exception as e:
                self.log(f"Traefik remove failed: {e}", "warn")
