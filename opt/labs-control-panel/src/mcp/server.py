#!/usr/bin/env python3
"""
Tom Labs MCP Server
FastMCP server exposing 77 lab management tools for AI agents.
With OAuth 2.1 + PKCE support and Discovery UI.
"""

import os
import sys
import json
import asyncio
import logging
import secrets
import hashlib
import base64
import time
from contextlib import asynccontextmanager
from typing import AsyncIterator, Dict, Any, Optional, List
from datetime import datetime, timedelta
from urllib.parse import urlencode, parse_qs

# Add repo root to path for `from src.mcp...` imports. Append (don't prepend)
# so the installed `mcp` library is NOT shadowed by the local `src/mcp` package.
sys.path.append(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

from mcp.server.fastmcp import FastMCP
from mcp.server import Server
from mcp.types import Tool, TextContent, CallToolRequest
from mcp.server.auth.settings import AuthSettings
from mcp.server.auth.provider import TokenVerifier, AccessToken
from starlette.requests import Request
from starlette.responses import JSONResponse, HTMLResponse, RedirectResponse
from starlette.middleware.cors import CORSMiddleware

from src.mcp.auth import get_auth, AuthError
from src.mcp.ownership import get_ownership, OwnershipError
from src.config import Config

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Global state
_config: Optional[Config] = None
_mongo_client = None

# OAuth constants
OAUTH_AUTH_CODE_TTL = 600  # 10 minutes
OAUTH_ACCESS_TOKEN_TTL = 3600  # 1 hour
OAUTH_REFRESH_TOKEN_TTL = 2592000  # 30 days
PKCE_CODE_VERIFIER_MIN_LEN = 43
PKCE_CODE_VERIFIER_MAX_LEN = 128


def get_config() -> Config:
    global _config
    if _config is None:
        _config = Config()
    return _config


def get_mongo_client():
    """Get or create MongoDB client."""
    global _mongo_client
    if _mongo_client is None:
        import pymongo
        cfg = get_config()
        _mongo_client = pymongo.MongoClient(cfg.mongo_uri)
    return _mongo_client


def get_db():
    """Get default database."""
    cfg = get_config()
    return get_mongo_client()[cfg.main_db]


# Initialize auth and ownership with shared MongoDB client
def init_mcp_dependencies():
    """Initialize MCP dependencies with shared MongoDB client."""
    client = get_mongo_client()
    cfg = get_config()
    db = client[cfg.main_db]

    # Initialize auth
    from src.mcp.auth import MCPAuth
    global _auth_instance
    _auth_instance = MCPAuth(mongo_client=client)

    # Initialize ownership
    from src.mcp.ownership import OwnershipResolver
    global _ownership_instance
    _ownership_instance = OwnershipResolver(mongo_client=client)


# Import tool modules
from src.mcp.tools import (
    account,
    lifecycle,
    workspace,
    files,
    processes,
    networks,
    domains,
    services,
    ssh,
)

# Create FastMCP server
mcp = FastMCP("Tom Labs MCP Server")


# ─── Authentication Middleware ───

async def get_current_user(token: str) -> Dict[str, Any]:
    """Validate token and return user identity."""
    auth = get_auth()
    return auth.validate_bearer_token(token)


async def get_current_user_from_context(ctx) -> Dict[str, Any]:
    """Extract and validate user from FastMCP Context (request headers)."""
    # ctx is the FastMCP Context; its request_context.request is a Starlette Request
    rc = getattr(ctx, 'request_context', None)
    if rc is None or rc.request is None:
        raise AuthError("Authentication required: no request context")
    request = rc.request
    auth_header = request.headers.get("authorization") or request.headers.get("Authorization")
    if not auth_header or not auth_header.startswith("Bearer "):
        raise AuthError("Authentication required: missing Bearer token")
    token = auth_header.split(" ", 1)[1]
    return await get_current_user(token)


# ─── Context Management ───

@asynccontextmanager
async def mcp_context(app: FastMCP = None) -> AsyncIterator[Dict[str, Any]]:
    """Provide shared context to all tool calls."""
    init_mcp_dependencies()
    yield {
        "config": get_config(),
        "db": get_db(),
        "mongo_client": get_mongo_client(),
    }


class LabsTokenVerifier(TokenVerifier):
    """Validate Bearer tokens against the mcp_tokens collection."""

    async def verify_token(self, token: str) -> AccessToken | None:
        try:
            info = get_auth().validate_bearer_token(token)
            return AccessToken(
                token=token,
                client_id=info.get("client_id", ""),
                scopes=info.get("scopes", ["labs:*"]),
            )
        except AuthError:
            return None


MCP_PUBLIC_URL = os.environ.get("MCP_PUBLIC_URL", "http://dev.tomweb.in:9080")

mcp = FastMCP(
    "Tom Labs MCP Server",
    lifespan=mcp_context,
    auth=AuthSettings(
        issuer_url=MCP_PUBLIC_URL,
        resource_server_url=f"{MCP_PUBLIC_URL}/mcp",
        required_scopes=["labs:*"],
    ),
    token_verifier=LabsTokenVerifier(),
)


# ─── Helper Functions ───

async def run_labsctl(command: str, *args: str, timeout: int = 300) -> Dict[str, Any]:
    """Run labsctl command as subprocess."""
    cfg = get_config()
    cmd = [cfg.labctl_path, command, *args]
    logger.info(f"Running: {' '.join(cmd)}")

    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)

        return {
            "exit_code": proc.returncode,
            "stdout": stdout.decode() if stdout else "",
            "stderr": stderr.decode() if stderr else "",
            "success": proc.returncode == 0
        }
    except asyncio.TimeoutError:
        return {"exit_code": -1, "stdout": "", "stderr": f"Command timed out after {timeout}s", "success": False}
    except Exception as e:
        return {"exit_code": -1, "stdout": "", "stderr": str(e), "success": False}


