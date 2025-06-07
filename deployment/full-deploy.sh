#!/bin/bash

# AHAZ Gaming Platform - Full Deployment Script
# ==============================================
# Complete deployment orchestration for Ubuntu 22.04 LTS

set -e  # Exit on any error

echo "🚀 AHAZ Gaming Platform - Full Deployment"
echo "=========================================="
echo "Complete deployment for Ubuntu 22.04 LTS with high-performance configuration"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() {
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

print_step() {
    echo ""
    echo -e "${GREEN}================================${NC}"
    echo -e "${GREEN}$1${NC}"
    echo -e "${GREEN}================================${NC}"
    echo ""
}

# Check if running as deploy user
if [[ $USER != "deploy" ]]; then
    print_error "This script should be run as the 'deploy' user"
    print_info "Please switch to deploy user: sudo su - deploy"
    exit 1
fi

# Configuration prompts
echo "Please provide the following information:"
read -p "Domain name (e.g., yourdomain.com): " DOMAIN_NAME
read -p "Backend repository URL: " BACKEND_REPO
read -p "Frontend repository URL: " FRONTEND_REPO
read -s -p "MySQL root password: " MYSQL_ROOT_PASSWORD
echo ""
read -s -p "Database password for ahaz user: " DB_PASSWORD
echo ""
read -p "Admin email for SSL certificate: " ADMIN_EMAIL

print_step "Step 1: Server Setup and Dependencies"
print_info "Running server setup script..."
bash ./server-setup.sh

print_step "Step 2: MySQL Performance Configuration"
print_info "Applying MySQL performance configuration..."
sudo cp mysql-performance.cnf /etc/mysql/mysql.conf.d/99-ahaz-performance.cnf
sudo systemctl restart mysql

# Configure MySQL database and user
print_info "Setting up database and user..."
mysql -u root -p$MYSQL_ROOT_PASSWORD << EOF
CREATE DATABASE IF NOT EXISTS ahaz_production;
CREATE USER IF NOT EXISTS 'ahaz'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON ahaz_production.* TO 'ahaz'@'localhost';
FLUSH PRIVILEGES;
EOF

print_step "Step 3: Nginx Performance Configuration"
print_info "Applying Nginx performance configuration..."
sudo cp nginx-performance.conf /etc/nginx/nginx.conf

# Update domain in nginx config
sudo sed -i "s/your-domain.com/$DOMAIN_NAME/g" /etc/nginx/nginx.conf

# Create nginx cache directories
sudo mkdir -p /var/cache/nginx/api
sudo mkdir -p /var/cache/nginx/static
sudo chown -R www-data:www-data /var/cache/nginx
sudo systemctl restart nginx

print_step "Step 4: Backend Deployment"
print_info "Updating backend deployment script with your repository..."
sed -i "s|https://github.com/your-username/ahaz-backend.git|$BACKEND_REPO|g" deploy-backend.sh
chmod +x deploy-backend.sh

print_info "Running backend deployment..."
bash ./deploy-backend.sh

# Configure backend environment
print_info "Configuring backend environment..."
cat > /var/www/ahaz-backend/.env << EOF
APP_NAME=AHAZ
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN_NAME

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ahaz_production
DB_USERNAME=ahaz
DB_PASSWORD=$DB_PASSWORD

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@$DOMAIN_NAME"
MAIL_FROM_NAME="\${APP_NAME}"

# JWT Configuration
JWT_SECRET=
JWT_TTL=60
JWT_REFRESH_TTL=20160

# SMS Configuration (update with your provider)
SMS_DRIVER=log
SMS_FROM=AHAZ

# Gaming Platform Settings
GAME_TOKEN_DAILY_BONUS=100
GAME_TOKEN_SIGNUP_BONUS=500
GAME_MAX_DAILY_ROUNDS=50
EOF

# Generate application and JWT keys
cd /var/www/ahaz-backend
php artisan key:generate --force
php artisan jwt:secret --force

print_step "Step 5: Frontend Deployment"
print_info "Updating frontend deployment script with your repository..."
sed -i "s|https://github.com/your-username/ahaz-frontend.git|$FRONTEND_REPO|g" deploy-frontend.sh
chmod +x deploy-frontend.sh

print_info "Running frontend deployment..."
bash ./deploy-frontend.sh

# Configure frontend environment
print_info "Configuring frontend environment..."
cat > /var/www/ahaz-frontend/.env << EOF
# AHAZ Frontend Environment Configuration
NODE_ENV=production
NUXT_API_BASE_URL=https://$DOMAIN_NAME/api
NUXT_PUBLIC_API_URL=https://$DOMAIN_NAME/api
NUXT_APP_BASE_URL=https://$DOMAIN_NAME
NUXT_SECRET_KEY=$(openssl rand -base64 32)
EOF

# Rebuild frontend with new environment
cd /var/www/ahaz-frontend
npm run build
pm2 reload ahaz-frontend

print_step "Step 6: SSL Certificate Setup"
print_info "Setting up SSL certificate with Let's Encrypt..."
sudo certbot --nginx -d $DOMAIN_NAME -d www.$DOMAIN_NAME --email $ADMIN_EMAIL --agree-tos --non-interactive

print_step "Step 7: Performance Optimization"
print_info "Applying final optimizations..."

# Optimize MySQL
sudo mysql -u root -p$MYSQL_ROOT_PASSWORD << EOF
USE ahaz_production;
ANALYZE TABLE users, games, rounds, leaderboards, transactions, otps;
OPTIMIZE TABLE users, games, rounds, leaderboards, transactions, otps;
EOF

# Warm up application caches
print_info "Warming up application caches..."
cd /var/www/ahaz-backend
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Test API endpoints
print_info "Testing API endpoints..."
curl -f -s https://$DOMAIN_NAME/api/health || print_warning "API health check failed"

# Test frontend
print_info "Testing frontend..."
curl -f -s https://$DOMAIN_NAME || print_warning "Frontend health check failed"

print_step "Step 8: Security Hardening"
print_info "Applying security configurations..."

# Configure firewall
sudo ufw limit ssh
sudo ufw deny 8000  # Block direct access to backend
sudo ufw deny 3000  # Block direct access to frontend

# Set up automatic security updates
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades

print_step "Step 9: Monitoring and Logging Setup"
print_info "Setting up monitoring and logging..."

# Create log analysis script
sudo tee /usr/local/bin/ahaz-logs > /dev/null << EOF
#!/bin/bash
case "\$1" in
    "nginx-access")
        tail -f /var/log/nginx/access.log | grep -E "(POST|PUT|DELETE)"
        ;;
    "nginx-errors")
        tail -f /var/log/nginx/error.log
        ;;
    "php-errors")
        tail -f /var/log/php8.2-fpm-ahaz.log
        ;;
    "laravel")
        tail -f /var/www/ahaz-backend/storage/logs/laravel.log
        ;;
    "mysql-slow")
        tail -f /var/log/mysql/slow.log
        ;;
    "frontend")
        pm2 logs ahaz-frontend --lines 50
        ;;
    "system")
        tail -f /var/log/syslog | grep -E "(ahaz|nginx|mysql|php)"
        ;;
    *)
        echo "Usage: ahaz-logs {nginx-access|nginx-errors|php-errors|laravel|mysql-slow|frontend|system}"
        ;;
