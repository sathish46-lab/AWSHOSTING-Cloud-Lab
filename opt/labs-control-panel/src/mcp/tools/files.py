"""
MCP File Operations Tools
File tree, read/write/edit/move/delete, glob, grep, download, directory.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

import base64
import json
from typing import Any, Dict


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register file operation tools."""

    @mcp.tool()
    async def labs_list_lab_files(lab: str, ctx: Context, path: str = "/") -> str:
        """List files in a directory."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"ls -la {full_path} 2>/dev/null || echo 'NOT_FOUND'",
            timeout=10
        )

        if "NOT_FOUND" in result["stdout"]:
            return json_response({"error": "Directory not found", "path": path})

        files = []
        for line in result["stdout"].strip().split('\n')[1:]:  # Skip total line
            parts = line.split()
            if len(parts) >= 9:
                perms = parts[0]
                size = parts[4]
                name = ' '.join(parts[8:])
                files.append({
                    "name": name,
                    "size": int(size) if size.isdigit() else 0,
                    "is_dir": perms.startswith('d'),
                    "perms": perms
                })

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "files": files,
            "count": len(files)
        })

    @mcp.tool()
    async def labs_glob_lab_files(lab: str, pattern: str, ctx: Context) -> str:
        """Find files matching a glob pattern."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"find {user_home} -path '{user_home}{pattern}' -type f 2>/dev/null | head -100",
            timeout=30
        )

        matches = [line.strip() for line in result["stdout"].strip().split('\n') if line.strip()]

        # Make paths relative to user home
        rel_matches = [m.replace(user_home, '') or '/' for m in matches]

        return json_response({
            "instance_hash": instance_hash,
            "pattern": pattern,
            "matches": rel_matches,
            "count": len(matches)
        })

    @mcp.tool()
    async def labs_grep_lab_files(lab: str, pattern: str, ctx: Context, path: str = "/") -> str:
        """Search file contents with grep."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"grep -r -n '{pattern}' {full_path} 2>/dev/null | head -50",
            timeout=30
        )

        matches = []
        for line in result["stdout"].strip().split('\n'):
            if line.strip():
                # Format: file:line_number:content
                parts = line.split(':', 2)
                if len(parts) >= 3:
                    matches.append({
                        "file": parts[0].replace(user_home, '') or '/',
                        "line": int(parts[1]) if parts[1].isdigit() else 0,
                        "content": parts[2]
                    })

        return json_response({
            "instance_hash": instance_hash,
            "pattern": pattern,
            "path": path,
            "matches": matches,
            "count": len(matches)
        })

    @mcp.tool()
    async def labs_lab_file_tree(lab: str, ctx: Context, path: str = "/", depth: int = 3) -> str:
        """Get directory tree structure."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"cd {full_path} && tree -L {depth} -J 2>/dev/null || find . -maxdepth {depth} -printf '%p\\n' 2>/dev/null | head -200",
            timeout=15
        )

        tree_output = result["stdout"].strip()

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "depth": depth,
            "tree": tree_output
        })

    @mcp.tool()
    async def labs_download_lab_file(lab: str, path: str, ctx: Context) -> str:
        """Download a file from the lab (returns base64 encoded content)."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"base64 {full_path} 2>/dev/null || echo 'FILE_NOT_FOUND'",
            timeout=15
        )

        if "FILE_NOT_FOUND" in result["stdout"]:
            return json_response({"error": "File not found", "path": path})

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "content_base64": result["stdout"].strip(),
            "encoding": "base64"
        })

    @mcp.tool()
    async def labs_upload_lab_file(lab: str, path: str, content_base64: str, ctx: Context) -> str:
        """Upload a file to the lab (base64 encoded content)."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p $(dirname {full_path}) && echo {content_base64} | base64 -d > {full_path}",
            timeout=15
        )

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "uploaded": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_move_lab_file(lab: str, source: str, destination: str, ctx: Context) -> str:
        """Move/rename a file in the lab filesystem."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"
        src = user_home + (source if source.startswith('/') else '/' + source)
        dst = user_home + (destination if destination.startswith('/') else '/' + destination)

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p $(dirname {dst}) && mv {src} {dst}",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "source": source,
            "destination": destination,
            "moved": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_delete_lab_file(lab: str, path: str, ctx: Context) -> str:
        """Delete a file or directory in the lab filesystem."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"rm -rf {full_path}",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "deleted": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_create_lab_directory(lab: str, path: str, ctx: Context) -> str:
        """Create a directory in the lab filesystem."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        if not path.startswith('/'):
            path = '/' + path
        user_home = f"/var/labsstorage/home/{user['username']}"
        full_path = user_home + path

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"mkdir -p {full_path}",
            timeout=10
        )

        return json_response({
            "instance_hash": instance_hash,
            "path": path,
            "created": result["success"],
            "result": result
        })
