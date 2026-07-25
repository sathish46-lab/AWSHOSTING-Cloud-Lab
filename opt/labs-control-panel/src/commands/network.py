from src.router import Command


class NetworkCmd(Command):
    """labsctl network — network route and WireGuard management."""

    name = "network"
    description = "Network routes, WireGuard peers, iptables"
    usage = "labsctl network <status|route|wg> [subcommand] [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "status": (self._status,  "Show all network state",     "labsctl network status"),
            "route":  (self._route,   "Route management",           "labsctl network route list"),
            "wg":     (self._wg,      "WireGuard peer management",  "labsctl network wg list"),
        }

    def _route(self, args):
        sub = args.get(1)
        if sub == "list":
            self._route_list(args)
        elif sub == "add":
            self._route_add(args)
        elif sub == "del":
            self._route_del(args)
        else:
            print("  labsctl network route <list|add|del>")
            print("    list                  List all routes")
            print("    add --tunnel=IP --docker=IP")
            print("    del --tunnel=IP")

    def _route_list(self, args):
        code, out = self.run("ip route show | grep -E 'via|dev wg0'", capture=True)
        print("\n  Active Routes:")
        print("  " + "-" * 60)
        if out:
            for line in out.split("\n"):
                print(f"  {line}")
        else:
            print("  No routes found.")
        print("  " + "-" * 60 + "\n")

    def _route_add(self, args):
        tunnel_ip = args.flag("tunnel")
        docker_ip = args.flag("docker")
        if not tunnel_ip or not docker_ip:
            self.log("Usage: labsctl network route add --tunnel=IP --docker=IP", "error")
            return
        self.configure_routing(tunnel_ip, docker_ip)
        self.log(f"Route added: {tunnel_ip} → {docker_ip}", "success")

    def _route_del(self, args):
        tunnel_ip = args.flag("tunnel")
        if not tunnel_ip:
            self.log("Usage: labsctl network route del --tunnel=IP", "error")
            return
        self.remove_route(tunnel_ip)
        self.log(f"Route removed: {tunnel_ip}", "success")

    def _wg(self, args):
        sub = args.get(1)
        if sub == "list":
            self._wg_list(args)
        elif sub == "add":
            self._wg_add(args)
        elif sub == "remove":
            self._wg_remove(args)
        else:
            print("  labsctl network wg <list|add|remove>")
            print("    list                         List all WireGuard peers")
            print("    add --pubkey=KEY --tunnel=IP")
            print("    remove --pubkey=KEY")

    def _wg_list(self, args):
        code, out = self.run("wg show wg0", capture=True)
        print("\n  WireGuard Peers:")
        print("  " + "-" * 60)
        if out:
            for line in out.split("\n"):
                print(f"  {line}")
        else:
            print("  No WireGuard interface found.")
        print("  " + "-" * 60 + "\n")

    def _wg_add(self, args):
        pubkey = args.flag("pubkey")
        tunnel_ip = args.flag("tunnel")
        if not pubkey or not tunnel_ip:
            self.log("Usage: labsctl network wg add --pubkey=KEY --tunnel=IP", "error")
            return
        self.wg_add_peer(pubkey, tunnel_ip)
        self.log(f"WireGuard peer added: {tunnel_ip}", "success")

    def _wg_remove(self, args):
        pubkey = args.flag("pubkey")
        if not pubkey:
            self.log("Usage: labsctl network wg remove --pubkey=KEY", "error")
            return
        self.wg_remove_peer(pubkey)
        self.log("WireGuard peer removed.", "success")

    def _status(self, args):
        print("\n  Network Status")
        print("  " + "=" * 60)

        # Bridge
        bridge = self.detect_bridge()
        print(f"  Bridge:      {bridge}")

        # Docker network
        docker_net = self.cfg.docker_network
        code, net_id = self.run(f"docker network inspect {docker_net} -f '{{{{.Id}}}}' 2>/dev/null | cut -c1-12", capture=True)
        print(f"  Docker Net:  {docker_net} ({net_id or 'not found'})")

        # WireGuard
        code, wg_ip = self.run("ip -4 addr show wg0 2>/dev/null | grep inet | awk '{print $2}'", capture=True)
        print(f"  WG IP:       {wg_ip or 'not active'}")

        code, peer_count = self.run("wg show wg0 peers 2>/dev/null | wc -l", capture=True)
        print(f"  WG Peers:    {peer_count or '0'}")

        # IP forwarding
        code, fwd = self.run("sysctl -n net.ipv4.ip_forward 2>/dev/null", capture=True)
        print(f"  IP Forward:  {'enabled' if fwd == '1' else 'disabled'}")

        # Routes
        code, routes = self.run("ip route show | grep -c 'via'", capture=True)
        print(f"  Routes:      {routes or '0'}")

        # iptables MASQUERADE
        code, masq = self.run("iptables -t nat -C POSTROUTING -s 172.31.0.0/16 -o eth0 -j MASQUERADE 2>/dev/null && echo yes || echo no", capture=True)
        print(f"  MASQUERADE:  {masq}")

        print("  " + "=" * 60 + "\n")
