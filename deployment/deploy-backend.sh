#!/bin/bash

# AHAZ Gaming Platform - Backend Deployment Script
# ================================================

set -e  # Exit on any error

echo "🚀 AHAZ Backend Deployment"
echo "=========================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
APP_NAME="ahaz-backend"
DEPLOY_PATH="/var/www/ahaz-backend"
BACKUP_PATH="/var/backups/ahaz-backend"
REPO_URL="https://github.com/your-username/ahaz-backend.git"  # Update this
BRANCH="main"
PHP_VERSION="8.2"

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

# Check if running as deploy user
if [[ $USER != "deploy" ]]; then
    print_error "This script should be run as the 'deploy' user"
    exit 1
fi

print_info "Starting backend deployment..."

# Create backup directory if it doesn't exist
sudo mkdir -p $BACKUP_PATH
sudo chown deploy:www-data $BACKUP_PATH

# Backup current deployment if it exists
if [ -d "$DEPLOY_PATH" ]; then
    print_info "Creating backup of current deployment..."
    BACKUP_NAME="backup-$(date +%Y%m%d-%H%M%S)"
    sudo cp -r $DEPLOY_PATH $BACKUP_PATH/$BACKUP_NAME
    print_success "Backup created: $BACKUP_PATH/$BACKUP_NAME"
fi

# Clone or update repository
if [ ! -d "$DEPLOY_PATH" ]; then
    print_info "Cloning repository..."
    sudo git clone $REPO_URL $DEPLOY_PATH
else
    print_info "Updating repository..."
    cd $DEPLOY_PATH
    sudo git fetch origin
    sudo git reset --hard origin/$BRANCH
fi

cd $DEPLOY_PATH

# Set proper ownership
print_info "Setting file permissions..."
sudo chown -R deploy:www-data $DEPLOY_PATH
sudo find $DEPLOY_PATH -type f -exec chmod 644 {} \;
sudo find $DEPLOY_PATH -type d -exec chmod 755 {} \;
sudo chmod -R 775 storage bootstrap/cache

# Install/Update Composer dependencies
print_info "Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Copy environment file if it doesn't exist
if [ ! -f ".env" ]; then
    print_info "Creating environment file..."
    cp .env.example .env
    print_warning "Please update .env file with production settings"
else
    print_info "Environment file exists, keeping current configuration"
fi

# Generate application key if needed
if ! grep -q "APP_KEY=base64:" .env; then
    print_info "Generating application key..."
    php artisan key:generate --force
fi

# Clear and cache configuration
print_info "Optimizing application..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Run database migrations
print_info "Running database migrations..."
php artisan migrate --force

# Seed games if needed
print_info "Checking if games need to be seeded..."
GAME_COUNT=$(php artisan tinker --execute="echo App\Models\Game::count();")
if [ "$GAME_COUNT" -eq "0" ]; then
    print_info "Seeding games database..."
    php artisan db:seed --class=GamesSeeder
    print_success "Games seeded successfully"
else
    print_info "Games already exist in database ($GAME_COUNT games found)"
fi

# Clear application cache
print_info "Clearing application cache..."
php artisan cache:clear
php artisan queue:clear

# Optimize for production
print_info "Optimizing for production..."
composer dump-autoload --optimize
php artisan optimize

# Set up log rotation
print_info "Setting up Laravel logs..."
sudo mkdir -p /var/log/ahaz
sudo touch /var/log/ahaz/laravel.log
sudo chown www-data:www-data /var/log/ahaz/laravel.log
sudo chmod 644 /var/log/ahaz/laravel.log

# Create systemd service for Laravel queue worker
print_info "Setting up queue worker service..."
sudo tee /etc/systemd/system/ahaz-queue.service > /dev/null <<EOF
[Unit]
Description=AHAZ Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5s
ExecStart=/usr/bin/php $DEPLOY_PATH/artisan queue:work --sleep=3 --tries=3 --timeout=300
WorkingDirectory=$DEPLOY_PATH

[Install]
WantedBy=multi-user.target
EOF

# Enable and start queue worker
sudo systemctl daemon-reload
sudo systemctl enable ahaz-queue
sudo systemctl restart ahaz-queue

# Create maintenance script
print_info "Creating maintenance scripts..."
sudo tee /usr/local/bin/ahaz-maintenance > /dev/null <<EOF
#!/bin/bash
# AHAZ Maintenance Script

case "\$1" in
    "down")
        cd $DEPLOY_PATH && php artisan down --render="errors::503" --secret="ahaz-secret"
        echo "Application is now in maintenance mode"
        ;;
    "up")
        cd $DEPLOY_PATH && php artisan up
        echo "Application is now live"
        ;;
    "logs")
        tail -f $DEPLOY_PATH/storage/logs/laravel.log
        ;;
    "queue-status")
        systemctl status ahaz-queue
        ;;
    "queue-restart")
        systemctl restart ahaz-queue
        echo "Queue worker restarted"
        ;;
    "cache-clear")
        cd $DEPLOY_PATH && php artisan cache:clear
        echo "Cache cleared"
        ;;
    *)
        echo "Usage: ahaz-maintenance {down|up|logs|queue-status|queue-restart|cache-clear}"
        exit 1
        ;;
esac
EOF

sudo chmod +x /usr/local/bin/ahaz-maintenance

# Set up cron jobs for Laravel scheduler
print_info "Setting up cron jobs..."
(sudo crontab -u www-data -l 2>/dev/null; echo "* * * * * cd $DEPLOY_PATH && php artisan schedule:run >> /dev/null 2>&1") | sudo crontab -u www-data -

# Restart PHP-FPM
print_info "Restarting PHP-FPM..."
sudo systemctl restart php$PHP_VERSION-fpm

# Final ownership and permissions
print_info "Final permissions setup..."
sudo chown -R www-data:www-data $DEPLOY_PATH
sudo chmod -R 755 $DEPLOY_PATH
sudo chmod -R 775 $DEPLOY_PATH/storage $DEPLOY_PATH/bootstrap/cache

# Health check
print_info "Performing health check..."
sleep 2

if curl -f -s http://localhost:8000/api/health > /dev/null; then
    print_success "Backend health check passed!"
else
    print_warning "Backend health check failed - check application logs"
fi

# Display status
print_success "Backend deployment completed!"
print_info "Application Status:"
echo "  - Path: $DEPLOY_PATH"
echo "  - Queue Worker: $(systemctl is-active ahaz-queue)"
echo "  - PHP-FPM: $(systemctl is-active php$PHP_VERSION-fpm)"
echo ""
print_info "Useful commands:"
echo "  - Maintenance mode: sudo ahaz-maintenance down|up"
echo "  - View logs: sudo ahaz-maintenance logs"
echo "  - Queue status: sudo ahaz-maintenance queue-status"
echo "  - Clear cache: sudo ahaz-maintenance cache-clear"
echo ""
print_info "Next steps:"
echo "1. Update .env file with production database credentials"
echo "2. Configure SSL certificate"
echo "3. Test API endpoints"
echo ""
print_success "AHAZ Backend is ready for production! 🎮" 