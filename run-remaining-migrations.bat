@echo off
echo Running remaining migrations...
echo.

C:\xampp\php\php.exe artisan migrate --path=database/migrations/2025_12_19_000009_add_store_role_to_users.php
echo.

C:\xampp\php\php.exe artisan migrate --path=database/migrations/2025_12_19_000010_add_store_id_to_coupons_and_sales.php
echo.

echo Done!
pause
