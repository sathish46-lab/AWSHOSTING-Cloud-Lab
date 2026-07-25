FROM ubuntu:24.10

# 1. FIX: Switch to old-releases because 24.10 is EOL
RUN sed -i 's/archive.ubuntu.com/old-releases.ubuntu.com/g' /etc/apt/sources.list.d/ubuntu.sources && \
    sed -i 's/security.ubuntu.com/old-releases.ubuntu.com/g' /etc/apt/sources.list.d/ubuntu.sources && \
    sed -i 's/ports.ubuntu.com\/ubuntu-ports/old-releases.ubuntu.com\/ubuntu/g' /etc/apt/sources.list.d/ubuntu.sources

# 2. Force IPv4 and Install Essentials
RUN echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99force-ipv4 && \
    apt-get update && apt-get install -y \
    curl wget sudo openssh-server wireguard-tools iproute2 \
    python3 php-cli apache2 libapache2-mod-php git \
    && rm -rf /var/lib/apt/lists/*

# SSH Hardening: Force Key-only login
RUN sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?KbdInteractiveAuthentication.*/KbdInteractiveAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config

# Create the directory for the SSH daemon to run
RUN mkdir -p /var/run/sshd

# Copy fixed SSH host keys (prevents host key changes on rebuild)
COPY Data/ssh_host_keys/ /etc/ssh/
RUN chmod 600 /etc/ssh/ssh_host*

# 3. Install MinIO Server (Specific Version: 2025-04-22T22:12:26Z)
RUN wget https://dl.min.io/server/minio/release/linux-amd64/archive/minio.RELEASE.2025-04-22T22-12-26Z \
    -O /usr/local/bin/minio && chmod +x /usr/local/bin/minio

# 4. Install MinIO Client (mc) - Required for linkuser.sh automation
RUN wget https://dl.min.io/client/mc/release/linux-amd64/mc \
    -O /usr/local/bin/mc && chmod +x /usr/local/bin/mc

# 5. Setup Directories
RUN mkdir -p /mnt/data /var/labsdata/scripts

# 6. Copy Control Panel Scripts
COPY Data/scripts/ /var/labsdata/scripts/
RUN chmod +x /var/labsdata/scripts/*.sh

# Ports: 9000 (API), 9001 (Console UI), 22 (SSH), 80 (Apache), 8080 (Code)
EXPOSE 9000 9001 22 80 8080

ENTRYPOINT ["/bin/bash", "/var/labsdata/scripts/entry.sh"]