import sys
from src.base import Base


class Args:
    """Argument parser — supports --flag=value, -f value, and positional args."""

    def __init__(self, argv=None):
        self.argv = list(argv or sys.argv[1:])
        self._positional = []
        self._flags = {}
        self._parse()

    def _parse(self):
        i = 0
        while i < len(self.argv):
            a = self.argv[i]
            if a.startswith("--") and "=" in a:
                k, v = a[2:].split("=", 1)
                self._flags[k] = v
            elif a.startswith("-") and len(a) == 2 and i + 1 < len(self.argv):
                self._flags[a[1:]] = self.argv[i + 1]
                i += 2
                continue
            elif a.startswith("--"):
                self._flags[a[2:]] = True
            elif a.startswith("-") and len(a) == 2:
                self._flags[a[1:]] = True
            else:
                self._positional.append(a)
            i += 1

    def get(self, n=0):
        return self._positional[n] if n < len(self._positional) else None

    def has(self, name):
        return name in self._flags

    def flag(self, name, default=None):
        return self._flags.get(name, default)

    @property
    def hash(self):
        return self.flag("hash")

    @property
    def user(self):
        return self.flag("user")

    @property
    def help(self):
        return self.has("help") or self.has("h")


class Command(Base):
    """Base class for all commands — combines routing with DB/Docker/shell."""

    name = ""
    description = ""
    usage = ""
    subcommands = {}  # {name: (handler_method, description, usage)}

    def __init__(self, router=None):
        Base.__init__(self)

    def handle(self, args):
        if args.help:
            self._print_help()
            return

        sub = args.get(0)
        if not sub:
            self._print_help()
            return

        if sub in self.subcommands:
            handler, _, _ = self.subcommands[sub]
            handler(args)
        else:
            print(f"Unknown subcommand: {sub}")
            self._print_help()

    def _print_help(self):
        print(f"\n  labsctl {self.name} — {self.description}\n")
        if self.usage:
            print(f"  Usage: {self.usage}\n")
        if self.subcommands:
            print("  Subcommands:")
            max_name = max(len(n) for n in self.subcommands)
            for name, (_, desc, usage) in self.subcommands.items():
                print(f"    {name:<{max_name}}   {desc}")
                if usage:
                    print(f"    {'':<{max_name}}   Example: {usage}")
            print()


class Router:
    """Command router — maps top-level commands to handler modules."""

    def __init__(self):
        self.commands = {}
        self.legacy = {}  # backward-compatible flat commands

    def register(self, name, cmd_class):
        self.commands[name] = cmd_class

    def register_legacy(self, name, handler):
        """Register a flat command (e.g., 'build' → old Lab.build)."""
        self.legacy[name] = handler

    def dispatch(self, argv=None):
        args = Args(argv)

        # --version flag (works anywhere)
        if args.has("version"):
            print("labsctl v2.0.0")
            return

        if not args.get():
            self._print_banner()
            return

        cmd_name = args.get(0)

        # Check new command groups first
        if cmd_name in self.commands:
            cmd = self.commands[cmd_name](self)
            # Pass remaining args (strip the group name, keep --help etc)
            remaining = args.argv[1:]
            sub_args = Args(remaining)
            cmd.handle(sub_args)
            return

        # Check legacy flat commands
        if cmd_name in self.legacy:
            self.legacy[cmd_name](args)
            return

        print(f"Unknown command: {cmd_name}")
        self._print_banner()

    def _print_banner(self):
        print("""
  labsctl — Tom Labs Orchestrator CLI

  Usage: labsctl <command> [subcommand] [options]

  Command Groups:
    lab          Base template operations (system only)
    instance     User instance operations (UI builds here)
    network      Network routes, WireGuard peers, iptables
    proxy        Traefik proxy management
    container    Docker container management
    system       System health, workers, cleanup
    user         User SSH sync, listing
    stream       Live log streaming
    quiz         AI quiz generation

  Quick Commands (legacy):
    build        Build lab image
    deploy       Deploy lab container
    stop         Stop lab container
    start        Start lab container
    remove       Remove lab container
    shell        Enter container shell
    list-images  List built images
    get-workers  Check active workers

  Options:
    --help, -h    Show this help
    --version     Show version

  Examples:
    labsctl lab generate-keys             Generate SSH host keys (all templates)
    labsctl lab generate-keys essentials  Generate keys for one template
    labsctl lab build essentials:lab      Build base template image (system)
    labsctl lab build docker_lab:lab      Build docker_lab template (system)
    labsctl lab build minio:lab           Build minio template (system)
    labsctl lab deploy --hash=abc123 --user=sathish
    labsctl instance build --hash=abc123     Build user instance (UI)
    labsctl instance deploy --hash=abc123
    labsctl network status
    labsctl proxy list
    labsctl container list
    labsctl system images
    labsctl system status
""")
