import pika
import json
import subprocess
import os
import sys
import time
import threading
import queue
import pymongo
from concurrent.futures import ThreadPoolExecutor

# Configuration
AMQP_HOST = os.environ.get('RABBITMQ_HOST', '127.0.0.1')
AMQP_PORT = int(os.environ.get('RABBITMQ_PORT', '5672'))
AMQP_USER = os.environ.get('RABBITMQ_USER', 'admin')
AMQP_PASS = os.environ.get('RABBITMQ_PASS', '')
QUEUE_NAME = 'labs_jobs'
LOG_FILE = '/var/log/labsctl/labsctl.log'
MAX_WORKERS = int(os.environ.get('MAX_WORKERS', '5'))

def _file_log(msg):
    try:
        os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
        with open(LOG_FILE, 'a') as f:
            f.write(msg + '\n')
    except Exception:
        pass

def _get_mongo_client(db_name='tom_labs_instances_db'):
    mongo_user = os.environ.get('MONGO_USER', '')
    mongo_pass = os.environ.get('MONGO_PASS', '')
    mongo_host = os.environ.get('MONGO_HOST', '127.0.0.1')
    mongo_port = os.environ.get('MONGO_PORT', '27018')

    env_paths = ['/var/www/env.json', '/host_www/www/env.json', '../../env.json']
    for ep in env_paths:
        try:
            with open(ep) as f:
                env_cfg = json.load(f)
            db_file = env_cfg.get('database_file', '')
            if db_file and '://' in db_file:
                import re
                m = re.match(r'mongodb://([^:]+):([^@]+)@([^:/]+):(\d+)/', db_file)
                if m:
                    mongo_user = m.group(1)
                    mongo_pass = m.group(2)
                    mongo_host = m.group(3)
                    mongo_port = m.group(4)
            elif not mongo_user:
                mongo_user = env_cfg.get('mongo_user', '')
                mongo_pass = env_cfg.get('mongo_pass', '')
            if mongo_user and mongo_pass:
                break
        except (FileNotFoundError, KeyError):
            continue

    if not mongo_user or not mongo_pass:
        return None
    uri = f"mongodb://{mongo_user}:{mongo_pass}@{mongo_host}:{mongo_port}/?authSource=admin"
    return pymongo.MongoClient(uri, serverSelectionTimeoutMS=3000)[db_name]

def _get_amqp_connection():
    creds = pika.PlainCredentials(AMQP_USER, AMQP_PASS)
    params = pika.ConnectionParameters(host=AMQP_HOST, port=AMQP_PORT, credentials=creds)
    return pika.BlockingConnection(params)

def reap_expired_challenges():
    print(" [Reaper] Started background thread to clean up expired challenges.")
    while True:
        try:
            db = _get_mongo_client('tom_labs_db')
            if not db:
                time.sleep(15)
                continue

            now_time = time.time()
            for inst in db.challenge_instances.find({"status": {"$in": ["running", "completed"]}}):
                hash_id = inst['instance_hash']
                user = inst['username']
                created_at = inst.get('created_at', now_time)
                expires_at = inst.get('expires_at') or created_at + (inst.get('duration', 15) * 60)
                if now_time >= expires_at:
                    subprocess.run([
                        'sudo', '/usr/bin/python3', '/opt/labs-control-panel/labsctl.py',
                        'challenge', 'stop', f'--user={user}', f'--hash={hash_id}'
                    ])
                    try:
                        db.challenge_instances.update_one(
                            {"instance_hash": hash_id}, {"$set": {"mission_started": False}}
                        )
                    except Exception:
                        pass
        except Exception as e:
            print(f" [Reaper Error] {e}")
        time.sleep(15)

def log_to_user(channel, exchange_name, routing_key, message):
    try:
        payload = json.dumps({'log': message})
        channel.basic_publish(exchange=exchange_name, routing_key=routing_key, body=payload)
    except Exception as e:
        print(f"Failed to log to user: {e}")

def _save_deploy_logs(instance_hash, logs, error_msg, is_build=False, action='deploy'):
    try:
        db = _get_mongo_client('tom_labs_instances_db')
        if not db:
            return

        now = time.time()
        log_key = 'build_log' if is_build else 'deploy_log'
        log_doc = {
            'logs': logs[-200:],
            'status': 'error' if error_msg else 'success',
            'message': error_msg or '',
            'created_at': now,
            'expire_at': now + 300
        }

        status_val = 'error' if error_msg else ('running' if action == 'deploy' else None)
        set_fields = {f'deploy.{log_key}': log_doc, 'deploy.last_error': error_msg or ''}
        if status_val is not None:
            set_fields['status'] = status_val
            set_fields['deploy.status'] = status_val

        db.instances.update_one({'instance_hash': instance_hash}, {'$set': set_fields})

        # Also update machine_labs so the UI reflects the status
        labs_db = _get_mongo_client('tom_labs_db')
        if labs_db:
            labs_db.machine_labs.update_one(
                {'deploy.instance_hash': instance_hash},
                {'$set': set_fields}
            )
    except Exception as e:
        print(f" [_save_deploy_logs] FAILED: {e}")