async def run_docker_exec(instance_hash: str, username: str, command: str, timeout: int = 60) -> Dict[str, Any]:
    """Run a command inside a lab container via docker exec."""
    cfg = get_config()
    # Validate command - block dangerous patterns
    dangerous_patterns = [
        'rm -rf /', 'mkfs', 'dd if=', '>: /dev/', 'chmod 777',
        'curl | bash', 'wget | bash', 'nc -l', 'netcat -l',
        '>/etc/passwd', '>/etc/shadow', '>/etc/sudoers'
    ]
    cmd_lower = command.lower()
    for pattern in dangerous_patterns:
        if pattern in cmd_lower:
            raise ValueError(f"Command blocked: dangerous pattern '{pattern}'")

    docker_cmd = [
        "docker", "exec",
        "-u", username,
        instance_hash,
        "bash", "-c", command
    ]

    logger.info(f"Running docker exec: {' '.join(docker_cmd[:4])} ... {command[:50]}")

    try:
        proc = await asyncio.create_subprocess_exec(
            *docker_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)

        return {
            "exit_code": proc.returncode,
            "stdout": stdout.decode() if stdout else "",
            "stderr": stderr.decode() if stderr else "",
            "success": proc.returncode == 0
        }
    except asyncio.TimeoutError:
        return {"exit_code": -1, "stdout": "", "stderr": f"Command timed out after {timeout}s", "success": False}
    except Exception as e:
        return {"exit_code": -1, "stdout": "", "stderr": str(e), "success": False}


