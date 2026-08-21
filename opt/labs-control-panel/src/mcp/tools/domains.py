"""
MCP Domain Tools
Two-step domain flow: Register → Assign to Lab.

STEP 1 (Register): labs_register_domain or labs_claim_tom_domain
  - Adds domain to the 'domains' collection (global inventory)
  - DNS verification for custom domains
  - Tom domains (*.tomweb.shop) auto-verified

STEP 2 (Assign): labs_add_domain
  - Associates a registered domain with a specific lab
  - Updates machine_labs.domains[]
  - Reloads Traefik routing
"""
import re
from mcp.server.fastmcp import Context
from mcp.types import Tool, TextContent

from typing import Any, Dict


# Available Tom Lab wildcard domains (mirrors config/available_domains.php)
AVAILABLE_TOM_DOMAINS = [
    "*.tomweb.shop",
    "*.tomweb.fun",
    "*.awshosting.in",
]

DOMAIN_REGEX = re.compile(r'^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')


def register_tools(mcp, get_current_user, get_ownership, run_labsctl, run_docker_exec, json_response):
    """Register domain management tools."""

    # ──────────────────────────────────────────────────
    # STEP 0: Query available domains
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_available_domains(ctx: Context) -> str:
        """List available Tom Lab wildcard domains that users can claim for free.

        Call this FIRST when the user wants to add a domain. It shows what
        wildcard domains are available (e.g. *.tomweb.shop). The user can
        pick one and then call labs_claim_tom_domain to register it.

        For custom third-party domains (e.g. example.com), the user should
        call labs_register_domain instead.
        """
        return json_response({
            "wildcard_domains": AVAILABLE_TOM_DOMAINS,
            "server_ip": "106.51.76.75",
            "instructions": (
                "To use a Tom domain: call labs_claim_tom_domain with a desired subdomain prefix. "
                "To use a custom domain: call labs_register_domain with the full domain. "
                "After registration, call labs_add_domain to assign it to a lab."
            )
        })

    # ──────────────────────────────────────────────────
    # STEP 1A: Register a custom domain (DNS verified)
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_register_domain(domain: str, ctx: Context) -> str:
        """Register a custom domain in the domain inventory.

        This is STEP 1 for custom third-party domains (e.g. example.com, myapp.io).
        It adds the domain to the user's global domain inventory and verifies DNS.

        BEFORE calling this, the user must:
        1. Create a DNS A record pointing the domain to server IP 106.51.76.75
        2. Wait for DNS propagation (may take a few minutes)

        After registration succeeds, call labs_add_domain to assign it to a lab.

        If the user wants a free wildcard domain (*.tomweb.shop), use
        labs_claim_tom_domain instead of this tool.

        Returns:
        - registered: bool
        - verified: bool (true if DNS A record points to server)
        - next_step: instructions to assign domain to a lab
        """
        user = await get_current_user(ctx)

        from src.mcp.server import get_db
        db = get_db()

        # Clean domain
        domain = domain.lower().strip()
        domain = domain.replace("http://", "").replace("https://", "").replace("www.", "")
        domain = domain.rstrip(".")

        if not DOMAIN_REGEX.match(domain):
            return json_response({
                "error": "Invalid domain format",
                "expected": "e.g. example.com, myapp.io, sub.domain.co.uk",
                "received": domain
            })

        # Check if already registered (by anyone)
        existing = db.domains.find_one({"domain": domain})
        if existing:
            if existing["user_id"] == user["user_id"]:
                return json_response({
                    "error": "Domain already registered by you",
                    "domain": domain,
                    "verified": existing.get("verified", False),
                    "next_step": f"Domain is already in your inventory. Call labs_add_domain to assign it to a lab."
                })
            else:
                return json_response({
                    "error": "Domain is registered by another user",
                    "domain": domain
                })

        # DNS verification via Google DNS-over-HTTPS
        import httpx
        is_verified = False
        dns_ip = None
        server_ip = "106.51.76.75"

        try:
            async with httpx.AsyncClient(timeout=5.0) as client:
                resp = await client.get(
                    f"https://dns.google/resolve?name={domain}&type=A",
                    headers={"Accept": "application/dns-json"}
                )
                if resp.status_code == 200:
                    data = resp.json()
                    for ans in data.get("Answer", []):
                        if ans.get("type") == 1:  # A record
                            dns_ip = ans.get("data")
                            if dns_ip == server_ip:
                                is_verified = True
                            break
        except Exception:
            pass  # DNS check failed, register as unverified

        # Insert into domains collection
        import time
        db.domains.insert_one({
            "user_id": user["user_id"],
            "email": user.get("email", ""),
            "domain": domain,
            "type": "custom",
            "verified": is_verified,
            "in_use": False,
            "created_at": int(time.time()),
            "last_checked": int(time.time()),
            "last_ip": server_ip if is_verified else None
        })

        result = {
            "registered": True,
            "domain": domain,
            "type": "custom",
            "verified": is_verified,
        }

        if is_verified:
            result["next_step"] = f"Domain verified! Now call labs_add_domain(lab='<lab_name>', domain='{domain}') to assign it to a lab."
        else:
            result["dns_issue"] = (
                f"DNS A record not pointing to {server_ip}"
                + (f" (found: {dns_ip})" if dns_ip else " (no A record found)")
            )
            result["next_step"] = (
                f"Domain registered but NOT verified. Create a DNS A record: {domain} → {server_ip}. "
                "Then call labs_verify_domain to re-check, or labs_add_domain to assign anyway (SSL may fail)."
            )

        return json_response(result)

    # ──────────────────────────────────────────────────
    # STEP 1B: Claim a Tom wildcard domain (auto-verified)
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_claim_tom_domain(subdomain: str, ctx: Context) -> str:
        """Claim a free Tom Lab wildcard domain (e.g. *.tomweb.shop).

        This is STEP 1 for Tom domains. It registers a subdomain under one of
        the available wildcard domains (e.g. "myproject" → myproject.tomweb.shop).

        Tom domains are auto-verified (no DNS setup needed from the user).
        The wildcard SSL certificate covers all subdomains automatically.

        BEFORE calling this:
        - Call labs_available_domains to see available wildcard domains
        - Pick a subdomain prefix (e.g. "myproject", "sathish-apps")

        After claiming, call labs_add_domain to assign it to a lab.

        Returns:
        - claimed: bool
        - domain: the full domain (e.g. myproject.tomweb.shop)
        - next_step: instructions to assign domain to a lab
        """
        user = await get_current_user(ctx)

        from src.mcp.server import get_db
        db = get_db()

        subdomain = subdomain.lower().strip().replace(" ", "-")
        subdomain = re.sub(r'[^a-z0-9-]', '', subdomain)

        if not subdomain or len(subdomain) < 2:
            return json_response({
                "error": "Subdomain must be at least 2 characters (a-z, 0-9, hyphens)"
            })

        # Try each available wildcard domain
        registered_domains = []
        for wildcard in AVAILABLE_TOM_DOMAINS:
            base_domain = wildcard.replace("*.", "")
            full_domain = f"{subdomain}.{base_domain}"

            # Skip if already registered
            if db.domains.find_one({"domain": full_domain}):
                continue

            import time
            db.domains.insert_one({
                "user_id": user["user_id"],
                "email": user.get("email", ""),
                "domain": full_domain,
                "type": "tom",
                "verified": True,  # Tom domains auto-verified
                "in_use": False,
                "created_at": int(time.time()),
                "last_checked": int(time.time()),
                "last_ip": None
            })
            registered_domains.append(full_domain)

        if not registered_domains:
            return json_response({
                "error": f"Subdomain '{subdomain}' is already taken on all wildcard domains",
                "suggestion": "Try a different subdomain name"
            })

        return json_response({
            "claimed": True,
            "subdomain": subdomain,
            "domains": registered_domains,
            "next_step": (
                f"Domains claimed: {', '.join(registered_domains)}. "
                "Now call labs_add_domain(lab='<lab_name>', domain='<domain>') to assign one to a lab."
            )
        })

    # ──────────────────────────────────────────────────
    # STEP 2: Assign a registered domain to a lab
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_add_domain(lab: str, domain: str, ctx: Context) -> str:
        """Assign a registered domain to a specific lab for public web exposure.

        This is STEP 2 in the domain workflow. The domain MUST already be
        registered via labs_register_domain or labs_claim_tom_domain.

        The domain will be added to the lab's public exposure list and Traefik
        will be configured to route traffic to the lab container.

        The 'lab' parameter accepts:
        - lab_type (e.g. "essentials", "gui_essentials")
        - instance_hash (e.g. "3fb0fe5d53738d9ccf5170b0f89f0458")

        When the user says 'add domain', FIRST ask:
        1. Which lab? (use labs_list_labs to show available labs)
        2. Which domain? (use labs_list_domains to show registered domains)
        3. If no domains registered yet → use labs_register_domain or labs_claim_tom_domain first

        Returns:
        - added: bool
        - domain: the domain assigned
        - lab: the lab name
        - traefik_reload: bool
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        # Try to resolve as lab_type first, then as instance_hash
        try:
            instance_hash = ownership.resolve_lab(user, lab_name=lab)
        except Exception:
            instance_hash = ownership.resolve_lab(user, instance_hash=lab)

        from src.mcp.server import get_db
        db = get_db()

        # Clean domain
        domain = domain.lower().strip()

        if not DOMAIN_REGEX.match(domain):
            return json_response({"error": "Invalid domain format"})

        # Check if domain is registered in the domains collection
        domain_doc = db.domains.find_one({"domain": domain})
        if not domain_doc:
            return json_response({
                "error": f"Domain '{domain}' is not registered",
                "action_required": (
                    "Register it first: call labs_register_domain(domain='{domain}') for custom domains, "
                    f"or labs_claim_tom_domain(subdomain='...') for a free *.tomweb.shop domain."
                )
            })

        # Check ownership of the domain
        if domain_doc["user_id"] != user["user_id"]:
            return json_response({
                "error": f"Domain '{domain}' belongs to another user"
            })

        # Add domain to lab
        db.machine_labs.update_one(
            {"instance_hash": instance_hash, "user_id": user["user_id"]},
            {"$addToSet": {"domains": domain}}
        )

        # Mark domain as in_use
        db.domains.update_one(
            {"domain": domain, "user_id": user["user_id"]},
            {"$set": {"in_use": True}}
        )

        # Re-apply Traefik config
        result = await run_labsctl(
            "lab", "apply-preferences",
            f"--hash={instance_hash}", f"--user={user['username']}",
            timeout=120
        )

        return json_response({
            "added": True,
            "instance_hash": instance_hash,
            "lab": lab,
            "domain": domain,
            "domain_verified": domain_doc.get("verified", False),
            "traefik_reload": result["success"],
            "next_step": (
                f"Domain {domain} is now routed to lab '{lab}'. "
                + (f"SSL will auto-provision if DNS points to 106.51.76.75."
                   if domain_doc.get("verified")
                   else "SSL may fail — verify DNS A record points to 106.51.76.75.")
            )
        })

    # ──────────────────────────────────────────────────
    # List all registered domains for the user
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_list_domains(ctx: Context) -> str:
        """List all domains registered by the current user.

        Shows the global domain inventory with verification status and usage.
        Use this to see what domains are available before assigning to a lab.

        When the user asks about domains, call this first to show what's available.
        """
        user = await get_current_user(ctx)

        from src.mcp.server import get_db
        db = get_db()

        cursor = db.domains.find(
            {"user_id": user["user_id"]},
            {"domain": 1, "type": 1, "verified": 1, "in_use": 1, "created_at": 1}
        )

        domains = []
        for doc in cursor:
            domains.append({
                "domain": doc["domain"],
                "type": doc.get("type", "custom"),
                "verified": doc.get("verified", False),
                "in_use": doc.get("in_use", False),
            })

        return json_response({
            "total": len(domains),
            "domains": domains,
            "wildcard_available": AVAILABLE_TOM_DOMAINS
        })

    # ──────────────────────────────────────────────────
    # Remove domain from lab (unassign)
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_remove_domain(lab: str, domain: str, ctx: Context) -> str:
        """Remove a domain from a lab's public exposure.

        This unassigns the domain from the lab but does NOT delete it from
        the domain inventory. The domain remains registered and can be
        reassigned to another lab later.

        To fully delete a domain from the inventory, use labs_delete_domain.

        The 'lab' parameter accepts lab_type or instance_hash.
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        try:
            instance_hash = ownership.resolve_lab(user, lab_name=lab)
        except Exception:
            instance_hash = ownership.resolve_lab(user, instance_hash=lab)

        from src.mcp.server import get_db
        db = get_db()

        db.machine_labs.update_one(
            {"instance_hash": instance_hash, "user_id": user["user_id"]},
            {"$pull": {"domains": domain}}
        )

        # Check if domain is still used by any other lab
        other_labs = db.machine_labs.count_documents({
            "user_id": user["user_id"],
            "domains": domain,
            "instance_hash": {"$ne": instance_hash}
        })

        if other_labs == 0:
            db.domains.update_one(
                {"domain": domain, "user_id": user["user_id"]},
                {"$set": {"in_use": False}}
            )

        # Re-apply Traefik config
        result = await run_labsctl(
            "lab", "apply-preferences",
            f"--hash={instance_hash}", f"--user={user['username']}",
            timeout=120
        )

        return json_response({
            "removed": True,
            "instance_hash": instance_hash,
            "lab": lab,
            "domain": domain,
            "traefik_reload": result["success"]
        })

    # ──────────────────────────────────────────────────
    # Delete domain from inventory entirely
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_delete_domain(domain: str, ctx: Context) -> str:
        """Delete a domain from the global domain inventory.

        This permanently removes the domain from the user's inventory.
        If the domain is currently assigned to any lab, it will be removed
        from all labs and Traefik will be reconfigured.

        Use labs_remove_domain to just unassign from a specific lab instead.
        """
        user = await get_current_user(ctx)

        from src.mcp.server import get_db
        db = get_db()

        domain_doc = db.domains.find_one({
            "domain": domain,
            "user_id": user["user_id"]
        })

        if not domain_doc:
            return json_response({"error": "Domain not found in your inventory"})

        # Remove from all labs that use this domain
        labs_affected = []
        cursor = db.machine_labs.find({
            "user_id": user["user_id"],
            "domains": domain
        })
        for lab_doc in cursor:
            db.machine_labs.update_one(
                {"instance_hash": lab_doc["instance_hash"]},
                {"$pull": {"domains": domain}}
            )
            labs_affected.append(lab_doc.get("lab_name", lab_doc["instance_hash"]))

        # Delete from domains collection
        db.domains.delete_one({"_id": domain_doc["_id"]})

        # Re-apply Traefik for affected labs
        reloaded = []
        for lab_name in labs_affected:
            try:
                result = await run_labsctl(
                    "lab", "apply-preferences",
                    f"--hash={ownership.resolve_lab(user, lab_name=lab_name)}",
                    f"--user={user['username']}",
                    timeout=60
                )
                reloaded.append(lab_name)
            except Exception:
                pass

        return json_response({
            "deleted": True,
            "domain": domain,
            "removed_from_labs": labs_affected,
            "traefik_reloaded": reloaded
        })

    # ──────────────────────────────────────────────────
    # Verify DNS for a domain
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_verify_domain(domain: str, ctx: Context) -> str:
        """Re-verify DNS A record for a registered domain.

        Checks if the domain's A record points to the server IP (106.51.76.75).
        Use this after the user has updated their DNS settings.
        """
        user = await get_current_user(ctx)

        from src.mcp.server import get_db
        db = get_db()

        domain_doc = db.domains.find_one({
            "domain": domain,
            "user_id": user["user_id"]
        })

        if not domain_doc:
            return json_response({"error": "Domain not found in your inventory"})

        server_ip = "106.51.76.75"
        dns_ip = None
        is_verified = False

        try:
            import httpx
            async with httpx.AsyncClient(timeout=5.0) as client:
                resp = await client.get(
                    f"https://dns.google/resolve?name={domain}&type=A",
                    headers={"Accept": "application/dns-json"}
                )
                if resp.status_code == 200:
                    data = resp.json()
                    for ans in data.get("Answer", []):
                        if ans.get("type") == 1:
                            dns_ip = ans.get("data")
                            if dns_ip == server_ip:
                                is_verified = True
                            break
        except Exception:
            pass

        import time
        db.domains.update_one(
            {"_id": domain_doc["_id"]},
            {"$set": {
                "verified": is_verified,
                "last_checked": int(time.time()),
                "last_ip": server_ip if is_verified else None
            }}
        )

        return json_response({
            "domain": domain,
            "verified": is_verified,
            "dns_ip": dns_ip,
            "expected_ip": server_ip,
            "match": is_verified
        })

    # ──────────────────────────────────────────────────
    # SSL and Error Pages (unchanged)
    # ──────────────────────────────────────────────────

    @mcp.tool()
    async def labs_domain_ssl(lab: str, domain: str, ctx: Context) -> str:
        """Get SSL certificate info for a domain on a lab.
        The 'lab' parameter accepts lab_type or instance_hash.
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        try:
            instance_hash = ownership.resolve_lab(user, lab_name=lab)
        except Exception:
            instance_hash = ownership.resolve_lab(user, instance_hash=lab)

        result = await run_docker_exec(
            instance_hash,
            "root",
            f"cat /etc/traefik/acme/acme.json 2>/dev/null | python3 -c \"import sys, json; data=json.load(sys.stdin); certs=data.get('Certificates', []); [print(json.dumps(c)) for c in certs if '{domain}' in c.get('domain', {{}}).get('main','')]\" 2>/dev/null || echo 'NOT_FOUND'",
            timeout=15
        )

        return json_response({
            "instance_hash": instance_hash,
            "domain": domain,
            "ssl_info": result["stdout"].strip() if result["stdout"].strip() != "NOT_FOUND" else "Not found or not issued yet"
        })

    @mcp.tool()
    async def labs_domain_error_pages(lab: str, domain: str, ctx: Context) -> str:
        """Get custom error pages for a domain.
        The 'lab' parameter accepts lab_type or instance_hash.
        """
        user = await get_current_user(ctx)
        ownership = get_ownership()
        try:
            instance_hash = ownership.resolve_lab(user, lab_name=lab)
        except Exception:
            instance_hash = ownership.resolve_lab(user, instance_hash=lab)

        user_home = f"/var/labsstorage/home/{user['username']}"
        error_dir = f"{user_home}/.config/traefik/error-pages/{domain}"

        result = await run_docker_exec(
            instance_hash,
            user["username"],
            f"ls -la {error_dir} 2>/dev/null || echo 'NOT_FOUND'",
            timeout=10
        )

        if "NOT_FOUND" in result["stdout"]:
            return json_response({
                "instance_hash": instance_hash,
                "domain": domain,
                "error_pages": "No custom error pages configured"
            })

        return json_response({
            "instance_hash": instance_hash,
            "domain": domain,
            "error_pages": result["stdout"].strip()
        })
