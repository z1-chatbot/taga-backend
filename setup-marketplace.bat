@echo off
echo ========================================
echo Phone & Gadget Marketplace Setup
echo ========================================
echo.

echo Step 1: Running product images migration...
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2025_12_19_000011_create_product_images_table.php
echo.

echo Step 2: Seeding system settings...
C:\xampp\php\php.exe artisan db:seed --class=SystemSettingsSeeder
echo.

echo Step 3: Clearing cache...
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan cache:clear
echo.

echo ========================================
echo Setup Complete!
echo ========================================
echo.
echo Next Steps:
echo 1. Add routes from ADMIN_ROUTES_COMPLETE.md to routes/api.php
echo 2. Build admin UI features from ADMIN_DASHBOARD_FEATURES.md
echo 3. Update frontend for multi-store browsing
echo.
pause