esac
EOF

sudo chmod +x /usr/local/bin/ahaz-logs

# Create system status script
sudo tee /usr/local/bin/ahaz-status > /dev/null << EOF
#!/bin/bash
echo "🎮 AHAZ Gaming Platform Status"
echo "=============================="
echo ""
echo "System Status:"
echo "  - Nginx: \$(systemctl is-active nginx)"
echo "  - MySQL: \$(systemctl is-active mysql)"
echo "  - PHP-FPM: \$(systemctl is-active php8.2-fpm)"
echo "  - Redis: \$(systemctl is-active redis-server)"
echo "  - Queue Worker: \$(systemctl is-active ahaz-queue)"
echo ""
echo "Application Status:"
echo "  - Frontend PM2: \$(pm2 jlist | jq -r '.[] | select(.name=="ahaz-frontend") | .pm2_env.status' 2>/dev/null || echo 'unknown')"
echo "  - Backend Health: \$(curl -f -s https://$DOMAIN_NAME/api/health && echo 'OK' || echo 'FAIL')"
echo "  - Frontend Health: \$(curl -f -s https://$DOMAIN_NAME && echo 'OK' || echo 'FAIL')"
echo ""
echo "Resource Usage:"
echo "  - CPU: \$(top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print 100 - \$1"%"}')"
echo "  - Memory: \$(free | grep Mem | awk '{printf("%.1f%%", \$3/\$2 * 100.0)}')"
echo "  - Disk: \$(df -h / | awk 'NR==2{print \$5}')"
echo ""
echo "Database:"
echo "  - MySQL Connections: \$(mysql -u root -p$MYSQL_ROOT_PASSWORD -e "SHOW STATUS LIKE 'Threads_connected';" | tail -1 | awk '{print \$2}')"
echo "  - Game Count: \$(mysql -u ahaz -p$DB_PASSWORD ahaz_production -e "SELECT COUNT(*) FROM games;" | tail -1)"
echo "  - User Count: \$(mysql -u ahaz -p$DB_PASSWORD ahaz_production -e "SELECT COUNT(*) FROM users;" | tail -1)"
EOF

sudo chmod +x /usr/local/bin/ahaz-status

print_step "🎉 Deployment Complete!"
print_success "AHAZ Gaming Platform has been successfully deployed!"
echo ""
print_info "Deployment Summary:"
echo "  - Domain: https://$DOMAIN_NAME"
echo "  - Backend API: https://$DOMAIN_NAME/api"
echo "  - Admin Panel: https://$DOMAIN_NAME/admin"
echo "  - SSL Certificate: Enabled"
echo "  - MySQL Database: ahaz_production"
echo "  - Games Seeded: ✅"
echo ""
print_info "Useful Commands:"
echo "  - System Status: sudo ahaz-status"
echo "  - View Logs: sudo ahaz-logs {type}"
echo "  - Backend Management: sudo ahaz-maintenance {command}"
echo "  - Frontend Management: ahaz-frontend {command}"
echo ""
print_info "Monitoring URLs:"
echo "  - Frontend Health: https://$DOMAIN_NAME/health"
echo "  - Backend Health: https://$DOMAIN_NAME/api/health"
echo "  - PM2 Monitor: pm2 monit"
echo ""
print_warning "Important Post-Deployment Tasks:"
echo "1. Test all game functionalities"
echo "2. Verify user registration and login"
echo "3. Check payment/token systems"
echo "4. Set up database backups"
echo "5. Configure monitoring alerts"
echo "6. Update DNS records if needed"
echo ""
print_success "🎮 AHAZ is now live and ready for players!"
echo "Visit https://$DOMAIN_NAME to start playing!" 