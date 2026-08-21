"""
MCP Service Tools
Databases, service credentials, service users.
"""
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register service management tools."""

    @mcp.tool()
    async def labs_databases(lab: str, ctx: Context) -> str:
        """List databases for a lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        doc = db.machine_labs.find_one(
            {"instance_hash": instance_hash},
            {"credentials": 1, "lab_type": 1}
        )

        if not doc:
            return json_response({"error": "Lab not found"})

        creds = doc.get("credentials", {})
        databases = []

        # Check for common database credentials
        for key, value in creds.items():
            if any(db_type in key.lower() for db_type in ['mysql', 'postgres', 'mongo', 'redis', 'database']):
                databases.append({"type": key, "connection": value})

        return json_response({
            "instance_hash": instance_hash,
            "lab_type": doc.get("lab_type"),
            "databases": databases
        })

    @mcp.tool()
    async def labs_create_database(lab: str, db_type: str, name: str, ctx: Context) -> str:
        """Create a database."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        # Validate inputs
        import re
        if not re.match(r'^[a-zA-Z0-9_]+$', name):
            return json_response({"error": "Invalid database name"})

        valid_types = ['mysql', 'postgresql', 'mongodb', 'redis']
        if db_type not in valid_types:
            return json_response({"error": f"Invalid database type. Supported: {valid_types}"})

        # Run appropriate creation command in container
        if db_type == 'mysql':
            cmd = f"mysql -u root -e \"CREATE DATABASE IF NOT EXISTS {name};\""
        elif db_type == 'postgresql':
            cmd = f"psql -U postgres -c \"CREATE DATABASE {name};\""
        elif db_type == 'mongodb':
            cmd = f"mongosh --eval \"db.getSiblingDB('{name}').createCollection('init')\""
        elif db_type == 'redis':
            return json_response({"error": "Redis databases are numbered 0-15, use SELECT command"})

        result = await run_docker_exec(
            instance_hash,
            "root",
            cmd,
            timeout=30
        )

        return json_response({
            "instance_hash": instance_hash,
            "database": name,
            "type": db_type,
            "created": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_delete_database(lab: str, db_type: str, name: str, ctx: Context) -> str:
        """Delete a database."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        import re
        if not re.match(r'^[a-zA-Z0-9_]+$', name):
            return json_response({"error": "Invalid database name"})

        valid_types = ['mysql', 'postgresql', 'mongodb']
        if db_type not in valid_types:
            return json_response({"error": f"Invalid database type. Supported: {valid_types}"})

        if db_type == 'mysql':
            cmd = f"mysql -u root -e \"DROP DATABASE IF EXISTS {name};\""
        elif db_type == 'postgresql':
            cmd = f"psql -U postgres -c \"DROP DATABASE {name};\""
        elif db_type == 'mongodb':
            cmd = f"mongosh --eval \"db.getSiblingDB('{name}').dropDatabase()\""

        result = await run_docker_exec(
            instance_hash,
            "root",
            cmd,
            timeout=30
        )

        return json_response({
            "instance_hash": instance_hash,
            "database": name,
            "type": db_type,
            "deleted": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_service_credentials(lab: str, ctx: Context) -> str:
        """List service credentials for a lab."""
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

        if not doc:
            return json_response({"error": "Lab not found"})

        creds = doc.get("credentials", {})
        service_creds = {k: v for k, v in creds.items()
                         if any(s in k.lower() for s in ['api', 'key', 'token', 'secret', 'password', 'cred'])}

        return json_response({
            "instance_hash": instance_hash,
            "service_credentials": service_creds
        })

    @mcp.tool()
    async def labs_create_service_credential(lab: str, name: str, value: str, ctx: Context) -> str:
        """Create a service credential."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        import re
        if not re.match(r'^[a-zA-Z0-9_]+$', name):
            return json_response({"error": "Invalid credential name"})

        from src.mcp.server import get_db
        db = get_db()

        db.machine_labs.update_one(
            {"instance_hash": instance_hash, "user_id": user["user_id"]},
            {"$set": {f"credentials.{name}": value}}
        )

        return json_response({
            "instance_hash": instance_hash,
            "credential": name,
            "created": True
        })

    @mcp.tool()
    async def labs_delete_service_credential(lab: str, name: str, ctx: Context) -> str:
        """Delete a service credential."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        from src.mcp.server import get_db
        db = get_db()

        db.machine_labs.update_one(
            {"instance_hash": instance_hash, "user_id": user["user_id"]},
            {"$unset": {f"credentials.{name}": ""}}
        )

        return json_response({
            "instance_hash": instance_hash,
            "credential": name,
            "deleted": True
        })

    @mcp.tool()
    async def labs_service_users(lab: str, ctx: Context) -> str:
        """List service users for a lab."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_docker_exec(
            instance_hash,
            "root",
            "getent passwd | grep -E '^([^:]+):x:[0-9]+:' | cut -d: -f1,3,6",
            timeout=10
        )

        users = []
        for line in result["stdout"].strip().split('\n'):
            if line:
                parts = line.split(':')
                if len(parts) >= 3:
                    users.append({
                        "username": parts[0],
                        "uid": parts[1],
                        "home": parts[2]
                    })

        return json_response({
            "instance_hash": instance_hash,
            "users": users,
            "count": len(users)
        })

    @mcp.tool()
    async def labs_add_service_user(lab: str, username: str, ctx: Context, password: str = None) -> str:
        """Add a service user."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        import re
        if not re.match(r'^[a-zA-Z0-9_-]+$', username):
            return json_response({"error": "Invalid username"})

        cmd = f"useradd -m -s /bin/bash {username}"
        if password:
            import base64
            encoded = base64.b64encode(password.encode()).decode()
            cmd += f" && echo {encoded} | base64 -d | passwd --stdin {username}"

        result = await run_docker_exec(
            instance_hash,
            "root",
            cmd,
            timeout=30
        )

        return json_response({
            "instance_hash": instance_hash,
            "username": username,
            "added": result["success"],
            "result": result
        })

    @mcp.tool()
    async def labs_remove_service_user(lab: str, username: str, ctx: Context) -> str:
        """Remove a service user."""
        user = await get_current_user(ctx)
        user = await get_current_user(ctx)
        ownership = get_ownership()
        instance_hash = ownership.resolve_lab(user, lab_name=lab)

        result = await run_docker_exec(
            instance_hash,
            "root",
            f"userdel -r {username}",
            timeout=30
        )

        return json_response({
            "instance_hash": instance_hash,
            "username": username,
            "removed": result["success"],
            "result": result
        })
