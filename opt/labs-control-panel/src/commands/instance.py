import os
import time
import tempfile
import shutil
from datetime import datetime, timedelta
from src.router import Command


class InstanceCmd(Command):
    """labsctl instance — instances collection operations."""

    name = "instance"
    description = "Instance operations (single + bulk deploy, health, reconcile)"
    usage = "labsctl instance <build|deploy|stop|start|remove|status|health|reconcile|bulk|cancel> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            # Single instance operations
            "build":     (self._build,      "Build instance image",       "labsctl instance build --hash=HASH"),
            "deploy":    (self._deploy,     "Deploy single instance",     "labsctl instance deploy --hash=HASH"),
            "stop":      (self._stop,       "Stop instance",              "labsctl instance stop --hash=HASH"),
            "start":     (self._start,      "Start instance",             "labsctl instance start --hash=HASH"),
            "restart":   (self._restart,    "Restart instance",           "labsctl instance restart --hash=HASH"),
            "remove":    (self._remove,     "Remove instance",            "labsctl instance remove --hash=HASH"),
            "status":    (self._status,     "Show instance status",       "labsctl instance status --hash=HASH"),
            # Bulk operations
            "bulk":      (self._bulk_deploy,"Bulk deploy instances",      "labsctl instance bulk --status=stopped --throttle=3"),
            "health":    (self._health,     "Health check (DB vs actual)","labsctl instance health"),
            "reconcile": (self._reconcile,  "Fix mismatched states",      "labsctl instance reconcile --apply"),
            "queue":     (self._queue_status,"Show queue status",         "labsctl instance queue"),
            "cancel":    (self._cancel,     "Cancel pending jobs",        "labsctl instance cancel"),
            "cleanup":   (self._cleanup,    "Cleanup old queue entries",  "labsctl instance cleanup --days=7"),
        }

    def _inst_col(self):
        idb = self.instances_db()
        return idb.instances if idb else None

    def _get(self, instance_id):
        col = self._inst_col()
        return col.find_one({"instance_hash": instance_id}) if col else None

    def _set_status(self, instance_id, status):
        col = self._inst_col()
        if col:
            col.update_one(
                {"instance_hash": instance_id},
                {"$set": {"status": status, "updated_at": time.time()}}
            )

    def _set_deploy_field(self, instance_id, field, value):
        col = self._inst_col()
        if col:
            col.update_one(
                {"instance_hash": instance_id},
                {"$set": {f"deploy.{field}": value, "updated_at": time.time()}}
            )

    def _resolve_template(self, data):
        for field in ["template", "lab_type", "type"]:
            name = data.get(field, "")
            if name and os.path.isdir(os.path.join(self.cfg.templates_dir, name)):
                return name
        return "essentials"

    def _get_collection(self, name):
        """Get MongoDB collection from main DB."""
        db = self.mongo_client['tom_labs_db'] if self.mongo_client else None
        return db[name] if db else None

    def _find_labs(self, filters):
        """Find labs matching filters from machine_labs."""
        col = self._get_collection('machine_labs')
        if not col:
            return []

        query = {}
        if 'status' in filters:
            query['status'] = filters['status']
        if 'email' in filters:
            query['email'] = {'$regex': filters['email'], '$options': 'i'}
        if 'hash' in filters:
            query['instance_hash'] = filters['hash']

        return list(col.find(query))

    def _get_container_status(self, instance_id):
        """Get actual Docker container status."""
        # Try both naming conventions: instance_{hash} and {hash}
        for name in [f"instance_{instance_id}", instance_id]:
            code, out = self.run(
                f"docker inspect --format='{{{{.State.Status}}}}' {name} 2>/dev/null",
                capture=True
            )
            if code == 0 and out.strip():
                return out.strip()
        return 'not_found'

    def _update_lab_status(self, instance_id, status, extra_fields=None):
        """Update lab status in machine_labs."""
        col = self._get_collection('machine_labs')
        if not col:
            return

        update = {'status': status, 'updated_at': datetime.utcnow()}
        if extra_fields:
            update.update(extra_fields)

        col.update_one(
            {'instance_hash': instance_id},
            {'$set': update}
        )

    # ── Build ───────────────────────────────────────────────────

    def _build(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        template_name = self._resolve_template(data)
        image_tag = f"instance_{instance_id}:latest"
        self.log(f"Building {image_tag} from {template_name}...")

        self._set_status(instance_id, "building")

        build_dir = tempfile.mkdtemp(prefix=f"build_{instance_id[:8]}_")
        try:
            # Copy base template
            src = os.path.join(self.cfg.templates_dir, template_name)
            if not os.path.isdir(src):
                self.log(f"Template not found: {src}", "error")
                self._set_status(instance_id, "error")
                return

            for item in os.listdir(src):
                s = os.path.join(src, item)
                d = os.path.join(build_dir, item)
                if os.path.isdir(s):
                    shutil.copytree(s, d)
                else:
                    shutil.copy2(s, d)

            # Overlay user files from DB
            files_db = self.mongo_client["tom_labs_instances_db"] if self.mongo_client else None
            if files_db:
                user_doc = files_db.instance_files.find_one({"instance_id": instance_id})
                if user_doc:
                    count = 0
                    for fpath, fdata in user_doc.get("files", {}).items():
                        if isinstance(fdata, dict) and fdata.get("is_dir"):
                            continue
                        content = fdata.get("content", "") if isinstance(fdata, dict) else ""
                        if not content:
                            continue
                        content = content.replace("\r\n", "\n")
                        full = os.path.join(build_dir, fpath)
                        os.makedirs(os.path.dirname(full), exist_ok=True)
                        with open(full, "w") as f:
                            f.write(content)
                        count += 1
                    self.log(f"Overlaid {count} user files")

            # Docker build
            cmd = "DOCKER_BUILDKIT=1 docker build"
            if args.has("no-cache"):
                cmd += " --no-cache"
            cmd += f" -t {image_tag} {build_dir}"

            code, _ = self.run(cmd)
            if code == 0:
                self.log(f"Image built: {image_tag}", "success")
                self._set_status(instance_id, "built")
                # Store build info
                col = self._inst_col()
                if col:
                    col.update_one(
                        {"instance_hash": instance_id},
                        {"$set": {"build": {
                            "image_tag": image_tag,
                            "built_at": time.time(),
                            "template": template_name,
                        }}}
                    )
                self.run("docker image prune -f")
            else:
                self.log(f"Build failed (exit {code})", "error")
                self._set_status(instance_id, "error")
        finally:
            shutil.rmtree(build_dir, ignore_errors=True)

    # ── Deploy (Single) ─────────────────────────────────────────

    def _deploy(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        deploy_data = data.get("deploy", {})
        if not deploy_data:
            self.log("No deploy data. Run deploy_instance.php first.", "error")
            return

        # Check for pre-built image
        image_tag = f"instance_{instance_id}:latest"
        if self.docker_image_exists(image_tag):
            self.log(f"Using pre-built image: {image_tag}")
            self._set_deploy_field(instance_id, "image", image_tag)

        self._set_status(instance_id, "deploying")
        self._set_deploy_field(instance_id, "status", "deploying")

        # Delegate to LabCmd deploy logic
        from src.commands.lab import LabCmd
        lab_cmd = LabCmd(None)
        lab_cmd.db = self.db
        lab_cmd.base = self
        lab_cmd.cfg = self.cfg

        # Override _get_deploy_data so LabCmd uses the data we already fetched
        # from instances collection (not machine_labs)
        deploy = data.get("deploy", {})
        lab_cmd._get_deploy_data = lambda iid: deploy

        # Override write methods to target instances collection, not machine_labs
        col = self._inst_col()
        def _inst_set_deploy_field(iid, field, value):
            if col:
                col.update_one({"instance_hash": iid}, {"$set": {f"deploy.{field}": value, "updated_at": time.time()}})
        def _inst_set_deploy_fields(iid, fields):
            if col:
                sf = {}
                for k, v in fields.items():
                    sf[f"deploy.{k}"] = v
                sf["updated_at"] = time.time()
                col.update_one({"instance_hash": iid}, {"$set": sf})
        lab_cmd._set_deploy_field = _inst_set_deploy_field
        lab_cmd._set_deploy_fields = _inst_set_deploy_fields

        # Override _fail_deploy to target instances collection
        def _inst_fail_deploy(iid, msg):
            import sys, time as _t
            lab_cmd.log(msg, "error")
            _inst_set_deploy_field(iid, "status", "error")
            if col:
                now = _t.time()
                col.update_one({"instance_hash": iid}, {"$set": {
                    "deploy.last_error": msg,
                    "deploy.error_at": now,
                    "deploy.deploy_log": {
                        "logs": [f"[!] {msg}"],
                        "status": "error",
                        "message": msg,
                        "created_at": now,
                        "expire_at": now + 300
                    },
                    "status": "error",
                    "updated_at": now
                }})
            sys.exit(1)
        lab_cmd._fail_deploy = _inst_fail_deploy

        # Build args for lab deploy
        lab_args = type("Args", (), {
            "hash": instance_id,
            "user": data.get("username"),
            "flag": lambda self, n, d=None: args.flag(n, d),
            "has": lambda self, n: args.has(n),
            "get": lambda self, n=0: None,
        })()

        try:
            lab_cmd._deploy(lab_args)
        except (SystemExit, Exception) as e:
            self.log(f"Deploy failed: {e}", "error")
            now = time.time()
            if self._inst_col():
                self._inst_col().update_one(
                    {"instance_hash": instance_id},
                    {"$set": {
                        "deploy.status": "error",
                        "deploy.last_error": str(e),
                        "deploy.error_at": now,
                        "deploy.deploy_log": {
                            "logs": [f"[!] {e}"],
                            "status": "error",
                            "message": str(e),
                            "created_at": now,
                            "expire_at": now + 300
                        },
                        "status": "error",
                        "updated_at": now
                    }}
                )
            return

        # Update final status
        final = self._get(instance_id)
        deploy_status = final.get("deploy", {}).get("status", "error") if final else "error"
        final_status = "running" if deploy_status == "running" else "error"
        self._set_status(instance_id, final_status)
        self._set_deploy_field(instance_id, "status", final_status)

    # ── Stop ────────────────────────────────────────────────────

    def _stop(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Stopping instance {instance_id}...")
        self._set_status(instance_id, "stopping")
        self._set_deploy_field(instance_id, "status", "stopping")

        data = self._get(instance_id)
        if data:
            creds = data.get("deploy", {}).get("credentials", {})
            if not isinstance(creds, dict):
                creds = {}
            tunnel_ip = creds.get("tunnel_ip")
            if tunnel_ip:
                self.remove_route(tunnel_ip)

        self.docker_stop(instance_id)
        self._set_status(instance_id, "stopped")
        self._set_deploy_field(instance_id, "status", "stopped")
        self.remove_traefik(instance_id)
        self.log("Instance stopped.", "success")

    # ── Start ───────────────────────────────────────────────────

    def _start(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self.log(f"Starting instance {instance_id}...")
        self._set_status(instance_id, "starting")
        self._set_deploy_field(instance_id, "status", "starting")

        if not self.docker_exists(instance_id):
            self.log(f"Container not found: {instance_id}", "error")
            self._set_status(instance_id, "error")
            return

        self.run(f"docker start {instance_id}")

        data = self._get(instance_id)
        if data:
            creds = data.get("deploy", {}).get("credentials", {})
            if not isinstance(creds, dict):
                creds = {}
            tunnel_ip = creds.get("tunnel_ip")
            docker_ip = creds.get("docker_ip")
            lab_pub_key = creds.get("wg_pubkey")

            if tunnel_ip and lab_pub_key:
                self.log("Re-applying WireGuard peer...")
                self.run(f"wg show wg0 allowed-ips | grep '{tunnel_ip}/32' | awk '{{print $1}}' | xargs -I{{}} wg set wg0 peer {{}} remove 2>/dev/null || true")
                self.run(f"wg set wg0 peer {lab_pub_key} allowed-ips {tunnel_ip}/32")

            if tunnel_ip and docker_ip:
                self.log("Re-applying routing...")
                bridge = self.detect_bridge()
                self.configure_routing(tunnel_ip, docker_ip, bridge)

        self._set_status(instance_id, "running")
        self._set_deploy_field(instance_id, "status", "running")
        self.log("Instance started.", "success")

    # ── Restart ──────────────────────────────────────────────────

    def _restart(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        self._stop(args)
        self._start(args)

    # ── Remove ──────────────────────────────────────────────────

    def _remove(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        username = data.get("username") if data else None

        self._stop(args)

        # Release quota if user has no more labs (check machine_labs collection)
        if username and self.db:
            remaining = self.db.machine_labs.count_documents({
                "username": username,
                "instance_hash": {"$ne": instance_id}
            })
            if remaining == 0:
                from src.commands.lab import LabCmd
                lab_cmd = LabCmd(None)
                lab_cmd.db = self.db
                lab_cmd.cfg = self.cfg
                lab_cmd._release_user_quota(username)
                self.log(f"User {username} has no more labs, quota released")

    # ── Status (Single) ─────────────────────────────────────────

    def _status(self, args):
        instance_id = args.hash
        if not instance_id:
            self.log("Missing --hash", "error")
            return

        data = self._get(instance_id)
        if not data:
            self.log(f"Instance not found: {instance_id}", "error")
            return

        status = data.get("status", "unknown")
        deploy = data.get("deploy", {})
        creds = deploy.get("credentials", {})
        if not isinstance(creds, dict):
            creds = {}

        print(f"\n  Instance: {instance_id}")
        print(f"  Status:   {status}")
        print(f"  Type:     {data.get('type', 'unknown')}")
        print(f"  Template: {data.get('template', 'unknown')}")
        if creds.get("tunnel_ip"):
            print(f"  VPN IP:   {creds['tunnel_ip']}")
        if creds.get("docker_ip"):
            print(f"  Docker IP:{creds['docker_ip']}")
        if creds.get("code_server_url"):
            print(f"  Code URL: {creds['code_server_url']}")
        print()

    # ═══════════════════════════════════════════════════════════════
    # BULK OPERATIONS
    # ═══════════════════════════════════════════════════════════════

    # ── Bulk Deploy ─────────────────────────────────────────────

    def _bulk_deploy(self, args):
        """Bulk deploy instances with throttling."""
        status = args.flag('status', 'stopped')
        user = args.user
        throttle = int(args.flag('throttle', '3'))
        timeout = int(args.flag('timeout', '600'))
        reason = args.flag('reason', 'bulk_deploy')
        dry_run = args.has('dry-run')

        print(f"\n  {'[DRY RUN] ' if dry_run else ''}Bulk Deploy")
        print("  " + "=" * 60)

        # Find labs
        filters = {'status': status}
        if user:
            filters['email'] = user

        labs = self._find_labs(filters)
        if not labs:
            print(f"  No labs found with status '{status}'")
            if user:
                print(f"  User filter: {user}")
            print()
            return

        print(f"  Found: {len(labs)} lab(s)")
        print(f"  Status filter: {status}")
        if user:
            print(f"  User filter: {user}")
        print(f"  Throttle: {throttle} concurrent")
        print(f"  Timeout: {timeout}s")
        print(f"  Reason: {reason}")
        print()

        if dry_run:
            for i, lab in enumerate(labs, 1):
                hash_id = lab.get('instance_hash', '?')
                email = lab.get('email', '?')
                lab_type = lab.get('lab_type', '?')
                print(f"  [{i}/{len(labs)}] {hash_id} ({email}) - {lab_type}")
            print()
            print(f"  Total: {len(labs)} lab(s) would be deployed")
            print()
            return

        # Enqueue jobs
        queue_col = self._get_collection('deploy_queue')
        if not queue_col:
            print("  ERROR: Cannot access deploy_queue collection")
            return

        enqueued = 0
        skipped = 0
        for lab in labs:
            hash_id = lab.get('instance_hash')

            # Check if already queued
            existing = queue_col.find_one({
                'instance_hash': hash_id,
                'status': {'$in': ['queued', 'processing']}
            })
            if existing:
                skipped += 1
                continue

            job = {
                'instance_hash': hash_id,
                'user_id': lab.get('user_id'),
                'email': lab.get('email'),
                'lab_type': lab.get('lab_type', 'essentials'),
                'status': 'queued',
                'reason': reason,
                'created_at': datetime.utcnow(),
                'started_at': None,
                'completed_at': None,
                'error': None,
                'retries': 0,
                'max_retries': 2
            }
            queue_col.insert_one(job)
            enqueued += 1

        print(f"  Enqueued: {enqueued}, Skipped: {skipped}")
        print()

        # Process queue
        processed = 0
        failed = 0
        start_time = time.time()

        while (time.time() - start_time) < timeout:
            # Get next job
            job = queue_col.find_one_and_update(
                {'status': 'queued'},
                {'$set': {'status': 'processing', 'started_at': datetime.utcnow()}},
                sort=[('created_at', 1)]
            )

            if not job:
                break

            # Check throttle
            processing_count = queue_col.count_documents({'status': 'processing'})
            if processing_count > throttle:
                time.sleep(2)
                queue_col.update_one(
                    {'_id': job['_id']},
                    {'$set': {'status': 'queued'}}
                )
                continue

            # Execute deploy
            hash_id = job['instance_hash']
            email = job.get('email', '?')
            lab_type = job.get('lab_type', '?')
            print(f"  Deploying: {hash_id} ({email}) - {lab_type}")

            self._update_lab_status(hash_id, 'deploying')

            # Call labsctl deploy
            cmd = f"labsctl deploy --hash={hash_id}"
            if email:
                username = email.split('@')[0]
                cmd += f" --user={username}"

            code, output = self.run(cmd, capture=True)

            if code == 0:
                queue_col.update_one(
                    {'_id': job['_id']},
                    {'$set': {
                        'status': 'completed',
                        'completed_at': datetime.utcnow(),
                        'result': {'success': True, 'output': output}
                    }}
                )
                processed += 1
                print(f"    ✓ Success")
            else:
                retries = job.get('retries', 0) + 1
                if retries < job.get('max_retries', 2):
                    queue_col.update_one(
                        {'_id': job['_id']},
                        {'$set': {
                            'status': 'queued',
                            'retries': retries,
                            'error': output
                        }}
                    )
                else:
                    queue_col.update_one(
                        {'_id': job['_id']},
                        {'$set': {
                            'status': 'failed',
                            'completed_at': datetime.utcnow(),
                            'error': output
                        }}
                    )
                    failed += 1
                    print(f"    ✗ Failed (retries exhausted)")

        remaining = queue_col.count_documents({'status': 'queued'})
        print()
        print(f"  Completed: {processed}, Failed: {failed}, Remaining: {remaining}")
        print()

    # ── Health Check ────────────────────────────────────────────

    def _health(self, args):
        """Check DB status vs actual container state."""
        print("\n  Health Check")
        print("  " + "=" * 60)

        labs = list(self._get_collection('machine_labs').find({}))
        if not labs:
            print("  No labs found")
            print()
            return

        mismatches = 0
        for lab in labs:
            hash_id = lab.get('instance_hash', '?')
            email = lab.get('email', '?')
            db_status = lab.get('status', 'unknown')
            container_status = self._get_container_status(hash_id)

            # Determine expected status
            if container_status == 'running':
                expected = 'running'
            elif container_status in ('exited', 'dead'):
                expected = 'stopped'
            else:
                expected = 'not_deployed'

            mismatch = db_status != expected
            if mismatch:
                mismatches += 1

            icon = '✗' if mismatch else '✓'
            color = '\033[31m' if mismatch else '\033[32m'
            reset = '\033[0m'

            print(f"  {color}{icon}{reset} {hash_id}")
            print(f"    Email: {email}")
            print(f"    DB: {db_status}, Container: {container_status}, Expected: {expected}")

        print()
        print(f"  Total: {len(labs)}, Mismatches: {mismatches}")
        print()

    # ── Reconcile ───────────────────────────────────────────────

    def _reconcile(self, args):
        """Fix mismatched states."""
        apply = args.has('apply')

        print("\n  State Reconciliation")
        print("  " + "=" * 60)

        if not apply:
            print("  DRY RUN (use --apply to fix)")
            print()

        labs = list(self._get_collection('machine_labs').find({}))
        fixed = 0

        for lab in labs:
            hash_id = lab.get('instance_hash', '?')
            db_status = lab.get('status', 'unknown')
            container_status = self._get_container_status(hash_id)

            if container_status == 'running':
                expected = 'running'
            elif container_status in ('exited', 'dead'):
                expected = 'stopped'
            else:
                expected = 'not_deployed'

            if db_status != expected:
                if apply:
                    self._update_lab_status(hash_id, expected, {
                        'reconciled_at': datetime.utcnow()
                    })
                    if expected == 'running':
                        self._update_deploy_status(hash_id, 'running', {
                            'reconciled_at': datetime.utcnow()
                        })
                    print(f"  Fixed: {hash_id} ({db_status} → {expected})")
                else:
                    print(f"  Would fix: {hash_id} ({db_status} → {expected})")
                fixed += 1

        print()
        print(f"  Total: {len(labs)}, Fixed: {fixed}")
        print()

    # ── Queue Status ────────────────────────────────────────────

    def _queue_status(self, args):
        """Show queue status."""
        queue_col = self._get_collection('deploy_queue')
        if not queue_col:
            print("  Queue not available")
            return

        queued = queue_col.count_documents({'status': 'queued'})
        processing = queue_col.count_documents({'status': 'processing'})
        completed = queue_col.count_documents({'status': 'completed'})
        failed = queue_col.count_documents({'status': 'failed'})

        print("\n  Queue Status")
        print("  " + "=" * 60)
        print(f"  Queued:     {queued}")
        print(f"  Processing: {processing}")
        print(f"  Completed:  {completed}")
        print(f"  Failed:     {failed}")

        # Recent jobs
        recent = list(queue_col.find().sort('created_at', -1).limit(10))
        if recent:
            print()
            print("  Recent Jobs:")
            for job in recent:
                status = job.get('status', '?')
                hash_id = job.get('instance_hash', '?')[:12]
                reason = job.get('reason', '?')
                created = job.get('created_at')
                ts = created.strftime('%Y-%m-%d %H:%M') if created else '?'
                print(f"    [{status}] {hash_id}... ({reason}) - {ts}")

        print()

    # ── Cancel ──────────────────────────────────────────────────

    def _cancel(self, args):
        """Cancel pending jobs."""
        queue_col = self._get_collection('deploy_queue')
        if not queue_col:
            print("  Queue not available")
            return

        result = queue_col.update_many(
            {'status': 'queued'},
            {'$set': {'status': 'cancelled', 'cancelled_at': datetime.utcnow()}}
        )

        print(f"\n  Cancelled {result.modified_count} job(s)")
        print()

    # ── Cleanup ─────────────────────────────────────────────────

    def _cleanup(self, args):
        """Cleanup old queue entries."""
        days = int(args.flag('days', '7'))
        queue_col = self._get_collection('deploy_queue')
        if not queue_col:
            print("  Queue not available")
            return

        cutoff = datetime.utcnow() - timedelta(days=days)
        result = queue_col.delete_many({
            'status': {'$in': ['completed', 'failed', 'cancelled']},
            'completed_at': {'$lt': cutoff}
        })

        print(f"\n  Cleaned up {result.deleted_count} old job(s) (>{days} days)")
        print()
