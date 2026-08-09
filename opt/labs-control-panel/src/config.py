import os
import re
import json


# ── Security Validation ───────────────────────────────────────────
SAFE_NAME_RE = re.compile(r'^[a-zA-Z0-9._-]+$')
SAFE_USERNAME_RE = re.compile(r'^[a-zA-Z0-9._@-]+$')
SAFE_TEMPLATE_RE = re.compile(r'^[a-zA-Z0-9_-]+$')


def validate_name(value, label="name"):
    """Validate a container/instance/label name — no shell metacharacters."""
    if not value or not SAFE_NAME_RE.match(str(value)):
        raise ValueError(f"Invalid {label}: {value!r}")
    return str(value)


def validate_username(value):
    """Validate a username — alphanumeric plus @._-."""
    if not value or not SAFE_USERNAME_RE.match(str(value)):
        raise ValueError(f"Invalid username: {value!r}")
    return str(value)


def validate_template(value):
    """Validate a template name — alphanumeric plus _-."""
    if not value or not SAFE_TEMPLATE_RE.match(str(value)):
        raise ValueError(f"Invalid template: {value!r}")
    return str(value)


def validate_ip(value, label="IP"):
    """Validate an IPv4 address."""
    parts = str(value).split('.')
    if len(parts) != 4 or not all(p.isdigit() and 0 <= int(p) <= 255 for p in parts):
        raise ValueError(f"Invalid {label}: {value!r}")
    return str(value)


def validate_path_within(base_dir, file_path):
    """Ensure file_path resolves within base_dir — prevents path traversal."""
    import pathlib
    base = pathlib.Path(base_dir).resolve()
    target = (base / file_path).resolve()
    if not str(target).startswith(str(base)):
        raise ValueError(f"Path traversal detected: {file_path!r}")
    return str(target)


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

    @property
    def storage_base(self):
        return self.env('storage_base', '/var/tomlabs/storage')

    @property
    def storage_limit_gb(self):
        return int(self.env('storage_limit_gb', 25))
