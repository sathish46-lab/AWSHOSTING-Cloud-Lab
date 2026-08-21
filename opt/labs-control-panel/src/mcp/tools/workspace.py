"""
MCP Workspace Tools
Code-server workspace management, preferences, startup scripts.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register workspace management tools."""

    @mcp.tool()
    async def labs_workspace_pin(lab: str, tab_id: str, ctx: Context) -> str:
        """Pin a workspace tab in code-server."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        # code-server stores workspace state in ~/.local/share/code-server/User/workspaceStorage
        # This is a simplified implementation - in reality would need to modify workspace JSON
        user_home = f"/var/labsstorage/home/{user['username']}"
        workspace_file = f"{user_home}/.local/share/code-server/User/workspaceStorage/workspace.json"

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p $(dirname {workspace_file}) && "
            f"python3 -c \"import json, os; "
            f"data = json.load(open('{workspace_file}')) if os.path.exists('{workspace_file}') else {{}}; "
            f"data.setdefault('pinnedTabs', []); "
            f"data['pinnedTabs'].append('{tab_id}') if '{tab_id}' not in data['pinnedTabs'] else None; "
            f"json.dump(data, open('{workspace_file}', 'w'))\"",
            timeout=30
        )

        return json_response({"instance_hash": instance_hash, "pinned": tab_id, "result": result})

    @mcp.tool()
    async def labs_workspace_unpin(lab: str, tab_id: str, ctx: Context) -> str:
        """Unpin a workspace tab in code-server."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"
        workspace_file = f"{user_home}/.local/share/code-server/User/workspaceStorage/workspace.json"

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"python3 -c \"import json, os; "
            f"data = json.load(open('{workspace_file}')) if os.path.exists('{workspace_file}') else {{}}; "
            f"data.setdefault('pinnedTabs', []); "
            f"data['pinnedTabs'] = [t for t in data['pinnedTabs'] if t != '{tab_id}']; "
            f"json.dump(data, open('{workspace_file}', 'w'))\"",
            timeout=30
        )

        return json_response({"instance_hash": instance_hash, "unpinned": tab_id, "result": result})

    @mcp.tool()
    async def labs_workspace_preferences(lab: str, ctx: Context) -> str:
        """Get code-server preferences."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"
        prefs_file = f"{user_home}/.local/share/code-server/User/settings.json"

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"cat {prefs_file} 2>/dev/null || echo '{{}}'",
            timeout=10
        )

        if result["success"]:
            try:
                prefs = json.loads(result["stdout"])
                return json_response({"instance_hash": instance_hash, "preferences": prefs})
            except:
                pass

        return json_response({"instance_hash": instance_hash, "preferences": {}, "raw": result["stdout"]})

    @mcp.tool()
    async def labs_apply_workspace_preferences(lab: str, preferences: Dict[str, Any], ctx: Context) -> str:
        """Apply code-server preferences."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"
        prefs_file = f"{user_home}/.local/share/code-server/User/settings.json"

        import json
        prefs_json = json.dumps(preferences)

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p $(dirname {prefs_file}) && echo '{prefs_json}' > {prefs_file}",
            timeout=10
        )

        # Restart code-server to apply
        if result["success"]:
            await run_docker_exec(
                instance_hash,
                user["username"],
                f"pkill -f code-server || true",
                timeout=10
            )

        return json_response({"instance_hash": instance_hash, "applied": True, "result": result})

    @mcp.tool()
    async def labs_read_lab_file(lab: str, path: str, ctx: Context) -> str:
        """Read a file from the lab filesystem."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        # Normalize path
        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"cat {full_path} 2>/dev/null || echo 'FILE_NOT_FOUND'",
            timeout=10
        )

        if result["stdout"].strip() == "FILE_NOT_FOUND":
            return json_response({"error": "File not found", "path": path})

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "content": result["stdout"],
            "size": len(result["stdout"])
        })

    @mcp.tool()
    async def labs_write_lab_file(lab: str, path: str, content: str, ctx: Context) -> str:
        """Write a file to the lab filesystem."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        # Write via base64 to handle special characters
        import base64
        encoded = base64.b64encode(content.encode()).decode()

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p $(dirname {full_path}) && echo {encoded} | base64 -d > {full_path}",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "written": result["success"],
            "size": len(content),
            "result": result
        })

    @mcp.tool()
    async def labs_edit_lab_file(lab: str, path: str, old_text: str, new_text: str, ctx: Context) -> str:
        """Edit a file in the lab filesystem (replace old_text with new_text)."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        import base64
        old_encoded = base64.b64encode(old_text.encode()).decode()
        new_encoded = base64.b64encode(new_text.encode()).decode()

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"python3 -c \"import base64; "
            f"content = open('{full_path}').read() if __import__('os').path.exists('{full_path}') else ''; "
            f"old = base64.b64decode('{old_encoded}').decode(); "
            f"new = base64.b64decode('{new_encoded}').decode(); "
            f"if old in content: "
            f"  content = content.replace(old, new, 1); "
            f"  open('{full_path}', 'w').write(content); "
            f"  print('REPLACED') "
            f"else: "
            f"  print('OLD_TEXT_NOT_FOUND')\"",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "result": result["stdout"].strip(),
            "success": "REPLACED" in result["stdout"]
        })
