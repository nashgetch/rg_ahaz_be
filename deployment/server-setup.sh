#!/bin/bash

# AHAZ Gaming Platform - Ubuntu 22.04 Server Setup Script
# ========================================================

set -e  # Exit on any error

echo "🚀 AHAZ Gaming Platform Server Setup"
echo "===================================="
echo "Setting up Ubuntu 22.04 LTS for high-performance gaming platform"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [[ $EUID -eq 0 ]]; then
    print_error "This script should not be run as root. Please run as a regular user with sudo privileges."
    exit 1
fi

print_status "Starting server setup..."

# Update system
print_status "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# Install essential packages
print_status "Installing essential packages..."
sudo apt install -y \
    curl \
    wget \
    git \
    unzip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release \
    htop \
    ufw \
    fail2ban \
    logrotate

# Install and configure UFW firewall
print_status "Configuring firewall..."
sudo ufw --force enable
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 3000/tcp  # Frontend dev server (if needed)
sudo ufw allow 8000/tcp  # Backend API (if needed)

# Install Nginx
print_status "Installing Nginx..."
sudo apt install -y nginx
sudo systemctl enable nginx
sudo systemctl start nginx

# Install PHP 8.2 and extensions
print_status "Installing PHP 8.2 and extensions..."
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
    php8.2 \
    php8.2-fpm \
    php8.2-mysql \
    php8.2-xml \
    php8.2-curl \
    php8.2-mbstring \
    php8.2-zip \
    php8.2-gd \
    php8.2-bcmath \
    php8.2-json \
    php8.2-opcache \
    php8.2-redis \
    php8.2-imagick

# Configure PHP-FPM for performance
print_status "Configuring PHP-FPM for high performance..."
sudo tee /etc/php/8.2/fpm/conf.d/99-performance.ini > /dev/null <<EOF
; PHP Performance Configuration for AHAZ Gaming Platform
memory_limit = 512M
max_execution_time = 60
max_input_vars = 3000
post_max_size = 100M
upload_max_filesize = 100M

; OPcache Configuration
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
opcache.save_comments = 1

; Session Configuration
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379"

; Security
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off
EOF

# Configure PHP-FPM pool
sudo tee /etc/php/8.2/fpm/pool.d/ahaz.conf > /dev/null <<EOF
[ahaz]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm-ahaz.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process Management
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 1000

; Performance tuning
request_slowlog_timeout = 10s
slowlog = /var/log/php8.2-fpm-slow.log
php_admin_value[error_log] = /var/log/php8.2-fpm-ahaz.log

; Security
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 512M
EOF

sudo systemctl enable php8.2-fpm
sudo systemctl start php8.2-fpm

# Install Composer
print_status "Installing Composer..."
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Install Node.js 18 LTS and npm
print_status "Installing Node.js 18 LTS..."
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Install PM2 globally
print_status "Installing PM2..."
sudo npm install -g pm2
pm2 startup | tail -1 | sudo bash

# Install and configure MySQL 8.0
print_status "Installing MySQL 8.0..."
sudo apt install -y mysql-server mysql-client

# Secure MySQL installation
print_status "Configuring MySQL security..."
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'your_secure_root_password';"
sudo mysql -e "DELETE FROM mysql.user WHERE User='';"
sudo mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
sudo mysql -e "DROP DATABASE IF EXISTS test;"
sudo mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Install and configure Redis
print_status "Installing Redis..."
sudo apt install -y redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server

# Configure Redis for sessions and caching
sudo tee -a /etc/redis/redis.conf > /dev/null <<EOF

# AHAZ Gaming Platform Configuration
maxmemory 256mb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
EOF

sudo systemctl restart redis-server

# Install Certbot for SSL
print_status "Installing Certbot for SSL..."
sudo apt install -y certbot python3-certbot-nginx

# Create application directories
print_status "Creating application directories..."
sudo mkdir -p /var/www/ahaz-backend
sudo mkdir -p /var/www/ahaz-frontend
sudo mkdir -p /var/log/ahaz

# Set proper permissions
sudo chown -R www-data:www-data /var/www/ahaz-backend
sudo chown -R www-data:www-data /var/www/ahaz-frontend
sudo chown -R www-data:www-data /var/log/ahaz

# Create deployment user
print_status "Creating deployment user..."
sudo useradd -m -s /bin/bash deploy
sudo usermod -aG www-data deploy
sudo mkdir -p /home/deploy/.ssh
sudo chown deploy:deploy /home/deploy/.ssh
sudo chmod 700 /home/deploy/.ssh

# Configure log rotation
print_status "Configuring log rotation..."
sudo tee /etc/logrotate.d/ahaz > /dev/null <<EOF
/var/log/ahaz/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
    postrotate
        systemctl reload nginx > /dev/null 2>&1 || true
        systemctl reload php8.2-fpm > /dev/null 2>&1 || true
    endscript
}
EOF

# Install monitoring tools
print_status "Installing monitoring tools..."
sudo apt install -y \
    htop \
    iotop \
    nethogs \
    nload \
    ncdu

# Configure fail2ban
print_status "Configuring fail2ban..."
sudo tee /etc/fail2ban/jail.local > /dev/null <<EOF
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
port = ssh
logpath = /var/log/auth.log
maxretry = 3

[nginx-http-auth]
enabled = true
filter = nginx-http-auth
logpath = /var/log/nginx/error.log
maxretry = 5

[nginx-limit-req]
enabled = true
filter = nginx-limit-req
logpath = /var/log/nginx/error.log
maxretry = 10
EOF

sudo systemctl enable fail2ban
sudo systemctl start fail2ban

# Optimize system limits
print_status "Optimizing system limits..."
sudo tee -a /etc/security/limits.conf > /dev/null <<EOF

# AHAZ Gaming Platform - System Limits
www-data soft nofile 65536
www-data hard nofile 65536
deploy soft nofile 65536
deploy hard nofile 65536
EOF

# Optimize kernel parameters
print_status "Optimizing kernel parameters..."
sudo tee -a /etc/sysctl.conf > /dev/null <<EOF

# AHAZ Gaming Platform - Network Optimizations
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.tcp_rmem = 4096 87380 16777216
net.ipv4.tcp_wmem = 4096 65536 16777216
net.core.netdev_max_backlog = 5000
net.ipv4.tcp_congestion_control = bbr
EOF

sudo sysctl -p

print_success "Server setup completed successfully!"
print_status "Next steps:"
echo "1. Configure MySQL with the high-performance configuration"
echo "2. Deploy backend application"
echo "3. Deploy frontend application"
echo "4. Configure Nginx virtual hosts"
echo "5. Set up SSL certificates"
echo ""
print_warning "Remember to:"
echo "- Change the MySQL root password"
echo "- Add your SSH public key to /home/deploy/.ssh/authorized_keys"
echo "- Configure your domain DNS to point to this server"
echo ""
print_success "AHAZ Gaming Platform server is ready for deployment!" 