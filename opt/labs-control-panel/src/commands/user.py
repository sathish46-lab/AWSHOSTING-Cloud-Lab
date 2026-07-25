import os
from src.router import Command


class UserCmd(Command):
    """labsctl user — user SSH sync and listing."""

    name = "user"
    description = "User SSH sync, listing"
    usage = "labsctl user <sync|list> [options]"

    def __init__(self, router=None):
        super().__init__()
        self.subcommands = {
            "sync": (self._sync, "Sync SSH keys + permissions", "labsctl user sync --user=USERNAME"),
            "list": (self._list, "List all users with labs",   "labsctl user list"),
        }

    def _sync(self, args):
        username = args.user
        if not username:
            # Try positional
            username = args.get(1)
        if not username:
            self.log("Usage: labsctl user sync --user=USERNAME", "error")
            return

        self.log(f"Syncing SSH keys for {username}...")

        if self.db is None:
            self.log("Database not connected", "error")
            return

        # Fetch user profile
        user_profile = self.db.users.find_one({"username": username})
        search_emails = [username]
        if user_profile and "email" in user_profile:
            search_emails.append(user_profile["email"])

        # Fetch SSH keys
        user_keys = list(self.db.ssh_keys.find({"username": username}))
        if not user_keys:
            self.log(f"No SSH keys found for {username}", "warn")

        auth_content = "\n".join([k["public_key"] for k in user_keys if "public_key" in k])

        # Find storage path
        user_lab = self.db.deployed_labs.find_one({"username": username})
        if user_lab:
            storage_path = user_lab.get("storage_path")
        else:
            storage_path = f"/var/tomlabs/storage/{username}"

        ssh_dir = os.path.join(storage_path, ".ssh")
        auth_file = os.path.join(ssh_dir, "authorized_keys")

        if os.path.exists(storage_path):
            os.makedirs(ssh_dir, mode=0o700, exist_ok=True)
            try:
                with open(auth_file, "w") as f:
                    f.write(auth_content)
                os.chmod(auth_file, 0o600)
                self.log(f"Updated {auth_file}", "success")
            except Exception as e:
                self.log(f"Failed to write keys: {e}", "error")
        else:
            self.log(f"Storage path not found: {storage_path}", "warn")

    def _list(self, args):
        if self.db is None:
            self.log("Database not connected", "error")
            return

        users = list(self.db.users.find({}))
        labs = list(self.db.deployed_labs.find({}))

        # Group labs by username
        user_labs = {}
        for lab in labs:
            username = lab.get("username", "unknown")
            if username not in user_labs:
                user_labs[username] = []
            user_labs[username].append(lab.get("lab_type", "unknown"))

        print("\n  Users:")
        print("  " + "-" * 50)
        print(f"  {'Username':<20} {'Email':<25} {'Labs':<5}")
        print("  " + "-" * 50)

        for user in users:
            username = user.get("username", "?")
            email = user.get("email", "?")
            lab_count = len(user_labs.get(username, []))
            print(f"  {username:<20} {email:<25} {lab_count:<5}")

        print("  " + "-" * 50 + "\n")
