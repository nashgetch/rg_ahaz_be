#!/bin/bash

# AHAZ Gaming Platform - Frontend Deployment Script
# =================================================

set -e  # Exit on any error

echo "🎨 AHAZ Frontend Deployment"
echo "==========================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
APP_NAME="ahaz-frontend"
DEPLOY_PATH="/var/www/ahaz-frontend"
BACKUP_PATH="/var/backups/ahaz-frontend"
REPO_URL="https://github.com/nashgetch/rg_ahaz_fe.git"  # Update this
BRANCH="main"
NODE_VERSION="18"
PM2_APP_NAME="ahaz-frontend"

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

print_info "Starting frontend deployment..."

# Check Node.js version
NODE_CURRENT=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_CURRENT" -lt "$NODE_VERSION" ]; then
    print_error "Node.js version $NODE_VERSION or higher is required. Current: $(node -v)"
    exit 1
fi

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

# Install/Update npm dependencies
print_info "Installing npm dependencies..."
npm ci --only=production

# Build the application
print_info "Building application for production..."
npm run build

# Create PM2 ecosystem file
print_info "Creating PM2 configuration..."
cat > ecosystem.config.js << EOF
module.exports = {
  apps: [{
    name: '$PM2_APP_NAME',
    script: '.output/server/index.mjs',
    cwd: '$DEPLOY_PATH',
    instances: 'max',
    exec_mode: 'cluster',
    env: {
      NODE_ENV: 'production',
      PORT: 3000,
      HOST: '127.0.0.1',
      NITRO_PORT: 3000,
      NITRO_HOST: '127.0.0.1'
    },
    env_production: {
      NODE_ENV: 'production',
      PORT: 3000,
      HOST: '127.0.0.1'
    },
    // Logging
    log_file: '/var/log/ahaz/frontend-combined.log',
    out_file: '/var/log/ahaz/frontend-out.log',
    error_file: '/var/log/ahaz/frontend-error.log',
    log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    
    // Auto restart
    autorestart: true,
    watch: false,
    max_memory_restart: '1G',
    
    // Advanced settings
    node_args: '--max-old-space-size=1024',
    restart_delay: 4000,
    min_uptime: '10s',
    max_restarts: 10,
    
    // Health monitoring
    listen_timeout: 8000,
    kill_timeout: 5000
  }]
};
EOF

# Create environment file if it doesn't exist
if [ ! -f ".env" ]; then
    print_info "Creating environment file..."
    cat > .env << EOF
# AHAZ Frontend Environment Configuration
NODE_ENV=production
NUXT_API_BASE_URL=https://your-domain.com/api
NUXT_PUBLIC_API_URL=https://your-domain.com/api
NUXT_APP_BASE_URL=https://your-domain.com
EOF
    print_warning "Please update .env file with production settings"
else
    print_info "Environment file exists, keeping current configuration"
fi

# Stop existing PM2 process if running
print_info "Managing PM2 processes..."
if pm2 list | grep -q $PM2_APP_NAME; then
    print_info "Stopping existing PM2 process..."
    pm2 stop $PM2_APP_NAME
    pm2 delete $PM2_APP_NAME
fi

# Start the application with PM2
print_info "Starting application with PM2..."
pm2 start ecosystem.config.js --env production

# Save PM2 configuration
pm2 save

# Set up log rotation for PM2 logs
print_info "Setting up log rotation..."
sudo mkdir -p /var/log/ahaz
sudo touch /var/log/ahaz/frontend-combined.log
sudo touch /var/log/ahaz/frontend-out.log
sudo touch /var/log/ahaz/frontend-error.log
sudo chown -R deploy:www-data /var/log/ahaz
sudo chmod 644 /var/log/ahaz/frontend-*.log

# Install PM2 log rotate module
pm2 install pm2-logrotate

