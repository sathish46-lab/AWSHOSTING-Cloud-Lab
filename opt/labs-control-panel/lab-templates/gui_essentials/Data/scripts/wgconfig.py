#!/usr/bin/env python3
"""
wgconfig.py - WireGuard peer registration for container
Usage: python3 wgconfig.py <tunnel_ip>
Output: private_key|public_key
"""

import subprocess
import sys
import re

IP_RE = re.compile(r'^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$')


def validate_ip(ip):
    if not IP_RE.match(ip):
        print(f"Invalid IP: {ip!r}", file=sys.stderr)
        sys.exit(1)
    return ip


def generate_keys():
    """Generate WireGuard keypair"""
    priv_key = subprocess.check_output(
        ["wg", "genkey"], text=True
    ).strip()
    pub_key = subprocess.check_output(
        ["bash", "-c", f"echo '{priv_key}' | wg pubkey"], text=True
    ).strip()
    return priv_key, pub_key


def remove_stale_peer(ip):
    """Remove any existing peer using this IP"""
    try:
        result = subprocess.check_output(
            ["wg", "show", "wg0", "allowed-ips"], text=True
        ).strip()
        for line in result.splitlines():
            if f"{ip}/32" in line:
                old_peer_key = line.split()[0]
                subprocess.run(
                    ["wg", "set", "wg0", "peer", old_peer_key, "remove"],
                    check=False
                )
                print(f"[*] Removed stale peer for {ip}", file=sys.stderr)
                break
    except Exception:
        pass


def register_peer(pub_key, ip):
    """Register new peer on host WireGuard"""
    remove_stale_peer(ip)
    result = subprocess.run(
        ["wg", "set", "wg0", "peer", pub_key, "allowed-ips", f"{ip}/32"],
        check=False
    )
    if result.returncode == 0:
        print(f"[✓] Registered peer: {ip}", file=sys.stderr)
    else:
        print(f"[!] Failed to register peer for {ip}", file=sys.stderr)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 wgconfig.py <tunnel_ip>", file=sys.stderr)
        sys.exit(1)

    tunnel_ip = validate_ip(sys.argv[1])

    priv_key, pub_key = generate_keys()
    register_peer(pub_key, tunnel_ip)
    print(f"{priv_key}|{pub_key}")
