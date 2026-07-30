import os
import re

SAFE_EMAIL_RE = re.compile(r'^[a-zA-Z0-9._@+-]+$')


class sshKeyHelper:
    def __init__(self):
        # Path where you might store public keys locally, or fetch from DB
        self.keys_dir = "/etc/labs-control-panel/storage/keys"
        os.makedirs(self.keys_dir, exist_ok=True)

    def get_key_by_email(self, email):
        if not email or not SAFE_EMAIL_RE.match(email):
            print(f"[!] Warning: Invalid email format: {email!r}")
            return ""
        # For now, let's assume keys are named 'email.pub'
        key_path = os.path.join(self.keys_dir, f"{email}.pub")
        if os.path.exists(key_path):
            with open(key_path, 'r') as f:
                return f.read().strip()
        
        # Fallback or logic to fetch from your Gitlab/Database
        print(f"[!] Warning: No SSH key found for {email}")
        return ""