# Configure PM2 log rotation
pm2 set pm2-logrotate:max_size 100M
pm2 set pm2-logrotate:retain 30
pm2 set pm2-logrotate:compress true
pm2 set pm2-logrotate:dateFormat YYYY-MM-DD_HH-mm-ss
pm2 set pm2-logrotate:workerInterval 3600
pm2 set pm2-logrotate:rotateInterval '0 0 * * *'

# Create monitoring and maintenance script
print_info "Creating frontend maintenance scripts..."
sudo tee /usr/local/bin/ahaz-frontend > /dev/null <<EOF
#!/bin/bash
# AHAZ Frontend Management Script

case "\$1" in
    "status")
        pm2 status $PM2_APP_NAME
        ;;
    "start")
        pm2 start $PM2_APP_NAME
        echo "Frontend application started"
        ;;
    "stop")
        pm2 stop $PM2_APP_NAME
        echo "Frontend application stopped"
        ;;
    "restart")
        pm2 restart $PM2_APP_NAME
        echo "Frontend application restarted"
        ;;
    "reload")
        pm2 reload $PM2_APP_NAME
        echo "Frontend application reloaded (zero-downtime)"
        ;;
    "logs")
        pm2 logs $PM2_APP_NAME --lines 100
        ;;
    "logs-error")
        pm2 logs $PM2_APP_NAME --err --lines 50
        ;;
    "monit")
        pm2 monit
        ;;
    "build")
        cd $DEPLOY_PATH
        npm run build
        pm2 reload $PM2_APP_NAME
        echo "Application rebuilt and reloaded"
        ;;
    "health")
        if curl -f -s http://localhost:3000/health > /dev/null; then
            echo "✅ Frontend is healthy"
        else
            echo "❌ Frontend health check failed"
            exit 1
        fi
        ;;
    *)
        echo "Usage: ahaz-frontend {status|start|stop|restart|reload|logs|logs-error|monit|build|health}"
        exit 1
        ;;
esac
EOF

sudo chmod +x /usr/local/bin/ahaz-frontend

# Set up static file serving permissions
print_info "Setting up static file permissions..."
sudo chown -R www-data:www-data $DEPLOY_PATH/dist
sudo find $DEPLOY_PATH/dist -type f -exec chmod 644 {} \;
sudo find $DEPLOY_PATH/dist -type d -exec chmod 755 {} \;

# Create health check endpoint
print_info "Setting up health check..."
mkdir -p $DEPLOY_PATH/dist
echo '{"status":"ok","timestamp":"'$(date -Iseconds)'","service":"ahaz-frontend"}' > $DEPLOY_PATH/dist/health.json

# Final ownership and permissions
print_info "Final permissions setup..."
sudo chown -R deploy:www-data $DEPLOY_PATH
sudo chmod -R 755 $DEPLOY_PATH

# Health check
print_info "Performing health check..."
sleep 5

if curl -f -s http://localhost:3000 > /dev/null; then
    print_success "Frontend health check passed!"
else
    print_warning "Frontend health check failed - check PM2 logs"
fi

# Display PM2 status
print_info "PM2 Application Status:"
pm2 status $PM2_APP_NAME

# Display deployment summary
print_success "Frontend deployment completed!"
print_info "Application Details:"
echo "  - Name: $PM2_APP_NAME"
echo "  - Path: $DEPLOY_PATH"
echo "  - URL: http://localhost:3000"
echo "  - PM2 Status: $(pm2 jlist | jq -r '.[] | select(.name=="'$PM2_APP_NAME'") | .pm2_env.status' 2>/dev/null || echo 'unknown')"
echo ""
print_info "Useful commands:"
echo "  - Status: ahaz-frontend status"
echo "  - Restart: ahaz-frontend restart"
echo "  - View logs: ahaz-frontend logs"
echo "  - Health check: ahaz-frontend health"
echo "  - Rebuild: ahaz-frontend build"
echo ""
print_info "Next steps:"
echo "1. Update .env file with production API URLs"
echo "2. Configure domain and SSL"
echo "3. Test frontend functionality"
echo ""
print_success "AHAZ Frontend is ready for production! 🎨" 