"""
MCP Account Tools
User identity, history, and MCP client management.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict
from mcp.server.fastmcp import Context


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register account-related tools."""

    @mcp.tool()
    async def labs_whoami(ctx: Context) -> str:
        """Get current authenticated user identity."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        return json_response({
            "user_id": user["user_id"],
            "username": user["username"],
            "email": user["email"],
            "client_id": user["client_id"],
            "scopes": user["scopes"]
        })

    @mcp.tool()
    async def labs_list_labs(ctx: Context) -> str:
        """List all labs owned by the current user."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        labs = ownership.list_user_labs(user)
        return json_response({
            "labs": [
                {
                    "instance_hash": lab["instance_hash"],
                    "lab_name": lab.get("lab_name"),
                    "lab_type": lab.get("lab_type"),
                    "status": lab.get("status"),
                    "created_at": str(lab.get("created_at", ""))
                }
                for lab in labs
            ],
            "count": len(labs)
        })

    @mcp.tool()
    async def labs_my_mcp_clients(ctx: Context) -> str:
        """List MCP clients (connected agents) for the current user."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        db = getattr(mcp, '_db', None)
        if db is None:
            from src.mcp.server import get_db
            db = get_db()

        clients = list(db.mcp_clients.find(
            {"user_id": user["user_id"], "revoked": {"$ne": True}},
            {"client_id": 1, "client_name": 1, "redirect_uris": 1, "scopes": 1, "created_at": 1, "last_used_at": 1}
        ))

        return json_response({
            "clients": [
                {
                    "client_id": c["client_id"],
                    "client_name": c["client_name"],
                    "redirect_uris": c.get("redirect_uris", []),
                    "scopes": c.get("scopes", ["labs:*"]),
                    "connected_at": str(c.get("created_at", "")),
                    "last_used_at": str(c.get("last_used_at", ""))
                }
                for c in clients
            ],
            "count": len(clients)
        })

    @mcp.tool()
    async def labs_my_history(ctx: Context, limit: int = 50) -> str:
        """Get recent activity history for the current user."""
        user = await get_current_user(ctx)
        db = getattr(mcp, '_db', None)
        if db is None:
            from src.mcp.server import get_db
            db = get_db()

        # Try to get activity from audit log or deploy logs
        activities = []

        # Check machine_labs for deploy history
        labs = list(db.machine_labs.find(
            {"user_id": user["user_id"]},
            {"instance_hash": 1, "lab_name": 1, "lab_type": 1, "status": 1, "created_at": 1, "last_error": 1}
        ).sort("created_at", -1).limit(limit))

        for lab in labs:
            activities.append({
                "type": "lab_deploy",
                "instance_hash": lab["instance_hash"],
                "lab_name": lab.get("lab_name"),
                "lab_type": lab.get("lab_type"),
                "status": lab.get("status"),
                "timestamp": str(lab.get("created_at", "")),
                "error": lab.get("last_error")
            })

        return json_response({
            "activities": activities,
            "count": len(activities)
        })

    @mcp.tool()
    async def labs_help(ctx: Context) -> str:
        """List all available MCP tools with descriptions."""
        user = await get_current_user(ctx)
        tools = [
            {"name": "labs_whoami", "description": "Get current authenticated user identity"},
            {"name": "labs_list_labs", "description": "List all labs owned by the current user"},
            {"name": "labs_lab_status", "description": "Check if a lab is running, stopped, etc."},
            {"name": "labs_lab_info", "description": "Get full lab information including credentials"},
            {"name": "labs_lab_logs", "description": "Get deployment logs for a lab"},
            {"name": "labs_deploy_lab", "description": "Deploy a lab"},
            {"name": "labs_stop_lab", "description": "Stop a running lab"},
            {"name": "labs_start_lab", "description": "Start a stopped lab"},
            {"name": "labs_pause_lab", "description": "Pause a lab (freeze container)"},
            {"name": "labs_resume_lab", "description": "Resume a paused lab"},
            {"name": "labs_terminate_lab", "description": "Permanently delete a lab"},
            {"name": "labs_renew_lab", "description": "Renew lab expiration (extend TTL)"},
            {"name": "labs_ensure_codeserver", "description": "Start code-server on demand"},
            {"name": "labs_code_status", "description": "Check if code-server is running"},
            {"name": "labs_run_command", "description": "Run a shell command inside the lab container"},
            {"name": "labs_read_lab_file", "description": "Read a file from the lab filesystem"},
            {"name": "labs_write_lab_file", "description": "Write a file to the lab filesystem"},
            {"name": "labs_edit_lab_file", "description": "Edit a file in the lab filesystem"},
            {"name": "labs_list_lab_files", "description": "List files in a directory"},
            {"name": "labs_glob_lab_files", "description": "Find files matching a glob pattern"},
            {"name": "labs_grep_lab_files", "description": "Search file contents with grep"},
            {"name": "labs_lab_file_tree", "description": "Get directory tree structure"},
            {"name": "labs_download_lab_file", "description": "Download a file from the lab"},
            {"name": "labs_upload_lab_file", "description": "Upload a file to the lab"},
            {"name": "labs_list_lab_processes", "description": "List running processes in the lab"},
            {"name": "labs_stop_lab_process", "description": "Stop a process in the lab"},
            {"name": "labs_wait_for_process", "description": "Wait for a process to complete"},
            {"name": "labs_lab_stats", "description": "Get resource usage stats (CPU, memory, disk)"},
            {"name": "labs_storage_usage", "description": "Get detailed storage usage breakdown"},
            {"name": "labs_wait_for_deploy", "description": "Wait for lab deployment to complete"},
            {"name": "labs_test_autologin", "description": "Test SSH auto-login to lab"},
            {"name": "labs_verify_restart", "description": "Verify lab restarts correctly"},
            {"name": "labs_run_startup_script", "description": "Run the lab's init.sh startup script"},
            {"name": "labs_apply_preferences", "description": "Hot-reload Traefik + run init.sh"},
            {"name": "labs_workspace_pin", "description": "Pin a workspace tab in code-server"},
            {"name": "labs_workspace_unpin", "description": "Unpin a workspace tab"},
            {"name": "labs_workspace_preferences", "description": "Get code-server preferences"},
            {"name": "labs_apply_workspace_preferences", "description": "Apply code-server preferences"},
            {"name": "labs_networks", "description": "List Docker networks"},
            {"name": "labs_create_network", "description": "Create a Docker network"},
            {"name": "labs_delete_network", "description": "Delete a Docker network"},
            {"name": "labs_network_devices", "description": "List devices on a network"},
            {"name": "labs_add_network_device", "description": "Add a device to a network"},
            {"name": "labs_remove_network_device", "description": "Remove a device from a network"},
            {"name": "labs_wireguard_config", "description": "Get WireGuard config for a lab"},
            {"name": "labs_domains", "description": "List custom domains for a lab"},
            {"name": "labs_add_domain", "description": "Add a custom domain"},
            {"name": "labs_remove_domain", "description": "Remove a custom domain"},
            {"name": "labs_domain_ssl", "description": "Get SSL certificate info for a domain"},
            {"name": "labs_domain_error_pages", "description": "Get custom error pages for a domain"},
            {"name": "labs_databases", "description": "List databases for a lab"},
            {"name": "labs_create_database", "description": "Create a database"},
            {"name": "labs_delete_database", "description": "Delete a database"},
            {"name": "labs_service_credentials", "description": "List service credentials"},
            {"name": "labs_create_service_credential", "description": "Create service credential"},
            {"name": "labs_delete_service_credential", "description": "Delete service credential"},
            {"name": "labs_service_users", "description": "List service users"},
            {"name": "labs_add_service_user", "description": "Add a service user"},
            {"name": "labs_remove_service_user", "description": "Remove a service user"},
            {"name": "labs_ssh_keys", "description": "List SSH keys for user"},
            {"name": "labs_add_ssh_key", "description": "Add an SSH key"},
            {"name": "labs_delete_ssh_key", "description": "Delete an SSH key"},
            {"name": "labs_enable_ssh_key", "description": "Enable/disable an SSH key"},
            {"name": "labs_my_mcp_clients", "description": "List connected MCP clients"},
            {"name": "labs_my_history", "description": "Get recent activity history"},
            {"name": "labs_help", "description": "List all available MCP tools"},
        ]

        return json_response({"tools": tools, "count": len(tools)})
