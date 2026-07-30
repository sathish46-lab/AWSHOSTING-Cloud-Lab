import os
import re
import json
import pymongo
import time
import subprocess
from src.DockerHelper import DockerHelper

SAFE_NAME_RE = re.compile(r'^[a-zA-Z0-9._-]+$')
SAFE_IP_RE = re.compile(r'^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$')

class BaseOrchestrator:
    def __init__(self, args, session_hash=None):
        self.args = args
        self.session_hash = session_hash
        self.docker = DockerHelper()
        self.config = self._load_global_config()
        self.env = self._load_env_config()
        self.db = None
        self._connect_db()

    def _load_env_config(self):
        """Load the global environment configuration (env.json)"""
        paths = ['/var/www/env.json', '/Users/sathish/Development/local_dev_lab/env.json', 'env.json']
        for path in paths:
            if os.path.exists(path):
                try:
                    with open(path, 'r') as f:
                        return json.load(f)
                except:
                    continue
        return {}

    def _load_global_config(self):
        config_path = '/opt/labs-control-panel/config.json'
        return json.load(open(config_path)) if os.path.exists(config_path) else {}

    def _connect_db(self):
        """Unified MongoDB connection using env.json or fallback."""
        mongo_uri = self.env.get('database_file')
        db_name = self.env.get('main_db', 'tom_labs_db')
        
        if mongo_uri:
            try:
                self.mongo_client = pymongo.MongoClient(mongo_uri, serverSelectionTimeoutMS=2000)
                self.mongo_client.admin.command('ping')
                self.db = self.mongo_client[db_name]
                return
            except Exception as e:
                self.log(f"Env Connection Failed: {e}", "warn", "init")

        # Fallback Logic for local development/bootstrapping
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
        self.db = None
        self.log("Database connection failed — set MONGO_USER/MONGO_PASS env vars", "error", "init")

    def log(self, message, level="info", phase="general", step=None):
        """Standard text log format."""
        prefixes = {"info": "[*]", "success": "[✓]", "error": "[!]", "warn": "[!]"}
        prefix = prefixes.get(level, '[*]')
        
        # In case some old message strings already include the prefix, we can strip it to avoid double prefixes
        if message.startswith("[*]") or message.startswith("[✓]") or message.startswith("[!]"):
            full_message = message
        else:
            full_message = f"{prefix} {message}"
            
        print(full_message, flush=True)

    def run(self, cmd, capture=False):
        """
        Replace os.system calls with a subprocess wrapper.
        If capture is True, returns (exit_code, stdout_string).
        If capture is False, returns exit_code, streaming stdout to self.log if not silent.
        """
        try:
            if capture:
                result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
                return result.returncode, result.stdout.strip()
            else:
                process = subprocess.Popen(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
                for line in process.stdout:
                    print(line, end='', flush=True)
                process.wait()
                return process.returncode, ""
        except Exception as e:
            self.log(f"Command execution error: {e} | cmd: {cmd}", "error", "system")
            return -1, ""

    def detect_bridge(self, docker_network):
        """Shared bridge detection logic."""
        code, bridge_id = self.run(f"docker network inspect {docker_network} -f '{{{{index .Options \"com.docker.network.bridge.name\"}}}}' 2>/dev/null", capture=True)
        
        if not bridge_id or bridge_id == "<no value>":
            code, br_id = self.run(f"docker network inspect {docker_network} -f '{{{{.Id}}}}' 2>/dev/null | cut -c1-12", capture=True)
            if br_id:
                bridge_id = f"br-{br_id}"

        # In a local docker environment, the bridge might not exist inside the vps_dev namespace.
        # Fallback to eth0 which is the standard outbound interface to the docker network.
        if not bridge_id or bridge_id == "br-":
            bridge_id = "eth0"
        else:
            code, _ = self.run(f"ip link show {bridge_id} > /dev/null 2>&1", capture=True)
            if code != 0:
                self.log(f"Bridge '{bridge_id}' not found in current namespace, falling back to eth0", "warn", "routing")
                bridge_id = "eth0"
                
        return bridge_id

    def configure_routing(self, tunnel_ip, docker_ip, bridge_id, dnat=False):
        """Shared iptables/routing logic."""
        if not tunnel_ip or not SAFE_IP_RE.match(str(tunnel_ip)):
            return
        if not docker_ip or not SAFE_IP_RE.match(str(docker_ip)):
            return
        self.run("sysctl -w net.ipv4.ip_forward=1")
        self.run(f"ip route del {tunnel_ip}/32 2>/dev/null || true")
        
        if dnat:
            # Challenge-style DNAT routing
            self.run(f"iptables -t nat -A PREROUTING -d {tunnel_ip} -j DNAT --to-destination {docker_ip} 2>/dev/null || true")
            self.run(f"iptables -t nat -A OUTPUT -d {tunnel_ip} -j DNAT --to-destination {docker_ip} 2>/dev/null || true")
        else:
            # Standard Lab-style routing
            self.run(f"ip route add {tunnel_ip}/32 via {docker_ip} dev {bridge_id} 2>/dev/null || true")
            
        self.run("iptables -A FORWARD -i wg0 -o wg0 -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i wg0 -o {bridge_id} -j ACCEPT 2>/dev/null || true")
        self.run(f"iptables -A FORWARD -i {bridge_id} -o wg0 -j ACCEPT 2>/dev/null || true")
        tunnel_prefix = self.config.get('tunnel_ip')
        if not tunnel_prefix:
            self.log("FATAL: 'tunnel_ip' not set in config.", "error", "network")
            return
        self.run(f"iptables -t nat -C POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || "
                 f"iptables -t nat -A POSTROUTING -s {tunnel_prefix}0/16 -o eth0 -j MASQUERADE 2>/dev/null || true")

    def cleanup_container(self, container_name, docker_network):
        """Shared container cleanup."""
        if not container_name or not SAFE_NAME_RE.match(str(container_name)):
            return False
        if self.docker.container_exists(container_name):
            try:
                self.run(f"docker network disconnect -f {docker_network} {container_name} 2>/dev/null")
            except Exception:
                pass
            self.run(f"docker stop {container_name} 2>/dev/null || true")
            self.run(f"docker rm -f {container_name} 2>/dev/null || true")
            return True
        return False

    def write_traefik_config(self, instance_id, config_content):
        """Writes a Traefik YAML from a string."""
        traefik_conf_dir = self.config.get('traefik_conf_dir', '/etc/traefik/dynamic_conf')
        traefik_file_path = os.path.join(traefik_conf_dir, f"{instance_id}.yml")
        try:
            with open(traefik_file_path, "w") as f:
                f.write(config_content)
            self.log("Traefik configuration applied", "success", "traefik")
        except Exception as e:
            self.log(f"Traefik config failed: {str(e)}", "error", "traefik")

    def remove_traefik_config(self, instance_id):
        """Removes Traefik YAML file."""
        traefik_conf_dir = self.config.get('traefik_conf_dir', '/etc/traefik/dynamic_conf')
        traefik_file_path = os.path.join(traefik_conf_dir, f"{instance_id}.yml")
        if os.path.exists(traefik_file_path):
            try:
                os.remove(traefik_file_path)
                self.log(f"Traefik route removed for {instance_id}", "success", "traefik")
            except Exception as e:
                self.log(f"Traefik cleanup error: {str(e)}", "warn", "traefik")
