#!/usr/bin/env python3
"""
Batch refactor all MCP tool files:
- Change signature from (..., _mcp_user: Dict[str, Any] = None) to (..., ctx: Context)
- Add `from mcp.server.fastmcp import Context` import
- Replace `_mcp_user` with `user = await get_current_user(ctx)` at start of each tool
"""

import re
from pathlib import Path

TOOL_FILES = [
    "src/mcp/tools/files.py",
    "src/mcp/tools/processes.py",
    "src/mcp/tools/workspace.py",
    "src/mcp/tools/networks.py",
    "src/mcp/tools/account.py",  # already done
    "src/mcp/tools/lifecycle.py",  # already done
    "src/mcp/tools/ssh.py",
    "src/mcp/tools/services.py",
    "src/mcp/tools/domains.py",
]

ROOT = Path("/opt/labs-control-panel")

def refactor_file(filepath: Path) -> bool:
    """Refactor a single tool file."""
    content = filepath.read_text()
    original = content

    # 1. Add Context import if not present
    if "from mcp.server.fastmcp import Context" not in content:
        # Add after existing imports
        content = re.sub(
            r'^(from typing import .*\n)',
            r'\1from mcp.server.fastmcp import Context\n',
            content,
            count=1
        )

    # 2. Replace function signatures: move ctx: Context to be BEFORE any params with defaults
    # Strategy: capture all params, remove _mcp_user, insert ctx: Context before first param with default
    def fix_signature(match):
        prefix = match.group(1)  # async def labs_xxx(
        params = match.group(2)  # all params
        suffix = match.group(3)  # ) -> str:

        # Remove _mcp_user param
        params = re.sub(r',?\s*_mcp_user: Dict\[str, Any\](?: = None)?', '', params)

        # Find first param with default (has =)
        # Insert ctx: Context before it
        # If no params with defaults, append at end
        if '=' in params:
            # Find position before first param with default
            # params like "lab: str, path: str = "/", depth: int = 3"
            # Insert before "path: str ="
            params = re.sub(r'(\s*)(\w+:\s*\w+\s*=)', r'\1ctx: Context, \2', params, count=1)
        else:
            # No defaults, append
            if params.strip():
                params = params.rstrip() + ", ctx: Context"
            else:
                params = "ctx: Context"

        return prefix + params + suffix

    content = re.sub(
        r'(async def labs_\w+\()([^)]*)(\) -> str:)',
        fix_signature,
        content
    )

    # 3. Replace `_mcp_user` usage with `user = await get_current_user(ctx)` at start of function body
    # Find each tool function and inject the user lookup after the docstring
    def inject_user_lookup(match):
        func_def = match.group(1)
        docstring = match.group(2)
        body = match.group(3)

        # Check if already has user lookup
        if "get_current_user(ctx)" in body[:100]:
            return match.group(0)

        # Inject after docstring
        indent = "        "
        injection = f"{indent}user = await get_current_user(ctx)\n"
        return func_def + docstring + injection + body

    # Match: @mcp.tool()\n    async def labs_xxx(...):\n        \"\"\"docstring\"\"\"\n        body
    content = re.sub(
        r'(@mcp\.tool\(\)\n\s*async def labs_\w+\([^)]*ctx: Context\) -> str:\n)(\s*""".*?"""\n)(\s*)',
        inject_user_lookup,
        content,
        flags=re.DOTALL
    )

    # 4. Replace all `_mcp_user[...]` with `user[...]`
    content = content.replace("_mcp_user[", "user[")

    # 5. Replace `_mcp_user.get(...)` with `user.get(...)`
    content = content.replace("_mcp_user.get", "user.get")

    # 6. Replace `_mcp_user` standalone (e.g. ownership.resolve_lab(_mcp_user, ...))
    content = re.sub(r'\b_mcp_user\b', 'user', content)

    if content != original:
        filepath.write_text(content)
        print(f"✓ Refactored: {filepath}")
        return True
    else:
        print(f"- No changes: {filepath}")
        return False


def main():
    changed = 0
    for rel in TOOL_FILES:
        filepath = ROOT / rel
        if filepath.exists():
            if refactor_file(filepath):
                changed += 1
        else:
            print(f"✗ Not found: {rel}")

    print(f"\nDone. {changed} files changed.")

if __name__ == "__main__":
    main()