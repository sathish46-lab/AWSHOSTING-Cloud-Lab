import subprocess
import json
import re
import time
import os
from collections import deque

# Store up to 20 samples (100 seconds of history) to match frontend charts
HISTORY = {}
LIMIT = 20 
CACHE_FILE = '/dev/shm/docker_stats.json'

def get_cpu_throttle_percent(container_id):
    """Read cgroup v2 CPU throttling stats for a container."""
    try:
        # Get container's long ID (full hash)
        result = subprocess.run(
            ["docker", "inspect", "--format", "{{.Id}}", container_id],
            capture_output=True, text=True, timeout=5
        )
        full_id = result.stdout.strip()
        
        # Try cgroup v2 first
        cgroup_path = f"/sys/fs/cgroup/system.slice/docker-{full_id}.scope/cpu.stat"
        if not os.path.exists(cgroup_path):
            # Try cgroup v1
            cgroup_path = f"/sys/fs/cgroup/cpu/docker/{full_id}/cpu.stat"
        
        if os.path.exists(cgroup_path):
            with open(cgroup_path, 'r') as f:
                stats = {}
                for line in f:
                    parts = line.strip().split()
                    if len(parts) == 2:
                        stats[parts[0]] = int(parts[1])
                
                nr_periods = stats.get('nr_periods', 0)
                nr_throttled = stats.get('nr_throttled', 0)
                
                if nr_periods > 0:
                    return round((nr_throttled / nr_periods) * 100, 1)
        
        # Fallback: try reading from host cgroup path via docker exec
        result = subprocess.run(
            ["docker", "exec", container_id, "cat", "/sys/fs/cgroup/cpu.stat"],
            capture_output=True, text=True, timeout=5
        )
        if result.returncode == 0:
            stats = {}
            for line in result.stdout.strip().split('\n'):
                parts = line.strip().split()
                if len(parts) == 2:
                    try:
                        stats[parts[0]] = int(parts[1])
                    except ValueError:
                        pass
            
            nr_periods = stats.get('nr_periods', 0)
            nr_throttled = stats.get('nr_throttled', 0)
            
            if nr_periods > 0:
                return round((nr_throttled / nr_periods) * 100, 1)
    except Exception:
        pass
    
    return 0.0

def collect_all_stats():
    cmd = ["docker", "stats", "--no-stream", "--format", "{{json .}}"]
    while True:
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
            all_stats = {}
            for line in result.stdout.splitlines():
                if not line.strip(): continue
                data = json.loads(line)
                name = data['Name']
                # 1. Parse Numeric Values
                cpu = float(data['CPUPerc'].replace('%', ''))
                pids = int(data['PIDs'])
                
                mem_raw = data['MemUsage'].split(' / ')[0]
                mem_val = float(re.sub(r'[^\d\.]', '', mem_raw) or 0)
                mem = mem_val * 1024 if 'G' in mem_raw else mem_val
                
                net_raw = data['NetIO'].split(' / ')[0]
                net = float(re.sub(r'[^\d\.]', '', net_raw) or 0)
                if 'M' in net_raw: net *= 1000
                elif 'G' in net_raw: net *= 1000000
                
                block_raw = data['BlockIO'].split(' / ')[0]
                block = float(re.sub(r'[^\d\.]', '', block_raw) or 0)
                if 'M' in block_raw: block *= 1000
                elif 'G' in block_raw: block *= 1000000
                
                # Get CPU throttling percentage
                container_id = data.get('ID', data.get('Container', ''))
                cpu_throttle = get_cpu_throttle_percent(container_id)

                # 2. Initialize History Deques
                if name not in HISTORY:
                    HISTORY[name] = {k: deque(maxlen=LIMIT) for k in ['cpu', 'mem', 'net', 'block', 'pids', 'l1', 'l5', 'l15']}

                # 3. Calculate Simulated Load Averages
                l1 = round(cpu / 100, 4)
                l5 = round(sum(list(HISTORY[name]['cpu'])[-12:]) / 1200, 4) if HISTORY[name]['cpu'] else l1
                l15 = round(sum(list(HISTORY[name]['cpu'])[-20:]) / 2000, 4) if HISTORY[name]['cpu'] else l1

                # 4. Update History
                HISTORY[name]['cpu'].append(cpu)
                HISTORY[name]['mem'].append(mem)
                HISTORY[name]['net'].append(net)
                HISTORY[name]['block'].append(block)
                HISTORY[name]['pids'].append(pids)
                HISTORY[name]['l1'].append(l1)
                HISTORY[name]['l5'].append(l5)
                HISTORY[name]['l15'].append(l15)

                # 5. Build Final Data Object
                data.update({
                    "cpu_h": list(HISTORY[name]['cpu']),
                    "mem_h": list(HISTORY[name]['mem']),
                    "net_h": list(HISTORY[name]['net']),
                    "block_h": list(HISTORY[name]['block']),
                    "pids_h": list(HISTORY[name]['pids']),
                    "l1_h": list(HISTORY[name]['l1']),
                    "l5_h": list(HISTORY[name]['l5']),
                    "l15_h": list(HISTORY[name]['l15']),
                    "Load1": l1, "Load5": l5, "Load15": l15,
                    "PeakCPU": f"{max(HISTORY[name]['cpu']):.2f}%",
                    "HighMem": f"{max(HISTORY[name]['mem']):.1f} MB",
                    "MaxPID": max(HISTORY[name]['pids']),
                    "CPUThrottled": f"{cpu_throttle}%"
                })
                all_stats[name] = data

            # Atomic write - use backup file to prevent read races
            if all_stats:
                import tempfile
                tmp_fd, tmp_path = tempfile.mkstemp(dir='/dev/shm', suffix='.json')
                try:
                    with os.fdopen(tmp_fd, 'w') as f:
                        json.dump(all_stats, f)
                        f.flush()
                        os.fsync(f.fileno())
                    # Copy backup to main file (safer than rename for concurrent reads)
                    import shutil
                    shutil.copy2(tmp_path, CACHE_FILE)
                    os.chmod(CACHE_FILE, 0o644)  # Ensure www-data can read
                finally:
                    try: os.unlink(tmp_path)
                    except: pass
        except Exception as e: print(f"Error: {e}")
        time.sleep(5)

if __name__ == "__main__": collect_all_stats()