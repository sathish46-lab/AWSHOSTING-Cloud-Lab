#!/usr/bin/env python3
"""
labsctl — Tom Labs Orchestrator CLI

Usage:
  labsctl <group> <action> [options]

Command Groups:
  lab         Lab operations (build, deploy, stop, start, restart, remove, status)
  instance    Instance operations (single + bulk deploy, health, reconcile, queue)
  network     Network operations (WireGuard, routing)
  proxy       Proxy operations (Traefik, domains)
  container   Container operations (Docker management)
  system      System operations (status, workers, images, db, clean)
  user        User operations (quota, SSH keys)

Instance Bulk Deploy:
  labsctl instance bulk                 Bulk deploy instances
  labsctl instance bulk --status=STATUS Filter by status (default: not_deployed)
  labsctl instance bulk --user=USER     Filter by user
  labsctl instance bulk --throttle=N    Concurrent deploys (default: 3)
  labsctl instance bulk --dry-run       Preview only

Instance Health & Reconcile:
  labsctl instance health               Check DB vs actual container state
  labsctl instance reconcile            Fix mismatched states
  labsctl instance reconcile --apply    Apply fixes
  labsctl instance queue                Show queue status
  labsctl instance cancel               Cancel pending jobs
  labsctl instance cleanup              Remove old queue entries

Legacy Commands (backward compat):
  labsctl build, labsctl deploy, labsctl stop, labsctl start, etc.
"""

import sys
import os

BASE_DIR = os.path.dirname(os.path.realpath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from src.router import Router, Args
from src.commands.lab import LabCmd
from src.commands.instance import InstanceCmd
from src.commands.network import NetworkCmd
from src.commands.proxy import ProxyCmd
from src.commands.container import ContainerCmd
from src.commands.system import SystemCmd
from src.commands.user import UserCmd


def build_router():
    router = Router()

    # ── New command groups ──────────────────────────────────────
    router.register("lab", LabCmd)
    router.register("instance", InstanceCmd)
    router.register("network", NetworkCmd)
    router.register("proxy", ProxyCmd)
    router.register("container", ContainerCmd)
    router.register("system", SystemCmd)
    router.register("user", UserCmd)

    # ── Legacy flat commands (backward compat) ──────────────────
    def legacy_handler(cmd_name):
        def handler(args):
            from src.Lab import Lab
            from src.Arguments import Arguments as OldArgs

            old_args = OldArgs([cmd_name] + args.argv)
            session_hash = args.hash
            lab = Lab(old_args, session_hash, is_instance=True)

            method = getattr(lab, cmd_name, None)
            if method:
                method()
            else:
                print(f"Unknown legacy command: {cmd_name}")
        return handler

    for cmd in ["build", "deploy", "stop", "start", "remove", "shell", "info",
                "ensure-codeserver", "apply-preferences", "run-script",
                "list-images", "get-workers", "syncuser"]:
        router.register_legacy(cmd, legacy_handler(cmd))

    # Legacy: stream
    def legacy_stream(args):
        from src.Stream import Stream
        key = args.flag("key")
        if not key:
            print("Usage: labsctl stream --key=<key>")
            return
        s = Stream(args, key)
        s.stream()
    router.register_legacy("stream", legacy_stream)

    # Legacy: quiz
    def legacy_quiz(args):
        sub = args.get(1)
        if sub == "generate":
            from src.QuizEngine import QuizEngine
            from src.config import Config
            from src.base import Base
            base = Base()
            engine = QuizEngine(base.db)
            result = engine.generate_quiz(
                args.flag("topic"),
                args.flag("subtopic"),
                args.flag("diff") or "normal",
                args.flag("job")
            )
            import json
            print(json.dumps(result))
        else:
            print("Usage: labsctl quiz generate --topic=ID --subtopic=ID --diff=normal")
    router.register_legacy("quiz", legacy_quiz)

    # Legacy: challenge
    def legacy_challenge(args):
        from src.Challenge import Challenge
        from src.router import Args as RArgs
        from src.base import Base

        sub = args.get(1)
        if not sub:
            print("Usage: labsctl challenge <build|deploy|stop|start|remove> [options]")
            return

        base = Base()
        session_hash = args.hash
        challenge = Challenge(Args(["challenge", sub] + args.argv[2:]), session_hash)

        actions = {
            "build": challenge.build,
            "deploy": challenge.deploy,
            "stop": challenge.stop,
            "start": challenge.start,
            "remove": challenge.remove,
        }
        if sub in actions:
            actions[sub]()
        else:
            print(f"Unknown challenge command: {sub}")
    router.register_legacy("challenge", legacy_challenge)

    return router


def main():
    router = build_router()
    router.dispatch(sys.argv[1:])


if __name__ == "__main__":
    main()
