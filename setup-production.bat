@echo off
echo ========================================
echo Phone and Gadget Marketplace Setup
echo ========================================
echo.

echo [1/3] Running migrations...
C:\xampp\php\php.exe artisan migrate --force
echo.

echo [2/3] Running production seeders...
C:\xampp\php\php.exe artisan db:seed --class=ProductionSeeder --force
echo.

echo [3/3] Clearing caches...
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan cache:clear
C:\xampp\php\php.exe artisan route:clear
C:\xampp\php\php.exe artisan view:clear
echo.

echo ========================================
echo Setup Complete!
echo ========================================
echo.
echo Admin Login:
echo Email: admin@hairecommerce.com
echo Password: admin123
echo.
echo Available Roles:
echo - Administrator (full access)
echo - Manager
echo - Sales Representative
echo - Customer Support
echo - Inventory Manager
echo - Marketing Manager
echo - Store Owner (for vendors)
echo - Delivery Agent (for riders)
echo.
echo Next Steps:
echo 1. Login to admin panel
echo 2. Create stores via /admin/stores
echo 3. Create delivery companies via /admin/delivery
echo 4. Configure shipping zones via /admin/shipping-zones
echo 5. Set up pricing rules via /admin/pricing
echo.
pause
