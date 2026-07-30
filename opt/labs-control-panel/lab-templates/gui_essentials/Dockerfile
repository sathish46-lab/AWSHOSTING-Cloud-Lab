FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

RUN rm /etc/dpkg/dpkg.cfg.d/excludes
RUN apt-get update && \ 
    dpkg -l | grep ^ii | cut -d' ' -f3 | xargs apt-get install -y --reinstall && \
    rm -rf /var/lib/apt/lists/*

# 1. Base dependencies
RUN apt-get update && apt-get install -y \
    net-tools procps curl wget ca-certificates \
    apache2 openssh-server build-essential nano htop vim \
    software-properties-common python3-dev stress iputils-ping \
    speedtest-cli sudo apt-utils \
    wireguard-tools iproute2 mysql-client \
    php php-mysql libapache2-mod-php dbus-x11 && \
    update-ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# 2. XFCE4 Desktop Environment (lightweight)
RUN apt-get update && apt-get install -y \
    xfce4 xfce4-goodies xfce4-terminal \
    thunar file-roller xterm && \
    rm -rf /var/lib/apt/lists/*

# 3. TigerVNC Server + noVNC (web-based access)
RUN apt-get update && apt-get install -y \
    tigervnc-standalone-server tigervnc-common \
    novnc websockify xfonts-base xfonts-100dpi xfonts-75dpi && \
    rm -rf /var/lib/apt/lists/*

# 4. STABLE CODE-SERVER INSTALL (v4.95.3)
RUN STABLE_VER="4.95.3" && \
    ARCH=$(dpkg --print-architecture) && \
    wget -O code-server.deb \
    "https://github.com/coder/code-server/releases/download/v${STABLE_VER}/code-server_${STABLE_VER}_${ARCH}.deb" && \
    dpkg -i code-server.deb && \
    rm code-server.deb

# --- BRANDING ---
RUN LOGIN_PAGE=$(find /usr/lib/code-server -name "login.html" | head -n 1) && \
    if [ -n "$LOGIN_PAGE" ]; then \
        sed -i 's/{{WELCOME_TEXT}}/Welcome to Tom Lab/g' "$LOGIN_PAGE" && \
        CUSTOM_MSG="With great power, comes great responsibility. Check your lab dashboard for the password. Happy Learning" && \
        sed -i "s|{{I18N_LOGIN_BELOW}} {{PASSWORD_MSG}}|$CUSTOM_MSG|g" "$LOGIN_PAGE"; \
    fi
    
COPY ./Data/welcome.html /var/www/html/index.html
RUN chown -R www-data:www-data /var/www/html && chmod 644 /var/www/html/index.html
RUN mkdir -p /etc/apache2/sites-available /var/www/html

# Enable color prompt
RUN sed -i 's/#force_color_prompt=yes/force_color_prompt=yes/' /etc/skel/.bashrc

# SSH Hardening: Key-only access
RUN sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?KbdInteractiveAuthentication.*/KbdInteractiveAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config && \
    sed -i 's/^#\?UsePAM.*/UsePAM yes/' /etc/ssh/sshd_config
    
RUN if [ -f /etc/apache2/mods-enabled/dir.conf ]; then \
    sed -ri 's/index.html index.cgi index.pl index.php index.xhtml index.htm/index.php index.cgi index.pl index.html index.xhtml index.htm/g' /etc/apache2/mods-enabled/dir.conf; \
    fi

RUN systemctl enable ssh
RUN mkdir -p /var/labsstorage /var/run/sshd /root/.ssh

WORKDIR /var/labsdata
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

EXPOSE 22 8080 6080 5901
RUN update-rc.d dbus defaults

RUN chmod +x /var/labsdata/scripts/entry.sh
CMD ["/var/labsdata/scripts/entry.sh"]
