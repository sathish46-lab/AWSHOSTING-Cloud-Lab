import os
import time
from datetime import datetime
from src.router import Command


class InstanceBulkCmd(Command):
    """labsctl instance — bulk operations, health, reconcile."""

    name = "instance"
    description = "Instance operations including bulk deploy, health, and reconcile"
    usage = "labsctl instance <deploy|health|reconcile|status|cancel> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "deploy":    (self._deploy_bulk, "Bulk deploy instances",      "labsctl instance deploy --status=stopped --throttle=3"),
            "health":    (self._health,      "Health check (DB vs actual)","labsctl instance health"),
            "reconcile": (self._reconcile,   "Fix mismatched states",      "labsctl instance reconcile --apply"),
            "status":    (self._queue_status, "Show queue status",         "labsctl instance status"),
            "cancel":    (self._cancel,      "Cancel pending jobs",        "labsctl instance cancel"),
            "cleanup":   (self._cleanup,     "Cleanup old queue entries",  "labsctl instance cleanup --days=7"),
        }

    def _get_collection(self, name):
        """Get MongoDB collection."""
        db = self.mongo_client['tom_labs_db'] if self.mongo_client else None
        return db[name] if db else None

    def _find_labs(self, filters):
        """Find labs matching filters."""
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
        code, out = self.run(
            f"docker inspect --format='{{{{.State.Status}}}}' instance_{instance_id} 2>/dev/null",
            capture=True
        )
        if code != 0 or not out.strip():
            return 'not_found'
        return out.strip()

    def _update_lab_status(self, instance_id, status, extra_fields=None):
        """Update lab status in database."""
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

    def _update_deploy_status(self, instance_id, status, extra_fields=None):
        """Update deploy status in database."""
        col = self._get_collection('machine_labs')
        if not col:
            return

        update = {'deploy.status': status, 'updated_at': datetime.utcnow()}
        if extra_fields:
            for k, v in extra_fields.items():
                update[f'deploy.{k}'] = v

        col.update_one(
            {'instance_hash': instance_id},
            {'$set': update}
        )

    # ── Bulk Deploy ─────────────────────────────────────────────

    def _deploy_bulk(self, args):
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

        labs = list(self._get_collection('machine_labs').find([]))
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

        labs = list(self._get_collection('machine_labs').find([]))
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
