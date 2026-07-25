import os
import json


class Config:
    """Unified configuration loader — reads config.json + env.json."""

    _instance = None
    _loaded = False

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance

    def __init__(self):
        if self._loaded:
            return
        self._loaded = True
        self._data = {}
        self._env = {}
        self._load_config()
        self._load_env()

    def _load_config(self):
        paths = [
            '/opt/labs-control-panel/config.json',
            os.path.join(os.path.dirname(os.path.dirname(os.path.realpath(__file__))), 'config.json'),
        ]
        for path in paths:
            if os.path.exists(path):
                try:
                    with open(path, 'r') as f:
                        self._data = json.load(f)
                    return
                except Exception:
                    continue

    def _load_env(self):
        paths = [
            '/var/www/env.json',
            '/Users/sathish/Development/local_dev_lab/env.json',
            os.path.join(os.path.dirname(os.path.dirname(os.path.realpath(__file__))), 'env.json'),
        ]
        for path in paths:
            if os.path.exists(path):
                try:
                    with open(path, 'r') as f:
                        self._env = json.load(f)
                    return
                except Exception:
                    continue

    def get(self, key, default=None):
        return self._data.get(key, default)

    def env(self, key, default=None):
        return self._env.get(key, default)

    @property
    def docker_ip(self):
        return self.get('docker_ip', '10.20.160.')

    @property
    def tunnel_ip(self):
        return self.get('tunnel_ip', '172.31.0.')

    @property
    def docker_network(self):
        return self.get('docker_network_name', 'dev_lab_frontend')

    @property
    def orchestrator_container(self):
        return self.get('orchestrator_container', 'Dev_lab')

    @property
    def templates_dir(self):
        return self.get('templates_dir', '/opt/labs-control-panel/lab-templates')

    @property
    def traefik_conf_dir(self):
        return self.get('traefik_conf_dir', '/etc/traefik/dynamic_conf')

    @property
    def code_domain(self):
        return os.environ.get('CODE_DOMAIN', self.env('code_domain', 'tomweb.fun'))

    @property
    def vpn_domain(self):
        return os.environ.get('VPN_DOMAIN', self.env('vpn_domain', 'vpn.tomweb.in'))

    @property
    def mongo_uri(self):
        return self.env('database_file', '')

    @property
    def main_db(self):
        return self.env('main_db', 'tom_labs_db')
