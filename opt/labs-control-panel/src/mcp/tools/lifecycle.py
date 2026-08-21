"""
MCP Lifecycle Tools
Deploy, stop, start, pause, resume, terminate, renew labs.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict
from mcp.server.fastmcp import Context


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response, run_docker_host=None):
    """Register lifecycle management tools."""

    @mcp.tool()
    async def labs_lab_status(lab: str, ctx: Context) -> str:
        """Check if a lab is running, stopped, etc."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"status": 1, "lab_type": 1, "lab_name": 1, "created_at": 1}
        )

        if not doc:
            return json_response({"error": "Lab not found"})

        return json_response({
            "instance_hash": instance_hash,
            "lab_name": doc.get("lab_name"),
            "lab_type": doc.get("lab_type"),
            "status": doc.get("status"),
            "created_at": str(doc.get("created_at", ""))
        })

    @mcp.tool()
    async def labs_lab_info(lab: str, ctx: Context) -> str:
        """Get full lab information including credentials."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"credentials": 1, "lab_type": 1, "lab_name": 1, "status": 1,
             "code_domain": 1, "gui_domain": 1, "domains": 1, "docker_ip": 1,
             "internal_ip": 1, "expose_web": 1, "http_proxies": 1}
        )

        if not doc:
            return json_response({"error": "Lab not found"})

        creds = doc.get("credentials", {})
        return json_response({
            "instance_hash": instance_hash,
            "lab_name": doc.get("lab_name"),
            "lab_type": doc.get("lab_type"),
            "status": doc.get("status"),
            "code_domain": doc.get("code_domain"),
            "gui_domain": doc.get("gui_domain"),
            "domains": doc.get("domains", []),
            "docker_ip": doc.get("docker_ip") or creds.get("docker_ip"),
            "tunnel_ip": creds.get("tunnel_ip"),
            "credentials": {
                "ssh": creds.get("ssh"),
                "ssh_proxy": creds.get("ssh_proxy"),
                "code_server_url": creds.get("code_server_url"),
                "gui_url": creds.get("gui_url"),
                "password": creds.get("password"),
                "su_pass": creds.get("su_pass"),
                "vnc_pass": creds.get("vnc_pass"),
                "wg_pubkey": creds.get("wg_pubkey"),
            } if creds else None
        })

    @mcp.tool()
    async def labs_lab_logs(lab: str, ctx: Context, lines: int = 100) -> str:
        """Get deployment logs for a lab."""
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        # Get from instances collection (deploy_log)
        inst_doc = db.instances.find_one(
            {"instance_hash": instance_hash},
            {"deploy_log": 1}
        )

        if not inst_doc:
            # Check machine_labs for any error info
            lab_doc = db.machine_labs.find_one(
                {"instance_hash": instance_hash},
                {"last_error": 1, "error_at": 1, "status": 1}
            )
            if lab_doc:
                return json_response({
                    "instance_hash": instance_hash,
                    "logs": f"Status: {lab_doc.get('status')}\nLast error: {lab_doc.get('last_error', 'None')}\nError at: {lab_doc.get('error_at', 'N/A')}",
                    "source": "machine_labs"
                })
            return json_response({"error": "Lab not found in instances"})

        deploy_log = inst_doc.get("deploy_log", "")
        if deploy_log:
            log_lines = deploy_log.strip().split('\n')
            return json_response({
                "instance_hash": instance_hash,
                "logs": '\n'.join(log_lines[-lines:]),
                "source": "deploy_log"
            })

        return json_response({
            "instance_hash": instance_hash,
            "logs": "No deploy logs available",
            "source": "deploy_log"
        })

    @mcp.tool()
    async def labs_deploy_lab(lab: str, ctx: Context) -> str:
        """Deploy a lab.
        
        Returns deployment result with logs. For real-time progress tracking,
        poll labs_deploy_progress after calling this tool.
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "deploy", f"--hash={instance_hash}", f"--user={user['username']}", timeout=300)
        
        # Add progress info to result
        from src.mcp.deploy_progress import get_progress_from_deploy_log
        deploy_log = {"logs": result.get("stdout", "").strip().split('\n') if result.get("stdout") else []}
        progress = get_progress_from_deploy_log(deploy_log, "deploy")
        result["progress"] = progress
        
        return json_response(result)

    @mcp.tool()
    async def labs_stop_lab(lab: str, ctx: Context) -> str:
        """Stop a running lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "stop", f"--hash={instance_hash}", timeout=60)
        return json_response(result)

    @mcp.tool()
    async def labs_start_lab(lab: str, ctx: Context) -> str:
        """Start a stopped lab."""
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "start", f"--hash={instance_hash}", timeout=60)
        return json_response(result)

    @mcp.tool()
    async def labs_deploy_progress(lab: str, ctx: Context) -> str:
        """Get real-time deployment progress for a lab.
        
        Poll this tool after calling labs_deploy_lab or labs_redeploy_lab
        to get current progress percentage and status.
        
        Returns:
        - progress: Percentage (0-100)
        - label: Current step description
        - status: "pending", "running", "completed", or "failed"
        - current_step: Formatted progress string
        - logs: Recent log lines
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        # Get deploy_log from machine_labs
        lab_doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"deploy_log": 1, "deploy": 1, "status": 1}
        )

        if not lab_doc:
            return json_response({"error": "Lab not found"})

        deploy_log = lab_doc.get("deploy_log", {})
        deploy_info = lab_doc.get("deploy", {})
        lab_status = lab_doc.get("status", "unknown")

        # Check if deployment is in progress
        if lab_status == "deploying" or deploy_info.get("status") == "deploying":
            # Parse logs for progress
            from src.mcp.deploy_progress import parse_deploy_progress
            logs = deploy_log.get("logs", [])
            progress = parse_deploy_progress(logs, "deploy")
            progress["recent_logs"] = logs[-10:] if logs else []
            return json_response(progress)

        # Deployment completed or failed
        from src.mcp.deploy_progress import get_progress_from_deploy_log
        progress = get_progress_from_deploy_log(deploy_log, "deploy")
        progress["recent_logs"] = deploy_log.get("logs", [])[-10:] if deploy_log else []
        
        return json_response(progress)

    @mcp.tool()
    async def labs_pause_lab(lab: str, ctx: Context) -> str:
        """Pause a lab (freeze container). Zero CPU, memory preserved, resume is instant."""
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)
        
        if not run_docker_host:
            return json_response({"error": "Docker host commands not available"})
        
        # 1. Pause container on host
        result = await run_docker_host(f"docker pause {instance_hash}", timeout=30)
        if not result.get("success"):
            return json_response({"error": f"Failed to pause container: {result.get('stderr', 'Unknown error')}"})
        
        # 2. Update status in MongoDB
        try:
            from src.mcp.server import get_db
            db = get_db()
            db.machine_labs.update_one(
                {"instance_hash": instance_hash},
                {"$set": {"status": "paused"}}
            )
        except Exception as e:
            logger.warning(f"Failed to update DB status: {e}")
        
        return json_response({"success": True, "message": "Lab paused", "hash": instance_hash})

    @mcp.tool()
    async def labs_resume_lab(lab: str, ctx: Context) -> str:
        """Resume a paused lab. Restores CPU, memory, network instantly."""
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)
        
        if not run_docker_host:
            return json_response({"error": "Docker host commands not available"})
        
        # 1. Resume container on host
        result = await run_docker_host(f"docker unpause {instance_hash}", timeout=30)
        if not result.get("success"):
            return json_response({"error": f"Failed to resume container: {result.get('stderr', 'Unknown error')}"})
        
        # 2. Update status in MongoDB
        try:
            from src.mcp.server import get_db
            db = get_db()
            db.machine_labs.update_one(
                {"instance_hash": instance_hash},
                {"$set": {"status": "running"}}
            )
        except Exception as e:
            logger.warning(f"Failed to update DB status: {e}")
        
        return json_response({"success": True, "message": "Lab resumed", "hash": instance_hash})

    @mcp.tool()
    async def labs_terminate_lab(lab: str, ctx: Context, confirm: bool = False) -> str:
        """Permanently delete a lab."""
        if not confirm:
            return json_response({"error": "Confirmation required. Pass confirm=true to proceed."})

        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "remove", f"--hash={instance_hash}", timeout=60)
        return json_response(result)

    @mcp.tool()
    async def labs_renew_lab(lab: str, ctx: Context, days: int = 7) -> str:
        """Renew lab expiration (extend TTL)."""
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        # Update expiration in machine_labs
        from datetime import datetime, timedelta
        new_expiry = datetime.utcnow() + timedelta(days=days)

        db.machine_labs.update_one(
            {"instance_hash": instance_hash, "user_id": user["user_id"]},
            {"$set": {"expires_at": new_expiry}}
        )

        return json_response({
            "instance_hash": instance_hash,
            "renewed_until": new_expiry.isoformat()
        })

    @mcp.tool()
    async def labs_ensure_codeserver(lab: str, ctx: Context) -> str:
        """Start code-server on demand."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "ensure-codeserver", f"--hash={instance_hash}", f"--user={user['username']}", timeout=60)
        return json_response(result)

    @mcp.tool()
    async def labs_code_status(lab: str, ctx: Context) -> str:
        """Check if code-server is running."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "code-status", f"--hash={instance_hash}", f"--user={user['username']}", timeout=30)
        return json_response(result)

    @mcp.tool()
    async def labs_run_startup_script(lab: str, ctx: Context) -> str:
        """Run the lab's init.sh startup script."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "run-script", f"--hash={instance_hash}", f"--user={user['username']}", timeout=120)
        return json_response(result)

    @mcp.tool()
    async def labs_apply_preferences(lab: str, ctx: Context) -> str:
        """Hot-reload Traefik + run init.sh."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_labsctl("lab", "apply-preferences", f"--hash={instance_hash}", f"--user={user['username']}", timeout=120)
        return json_response(result)

    @mcp.tool()
    async def labs_wait_for_deploy(lab: str, ctx: Context, timeout: int = 300) -> str:
        """Wait for lab deployment to complete."""
        import asyncio
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        start_time = asyncio.get_event_loop().time()
        while True:
            if asyncio.get_event_loop().time() - start_time > timeout:
                return json_response({"error": "Timeout waiting for deployment", "instance_hash": instance_hash})

            doc = db.machine_labs.find_one(
                {"instance_hash": instance_hash},
                {"status": 1, "last_error": 1}
            )

            if doc:
                status = doc.get("status")
                if status == "running":
                    return json_response({"instance_hash": instance_hash, "status": "running", "message": "Deployment complete"})
                elif status == "error":
                    return json_response({"instance_hash": instance_hash, "status": "error", "error": doc.get("last_error")})

            await asyncio.sleep(5)

    @mcp.tool()
    async def labs_test_autologin(lab: str, ctx: Context) -> str:
        """Test SSH auto-login to lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"credentials": 1}
        )

        if not doc or not doc.get("credentials"):
            return json_response({"error": "No credentials found"})

        creds = doc["credentials"]
        tunnel_ip = creds.get("tunnel_ip")
        username = user["username"]

        if not tunnel_ip:
            return json_response({"error": "No tunnel IP available"})

        # Test SSH connection
        cmd = f"ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no {username}@{tunnel_ip} 'echo OK'"
        result = await run_docker_exec(instance_hash, username, cmd, timeout=15)

        return json_response({
            "instance_hash": instance_hash,
            "test": "ssh_autologin",
            "success": result["success"],
            "output": result["stdout"],
            "error": result["stderr"] if not result["success"] else None
        })

    @mcp.tool()
    async def labs_verify_restart(lab: str, ctx: Context) -> str:
        """Verify lab restarts correctly."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        # Stop then start
        stop_result = await run_labsctl("lab", "stop", f"--hash={instance_hash}", timeout=60)
        if not stop_result["success"]:
            return json_response({"error": "Stop failed", "details": stop_result})

        import asyncio
        await asyncio.sleep(3)

        start_result = await run_labsctl("lab", "start", f"--hash={instance_hash}", timeout=60)
        if not start_result["success"]:
            return json_response({"error": "Start failed", "details": start_result})

        # Verify running
        await asyncio.sleep(5)
        from src.mcp.server import get_db
        db = get_db()
        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"status": 1}
        )

        return json_response({
            "instance_hash": instance_hash,
            "verified": doc.get("status") == "running",
            "status": doc.get("status") if doc else "unknown"
        })
