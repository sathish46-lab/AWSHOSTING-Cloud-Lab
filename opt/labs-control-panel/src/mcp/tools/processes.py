"""
MCP Process Management Tools
List processes, stop processes, wait for processes, stats, storage, probe.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register process management tools."""

    @mcp.tool()
    async def labs_list_lab_processes(lab: str, ctx: Context) -> str:
        """List running processes in the lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            "ps aux --no-headers",
            timeout=10
        )

        processes = []
        for line in result["stdout"].strip().split('\n'):
            if line.strip():
                parts = line.split(None, 10)
                if len(parts) >= 11:
                    processes.append({
                        "user": parts[0],
                        "pid": int(parts[1]) if parts[1].isdigit() else 0,
                        "cpu": float(parts[2]) if parts[2].replace('.', '').isdigit() else 0,
                        "mem": float(parts[3]) if parts[3].replace('.', '').isdigit() else 0,
                        "vsz": parts[4],
                        "rss": parts[5],
                        "tty": parts[6],
                        "stat": parts[7],
                        "start": parts[8],
                        "time": parts[9],
                        "command": parts[10]
                    })

        return json_response({
            "instance_hash": instance_hash,
            "processes": processes,
            "count": len(processes)
        })

    @mcp.tool()
    async def labs_stop_lab_process(lab: str, pid: int, ctx: Context, signal: str = "TERM") -> str:
        """Stop a process in the lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"kill -{signal} {pid}",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "pid": pid,
            "signal": signal,
            "stopped": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_wait_for_process(lab: str, pid: int, ctx: Context, timeout: int = 60) -> str:
        """Wait for a process to complete."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        import asyncio
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        start_time = asyncio.get_event_loop().time()
        while True:
            if asyncio.get_event_loop().time() - start_time > timeout:
                return json_response({"error": "Timeout waiting for process", "pid": pid})

            result = await run_docker_exec(
                instance_hash,
                user["username"],
                f"ps -p {pid} -o pid= 2>/dev/null || echo 'NOT_FOUND'",
                timeout=5
            )

            if "NOT_FOUND" in result["stdout"] or not result["stdout"].strip():
                return json_response({"instance_hash": instance_hash, "pid": pid, "completed": True})

            await asyncio.sleep(2)

    @mcp.tool()
    async def labs_lab_stats(lab: str, ctx: Context) -> str:
        """Get resource usage stats (CPU, memory, disk)."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_docker_exec(
            instance_hash,
            "root",
            "docker stats --no-stream --format 'table {{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}' " + instance_hash,
            timeout=15
        )

        # Parse docker stats output
        stats = {}
        for line in result["stdout"].strip().split('\n')[1:]:  # Skip header
            if line.strip():
                parts = line.split('\t')
                if len(parts) >= 5:
                    stats = {
                        "cpu_percent": parts[0].strip(),
                        "mem_usage": parts[1].strip(),
                        "mem_percent": parts[2].strip(),
                        "net_io": parts[3].strip(),
                        "block_io": parts[4].strip()
                    }

        # Also get disk usage
        disk_result = await run_docker_exec(
            instance_hash,
            "root",
            f"df -h /var/labsstorage/home/{user['username']} 2>/dev/null | tail -1",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "container_stats": stats,
            "disk_usage": disk_result["stdout"].strip() if disk_result["success"] else "N/A"
        })

    @mcp.tool()
    async def labs_storage_usage(lab: str, ctx: Context) -> str:
        """Get detailed storage usage breakdown."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"

        # Get directory sizes
        result = await run_docker_exec(
            instance_hash,
            "root",
            f"du -h --max-depth=2 {user_home} 2>/dev/null | sort -hr | head -30",
            timeout=30
        )

        # Get total usage
        total_result = await run_docker_exec(
            instance_hash,
            "root",
            f"du -sh {user_home} 2>/dev/null",
            timeout=15
        )

        return json_response({
            "instance_hash": instance_hash,
            "user_home": user_home,
            "total_usage": total_result["stdout"].strip() if total_result["success"] else "N/A",
            "breakdown": result["stdout"].strip()
        })

    @mcp.tool()
    async def labs_probe_lab(lab: str, ctx: Context) -> str:
        """Probe lab connectivity and services."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"credentials": 1, "status": 1, "lab_type": 1}
        )

        if not doc:
            return json_response({"error": "Lab not found"})

        results = {
            "instance_hash": instance_hash,
            "status": doc.get("status"),
            "lab_type": doc.get("lab_type"),
            "checks": {}
        }

        creds = doc.get("credentials", {})
        tunnel_ip = creds.get("tunnel_ip")
        docker_ip = creds.get("docker_ip")

        # Test container running
        import subprocess
        container_check = await run_docker_exec(
            instance_hash,
            "root",
            "echo 'container_ok'",
            timeout=5
        )
        results["checks"]["container"] = {
            "running": container_check["success"],
            "output": container_check["stdout"].strip()
        }

        # Test SSH if tunnel_ip available
        if tunnel_ip:
            ssh_test = await run_docker_exec(
                instance_hash,
                user["username"],
                f"ssh -o ConnectTimeout=3 -o BatchMode=yes -o StrictHostKeyChecking=no {user['username']}@{tunnel_ip} 'echo ssh_ok' 2>&1",
                timeout=10
            )
            results["checks"]["ssh"] = {
                "reachable": ssh_test["success"],
                "output": ssh_test["stdout"].strip()[:200]
            }

        # Test code-server if running
        code_check = await run_docker_exec(
            instance_hash,
            user["username"],
            "pgrep -f code-server >/dev/null && echo 'running' || echo 'not_running'",
            timeout=5
        )
        results["checks"]["code_server"] = {
            "running": "running" in code_check["stdout"]
        }

        return json_response(results)
