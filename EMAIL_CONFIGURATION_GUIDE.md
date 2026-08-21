# 📧 Email Configuration Guide

## 🚨 Current Issue

Your emails are not being sent because the `.env` file on the server is set to:
```
MAIL_MAILER=log
```

This means emails are only being logged to files, not actually sent.

---

## ✅ Solution: Configure Real Email Service

You have **3 options** for sending emails on Hostinger:

---

### **Option 1: Use Hostinger's SMTP (Recommended)**

Hostinger provides free email hosting with your domain.

#### **Step 1: Create Email Account**

1. Go to **Hostinger Control Panel**
2. Click **Emails** → **Email Accounts**
3. Create an email like: `noreply@z1stores.com`
4. Set a strong password

#### **Step 2: Update `.env` on Server**

Edit your `.env` file on the server and update these lines:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=noreply@z1stores.com
MAIL_PASSWORD=your_email_password_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@z1stores.com
MAIL_FROM_NAME="Z1 Stores"
```

#### **Step 3: Clear Cache**

```
https://api.z1stores.com/clear-cache-direct.php?key=YOUR_KEY
```

#### **Step 4: Test Email**

Go to your admin panel:
```
Admin → Email Automation → Test Email
```

---

### **Option 2: Use Gmail SMTP (Quick Test)**

If you want to test quickly with Gmail:

#### **Step 1: Enable App Password**

1. Go to Google Account → Security
2. Enable 2-Factor Authentication
3. Generate an **App Password** for "Mail"
4. Copy the 16-character password

#### **Step 2: Update `.env`**

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-gmail@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-gmail@gmail.com
MAIL_FROM_NAME="Z1 Stores"
```

⚠️ **Note:** Gmail has daily sending limits (500 emails/day). Not recommended for production.

---

### **Option 3: Use Mailtrap (Development/Testing)**

For testing emails without sending real ones:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@z1stores.com
MAIL_FROM_NAME="Z1 Stores"
```

Get credentials from: https://mailtrap.io

---

## 🧪 Test Email Functionality

### **Method 1: Via Admin Panel**

1. Login to admin dashboard
2. Go to **Email Automation**
3. Click **Test Email**
4. Enter your email address
5. Click **Send Test Email**

### **Method 2: Via API**

```bash
curl -X POST https://api.z1stores.com/api/v1/admin/email-automation/test-email \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"your-email@example.com"}'
```

---

## 📋 Email Features in Your App

Your app sends emails for:

✅ **User Registration** - Email verification
✅ **Password Reset** - Forgot password
✅ **Order Confirmation** - After order placed
✅ **Order Status Updates** - Shipped, delivered, etc.
✅ **Cart Reminders** - Abandoned cart emails
✅ **Low Stock Alerts** - To admin
✅ **Daily Sales Reports** - To admin
✅ **New Product Notifications** - To subscribers
✅ **Sale Event Notifications** - To customers
✅ **Delivery Assignment** - To delivery agents
✅ **Staff Welcome Email** - When staff account created

---

## 🔍 Check Email Logs

Your app logs all email attempts in the database.

### **Check Email Logs Table:**

Via phpMyAdmin:
```sql
SELECT * FROM email_logs 
ORDER BY created_at DESC 
LIMIT 20;
```

This shows:
- Which emails were sent
- To whom
- Status (sent/failed)
- Error messages if any

---

## ⚠️ Common Issues & Fixes

### **Issue 1: "Connection refused"**

**Cause:** Wrong SMTP host or port

**Fix:** Double-check SMTP settings with your email provider

### **Issue 2: "Authentication failed"**

**Cause:** Wrong username/password

**Fix:** 
- Verify email and password are correct
- For Gmail, use App Password (not regular password)
- For Hostinger, use full email address as username

### **Issue 3: "SSL certificate problem"**

**Cause:** SSL verification issues on shared hosting

**Fix:** Add to `.env`:
```env
MAIL_VERIFY_PEER=false
```

### **Issue 4: Emails go to spam**

**Cause:** No SPF/DKIM records

**Fix:**
1. Go to Hostinger Control Panel
2. Domains → DNS Records
3. Add SPF record:
   ```
   Type: TXT
   Name: @
   Value: v=spf1 include:_spf.hostinger.com ~all
   ```
4. Enable DKIM in Email settings

---

## 🎯 Recommended Setup for Production

```env
# Email Configuration (Hostinger SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=noreply@z1stores.com
MAIL_PASSWORD=strong_password_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@z1stores.com
MAIL_FROM_NAME="Z1 Stores"

# Optional: Admin email for notifications
ADMIN_EMAIL=admin@z1stores.com
```

---

## 📞 Need Help?

If emails still don't work after configuration:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check email logs in database
3. Test with a simple test email first
4. Contact Hostinger support to verify SMTP is enabled

---

**After configuring, always clear cache and test!** 🚀
