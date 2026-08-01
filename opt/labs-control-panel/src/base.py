import os
import re
import subprocess
import pymongo
from src.config import Config

SAFE_NAME_RE = re.compile(r'^[a-zA-Z0-9._-]+$')
SAFE_IP_RE = re.compile(r'^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$')


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

        mongo_user = os.environ.get('MONGO_USER', '')
        mongo_pass = os.environ.get('MONGO_PASS', '')
        mongo_host = os.environ.get('MONGO_HOST', 'localhost')
        mongo_port = os.environ.get('MONGO_PORT', '27018')

        if mongo_user and mongo_pass:
            fallback_uri = f"mongodb://{mongo_user}:{mongo_pass}@{mongo_host}:{mongo_port}/?authSource=admin"
            try:
                self.mongo_client = pymongo.MongoClient(fallback_uri, serverSelectionTimeoutMS=2000)
                self.mongo_client.admin.command('ping')
                self.db = self.mongo_client.tom_labs_db
                return
            except Exception:
                pass
        self.log("Database connection failed — set MONGO_USER/MONGO_PASS env vars", "error")

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
        if not name or not SAFE_NAME_RE.match(str(name)):
            return False
        code, out = self.run(f"docker ps -a --format '{{{{.Names}}}}' -f name=^{name}$", capture=True)
        return out == name

    def docker_running(self, name):
        if not name or not SAFE_NAME_RE.match(str(name)):
            return False
        code, out = self.run(f"docker ps --format '{{{{.Names}}}}' -f name=^{name}$", capture=True)
        return out == name

    def docker_image_exists(self, tag):
        if not tag or not SAFE_NAME_RE.match(str(tag).split(':')[0]):
            return False
        code, _ = self.run(f"docker image inspect {tag} >/dev/null 2>&1", capture=True)
        return code == 0

    def docker_stop(self, name):
        if not name or not SAFE_NAME_RE.match(str(name)):
            return
        self.run(f"docker stop {name} 2>/dev/null || true")
        self.run(f"docker rm -f {name} 2>/dev/null || true")

    def docker_inspect_ip(self, container, network=None):
        if not container or not SAFE_NAME_RE.match(str(container)):
            return None
        fmt = "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}"
        if network:
            fmt = f"{{{{.NetworkSettings.Networks.{network}.IPAddress}}}}"
        code, out = self.run(f"docker inspect {container} --format '{fmt}' 2>/dev/null", capture=True)
        return out if out and out != "<no value>" else None

    # ── Network helpers ─────────────────────────────────────────

    def detect_bridge(self, network=None):
        network = network or self.cfg.docker_network
        if not network or not SAFE_NAME_RE.match(str(network)):
            return "eth0"
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
                # Bridge name doesn't exist inside this container (e.g. Docker Desktop)
                # Find the interface by matching the network's subnet
                fmt_cmd = "docker network inspect " + network + ' -f "'
                fmt_cmd += "{{range .IPAM.Config}}{{.Subnet}}{{end}}"
                fmt_cmd += '" 2>/dev/null'
                code, gw = self.run(fmt_cmd, capture=True)
                if gw:
                    subnet = gw.split('/')[0]
                    subnet_prefix = '.'.join(subnet.split('.')[:3])
                    code2, ifname = self.run(
                        f"ip -4 addr show | grep -B1 '{subnet_prefix}' | grep -oE 'eth[0-9]+' | head -1",
                        capture=True
                    )
                    if code2 == 0 and ifname:
                        bridge = ifname
                    else:
                        bridge = "eth0"
                else:
                    bridge = "eth0"
        return bridge

    def configure_routing(self, tunnel_ip, docker_ip, bridge=None):
        bridge = bridge or self.detect_bridge()
        if not tunnel_ip or not SAFE_IP_RE.match(str(tunnel_ip)):
            return
        if not docker_ip or not SAFE_IP_RE.match(str(docker_ip)):
            return
        self.run("sysctl -w net.ipv4.ip_forward=1")
        # IP route: forward tunnel traffic to container's Docker IP
        self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")
        self.run(f"ip route add {tunnel_ip}/32 via {docker_ip} dev {bridge} 2>/dev/null || true")
        # iptables: allow forwarding between WireGuard and Docker bridge
        self.run("iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i wg0 -o {bridge} -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i {bridge} -o wg0 -j ACCEPT 2>/dev/null || true")
        # NAT: masquerade tunnel traffic going to internet (not to containers)
        tunnel_prefix = self.cfg.tunnel_ip
        self.run(
            f"iptables -t nat -C POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || "
            f"iptables -t nat -A POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || true"
        )

    def remove_route(self, tunnel_ip):
        if not tunnel_ip or not SAFE_IP_RE.match(str(tunnel_ip)):
            return
        self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")

    def wg_add_peer(self, pubkey, tunnel_ip):
        if not pubkey or not SAFE_NAME_RE.match(str(pubkey)):
            return
        if not tunnel_ip or not SAFE_IP_RE.match(str(tunnel_ip)):
            return
        self.run(f"wg set wg0 peer {pubkey} allowed-ips {tunnel_ip}/32")

    def wg_remove_peer(self, pubkey):
        if not pubkey or not SAFE_NAME_RE.match(str(pubkey)):
            return
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

    # ── Apache code_server_map.txt ────────────────────────────────
    CODE_SERVER_MAP = "/etc/apache2/code_server_map.txt"

    def write_code_server_map(self, instance_id, docker_ip, prefix=""):
        """Write hash → docker_ip mapping to Apache code_server_map.txt."""
        key = f"{prefix}{instance_id}" if prefix else instance_id
        entry = f"{key} {docker_ip}\n"
        try:
            lines = []
            if os.path.exists(self.CODE_SERVER_MAP):
                with open(self.CODE_SERVER_MAP, "r") as f:
                    lines = f.readlines()
            # Remove existing entry for this key
            lines = [l for l in lines if not l.startswith(f"{key} ")]
            lines.append(entry)
            with open(self.CODE_SERVER_MAP, "w") as f:
                f.writelines(lines)
            self.log(f"code_server_map updated: {key} → {docker_ip}", "success")
        except Exception as e:
            self.log(f"code_server_map write failed: {e}", "error")

    def remove_code_server_map(self, instance_id, prefix=""):
        """Remove hash entry from Apache code_server_map.txt."""
        key = f"{prefix}{instance_id}" if prefix else instance_id
        try:
            if not os.path.exists(self.CODE_SERVER_MAP):
                return
            with open(self.CODE_SERVER_MAP, "r") as f:
                lines = f.readlines()
            lines = [l for l in lines if not l.startswith(f"{key} ")]
            with open(self.CODE_SERVER_MAP, "w") as f:
                f.writelines(lines)
            self.log(f"code_server_map entry removed: {key}", "success")
        except Exception as e:
            self.log(f"code_server_map remove failed: {e}", "error")
