"""
MCP SSH Key Tools
SSH keys add/list/delete/enable.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from datetime import datetime
from typing import Any, Dict
import subprocess
import tempfile
import os


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register SSH key management tools."""

    @mcp.tool()
    async def labs_ssh_keys(user: Dict[str, Any], ctx: Context) -> str:
        """List SSH keys for the current user."""
        user = await get_current_user(ctx)
        from src.mcp.server import get_db
        db = get_db()

        keys = list(db.ssh_keys.find(
            {"username": user["username"]},
            {"public_key": 1, "title": 1, "enabled": 1, "created_at": 1, "expiration_date": 1}
        ))

        return json_response({
            "username": user["username"],
            "keys": [
                {
                    "title": k.get("title"),
                    "fingerprint": k.get("fingerprint", "unknown"),
                    "enabled": k.get("enabled", True),
                    "created_at": str(k.get("created_at", "")),
                    "expiration_date": str(k.get("expiration_date", "")),
                    "preview": k.get("public_key", "")[:50] + "..."
                }
                for k in keys
            ],
            "count": len(keys)
        })

    @mcp.tool()
    async def labs_add_ssh_key(title: str, public_key: str, ctx: Context, expiration_date: str = None) -> str:
        """Add an SSH key."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        from src.mcp.server import get_db
        db = get_db()

        # Validate public key format
        if not public_key.strip().startswith(('ssh-rsa ', 'ssh-ed25519 ', 'ecdsa-sha2-', 'sk-ssh-ed25519@', 'sk-ecdsa-sha2-')):
            return json_response({"error": "Invalid public key format"})

        # Generate fingerprint
        with tempfile.NamedTemporaryFile(mode='w', suffix='.pub', delete=False) as f:
            f.write(public_key.strip())
            tmp_path = f.name

        try:
            result = subprocess.run(['ssh-keygen', '-lf', tmp_path], capture_output=True, text=True)
            fingerprint = result.stdout.split()[1] if result.stdout else "unknown"
        finally:
            os.unlink(tmp_path)

        doc = {
            "username": user["username"],
            "user_id": user["user_id"],
            "title": title,
            "public_key": public_key.strip(),
            "fingerprint": fingerprint,
            "enabled": True,
            "created_at": datetime.utcnow(),
        }

        if expiration_date:
            doc["expiration_date"] = expiration_date

        result = db.ssh_keys.insert_one(doc)

        # Sync to running labs
        await run_labsctl("lab", "sync-user", f"--user={user['username']}", timeout=60)

        return json_response({
            "key_id": str(result.inserted_id),
            "title": title,
            "fingerprint": fingerprint,
            "added": True
        })

    @mcp.tool()
    async def labs_delete_ssh_key(fingerprint: str, ctx: Context) -> str:
        """Delete an SSH key by fingerprint."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        from src.mcp.server import get_db
        db = get_db()

        result = db.ssh_keys.delete_one({
            "username": user["username"],
            "fingerprint": fingerprint
        })

        if result.deleted_count > 0:
            # Sync to running labs
            await run_labsctl("lab", "sync-user", f"--user={user['username']}", timeout=60)

        return json_response({
            "fingerprint": fingerprint,
            "deleted": result.deleted_count > 0
        })

    @mcp.tool()
    async def labs_enable_ssh_key(fingerprint: str, enabled: bool, ctx: Context) -> str:
        """Enable/disable an SSH key."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        from src.mcp.server import get_db
        db = get_db()

        result = db.ssh_keys.update_one(
            {"username": user["username"], "fingerprint": fingerprint},
            {"$set": {"enabled": enabled}}
        )

        if result.modified_count > 0:
            # Sync to running labs
            await run_labsctl("lab", "sync-user", f"--user={user['username']}", timeout=60)

        return json_response({
            "fingerprint": fingerprint,
            "enabled": enabled,
            "updated": result.modified_count > 0
        })
