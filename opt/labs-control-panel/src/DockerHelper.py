import subprocess
import re
import docker

SAFE_NAME_RE = re.compile(r'^[a-zA-Z0-9._-]+$')


class DockerHelper:
    def __init__(self):
        try:
            self.client = docker.from_env()
        except Exception as e:
            # print(f"Warning: Docker client initialization failed: {e}")
            self.client = None

    def _validate_name(self, name, label="name"):
        if not name or not SAFE_NAME_RE.match(str(name)):
            raise ValueError(f"Invalid {label}: {name!r}")
        return str(name)

    def container_exists(self, name):
        """Checks if a lab container is currently active or stopped."""
        try:
            name = self._validate_name(name, "container name")
            cmd = f"docker ps -a --format '{{{{.Names}}}}' -f name=^{name}$"
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
            return result.stdout.strip() == name
        except (ValueError, Exception):
            return False

    def is_container_running(self, name):
        """Checks if a container is in running state."""
        try:
            name = self._validate_name(name, "container name")
            cmd = f"docker ps --format '{{{{.Names}}}}' -f name=^{name}$"
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
            return result.stdout.strip() == name
        except (ValueError, Exception):
            return False

    def image_exists(self, image_tag):
        """Verifies if the image exists locally before deployment."""
        try:
            cmd = f"docker image inspect {image_tag}"
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
            return result.returncode == 0
        except Exception:
            return False

    def run_command(self, cmd_template, mapping):
        """Formats and executes commands with real-time logging."""
        try:
            formatted_cmd = cmd_template.format(**mapping)
            # Security Fix: Hide internal command structure from the public deploy logs
            # print(f"[*] Executing: {formatted_cmd}")
            
            # Use Popen for real-time streaming of build logs
            with subprocess.Popen(formatted_cmd, shell=True, stdout=subprocess.PIPE, 
                                  stderr=subprocess.STDOUT, text=True) as process:
                for line in process.stdout:
                    stripped = line.strip()
                    # Security Fix: Filter out the 64-character hex container ID printed by docker run -d
                    if len(stripped) == 64 and stripped.isalnum():
                        continue
                    print(line, end="") # Print each line as it comes from Docker
                
                process.wait()
                if process.returncode != 0:
                    return None
                return "Success"
        except Exception as e:
            print(f"[!] Execution error: {str(e)}")
            return None