def _run_job(job_data):
    """Execute a single job — thread owns its own AMQP connection"""
    instance_hash = None
    routing_key = None
    thread_name = threading.current_thread().name

    try:
        job = json.loads(job_data)
        instance_hash = job.get('hash')
        routing_key = f"logs.{instance_hash}"
        user = job.get('user')
        action = job.get('action', 'deploy')
        lab = job.get('lab', 'essentials')

        print(f" [x] [{thread_name}] Received: {action} {instance_hash}")

        conn = _get_amqp_connection()
        ch = conn.channel()

        log_to_user(ch, "amq.topic", routing_key, f"[Queue] Job accepted. Worker [{thread_name}] assigned.")
        _file_log(f"=== {time.strftime('%Y-%m-%d %H:%M:%S')} | {action} | {instance_hash} | user={user} | worker={thread_name} ===")

        # Build command
        if job.get('is_challenge'):
            cmd = ['sudo', '/usr/bin/python3', '/opt/labs-control-panel/labsctl.py',
                   'challenge', action, f"--user={user}", f"--hash={instance_hash}",
                   f"--challenge={job.get('challenge_id')}"]
        elif lab == 'instance':
            cmd = ['sudo', '/usr/bin/python3', '/opt/labs-control-panel/labsctl.py',
                   'instance', action, f"--user={user}", f"--hash={instance_hash}"]
        else:
            cmd = ['sudo', '/usr/bin/python3', '/opt/labs-control-panel/labsctl.py',
                   'lab', action, f"--user={user}", f"--hash={instance_hash}"]
            for flag, key in [("--vsc-domain", "vsc_domain"),
                              ("--minio-console-domain", "minio_console_domain"),
                              ("--minio-api-domain", "minio_api_domain"),
                              ("--n8n-domain", "n8n_domain")]:
                if key in job:
                    cmd.append(f"{flag}={job[key]}")

        # TEST MODE
        if job.get('test_mode'):
            for step in range(3):
                log_to_user(ch, "amq.topic", routing_key, f"[TEST] Simulation step {step+1}/3...")
                time.sleep(1)
            log_to_user(ch, "amq.topic", routing_key, "[TEST] Simulation completed.")
            conn.close()
            return

        # REAL EXECUTION
        start_time = time.time()
        _file_log(f"  Executing: {' '.join(cmd)}")

        process = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True)

        error_lines = []
        all_logs = []
        for line in process.stdout:
            clean_line = line.strip()
            if clean_line:
                log_to_user(ch, "amq.topic", routing_key, clean_line)
                _file_log(f"  {clean_line}")
                all_logs.append(clean_line)
                if clean_line.startswith('[!]') or 'error' in clean_line.lower() or 'failed' in clean_line.lower():
                    error_lines.append(clean_line)

        process.wait()

        is_build = (action == 'build')
        if process.returncode == 0:
            if action == 'stop':
                log_to_user(ch, "amq.topic", routing_key, "[*] Instance stopped. Page will reload in few seconds.")
            else:
                log_to_user(ch, "amq.topic", routing_key, "[*] Your lab is up and ready to experiment... Page will reload in few seconds.")
            log_to_user(ch, "amq.topic", routing_key, "[*] reload")
            _file_log(f"[OK] {action} completed for {instance_hash}")
            _save_deploy_logs(instance_hash, all_logs, None, is_build, action)
        else:
            error_msg = error_lines[-1] if error_lines else f"Exit code {process.returncode}"
            log_to_user(ch, "amq.topic", routing_key, f"[!] {error_msg}")
            log_to_user(ch, "amq.topic", routing_key, "[*] reload")
            _file_log(f"[FAIL] {action} failed for {instance_hash}: {error_msg}")
            _save_deploy_logs(instance_hash, all_logs, error_msg, is_build, action)

        conn.close()

    except Exception as e:
        print(f" [!] [{thread_name}] Error: {e}")
        _file_log(f"[SYSERR] {e}")
        if routing_key:
            try:
                err_conn = _get_amqp_connection()
                err_ch = err_conn.channel()
                log_to_user(err_ch, "amq.topic", routing_key, f"[!] System Error: {str(e)}")
                log_to_user(err_ch, "amq.topic", routing_key, "[*] reload")
                err_conn.close()
            except Exception:
                pass
        if instance_hash:
            _save_deploy_logs(instance_hash, [f"[!] System Error: {str(e)}"], str(e))

    finally:
        print(f" [x] [{thread_name}] Job Done")


def main():
    reaper = threading.Thread(target=reap_expired_challenges, daemon=True)
    reaper.start()

    _file_log(f"=== Worker started | MAX_WORKERS={MAX_WORKERS} | PID={os.getpid()} ===")

    executor = ThreadPoolExecutor(max_workers=MAX_WORKERS)
    print(f" [*] Worker pool started (MAX_WORKERS={MAX_WORKERS})")

    while True:
        try:
            connection = _get_amqp_connection()
            channel = connection.channel()
            channel.queue_declare(queue=QUEUE_NAME, durable=True)
            channel.basic_qos(prefetch_count=MAX_WORKERS)

            print(f" [*] Connected to '{QUEUE_NAME}' — polling...")

            while connection.is_open:
                try:
                    method_frame, properties, body = channel.basic_get(queue=QUEUE_NAME, auto_ack=False)

                    if method_frame is None:
                        time.sleep(0.2)
                        continue

                    body_str = body.decode('utf-8') if isinstance(body, bytes) else body

                    # Ack on main thread (safe — only this thread touches this connection)
                    channel.basic_ack(delivery_tag=method_frame.delivery_tag)

                    # Submit to thread pool — threads never touch main channel
                    executor.submit(_run_job, body_str)

                except Exception as e:
                    print(f" Poll error: {e}")
                    break

        except pika.exceptions.AMQPConnectionError:
            print(" Connection lost, retrying in 5s...")
            time.sleep(5)
        except Exception as e:
            print(f" Unexpected error: {e}")
            time.sleep(5)

if __name__ == '__main__':
    main()
