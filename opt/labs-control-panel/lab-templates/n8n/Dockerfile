FROM ubuntu:22.04

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# 1. Install Base Dependencies
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    git \
    sudo \
    nano \
    vim \
    unzip \
    zip \
    build-essential \
    python3 \
    python3-pip \
    python3-venv \
    lsb-release \
    ca-certificates \
    openssh-server \
    wireguard \
    iproute2 \
    net-tools \
    iputils-ping \
    apache2 \
    htop \
    && rm -rf /var/lib/apt/lists/*

# 2. Install Node.js (Latest LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

# 3. Install n8n
RUN npm install -g n8n



# 5. Configure SSH
RUN mkdir /var/run/sshd
RUN sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin no/' /etc/ssh/sshd_config
RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
RUN sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config

# Copy fixed SSH host keys (prevents host key changes on rebuild)
COPY Data/ssh_host_keys/ /etc/ssh/
RUN chmod 600 /etc/ssh/ssh_host*

# 6. Configure Apache (Reverse Proxy for 80 -> 5678 handled by Traefik, but Apache serves static)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod proxy proxy_http rewrite headers

# Custom Welcome Page
RUN echo '<!DOCTYPE html><html><body style="background:#1a1a1a;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;"><h1>n8n Lab Ready</h1></body></html>' > /var/www/html/index.html

# 7. Create Scripts Directory
RUN mkdir -p /var/labsdata/scripts

# 8. Set up Entrypoint
COPY Data/scripts/entry.sh /var/labsdata/scripts/entry.sh
COPY Data/scripts/linkuser.sh /var/labsdata/scripts/linkuser.sh
RUN chmod +x /var/labsdata/scripts/*.sh

EXPOSE 22 80 5678 8080 51820/udp

CMD ["/var/labsdata/scripts/entry.sh"]
