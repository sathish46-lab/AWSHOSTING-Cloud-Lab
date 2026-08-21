"""
MCP Feature Gate
Checks if MCP is enabled and if user has access.
Reads from PHP file cache (shared with LabFeatures.php).
"""

import os
import re
import logging
from typing import Optional, Dict, Any

logger = logging.getLogger(__name__)

# Cache file path (shared with PHP LabFeatures)
CACHE_FILE = "/var/cache/labs/feature_flags"

# In-memory cache for feature flags (ttl: 30 seconds)
_gate_cache = None
_gate_cache_time = 0
_GATE_CACHE_TTL = 30

# Shared MongoDB connection (reused across requests)
_mongo_client = None
_mongo_db = None


def _get_db():
    """Get or create MongoDB connection."""
    global _mongo_client, _mongo_db
    if _mongo_db is None:
        from src.config import Config
        import pymongo
        cfg = Config()
        _mongo_client = pymongo.MongoClient(cfg.mongo_uri)
        _mongo_db = _mongo_client[cfg.main_db]
    return _mongo_db


def _read_cache_file() -> Optional[Dict[str, Any]]:
    """Read the PHP cache file and extract feature flags."""
    global _gate_cache, _gate_cache_time

    import time
    now = time.time()

    # Return cached value if fresh
    if _gate_cache is not None and (now - _gate_cache_time) < _GATE_CACHE_TTL:
        return _gate_cache

    try:
        if not os.path.isfile(CACHE_FILE):
            return None

        with open(CACHE_FILE, 'r') as f:
            content = f.read()

        # Parse PHP array format: 'mcp' => true/false
        # Extract master_switches section
        mcp_enabled = True
        admin_only = False

        # Simple approach: search for the exact pattern in the whole file
        mcp_match = re.search(r"'mcp'\s*=>\s*(true|false)", content)
        if mcp_match:
            mcp_enabled = mcp_match.group(1) == 'true'

        # Check for admin_only in mcp_settings
        admin_match = re.search(r"'admin_only'\s*=>\s*(true|false)", content)
        if admin_match:
            admin_only = admin_match.group(1) == 'true'

        _gate_cache = {
            'mcp_enabled': mcp_enabled,
            'mcp_admin_only': admin_only
        }
        _gate_cache_time = now
        return _gate_cache

    except Exception as e:
        logger.warning(f"Failed to read feature gate cache: {e}")
        return None


def is_mcp_enabled() -> bool:
    """Check if MCP is enabled globally (master switch)."""
    cache = _read_cache_file()
    if cache is None:
        return True  # Default: enabled if cache unreadable
    return cache['mcp_enabled']


def is_mcp_admin_only() -> bool:
    """Check if MCP is restricted to admin users only."""
    cache = _read_cache_file()
    if cache is None:
        return False  # Default: not restricted
    return cache['mcp_admin_only']


def check_mcp_access(user: Dict[str, Any]) -> None:
    """
    Check if a user can access MCP.
    Raises PermissionError if access denied.
    """
    # 1. Master switch check
    if not is_mcp_enabled():
        raise PermissionError("MCP is currently disabled by administrator")

    # 2. Admin-only mode check
    if is_mcp_admin_only():
        # Look up user role from users collection
        try:
            db = _get_db()

            user_doc = db.users.find_one({"user_id": user.get("user_id")})
            if not user_doc:
                # Try by email
                user_doc = db.users.find_one({"email": user.get("email")})

            role = user_doc.get("role", "user") if user_doc else "user"
            if role != "superuser":
                raise PermissionError("MCP is restricted to administrator accounts only")

        except PermissionError:
            raise
        except Exception as e:
            logger.warning(f"Failed to check user role: {e}")
            # Fail closed: deny access if we can't verify role
            raise PermissionError("Unable to verify admin access")


def invalidate_cache():
    """Force re-read of cache file on next check."""
    global _gate_cache, _gate_cache_time
    _gate_cache = None
    _gate_cache_time = 0
