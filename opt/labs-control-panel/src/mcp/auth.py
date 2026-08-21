"""
MCP Server Authentication Middleware
Validates Bearer tokens against mcp_tokens collection.
Includes feature gate checks (master switch + admin-only mode).
"""

import hashlib
import bcrypt
import logging
from datetime import datetime
from typing import Optional, Dict, Any

from src.config import Config
from src.mcp.feature_gate import check_mcp_access

logger = logging.getLogger(__name__)

class AuthError(Exception):
    """Authentication/authorization error."""
    pass


class MCPAuth:
    """MCP Token validation and user resolution."""

    def __init__(self, mongo_client=None):
        self.config = Config()
        if mongo_client:
            self.db = mongo_client[self.config.main_db]
        else:
            import pymongo
            self.db = pymongo.MongoClient(self.config.mongo_uri)[self.config.main_db]

    def validate_bearer_token(self, token: str) -> Dict[str, Any]:
        """
        Validate MCP access token, return user identity.
        Checks feature gate after token validation.

        Args:
            token: The access token string

        Returns:
            Dict with user_id, username, email, client_id, scopes

        Raises:
            AuthError: If token is invalid, expired, revoked, or feature disabled
        """
        if not token:
            raise AuthError("Missing access token")

        token_hash = hashlib.sha256(token.encode()).hexdigest()

        doc = self.db.mcp_tokens.find_one({
            "access_token_hash": token_hash,
            "access_expires_at": {"$gte": datetime.utcnow()},
            "revoked": {"$ne": True}
        })

        if not doc:
            raise AuthError("Invalid or expired token")

        if not bcrypt.checkpw(token.encode(), doc["access_token_enc"].encode()):
            raise AuthError("Token verification failed")

        # Update last_used_at (non-blocking)
        try:
            self.db.mcp_tokens.update_one(
                {"_id": doc["_id"]},
                {"$set": {"last_used_at": datetime.utcnow()}}
            )
        except Exception as e:
            logger.warning(f"Failed to update last_used_at: {e}")

        user = {
            "user_id": doc["user_id"],
            "username": doc["username"],
            "email": doc["email"],
            "client_id": doc["client_id"],
            "scopes": doc.get("scopes", ["labs:*"])
        }

        # Feature gate check (master switch + admin-only mode)
        try:
            check_mcp_access(user)
        except PermissionError as e:
            raise AuthError(str(e))

        return user

    def check_scope(self, user: Dict[str, Any], required_scope: str) -> bool:
        """Check if user has required scope."""
        user_scopes = user.get("scopes", [])
        # Support wildcard scopes
        for scope in user_scopes:
            if scope == required_scope:
                return True
            if scope.endswith("*") and required_scope.startswith(scope[:-1]):
                return True
        return False


# Global auth instance (initialized on server start)
_auth_instance: Optional[MCPAuth] = None


def get_auth() -> MCPAuth:
    """Get or create the global auth instance."""
    global _auth_instance
    if _auth_instance is None:
        _auth_instance = MCPAuth()
    return _auth_instance


async def validate_bearer_token(token: str) -> Dict[str, Any]:
    """Convenience function for validating tokens in tool handlers."""
    return get_auth().validate_bearer_token(token)
