#!/usr/bin/env python3
"""
MCP Setup Test Script
Verifies that the MCP server components can be imported and configured correctly.
"""

import sys
import os

# Add src to path - src is in the parent directory of scripts
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

def test_imports():
    """Test that all MCP modules can be imported."""
    print("Testing imports...")

    try:
        from src.mcp.auth import MCPAuth, AuthError, get_auth
        print("✓ src.mcp.auth")
    except Exception as e:
        print(f"✗ src.mcp.auth: {e}")
        return False

    try:
        from src.mcp.ownership import OwnershipResolver, OwnershipError, get_ownership
        print("✓ src.mcp.ownership")
    except Exception as e:
        print(f"✗ src.mcp.ownership: {e}")
        return False

    try:
        from src.mcp.tools import account, lifecycle, workspace, files, processes, networks, domains, services, ssh
        print("✓ src.mcp.tools (all modules)")
    except Exception as e:
        print(f"✗ src.mcp.tools: {e}")
        return False

    try:
        from src.config import Config
        print("✓ src.config")
    except Exception as e:
        print(f"✗ src.config: {e}")
        return False

    return True


def test_config():
    """Test configuration loading."""
    print("\nTesting configuration...")
    try:
        from src.config import Config
        cfg = Config()
        print(f"  mongo_uri: {'***' if cfg.mongo_uri else 'NOT SET'}")
        print(f"  main_db: {cfg.main_db}")
        print(f"  labctl_path: {cfg.labctl_path}")
        print(f"  templates_dir: {cfg.templates_dir}")
        return True
    except Exception as e:
        print(f"✗ Configuration test failed: {e}")
        return False


def test_mongo_connection():
    """Test MongoDB connection."""
    print("\nTesting MongoDB connection...")
    try:
        from src.config import Config
        import pymongo
        cfg = Config()
        if not cfg.mongo_uri:
            print("  SKIP: mongo_uri not configured")
            return True

        client = pymongo.MongoClient(cfg.mongo_uri, serverSelectionTimeoutMS=5000)
        client.admin.command('ping')
        print(f"  ✓ Connected to MongoDB")
        print(f"  Database: {cfg.main_db}")
        return True
    except Exception as e:
        print(f"✗ MongoDB connection failed: {e}")
        return False


def test_lab_hash_generation():
    """Test lab hash generation (matches PHP implementation)."""
    print("\nTesting lab hash generation...")
    try:
        from src.mcp.ownership import OwnershipResolver
        resolver = OwnershipResolver()

        # Test with email
        hash1 = resolver.get_lab_hash("user@example.com", "essentials")
        print(f"  Hash for user@example.com + essentials: {hash1}")

        # Test without email (guest)
        hash2 = resolver.get_lab_hash("", "essentials")
        print(f"  Hash for guest + essentials: {hash2}")

        # Verify deterministic
        hash1b = resolver.get_lab_hash("user@example.com", "essentials")
        assert hash1 == hash1b, "Hash should be deterministic"
        print("  ✓ Deterministic hashing verified")
        return True
    except Exception as e:
        print(f"✗ Lab hash test failed: {e}")
        return False


def test_auth_token_hashing():
    """Test token hashing (matches PHP implementation)."""
    print("\nTesting token hashing...")
    try:
        import hashlib
        import bcrypt

        token = "test-token-12345"
        token_hash = hashlib.sha256(token.encode()).hexdigest()
        token_enc = bcrypt.hashpw(token.encode(), bcrypt.gensalt(12))

        # Verify
        assert bcrypt.checkpw(token.encode(), token_enc)
        print(f"  ✓ SHA256 hash: {token_hash[:16]}...")
        print(f"  ✓ bcrypt verification works")
        return True
    except Exception as e:
        print(f"✗ Token hashing test failed: {e}")
        return False


def main():
    print("=" * 60)
    print("Tom Labs MCP Server - Setup Verification")
    print("=" * 60)

    all_passed = True
    all_passed &= test_imports()
    all_passed &= test_config()
    all_passed &= test_mongo_connection()
    all_passed &= test_lab_hash_generation()
    all_passed &= test_auth_token_hashing()

    print("\n" + "=" * 60)
    if all_passed:
        print("✓ ALL TESTS PASSED")
        print("MCP Server is ready to run!")
    else:
        print("✗ SOME TESTS FAILED")
        print("Please fix the issues above before running the server.")
    print("=" * 60)

    return 0 if all_passed else 1


if __name__ == "__main__":
    sys.exit(main())