async def run_docker_host(command: str, timeout: int = 60) -> Dict[str, Any]:
    """Run a docker command on the HOST (not inside container).
    
    Used for docker pause/unpause which must run on the host.
    The MCP server runs inside the container, so we use docker to execute on host.
    """
    cfg = get_config()
    
    # Security: only allow pause/unpause/inspect commands
    allowed_commands = ['docker pause ', 'docker unpause ', 'docker inspect ']
    if not any(command.startswith(c) for c in allowed_commands):
        return {"exit_code": -1, "stdout": "", "stderr": f"Command not allowed: {command}", "success": False}
    
    # Run via docker exec on the host container (Dev_lab)
    docker_cmd = [
        "docker", "exec",
        cfg.orchestrator_container,  # e.g., "Dev_lab"
        "bash", "-c", command
    ]
    
    logger.info(f"Running docker host command: {command[:80]}")
    
    try:
        proc = await asyncio.create_subprocess_exec(
            *docker_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        
        return {
            "exit_code": proc.returncode,
            "stdout": stdout.decode() if stdout else "",
            "stderr": stderr.decode() if stderr else "",
            "success": proc.returncode == 0
        }
    except asyncio.TimeoutError:
        return {"exit_code": -1, "stdout": "", "stderr": f"Command timed out after {timeout}s", "success": False}
    except Exception as e:
        return {"exit_code": -1, "stdout": "", "stderr": str(e), "success": False}


def json_response(data: Any) -> str:
    """Format response as JSON string."""
    return json.dumps(data, default=str, indent=2)


# ─── OAuth 2.1 + PKCE Implementation ───

def generate_code_verifier() -> str:
    """Generate PKCE code verifier (43-128 chars)."""
    return secrets.token_urlsafe(32)[:PKCE_CODE_VERIFIER_MAX_LEN]

def generate_code_challenge(verifier: str) -> str:
    """Generate PKCE code challenge (S256)."""
    digest = hashlib.sha256(verifier.encode()).digest()
    return base64.urlsafe_b64encode(digest).decode().rstrip('=')

def verify_pkce(verifier: str, challenge: str) -> bool:
    """Verify PKCE code verifier against challenge."""
    return generate_code_challenge(verifier) == challenge

def generate_token() -> str:
    """Generate secure random token."""
    return secrets.token_urlsafe(32)

def get_base_url(request: Request) -> str:
    """Get base URL from request."""
    return f"{request.url.scheme}://{request.url.netloc}"

async def get_oauth_client(db, client_id: str) -> Optional[dict]:
    """Get OAuth client by ID."""
    return db.mcp_clients.find_one({"client_id": client_id})

async def create_oauth_client(db, client_name: str, redirect_uris: List[str], scopes: List[str] = None) -> dict:
    """Create new OAuth client (dynamic registration)."""
    client_id = f"mcp_{secrets.token_urlsafe(16)}"
    client_secret = secrets.token_urlsafe(32)
    
    client_doc = {
        "client_id": client_id,
        "client_secret": client_secret,
        "client_name": client_name,
        "redirect_uris": redirect_uris,
        "scopes": scopes or ["labs:*"],
        "created_at": datetime.utcnow(),
        "updated_at": datetime.utcnow()
    }
    
    db.mcp_clients.insert_one(client_doc)
    return client_doc

async def store_auth_code(db, code: str, client_id: str, user_id: str, redirect_uri: str, 
                          scopes: List[str], code_challenge: str, code_challenge_method: str) -> None:
    """Store authorization code."""
    expires_at = datetime.utcnow() + timedelta(seconds=OAUTH_AUTH_CODE_TTL)
    
    db.mcp_oauth_auth_codes.insert_one({
        "code": code,
        "client_id": client_id,
        "user_id": user_id,
        "redirect_uri": redirect_uri,
        "scopes": scopes,
        "code_challenge": code_challenge,
        "code_challenge_method": code_challenge_method,
        "created_at": datetime.utcnow(),
        "expires_at": expires_at
    })

async def get_and_delete_auth_code(db, code: str) -> Optional[dict]:
    """Get and delete authorization code (single use)."""
    doc = db.mcp_oauth_auth_codes.find_one_and_delete({
        "code": code,
        "expires_at": {"$gt": datetime.utcnow()}
    })
    return doc

async def store_tokens(db, client_id: str, user_id: str, scopes: List[str]) -> dict:
    """Store access and refresh tokens."""
    access_token = f"mcp_{generate_token()}"
    refresh_token = f"mcp_rt_{generate_token()}"
    
    access_hash = hashlib.sha256(access_token.encode()).hexdigest()
    refresh_hash = hashlib.sha256(refresh_token.encode()).hexdigest()
    
    access_expires = datetime.utcnow() + timedelta(seconds=OAUTH_ACCESS_TOKEN_TTL)
    refresh_expires = datetime.utcnow() + timedelta(seconds=OAUTH_REFRESH_TOKEN_TTL)
    
    import bcrypt
    access_enc = bcrypt.hashpw(access_token.encode(), bcrypt.gensalt(12))
    refresh_enc = bcrypt.hashpw(refresh_token.encode(), bcrypt.gensalt(12))
    
    # Store in mcp_tokens (compatible with existing Bearer token auth)
    db.mcp_tokens.insert_one({
        "user_id": user_id,
        "client_id": client_id,
        "access_token_hash": access_hash,
        "access_token_enc": access_enc.decode(),
        "access_expires_at": access_expires,
        "refresh_token_hash": refresh_hash,
        "refresh_token_enc": refresh_enc.decode(),
        "refresh_expires_at": refresh_expires,
        "scopes": scopes,
        "created_at": datetime.utcnow(),
        "revoked": False
    })
    
    return {
        "access_token": access_token,
        "refresh_token": refresh_token,
        "expires_in": OAUTH_ACCESS_TOKEN_TTL,
        "token_type": "Bearer",
        "scope": " ".join(scopes)
    }

async def validate_refresh_token(db, refresh_token: str, client_id: str) -> Optional[dict]:
    """Validate and rotate refresh token."""
    refresh_hash = hashlib.sha256(refresh_token.encode()).hexdigest()
    
    import bcrypt
    doc = db.mcp_tokens.find_one({
        "refresh_token_hash": refresh_hash,
        "client_id": client_id,
        "refresh_expires_at": {"$gt": datetime.utcnow()},
        "revoked": {"$ne": True}
    })
    
    if not doc:
        return None
    
    if not bcrypt.checkpw(refresh_token.encode(), doc["refresh_token_enc"].encode()):
        return None
    
    # Revoke old tokens
    db.mcp_tokens.update_one(
        {"_id": doc["_id"]},
        {"$set": {"revoked": True}}
    )
    
    return {
        "user_id": doc["user_id"],
        "scopes": doc.get("scopes", ["labs:*"])
    }

# ─── Custom Routes ───

def setup_custom_routes(mcp: FastMCP):
    """Setup OAuth and Discovery UI routes."""
    
    @mcp.custom_route("/.well-known/oauth-authorization-server", methods=["GET"])
    async def oauth_metadata(request: Request):
        """OAuth 2.0 Authorization Server Metadata (RFC 8414)."""
        base = get_base_url(request)
        return JSONResponse({
            "issuer": base,
            "authorization_endpoint": f"{base}/mcp/authorize",
            "token_endpoint": f"{base}/mcp/token",
            "registration_endpoint": f"{base}/mcp/register",
            "jwks_uri": f"{base}/mcp/jwks",
            "scopes_supported": ["labs:*", "openid", "profile", "email"],
            "response_types_supported": ["code"],
            "response_modes_supported": ["query", "fragment"],
            "grant_types_supported": ["authorization_code", "refresh_token"],
            "token_endpoint_auth_methods_supported": ["client_secret_post", "client_secret_basic", "none"],
            "code_challenge_methods_supported": ["S256"],
            "subject_types_supported": ["public"],
            "id_token_signing_alg_values_supported": ["RS256"],
            "service_documentation": f"{base}/mcp",
            "ui_locales_supported": ["en"]
        })
    
    @mcp.custom_route("/.well-known/oauth-protected-resource", methods=["GET"])
    async def protected_resource_metadata(request: Request):
        """OAuth 2.0 Protected Resource Metadata (RFC 9728)."""
        base = get_base_url(request)
        return JSONResponse({
            "resource": f"{base}/mcp",
            "authorization_servers": [base],
            "scopes_supported": ["labs:*"],
            "bearer_methods_supported": ["header"]
        })
    
    @mcp.custom_route("/mcp/register", methods=["POST"])
    async def oauth_register(request: Request):
        """Dynamic Client Registration (RFC 7591)."""
        db = get_db()
        body = await request.json()
        
        client_name = body.get("client_name", "MCP Client")
        redirect_uris = body.get("redirect_uris", [])
        scopes = body.get("scope", "labs:*").split()
        
        if not redirect_uris:
            return JSONResponse(
                {"error": "invalid_redirect_uri", "error_description": "redirect_uris required"},
                status_code=400
            )
        
        client = await create_oauth_client(db, client_name, redirect_uris, scopes)
        
        return JSONResponse({
            "client_id": client["client_id"],
            "client_secret": client["client_secret"],
            "client_name": client["client_name"],
            "redirect_uris": client["redirect_uris"],
            "scopes": client["scopes"],
            "client_id_issued_at": int(client["created_at"].timestamp()),
            "client_secret_expires_at": 0
        }, status_code=201)
    
    @mcp.custom_route("/mcp/authorize", methods=["GET"])
    async def oauth_authorize(request: Request):
        """OAuth Authorization Endpoint - shows consent page."""
        db = get_db()
        
        # Extract parameters
        client_id = request.query_params.get("client_id")
        redirect_uri = request.query_params.get("redirect_uri")
        response_type = request.query_params.get("response_type", "code")
        scope = request.query_params.get("scope", "labs:*")
        state = request.query_params.get("state")
        code_challenge = request.query_params.get("code_challenge")
        code_challenge_method = request.query_params.get("code_challenge_method", "S256")
        resource = request.query_params.get("resource")
        
        # Validate client
        client = await get_oauth_client(db, client_id)
        if not client:
            return HTMLResponse(f"""
                <html><body>
                <h2>Error: Invalid Client</h2>
                <p>Client ID '{client_id}' not found.</p>
                </body></html>
            """, status_code=400)
        
        # Validate redirect_uri
        if redirect_uri not in client["redirect_uris"]:
            return HTMLResponse(f"""
                <html><body>
                <h2>Error: Invalid Redirect URI</h2>
                <p>Redirect URI not allowed for this client.</p>
                </body></html>
            """, status_code=400)
        
        # Validate PKCE
        if not code_challenge:
            return HTMLResponse(f"""
                <html><body>
                <h2>Error: PKCE Required</h2>
                <p>code_challenge parameter is required.</p>
                </body></html>
            """, status_code=400)
        
        if code_challenge_method != "S256":
            return HTMLResponse(f"""
                <html><body>
                <h2>Error: Unsupported PKCE Method</h2>
                <p>Only S256 is supported.</p>
                </body></html>
            """, status_code=400)
        
        # For now, we need user to be logged in via session/cookie
        # In production, integrate with your existing auth system
        # For demo, we'll show a consent page with a "login" link
        
        # Check if user has existing session (via cookie or header)
        # This is a simplified version - integrate with your PHP auth
        user_email = request.cookies.get("user_email") or request.headers.get("X-User-Email")
        user_id = request.cookies.get("user_id") or request.headers.get("X-User-ID")
        
        if not user_id or not user_email:
            # Show login page
            login_url = f"https://dev.tomweb.in/login?redirect=/mcp/authorize?{urlencode(dict(request.query_params))}"
            return RedirectResponse(url=login_url)
        
        # Show consent page
        scopes_list = scope.split()
        return HTMLResponse(f"""
            <!DOCTYPE html>
            <html>
            <head>
                <title>Authorize {client['client_name']} - Tom Labs MCP</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body {{ font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 60px auto; padding: 20px; }}
                    .card {{ background: white; border: 1px solid #e0e0e0; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }}
                    h2 {{ margin: 0 0 8px; color: #1a1a1a; }}
                    .client-name {{ color: #666; margin-bottom: 24px; }}
                    .scope-list {{ background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 16px 0; }}
                    .scope-item {{ display: flex; align-items: center; gap: 12px; padding: 8px 0; }}
                    .scope-item:last-child {{ border-bottom: none; }}
                    .btn {{ display: inline-block; padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-size: 14px; }}
                    .btn-primary {{ background: #ff9800; color: white; }}
                    .btn-primary:hover {{ background: #f57c00; }}
                    .btn-secondary {{ background: #f5f5f5; color: #333; margin-left: 12px; }}
                    .btn-secondary:hover {{ background: #eee; }}
                    .btn-group {{ display: flex; gap: 12px; margin-top: 24px; }}
                    .info {{ font-size: 13px; color: #888; margin-top: 16px; }}
                </style>
            </head>
            <body>
                <div class="card">
                    <h2>Authorize Application</h2>
                    <p class="client-name"><strong>{client['client_name']}</strong> wants to access your Tom Labs account</p>
                    
                    <div class="scope-list">
                        <p style="margin: 0 0 12px; font-weight: 500;">This application will be able to:</p>
                        {''.join(f'<div class="scope-item"><span style="width:20px;height:20px;border:2px solid #ff9800;border-radius:4px;flex-shrink:0;"></span><span>{escape_html(s)}</span></div>' for s in scopes_list)}
                    </div>
                    
                    <form method="POST" action="/mcp/authorize">
                        <input type="hidden" name="client_id" value="{escape_html(client_id)}">
                        <input type="hidden" name="redirect_uri" value="{escape_html(redirect_uri)}">
                        <input type="hidden" name="response_type" value="{escape_html(response_type)}">
                        <input type="hidden" name="scope" value="{escape_html(scope)}">
                        <input type="hidden" name="state" value="{escape_html(state or '')}">
                        <input type="hidden" name="code_challenge" value="{escape_html(code_challenge)}">
                        <input type="hidden" name="code_challenge_method" value="{escape_html(code_challenge_method)}">
                        <input type="hidden" name="resource" value="{escape_html(resource or '')}">
                        <input type="hidden" name="user_id" value="{escape_html(user_id)}">
                        <input type="hidden" name="user_email" value="{escape_html(user_email)}">
                        <input type="hidden" name="action" value="allow">
                        
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">Allow Access</button>
                            <button type="submit" name="action" value="deny" class="btn btn-secondary">Deny</button>
                        </div>
                    </form>
                    
                    <p class="info">You can revoke access anytime from your account settings.</p>
                </div>
            </body>
            </html>
        """)
    
    @mcp.custom_route("/mcp/authorize", methods=["POST"])
    async def oauth_authorize_post(request: Request):
        """Handle consent form submission."""
        db = get_db()
        form = await request.form()
        
        action = form.get("action")
        client_id = form.get("client_id")
        redirect_uri = form.get("redirect_uri")
        response_type = form.get("response_type")
        scope = form.get("scope")
        state = form.get("state")
        code_challenge = form.get("code_challenge")
        code_challenge_method = form.get("code_challenge_method")
        user_id = form.get("user_id")
        user_email = form.get("user_email")
        
        if action == "deny":
            params = {"error": "access_denied", "error_description": "User denied access"}
            if state:
                params["state"] = state
            return RedirectResponse(url=f"{redirect_uri}?{urlencode(params)}")
        
        # Generate authorization code
        auth_code = secrets.token_urlsafe(32)
        scopes_list = scope.split()
        
        await store_auth_code(db, auth_code, client_id, user_id, redirect_uri, 
                              scopes_list, code_challenge, code_challenge_method)
        
        # Redirect back with code
        params = {"code": auth_code}
        if state:
            params["state"] = state
        
        return RedirectResponse(url=f"{redirect_uri}?{urlencode(params)}")
    
    @mcp.custom_route("/mcp/token", methods=["POST"])
    async def oauth_token(request: Request):
        """OAuth Token Endpoint."""
        db = get_db()
        form = await request.form()
        
        grant_type = form.get("grant_type")
        client_id = form.get("client_id")
        client_secret = form.get("client_secret")
        
        # Validate client
        client = await get_oauth_client(db, client_id)
        if not client:
            return JSONResponse(
                {"error": "invalid_client", "error_description": "Invalid client"},
                status_code=401
            )
        
        # Verify client secret (if provided)
        if client_secret and client.get("client_secret") != client_secret:
            return JSONResponse(
                {"error": "invalid_client", "error_description": "Invalid client secret"},
                status_code=401
            )
        
        if grant_type == "authorization_code":
            code = form.get("code")
            code_verifier = form.get("code_verifier")
            redirect_uri = form.get("redirect_uri")
            
            auth_code_doc = await get_and_delete_auth_code(db, code)
            if not auth_code_doc:
                return JSONResponse(
                    {"error": "invalid_grant", "error_description": "Invalid or expired authorization code"},
                    status_code=400
                )
            
            # Verify client_id matches
            if auth_code_doc["client_id"] != client_id:
                return JSONResponse(
                    {"error": "invalid_grant", "error_description": "Client ID mismatch"},
                    status_code=400
                )
            
            # Verify redirect_uri matches
            if auth_code_doc["redirect_uri"] != redirect_uri:
                return JSONResponse(
                    {"error": "invalid_grant", "error_description": "Redirect URI mismatch"},
                    status_code=400
                )
            
            # Verify PKCE
            if not verify_pkce(code_verifier, auth_code_doc["code_challenge"]):
                return JSONResponse(
                    {"error": "invalid_grant", "error_description": "Invalid PKCE code verifier"},
                    status_code=400
                )
            
            # Issue tokens
            tokens = await store_tokens(db, client_id, auth_code_doc["user_id"], auth_code_doc["scopes"])
            return JSONResponse(tokens)
        
        elif grant_type == "refresh_token":
            refresh_token = form.get("refresh_token")
            
            token_data = await validate_refresh_token(db, refresh_token, client_id)
            if not token_data:
                return JSONResponse(
                    {"error": "invalid_grant", "error_description": "Invalid or expired refresh token"},
                    status_code=400
                )
            
            # Issue new tokens
            tokens = await store_tokens(db, client_id, token_data["user_id"], token_data["scopes"])
            return JSONResponse(tokens)
        
        else:
            return JSONResponse(
                {"error": "unsupported_grant_type", "error_description": f"Grant type '{grant_type}' not supported"},
                status_code=400
            )
    
    @mcp.custom_route("/mcp/jwks", methods=["GET"])
    async def jwks(request: Request):
        """JWKS endpoint for token verification."""
        # For now, return empty - implement with actual keys if needed
        return JSONResponse({"keys": []})
    
    @mcp.custom_route("/mcp", methods=["GET"])
    async def mcp_discovery_ui(request: Request):
        """MCP Discovery UI - shows connection instructions and tool list."""
        base = get_base_url(request)
        
        # Get available tools from MCP server
        tools_list = []
        try:
            # This would need to call the MCP tools/list method
            # For now, return static list from account.py help
            tools_list = [
                {"name": "labs_whoami", "description": "Get current authenticated user identity", "category": "Account"},
                {"name": "labs_list_labs", "description": "List all labs owned by the current user", "category": "Account"},
                {"name": "labs_lab_status", "description": "Check if a lab is running, stopped, etc.", "category": "Lifecycle"},
                {"name": "labs_lab_info", "description": "Get full lab information including credentials", "category": "Lifecycle"},
                {"name": "labs_deploy_lab", "description": "Deploy a lab", "category": "Lifecycle"},
                {"name": "labs_stop_lab", "description": "Stop a running lab", "category": "Lifecycle"},
                {"name": "labs_start_lab", "description": "Start a stopped lab", "category": "Lifecycle"},
                {"name": "labs_terminate_lab", "description": "Permanently delete a lab", "category": "Lifecycle"},
                {"name": "labs_renew_lab", "description": "Renew lab expiration (extend TTL)", "category": "Lifecycle"},
                {"name": "labs_read_lab_file", "description": "Read a file from the lab filesystem", "category": "Files"},
                {"name": "labs_write_lab_file", "description": "Write a file to the lab filesystem", "category": "Files"},
                {"name": "labs_list_lab_files", "description": "List files in a directory", "category": "Files"},
                {"name": "labs_glob_lab_files", "description": "Find files matching a glob pattern", "category": "Files"},
                {"name": "labs_grep_lab_files", "description": "Search file contents with grep", "category": "Files"},
                {"name": "labs_download_lab_file", "description": "Download a file from the lab", "category": "Files"},
                {"name": "labs_upload_lab_file", "description": "Upload a file to the lab", "category": "Files"},
                {"name": "labs_list_lab_processes", "description": "List running processes in the lab", "category": "Processes"},
                {"name": "labs_stop_lab_process", "description": "Stop a process in the lab", "category": "Processes"},
                {"name": "labs_lab_stats", "description": "Get resource usage stats (CPU, memory, disk)", "category": "Processes"},
                {"name": "labs_storage_usage", "description": "Get detailed storage usage breakdown", "category": "Processes"},
                {"name": "labs_networks", "description": "List Docker networks", "category": "Networks"},
                {"name": "labs_wireguard_config", "description": "Get WireGuard config for a lab", "category": "Networks"},
                {"name": "labs_domains", "description": "List custom domains for a lab", "category": "Domains"},
                {"name": "labs_add_domain", "description": "Add a custom domain", "category": "Domains"},
                {"name": "labs_remove_domain", "description": "Remove a custom domain", "category": "Domains"},
                {"name": "labs_databases", "description": "List databases for a lab", "category": "Services"},
                {"name": "labs_create_database", "description": "Create a database", "category": "Services"},
                {"name": "labs_service_credentials", "description": "List service credentials", "category": "Services"},
                {"name": "labs_ssh_keys", "description": "List SSH keys for user", "category": "SSH"},
                {"name": "labs_add_ssh_key", "description": "Add an SSH key", "category": "SSH"},
                {"name": "labs_workspace_pin", "description": "Pin a workspace tab in code-server", "category": "Workspace"},
                {"name": "labs_workspace_preferences", "description": "Get code-server preferences", "category": "Workspace"},
                {"name": "labs_help", "description": "List all available MCP tools", "category": "Account"},
            ]
        except Exception as e:
            logger.warning(f"Could not load tools list: {e}")
        
        # Group by category
        categories = {}
        for tool in tools_list:
            cat = tool.get("category", "Other")
            if cat not in categories:
                categories[cat] = []
            categories[cat].append(tool)
        
        return HTMLResponse(f"""
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Tom Labs MCP Server</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
                <style>
                    :root {{
                        --primary: #ff9800;
                        --primary-dark: #f57c00;
                        --bg: #fafafa;
                        --card-bg: #fff;
                        --text: #1a1a1a;
                        --muted: #666;
                        --border: #e8e8e8;
                    }}
                    @media (prefers-color-scheme: dark) {{
                        :root {{
                            --bg: #121212;
                            --card-bg: #1e1e1e;
                            --text: #eaeaea;
                            --muted: #aaa;
                            --border: #333;
                        }}
                    }}
                    body {{ background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; min-height: 100vh; }}
                    .hero {{ background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 60px 20px; }}
                    .card {{ background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }}
                    .tool-item {{ padding: 16px; border-bottom: 1px solid var(--border); transition: background 0.15s; }}
                    .tool-item:last-child {{ border-bottom: none; }}
                    .tool-item:hover {{ background: rgba(255,152,0,0.05); }}
                    .tool-name {{ font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 600; color: var(--text); }}
                    .tool-desc {{ color: var(--muted); font-size: 13px; margin-top: 4px; }}
                    .tool-category {{ text-transform: uppercase; font-size: 11px; font-weight: 600; color: var(--primary); letter-spacing: 0.5px; margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--primary); }}
                    .config-box {{ background: #f5f5f5; border-radius: 8px; padding: 16px; font-family: 'JetBrains Mono', monospace; font-size: 12px; overflow-x: auto; }}
                    @media (prefers-color-scheme: dark) {{ .config-box {{ background: #2a2a2a; }} }}
                    .btn-primary {{ background: var(--primary); border-color: var(--primary); }}
                    .btn-primary:hover {{ background: var(--primary-dark); border-color: var(--primary-dark); }}
                    .badge-oauth {{ background: var(--primary); color: white; font-size: 11px; padding: 4px 8px; border-radius: 4px; }}
                    .status-connected {{ color: #4caf50; }}
                    .status-disconnected {{ color: #f44336; }}
                </style>
            </head>
            <body>
                <div class="hero text-center">
                    <div class="container">
                        <div style="max-width: 720px; margin: 0 auto;">
                            <i class="bx bxl-meta" style="font-size: 48px; margin-bottom: 16px; opacity: 0.9;"></i>
                            <h1 style="font-weight: 700; margin-bottom: 8px;">Tom Labs MCP Server</h1>
                            <p class="lead" style="opacity: 0.9; margin-bottom: 0;">Model Context Protocol endpoint for AI-powered lab management</p>
                        </div>
                    </div>
                </div>
                
                <div class="container py-5">
                    <div class="row g-4">
                        <!-- Connection Info -->
                        <div class="col-12 col-lg-4">
                            <div class="card h-100 p-4">
                                <h3 class="h5 mb-3"><i class="bx bx-link-external me-2"></i>Connection Endpoint</h3>
                                <div class="config-box mb-3" id="endpointUrl">{base}/mcp</div>
                                <button class="btn btn-sm btn-outline-secondary w-100 mb-3" onclick="copyToClipboard('endpointUrl')">
                                    <i class="bx bx-copy me-1"></i> Copy Endpoint
                                </button>
                                
                                <h3 class="h5 mb-3"><i class="bx bx-key me-2"></i>Authentication</h3>
                                <span class="badge-oauth me-2">OAuth 2.1 + PKCE</span>
                                <span class="badge bg-secondary">Bearer Token</span>
                                <p class="text-muted small mt-2">Supports dynamic client registration (RFC 7591)</p>
                                
                                <div class="mt-3 p-3 rounded" style="background: rgba(255,152,0,0.1);">
                                    <strong>Quick Connect (opencode):</strong>
                                    <pre class="config-box mt-2 mb-0" style="font-size: 11px;">opencode mcp add labs {base}/mcp</pre>
                                </div>
                            </div>
                        </div>
                        
                        <!-- OAuth Flow -->
                        <div class="col-12 col-lg-4">
                            <div class="card h-100 p-4">
                                <h3 class="h5 mb-3"><i class="bx bx-shield-check me-2"></i>OAuth Flow</h3>
                                <ol class="small text-muted" style="line-height: 2;">
                                    <li>Client registers via <code>/mcp/register</code></li>
                                    <li>User visits <code>/mcp/authorize</code></li>
                                    <li>User logs in &amp; grants consent</li>
                                    <li>Client exchanges code for tokens at <code>/mcp/token</code></li>
                                    <li>Use access token as Bearer header</li>
                                </ol>
                                <hr>
                                <h6 class="text-muted">Endpoints</h6>
                                <div class="config-box small">
Authorization: {base}/mcp/authorize<br>
Token: {base}/mcp/token<br>
Register: {base}/mcp/register<br>
Metadata: {base}/.well-known/oauth-authorization-server
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Start -->
                        <div class="col-12 col-lg-4">
                            <div class="card h-100 p-4">
                                <h3 class="h5 mb-3"><i class="bx bx-rocket me-2"></i>Quick Start</h3>
                                
                                <h6 class="mb-2">1. opencode (CLI)</h6>
                                <pre class="config-box small mb-3"># Auto OAuth flow
opencode mcp auth labs

# Or manual Bearer token
export TOM_LABS_MCP_TOKEN="mcp_xxx..."
opencode</pre>
                                
                                <h6 class="mb-2">2. Claude Code</h6>
                                <pre class="config-box small mb-3">claude mcp add labs {base}/mcp \\
  --header "Authorization: Bearer $TOM_LABS_MCP_TOKEN"</pre>
                                
                                <h6 class="mb-2">3. Cursor / Windsurf</h6>
                                <pre class="config-box small mb-0">Add to mcp.json:
{{
  "mcpServers": {{
    "labs": {{
      "url": "{base}/mcp",
      "headers": {{ "Authorization": "Bearer YOUR_TOKEN" }}
    }}
  }}
}}</pre>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tools Catalog -->
                    <div class="row mt-5">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-transparent border-bottom">
                                    <h3 class="h5 mb-0"><i class="bx bx-cog me-2"></i>Available Tools ({len(tools_list)})</h3>
                                </div>
                                <div class="card-body p-0">
                                    {''.join(f'''
                                    <div class="tool-category">{cat} ({len(tools)})</div>
                                    {''.join(f'''
                                    <div class="tool-item">
                                        <div class="tool-name">{escape_html(t['name'])}</div>
                                        <div class="tool-desc">{escape_html(t['description'])}</div>
                                    </div>''' for t in tools)}
                                    ''' for cat, tools in categories.items())}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Inspector Link -->
                    <div class="row mt-4">
                        <div class="col-12 text-center">
                            <a href="/mcp/inspector" class="btn btn-primary btn-lg px-5">
                                <i class="bx bx-terminal me-2"></i>Open MCP Inspector (Playground)
                            </a>
                            <p class="text-muted mt-2">Test tools interactively with OAuth authentication</p>
                        </div>
                    </div>
                </div>
                
                <footer class="text-center py-4 text-muted small border-top" style="border-color: var(--border) !important;">
                    Tom Labs MCP Server &middot; <a href="https://github.com/sathish46-lab/tom-cloud-labs" class="text-muted" target="_blank">GitHub</a> &middot; 
                    <a href="/.well-known/oauth-authorization-server" class="text-muted">OAuth Metadata</a> &middot;
                    <a href="/.well-known/oauth-protected-resource" class="text-muted">Resource Metadata</a>
                </footer>
                
                <script>
                    function copyToClipboard(elementId) {{
                        const text = document.getElementById(elementId).textContent;
                        navigator.clipboard.writeText(text).then(() => {{
                            const btn = event.target.closest('button');
                            const original = btn.innerHTML;
                            btn.innerHTML = '<i class="bx bx-check me-1"></i> Copied!';
                            setTimeout(() => btn.innerHTML = original, 2000);
                        }});
                    }}
                </script>
            </body>
            </html>
        """)

def escape_html(text: str) -> str:
    """Escape HTML special characters."""
    return (str(text)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&#x27;"))

# ─── MCP Connection Tracking ───

_CONNECTION_CACHE = {}
_CONNECTION_LOCK = None


def get_connection_lock() -> asyncio.Lock:
    global _CONNECTION_LOCK
    if _CONNECTION_LOCK is None:
        _CONNECTION_LOCK = asyncio.Lock()
    return _CONNECTION_LOCK


async def _bump_connection(client_id: str, delta: int):
    """Track live SSE stream count per client in mcp_connections collection."""
    if not client_id:
        return
    try:
        _CONNECTION_CACHE[client_id] = _CONNECTION_CACHE.get(client_id, 0) + delta
        count = _CONNECTION_CACHE[client_id]
        db = get_db()
        if count <= 0:
            db.mcp_connections.update_one(
                {"client_id": client_id},
                {"$set": {"connected": False, "streams": 0, "disconnected_at": datetime.utcnow()}},
                upsert=True,
            )
        else:
            db.mcp_connections.update_one(
                {"client_id": client_id},
                {"$set": {"connected": True, "streams": count, "last_seen_at": datetime.utcnow()}},
                upsert=True,
            )
    except Exception as e:
        logger.warning(f"Connection tracking failed: {e}")


class MCPConnectionTrackingMiddleware:
    """
    ASGI middleware: marks a client as connected while it has an open
    StreamableHTTP SSE stream, and disconnected when the stream closes.
    """

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        path = scope.get("path", "").rstrip("/")
        headers = {}
        for k, v in scope.get("headers") or []:
            try:
                headers[k.decode("latin-1").lower()] = v.decode("latin-1")
            except Exception:
                pass

        accept = headers.get("accept", "")
        is_sse = "text/event-stream" in accept

        client_id = ""
        if is_sse and path == "/mcp":
            auth = headers.get("authorization", "")
            if auth.lower().startswith("bearer "):
                try:
                    info = get_auth().validate_bearer_token(auth[7:].strip())
                    client_id = info.get("client_id") or ""
                except Exception as e:
                    logger.debug(f"Connection auth failed: {e}")

        opened = False
        lock = get_connection_lock()

        async def tracked_receive():
            nonlocal opened
            msg = await receive()
            if client_id and opened and msg.get("type") == "http.disconnect":
                opened = False
                logger.info(f"SSE disconnect for client {client_id}")
                async with lock:
                    await _bump_connection(client_id, -1)
            return msg

        async def tracked_send(message):
            nonlocal opened
            if client_id and message.get("type") == "http.response.start" and not opened:
                opened = True
                logger.info(f"SSE connect for client {client_id}")
                async with lock:
                    await _bump_connection(client_id, +1)
            elif client_id and opened and message.get("type") == "http.response.body" and not message.get("more_body"):
                opened = False
                logger.info(f"SSE close for client {client_id}")
                async with lock:
                    await _bump_connection(client_id, -1)
            await send(message)

        try:
            await self.app(scope, tracked_receive, tracked_send)
        finally:
            if client_id and opened:
                opened = False
                async with lock:
                    await _bump_connection(client_id, -1)


# ─── MCP Activity Logging ───

def _truncate(value: Any, max_chars: int = 4000) -> Any:
    """Truncate a value to keep stored activity documents small."""
    if isinstance(value, (dict, list)):
        s = json.dumps(value, default=str)
        if len(s) > max_chars:
            try:
                return json.loads(s[:max_chars] + '..."(truncated)"')
            except Exception:
                return s[:max_chars]
        return value
    s = str(value)
    return s[:max_chars] if len(s) > max_chars else value


def _extract_identity(request) -> Dict[str, Any]:
    """Extract user/client identity from the request's Authorization header."""
    identity = {"user_id": None, "username": "", "email": "", "client_id": ""}
    try:
        auth_header = (request.headers.get("authorization") or "").strip()
        if not auth_header.lower().startswith("bearer "):
            return identity
        token = auth_header.split(" ", 1)[1].strip()
        info = get_auth().validate_bearer_token(token)
        identity["user_id"] = info.get("user_id")
        identity["username"] = info.get("username", "")
        identity["email"] = info.get("email", "")
        identity["client_id"] = info.get("client_id", "")
    except AuthError:
        pass
    except Exception as e:
        logger.warning(f"Activity identity extraction failed: {e}")
    return identity


async def _log_activity(identity, tool, request_args, response, duration_ms, status, error=None):
    """Persist a single MCP tool call into the mcp_activity collection."""
    try:
        doc = {
            "client_id": identity["client_id"],
            "user_id": identity["user_id"],
            "username": identity["username"],
            "email": identity["email"],
            "tool": tool,
            "request": _truncate(request_args),
            "response": _truncate(response),
            "status": status,
            "error": _truncate(error) if error else None,
            "duration_ms": int(duration_ms),
            "created_at": datetime.utcnow(),
        }
        get_db().mcp_activity.insert_one(doc)
    except Exception as e:
        logger.warning(f"Failed to log MCP activity: {e}")


def setup_activity_logging(mcp: FastMCP):
    """Wrap the lowlevel CallToolRequest handler to log every tool call."""
    try:
        lowlevel = mcp._mcp_server
        original = lowlevel.request_handlers.get(CallToolRequest)
        if original is None:
            logger.warning("CallToolRequest handler not found; activity logging disabled")
            return

        async def logging_handler(req: CallToolRequest):
            tool = req.params.name
            request_args = req.params.arguments or {}
            identity = {"user_id": None, "username": "", "email": "", "client_id": ""}
            try:
                identity = _extract_identity(lowlevel.request_context.request)
            except LookupError:
                pass
            except Exception:
                pass

            start = time.monotonic()
            status = "ok"
            error = None
            response = None
            try:
                result = await original(req)
                response = result
                return result
            except Exception as e:
                status = "error"
                error = str(e)
                raise
            finally:
                duration_ms = (time.monotonic() - start) * 1000
                resp_payload = response
                if hasattr(response, "model_dump"):
                    try:
                        resp_payload = response.model_dump(exclude_none=True)
                    except Exception:
                        pass
                await _log_activity(identity, tool, request_args, resp_payload, duration_ms, status, error)

        lowlevel.request_handlers[CallToolRequest] = logging_handler
        logger.info("MCP activity logging enabled")
    except Exception as e:
        logger.warning(f"Failed to enable MCP activity logging: {e}")



# ─── Tool Registration ───

# Setup OAuth and Discovery UI routes
setup_custom_routes(mcp)

# Import and register all tool modules
account.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
lifecycle.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
workspace.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
files.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
processes.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
networks.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
domains.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
services.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)
ssh.register_tools(mcp, get_current_user_from_context, get_ownership, run_labsctl, run_docker_exec, json_response)

# Enable activity logging (records every tool call into mcp_activity)
setup_activity_logging(mcp)



# ─── Main Entry Point ───

if __name__ == "__main__":
    import uvicorn
    cfg = get_config()
    port = int(os.environ.get("MCP_SERVER_PORT", "8099"))

    logger.info(f"Starting Tom Labs MCP Server on port {port}")
    app = MCPConnectionTrackingMiddleware(mcp.streamable_http_app())
    uvicorn.run(app, host="0.0.0.0", port=port)

@mcp.custom_route('/debug-path', methods=['GET'])
async def debug_path(request: Request):
    return JSONResponse({'path': str(request.url.path), 'headers': dict(request.headers)})
