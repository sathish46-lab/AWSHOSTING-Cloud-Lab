import os
import shutil
import time
import tempfile
import pathlib
from src.Lab import Lab


class Instance(Lab):
    """Instance deploy pipeline — extends Lab with single-doc instances model."""

    def __init__(self, args, session_hash=None):
        super().__init__(args, session_hash, is_instance=True)
        self.files_db = self.mongo_client['tom_labs_files_db'] if self.mongo_client else None

    def _get_instance(self, instance_id):
        if not self.instances_col:
            return None
        return self.instances_col.find_one({"instance_hash": instance_id})

    def _set_instance_status(self, instance_id, status):
        if not self.instances_col:
            return
        self.instances_col.update_one(
            {"instance_hash": instance_id},
            {"$set": {"status": status, "updated_at": time.time()}}
        )

    def _resolve_template(self, instance_data):
        """Resolve template folder name from instance data."""
        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        for field in ['template', 'lab_type', 'type']:
            name = instance_data.get(field, '')
            if name and os.path.isdir(os.path.join(templates_dir, name)):
                return name
        return 'essentials'

    def _copy_base_template(self, template_name, dest_dir):
        """Copy base template files to destination directory."""
        templates_dir = self.config.get('templates_dir', '/opt/labs-control-panel/lab-templates')
        src = os.path.join(templates_dir, template_name)
        if not os.path.isdir(src):
            self.log(f"Base template not found: {src}", "error", "build")
            return False
        for item in os.listdir(src):
            s = os.path.join(src, item)
            d = os.path.join(dest_dir, item)
            if os.path.isdir(s):
                shutil.copytree(s, d)
            else:
                shutil.copy2(s, d)
        return True

    def _overlay_user_files(self, instance_id, template_name, dest_dir):
        """Read user files from DB and write them on top of base template."""
        if not self.files_db:
            self.log("Files DB not available", "warn", "build")
            return

        user_doc = self.files_db.files.find_one({"instance_id": instance_id})
        if not user_doc:
            self.log("No user file overrides found", "info", "build")
            return

        user_files = user_doc.get('files', {})
        if not user_files:
            self.log("No user file overrides found", "info", "build")
            return

        count = 0
        base = pathlib.Path(dest_dir).resolve()
        for file_path, file_data in user_files.items():
            if isinstance(file_data, dict) and file_data.get('is_dir'):
                continue
            content = file_data.get('content', '') if isinstance(file_data, dict) else ''
            if not content:
                continue
            # Path traversal check
            target = (base / file_path).resolve()
            if not str(target).startswith(str(base)):
                self.log(f"Path traversal blocked: {file_path}", "warn", "build")
                continue
            content = content.replace('\r\n', '\n')
            full_path = str(target)
            os.makedirs(os.path.dirname(full_path), exist_ok=True)
            with open(full_path, 'w') as f:
                f.write(content)
            count += 1

        self.log(f"Overlaid {count} user files", "info", "build")

    def build(self):
        """Build Docker image: base template + user file overrides from DB."""
        instance_id = self.session_hash
        self.log(f"Instance build: {instance_id}", "info", "build")

        instance_data = self._get_instance(instance_id)
        if not instance_data:
            self.log(f"Instance not found: {instance_id}", "error", "build")
            return

        template_name = self._resolve_template(instance_data)
        image_tag = f"instance_{instance_id}:latest"
        self.log(f"Template: {template_name}, Image: {image_tag}", "info", "build")
        self._set_instance_status(instance_id, 'building')

        build_dir = tempfile.mkdtemp(prefix=f"build_{instance_id[:8]}_")
        try:
            if not self._copy_base_template(template_name, build_dir):
                self._set_instance_status(instance_id, 'error')
                return

            self._overlay_user_files(instance_id, template_name, build_dir)

            build_cmd = self.config.get('docker_build', "docker build -t {image_tag} {path}")
            build_cmd = build_cmd.replace("docker build", "DOCKER_BUILDKIT=1 docker build")
            if self.args.hasFlag('no-cache'):
                build_cmd = build_cmd.replace("docker build", "docker build --no-cache")

            mapping = {"image_tag": image_tag, "path": build_dir}
            exit_status, output = self.run(build_cmd.format(**mapping))

            if exit_status == 0:
                self.log(f"Image {image_tag} built successfully.", "success", "build")
                _, size_bytes = self.run(f"docker image inspect {image_tag} --format '{{{{.Size}}}}'", capture=True)
                image_size = int(size_bytes.strip()) if size_bytes and size_bytes.strip().isdigit() else None
                self._set_instance_status(instance_id, 'built')
                if self.instances_col:
                    build_info = {
                        "image_tag": image_tag,
                        "built_at": time.time(),
                        "template": template_name,
                    }
                    if image_size:
                        build_info["image_size"] = image_size
                    self.instances_col.update_one(
                        {"instance_hash": instance_id},
                        {"$set": {"build": build_info}}
                    )
                self.run("docker image prune -f")
            else:
                self.log(f"Build failed with exit code {exit_status}", "error", "build")
                self._set_instance_status(instance_id, 'error')
        finally:
            shutil.rmtree(build_dir, ignore_errors=True)

    def deploy(self):
        instance_id = self.session_hash
        self.log(f"Instance deploy: {instance_id}", "info", "init")

        instance_data = self._get_instance(instance_id)
        if not instance_data:
            self._fail_deploy(instance_id, f"Instance not found: {instance_id}")
            return

        deploy_data = instance_data.get('deploy', {})
        if not deploy_data:
            self._fail_deploy(instance_id, f"No deploy data for {instance_id}. Run deploy_instance.php first.")
            return

        template_name = deploy_data.get('lab_type', 'essentials')
        template_config_path = os.path.join(
            self.config.get('templates_dir'), template_name, 'config.json'
        )
        if not os.path.exists(template_config_path):
            self.log(f"Template '{template_name}' not found, using essentials", "warn", "init")
            template_name = 'essentials'
            self._set_deploy_field(instance_id, "lab_type", template_name)

        instance_image_tag = f"instance_{instance_id}:latest"
        exit_code, _ = self.run(f"docker image inspect {instance_image_tag} >/dev/null 2>&1", capture=True)
        if exit_code == 0:
            self.log(f"Using pre-built image: {instance_image_tag}", "info", "init")
            self._set_deploy_field(instance_id, "image", instance_image_tag)
        else:
            self.log(f"No pre-built image found, using base template: {template_name}:lab", "info", "init")

        self._set_instance_status(instance_id, 'deploying')
        self._set_deploy_field(instance_id, "status", "deploying")
        super().deploy()

        deployed = self.instances_col.find_one({"instance_hash": instance_id})
        deploy_status = deployed.get('deploy', {}).get('status', 'error') if deployed else 'error'
        final_status = 'running' if deploy_status == 'running' else 'error'
        self._set_instance_status(instance_id, final_status)
        self._set_deploy_field(instance_id, "status", final_status)

    def stop(self):
        instance_id = self.session_hash
        self.log(f"Stopping instance: {instance_id}", "info", "init")
        self._set_instance_status(instance_id, 'stopping')
        self._set_deploy_field(instance_id, "status", "stopping")
        super().stop()
        self._set_instance_status(instance_id, 'stopped')
        self._set_deploy_field(instance_id, "status", "stopped")

    def start(self):
        instance_id = self.session_hash
        self.log(f"Starting instance: {instance_id}", "info", "init")
        self._set_instance_status(instance_id, 'starting')
        self._set_deploy_field(instance_id, "status", "starting")
        super().start()
        self._set_instance_status(instance_id, 'running')
        self._set_deploy_field(instance_id, "status", "running")

    def remove(self):
        instance_id = self.session_hash
        self.log(f"Removing instance: {instance_id}", "info", "init")
        self._set_instance_status(instance_id, 'removing')
        self._set_deploy_field(instance_id, "status", "removing")
        super().remove()
        self._set_instance_status(instance_id, 'removed')
        self._set_deploy_field(instance_id, "status", "removed")
