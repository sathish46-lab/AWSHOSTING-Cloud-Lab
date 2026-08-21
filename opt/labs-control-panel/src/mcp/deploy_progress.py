"""
Deployment Progress Tracker
Parses labsctl deploy output and maps log lines to percentage progress.
"""

import re
from typing import Tuple, Optional

# Deployment steps mapped to percentage ranges
# Based on actual labsctl lab deploy output analysis
DEPLOY_STEPS = [
    # (pattern, percentage, label)
    (r"Deployment initiated", 5, "Initializing"),
    (r"Fetching lab metadata", 8, "Loading metadata"),
    (r"Starting deployment for user", 10, "Starting deployment"),
    (r"Instance ID:", 12, "Preparing instance"),
    (r"Reusing existing lab IP|Assigned Docker IP", 15, "Assigning IP"),
    (r"Checking for conflicting containers", 18, "Checking containers"),
    (r"No existing container found|Removing existing container", 20, "Cleaning up"),
    (r"Storage preserved", 25, "Preserving storage"),
    (r"Clearing stale VPN sessions", 28, "Clearing VPN sessions"),
    (r"Removing stale WireGuard peer", 30, "Removing old peer"),
    (r"Peer removed", 32, "Peer removed"),
    (r"Reusing existing keys|Generating new keys", 35, "Configuring keys"),
    (r"Peer re-registered", 38, "Re-registering peer"),
    (r"Provisioning", 40, "Provisioning lab"),
    (r"Waiting for container services", 45, "Starting container"),
    (r"Configuring network routing", 50, "Configuring network"),
    (r"Routing and firewall configured", 55, "Firewall ready"),
    (r"Optimizing Apache", 58, "Configuring Apache"),
    (r"Configuring user environment", 60, "Setting up user"),
    (r"Syncing ssh authorized_keys", 62, "Syncing SSH keys"),
    (r"Starting user configuration", 65, "Creating user"),
    (r"User .* created", 68, "User created"),
    (r"System password set", 70, "Password set"),
    (r"SSH configured and reloaded", 72, "SSH ready"),
    (r"Bash environment configured", 74, "Shell configured"),
    (r"Configuring WireGuard tunnel", 76, "Setting up VPN"),
    (r"WireGuard configured", 80, "VPN ready"),
    (r"Configuring persistent storage", 82, "Linking storage"),
    (r"Storage links configured", 85, "Storage ready"),
    (r"Setting up Code-Server", 88, "Starting Code-Server"),
    (r"Code-server started", 90, "Code-Server ready"),
    (r"Applying firewall rules", 92, "Applying firewall"),
    (r"Firewall rules applied", 94, "Firewall ready"),
    (r"Finalizing Traefik routing", 96, "Configuring proxy"),
    (r"Traefik config written", 98, "Proxy configured"),
    (r"Deployment Complete|Deploy complete", 100, "Complete"),
    (r"Apache routes added", 99, "Routes added"),
    (r"Access URL:", 100, "Deployment complete"),
]

# Redeploy steps (subset of deploy steps)
REDEPLOY_STEPS = [
    (r"Redeployment initiated", 5, "Initializing"),
    (r"Stopping existing container", 15, "Stopping lab"),
    (r"Container stopped", 25, "Lab stopped"),
    (r"Removing existing container", 35, "Removing old container"),
    (r"Container removed", 45, "Cleanup complete"),
    (r"Starting redeployment", 50, "Starting redeploy"),
    (r"Reusing existing lab IP", 55, "Assigning IP"),
    (r"Provisioning", 60, "Provisioning lab"),
    (r"Waiting for container", 65, "Starting container"),
    (r"Configuring network", 70, "Configuring network"),
    (r"Configuring user", 75, "Setting up user"),
    (r"Configuring WireGuard", 80, "Setting up VPN"),
    (r"Configuring persistent storage", 85, "Linking storage"),
    (r"Finalizing Traefik routing", 90, "Configuring proxy"),
    (r"Deployment Complete|Redeploy complete", 100, "Complete"),
]


def parse_deploy_progress(logs: list, deploy_type: str = "deploy") -> dict:
    """
    Parse deployment logs and return current progress.
    
    Args:
        logs: List of log lines from deployment
        deploy_type: "deploy" or "redeploy"
    
    Returns:
        dict with progress, label, and status
    """
    if not logs:
        return {
            "progress": 0,
            "label": "Waiting to start",
            "status": "pending",
            "current_step": None
        }
    
    steps = DEPLOY_STEPS if deploy_type == "deploy" else REDEPLOY_STEPS
    
    # Track the highest matched step
    highest_progress = 0
    current_label = "Starting"
    
    # Check each log line against patterns
    for log_line in logs:
        for pattern, percentage, label in steps:
            if re.search(pattern, log_line, re.IGNORECASE):
                if percentage > highest_progress:
                    highest_progress = percentage
                    current_label = label
    
    # Determine status based on final log
    last_log = logs[-1].strip() if logs else ""
    
    if "Complete" in last_log or "100" in last_log:
        status = "completed"
        current_label = "Complete"
        highest_progress = 100
    elif "Error" in last_log or "Failed" in last_log:
        status = "failed"
    elif highest_progress >= 100:
        status = "completed"
    else:
        status = "running"
    
    return {
        "progress": highest_progress,
        "label": current_label,
        "status": status,
        "current_step": f"{highest_progress}% - {current_label}",
        "total_steps": len(logs)
    }


def get_progress_from_deploy_log(deploy_log: dict, deploy_type: str = "deploy") -> dict:
    """
    Extract progress from deploy_log document.
    
    Args:
        deploy_log: The deploy_log dict from MongoDB
        deploy_type: "deploy" or "redeploy"
    
    Returns:
        dict with progress info
    """
    logs = deploy_log.get("logs", [])
    status = deploy_log.get("status", "")
    
    # If deployment is already complete
    if status == "success":
        return {
            "progress": 100,
            "label": "Complete",
            "status": "completed",
            "current_step": "100% - Complete",
            "total_steps": len(logs)
        }
    elif status == "failed":
        return {
            "progress": 0,
            "label": "Failed",
            "status": "failed",
            "current_step": "Deployment failed",
            "total_steps": len(logs)
        }
    
    # Parse logs for progress
    return parse_deploy_progress(logs, deploy_type)


def format_progress_bar(progress: int, width: int = 20) -> str:
    """
    Create a text-based progress bar.
    
    Args:
        progress: Percentage (0-100)
        width: Width of the progress bar
    
    Returns:
        Text progress bar string
    """
    filled = int(width * progress / 100)
    empty = width - filled
    bar = "█" * filled + "░" * empty
    return f"[{bar}] {progress}%"
