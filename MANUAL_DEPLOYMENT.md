# 📦 Manual Deployment Guide

## Quick Reference - Deploy Updates in 3 Steps

### **Step 1: Create Update Package (Local)**

```powershell
# See what changed
.\show-changes.ps1

# Create update package
.\create-update-package.ps1
```

This creates: `update-YYYYMMDD-HHMMSS.zip`

---

### **Step 2: Upload to Hostinger**

1. Open Hostinger File Manager
2. Navigate to: `/public_html/api.Z1 Storesempirehairs.com/`
3. Upload the `update-YYYYMMDD-HHMMSS.zip` file
4. Right-click → Extract
5. Delete the zip file

---

### **Step 3: Run Post-Deploy Tasks**

Visit in browser:
```
https://api.Z1 Storesempirehairs.com/post-deploy.php?key=post_deploy_secret_xyz789
```

This will:
- ✅ Run database migrations
- ✅ Clear all caches
- ✅ Rebuild caches
- ✅ Optimize application

**Done!** ✨

---

## 📋 Complete Workflow

### **Daily Development Workflow:**

```powershell
# 1. Make your changes
# Edit files...

# 2. Test locally
npm run dev  # or php artisan serve

# 3. Commit to Git (for version control)
git add .
git commit -m "Your change description"
git push origin main

# 4. See what changed
.\show-changes.ps1

# 5. Create update package
.\create-update-package.ps1

# 6. Upload to Hostinger (via File Manager)
# - Upload the zip
# - Extract it
# - Delete zip

# 7. Run post-deploy
# Visit: https://api.Z1 Storesempirehairs.com/post-deploy.php?key=post_deploy_secret_xyz789
```

---

## 🎯 What Gets Deployed

### **Included in Update Package:**
- ✅ `app/` - Your application code
- ✅ `routes/` - API routes
- ✅ `config/` - Configuration files
- ✅ `database/migrations/` - Database changes
- ✅ `database/seeders/` - Seed data
- ✅ `public/` - Public assets and scripts
- ✅ `resources/` - Views and assets

### **NOT Included (Stays on Server):**
- ✅ `vendor/` - Already uploaded (747MB!)
- ✅ `.env` - Your secrets
- ✅ `storage/` - Logs and uploads
- ✅ `bootstrap/cache/` - Cache files

---

## 🔧 Maintenance Scripts

### **Available Scripts on Server:**

**RECOMMENDED (Direct Laravel - No Shell Commands):**

1. **Test Artisan** (Diagnostic - run this first if issues)
   ```
   https://api.Z1 Storesempirehairs.com/test-artisan.php?key=test_artisan_xyz
   ```

2. **Clear Cache Direct** (Fastest, most reliable)
   ```
   https://api.Z1 Storesempirehairs.com/clear-cache-direct.php?key=clear_cache_direct_xyz
   ```

3. **Run Migrations Direct**
   ```
   https://api.Z1 Storesempirehairs.com/migrate-direct.php?key=migrate_direct_xyz
   ```

**ALTERNATIVE (Shell Commands - may timeout):**

4. **Post-Deploy** (All-in-one)
   ```
   https://api.Z1 Storesempirehairs.com/post-deploy.php?key=post_deploy_secret_xyz789
   ```

5. **Quick Cache**
   ```
   https://api.Z1 Storesempirehairs.com/quick-cache.php?key=quick_cache_xyz123
   ```

---

## 🚨 Troubleshooting

### **Problem: Changes not showing**
**Solution:** Run post-deploy.php to clear caches

### **Problem: Routes not found**
**Solution:** Run cache-routes-permanent.php

### **Problem: Database errors**
**Solution:** Run setup-migrate.php

### **Problem: Need to update vendor**
**Solution:** 
1. Run `composer update` locally
2. Upload entire vendor folder via FTP (one-time)
3. Or run composer on server if available

---

## 📊 Deployment Checklist

Before deploying:
- [ ] Test changes locally
- [ ] Commit to Git
- [ ] Run show-changes.ps1
- [ ] Create update package
- [ ] Backup .env (if needed)

During deployment:
- [ ] Upload zip to Hostinger
- [ ] Extract zip
- [ ] Delete zip file
- [ ] Run post-deploy.php

After deployment:
- [ ] Test API endpoints
- [ ] Check error logs
- [ ] Verify functionality

---

## 🎉 Benefits

✅ **Simple** - Just upload a zip file  
✅ **Fast** - Only changed files (usually < 5MB)  
✅ **Safe** - Vendor and .env never touched  
✅ **Reliable** - No Git issues  
✅ **Version Control** - Still using Git locally  
✅ **Rollback** - Keep old zips for rollback  

---

## 💡 Tips

1. **Keep old update zips** for 1-2 weeks (for rollback)
2. **Test locally first** before deploying
3. **Deploy during low traffic** times
4. **Check logs** after deployment
5. **Run post-deploy** after every update

---

## 🔒 Security

- **Change secret keys** in all PHP scripts
- **Delete setup scripts** after initial setup
- **Keep post-deploy.php** but with strong secret
- **Never commit .env** to Git
- **Use HTTPS** for all admin URLs

---

**You're all set!** 🚀

For questions or issues, refer to this guide.
