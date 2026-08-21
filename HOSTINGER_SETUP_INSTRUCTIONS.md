# Hostinger Deployment Instructions (No Terminal Access)

## ✅ What You've Already Done:
- ✅ Uploaded app, config, database, routes folders
- ✅ Uploaded composer.json and composer.lock
- ✅ Uploaded vendor folder via FileZilla

---

## 📋 Next Steps:

### **Step 1: Configure .env File**

1. Go to Hostinger File Manager
2. Find the `.env` file in your Laravel root directory
3. Edit it with these settings:

```env
APP_NAME="Z1 Stores"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_KEY_HERE  # Keep existing or generate new
APP_URL=https://yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

# Mail Settings (configure later if needed)
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=your_email@yourdomain.com
MAIL_PASSWORD=your_email_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# Frontend URL
FRONTEND_URL=https://yourfrontend.com

# Paystack (if using)
PAYSTACK_PUBLIC_KEY=your_public_key
PAYSTACK_SECRET_KEY=your_secret_key
PAYSTACK_PAYMENT_URL=https://api.paystack.co
```

---

### **Step 2: Upload Setup Scripts**

1. Upload these 4 files from `public/` folder to Hostinger's `public/` folder:
   - `setup-migrate.php`
   - `setup-seed.php`
   - `setup-cache.php`
   - `setup-storage-link.php`

2. **IMPORTANT:** Edit each file and change the secret key:
   ```php
   $secret_key = 'my_secret_key_12345'; // Change this to something random!
   ```

---

### **Step 3: Run Setup Scripts in Browser**

Visit these URLs **in order** (replace with your actual domain and secret key):

#### 1. **Run Migrations** (Creates database tables)
```
https://yourdomain.com/setup-migrate.php?key=my_secret_key_12345
```
✅ You should see "Migrations completed successfully!"

#### 2. **Run Seeders** (Populates initial data)
```
https://yourdomain.com/setup-seed.php?key=my_secret_key_12345
```
✅ You should see "Seeders completed successfully!"

#### 3. **Create Storage Link** (For file uploads)
```
https://yourdomain.com/setup-storage-link.php?key=my_secret_key_12345
```
✅ You should see "Storage link created successfully!"

#### 4. **Optimize Cache** (For better performance)
```
https://yourdomain.com/setup-cache.php?key=my_secret_key_12345
```
✅ You should see "Cache optimization completed successfully!"

---

### **Step 4: Set Folder Permissions**

In Hostinger File Manager, set these permissions:

1. **storage/** folder → 775 (recursive)
2. **bootstrap/cache/** folder → 775 (recursive)
3. **public/storage/** folder → 775

Right-click folder → Change Permissions → Check boxes for:
- Owner: Read, Write, Execute
- Group: Read, Execute
- Public: Read, Execute

---

### **Step 5: Security - DELETE Setup Scripts!**

**IMMEDIATELY** delete these files from `public/` folder:
- ❌ setup-migrate.php
- ❌ setup-seed.php
- ❌ setup-cache.php
- ❌ setup-storage-link.php

**These are security risks if left on the server!**

---

### **Step 6: Test Your API**

Visit these URLs to test:

1. **Health Check:**
   ```
   https://yourdomain.com/api/health
   ```
   Should return: `{"status":"ok"}`

2. **Get Products:**
   ```
   https://yourdomain.com/api/products
   ```
   Should return JSON with products

3. **Get Categories:**
   ```
   https://yourdomain.com/api/categories
   ```
   Should return JSON with categories

---

## 🔧 Troubleshooting:

### If you get "500 Internal Server Error":
1. Check `.env` file is configured correctly
2. Check folder permissions (storage and bootstrap/cache)
3. Enable debug temporarily: `APP_DEBUG=true` in `.env`
4. Check Hostinger error logs

### If migrations fail:
- Check database credentials in `.env`
- Ensure database exists in Hostinger's MySQL panel
- Check database user has proper permissions

### If seeders fail:
- Run migrations first
- Check if tables exist in phpMyAdmin
- You can manually insert data via phpMyAdmin if needed

---

## 📝 Alternative: Manual Seeding via phpMyAdmin

If seeders fail, you can manually insert data:

1. Go to Hostinger's phpMyAdmin
2. Select your database
3. Run SQL from your seeder files manually

Example for categories:
```sql
INSERT INTO categories (name, slug, description, created_at, updated_at) VALUES
('Wigs', 'wigs', 'Premium quality wigs', NOW(), NOW()),
('Bundles', 'bundles', 'Hair bundles', NOW(), NOW());
```

---

## ✅ Final Checklist:

- [ ] .env file configured
- [ ] Migrations run successfully
- [ ] Seeders run successfully
- [ ] Storage link created
- [ ] Cache optimized
- [ ] Folder permissions set
- [ ] Setup scripts DELETED
- [ ] API endpoints tested
- [ ] Admin user created (check users table)

---

## 🎉 You're Done!

Your backend should now be live at: `https://yourdomain.com/api`

Update your frontend to point to this URL!
