#!/bin/bash

###############################################################################
# Laravel Deployment Script for Hostinger
# 
# This script automates the deployment process:
# 1. Pulls latest code from GitHub
# 2. Installs/updates dependencies
# 3. Runs migrations
# 4. Clears and rebuilds cache
# 5. Sets proper permissions
###############################################################################

echo "🚀 Starting deployment..."
echo ""

# Navigate to project directory
cd /home/u570104660/domains/Z1 Storesempirehairs.com/public_html/api.Z1 Storesempirehairs.com

# Pull latest code from GitHub
echo "📥 Pulling latest code from GitHub..."
git pull origin main
echo "✅ Code updated"
echo ""

# Install/Update Composer dependencies (production only)
echo "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction
echo "✅ Dependencies installed"
echo ""

# Run database migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force
echo "✅ Migrations completed"
echo ""

# Clear all caches
echo "🧹 Clearing caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
echo "✅ Caches cleared"
echo ""

# Rebuild caches for production
echo "⚡ Building production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "✅ Caches rebuilt"
echo ""

# Optimize application
echo "🔧 Optimizing application..."
php artisan optimize
echo "✅ Application optimized"
echo ""

# Set proper permissions
echo "🔒 Setting permissions..."
chmod -R 755 storage bootstrap/cache
echo "✅ Permissions set"
echo ""

echo "✨ Deployment completed successfully!"
echo "🌐 Your API is live at: https://api.Z1 Storesempirehairs.com"
