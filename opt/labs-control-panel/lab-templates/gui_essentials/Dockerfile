FROM kasmweb/core-ubuntu-jammy:1.15.0
USER root

ENV DEBIAN_FRONTEND=noninteractive

# 1. Fix corrupted GPG keyring — keep insecure until all apt installs done
RUN rm -f /etc/dpkg/dpkg.cfg.d/excludes && \
    rm -rf /var/lib/apt/lists/* && \
    rm -f /etc/apt/sources.list.d/* && \
    echo 'Acquire::AllowInsecureRepositories "true";' > /etc/apt/apt.conf.d/99insecure

# 2. All apt installs — use /tmp for apt cache (KasmVNC base has tiny /var/cache)
RUN rm -rf /var/lib/apt/lists/* && \
    apt-get update -o Acquire::AllowInsecureRepositories=true && \
    apt-get install -y --allow-unauthenticated --no-install-recommends \
    -o Dir::Cache::Archives=/tmp \
    ca-certificates sudo net-tools procps curl wget \
    openssh-server nano htop vim \
    build-essential python3-dev \
    iputils-ping wireguard-tools iproute2 \
    apache2 php libapache2-mod-php php-mysql && \
    rm -rf /var/lib/apt/lists/* /tmp/*.deb

# 3. Remove insecure config now that packages are installed
RUN rm -f /etc/apt/apt.conf.d/99insecure

# 4. CODE-SERVER INSTALL (v4.95.3)
RUN STABLE_VER="4.95.3" && \
    ARCH=$(dpkg --print-architecture) && \
    wget -O code-server.deb \
    "https://github.com/coder/code-server/releases/download/v${STABLE_VER}/code-server_${STABLE_VER}_${ARCH}.deb" && \
    dpkg -i code-server.deb && \
    rm code-server.deb

# --- BRANDING ---
RUN LOGIN_PAGE=$(find /usr/lib/code-server -name "login.html" | head -n 1) && \
    if [ -n "$LOGIN_PAGE" ]; then \
        sed -i 's/{{WELCOME_TEXT}}/Welcome to TomCloud Lab/g' "$LOGIN_PAGE" && \
        CUSTOM_MSG="With great power, comes great responsibility. Check your lab dashboard for the password. Happy Learning" && \
        sed -i "s|{{I18N_LOGIN_BELOW}} {{PASSWORD_MSG}}|$CUSTOM_MSG|g" "$LOGIN_PAGE"; \
    fi

COPY ./Data/welcome.html /var/www/html/index.html
RUN chown -R www-data:www-data /var/www/html && chmod 644 /var/www/html/index.html
RUN mkdir -p /etc/apache2/sites-available /var/www/html

# Enable color prompt
RUN sed -i 's/#force_color_prompt=yes/force_color_prompt=yes/' /etc/skel/.bashrc
RUN sed -i 's/#force_color_prompt=yes/force_color_prompt=yes/' /home/kasm-user/.bashrc 2>/dev/null || true

# SSH Hardening: Key-only access
RUN sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?KbdInteractiveAuthentication.*/KbdInteractiveAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?UsePAM.*/UsePAM yes/' /etc/ssh/sshd_config

# KasmVNC config
RUN printf 'network:\n  ssl:\n    pem_certificate: ${HOME}/.vnc/self.pem\n    pem_key: ${HOME}/.vnc/self.pem\n    require_ssl: false\n  udp:\n    public_ip: 127.0.0.1\n' > /etc/kasmvnc/kasmvnc.yaml

RUN if [ -f /etc/apache2/mods-enabled/dir.conf ]; then \
    sed -ri 's/index.html index.cgi index.pl index.php index.xhtml index.htm/index.php index.cgi index.pl index.html index.xhtml index.htm/g' /etc/apache2/mods-enabled/dir.conf; \
    fi

RUN systemctl enable ssh
RUN mkdir -p /var/labsstorage /var/run/sshd /root/.ssh /root/.vnc

# Generate self-signed SSL cert for KasmVNC
RUN openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /root/.vnc/self.pem -out /root/.vnc/self.pem \
    -subj "/CN=localhost" 2>/dev/null

WORKDIR /root
COPY ./Data /var/labsdata

# Permissions and MOTD setup
RUN chmod +x /var/labsdata/scripts/* && \
    mkdir -p /etc/update-motd.d/ && \
    find /etc/update-motd.d/ -type f -exec chmod -x {} + || true && \
    cp /var/labsdata/motd/00-tom-welcome /etc/update-motd.d/ && \
    chmod +x /etc/update-motd.d/00-tom-welcome && \
    chmod -R 775 /var/labsstorage

# Identity setup
RUN cp /var/labsdata/ssh_host_keys/* /etc/ssh/ 2>/dev/null || true && \
    chmod 600 /etc/ssh/ssh_host* 2>/dev/null || true

# Set default shell prompt and cd to home
RUN echo 'export PS1="\\[\\033[01;32m\\]\\u@\\h\\[\\033[00m\\]:\\[\\033[01;34m\\]\\w\\[\\033[00m\\]\\$ "' >> /etc/bash.bashrc && \
    echo 'cd /home/sathish46 2>/dev/null || cd /root' >> /etc/bash.bashrc

ENV HOME=/root
ENV USER=root

EXPOSE 22 8080 8444

RUN chmod +x /var/labsdata/scripts/entry.sh
ENTRYPOINT ["/var/labsdata/scripts/entry.sh"]
