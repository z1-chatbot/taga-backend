# Email Queue Fix for Hostinger

## Problem
Your emails implement `ShouldQueue`, which means they're stored in a database queue but never sent because Hostinger shared hosting doesn't run queue workers automatically.

## Solution 1: Use Sync Queue (Recommended for Shared Hosting)

### Step 1: Update Server `.env`
Change this line in your server's `.env` file:
```env
QUEUE_CONNECTION=sync
```

This makes emails send **immediately** instead of being queued.

### Step 2: Clear Cache on Server
Visit: `https://api.z1stores.com/clear-cache-direct.php?key=YOUR_SECRET_KEY`

### Step 3: Test Email
Try sending a test email from your admin panel.

---

## Solution 2: Setup Cron Job for Queue Worker (Advanced)

If you want to keep using queues, you need to process them regularly.

### Step 1: Keep `.env` as:
```env
QUEUE_CONNECTION=database
```

### Step 2: Create Queue Processor Script
Upload this file to your server as `public/process-queue.php`:

```php
<?php
// Secret key for security
$SECRET_KEY = 'your-secret-key-here'; // CHANGE THIS!

if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
    http_response_code(403);
    die('Unauthorized');
}

// Load Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Process queue jobs
$exitCode = $kernel->call('queue:work', [
    '--stop-when-empty' => true,
    '--tries' => 3,
    '--timeout' => 60
]);

echo json_encode([
    'success' => $exitCode === 0,
    'message' => $exitCode === 0 ? 'Queue processed successfully' : 'Queue processing failed',
    'exit_code' => $exitCode,
    'timestamp' => date('Y-m-d H:i:s')
]);
```

### Step 3: Setup Cron Job in Hostinger
1. Go to Hostinger Control Panel → Advanced → Cron Jobs
2. Add a new cron job:
   - **Command:** `curl -s "https://api.z1stores.com/process-queue.php?key=your-secret-key-here"`
   - **Frequency:** Every 5 minutes (*/5 * * * *)

---

## Solution 3: Remove Queue from Email Jobs (Quick Fix)

If you want emails to send immediately without changing `.env`, remove `implements ShouldQueue` from all email job files.

### Files to modify:
- `app/Jobs/SendWelcomeEmail.php`
- `app/Jobs/SendOrderStatusEmail.php`
- `app/Jobs/SendCartReminderEmail.php`
- `app/Jobs/SendDailySalesReport.php`
- `app/Jobs/SendLowStockAlert.php`
- `app/Jobs/SendNewProductEmail.php`
- `app/Jobs/SendOrderFollowUpEmail.php`
- `app/Jobs/SendSaleEventEmail.php`
- `app/Jobs/SendStaffWelcomeEmail.php`

Change from:
```php
class SendWelcomeEmail implements ShouldQueue
```

To:
```php
class SendWelcomeEmail
```

---

## Recommended Approach

**For Hostinger shared hosting, use Solution 1 (Sync Queue)** - it's the simplest and most reliable.

1. Change `.env`: `QUEUE_CONNECTION=sync`
2. Clear cache
3. Test emails

This will make emails send immediately without needing queue workers or cron jobs.

---

## Verify Email is Working

After applying the fix:

1. **Check Laravel Logs:**
   - Look in `storage/logs/laravel.log` for email sending attempts
   - Search for "Welcome email sent" or "Failed to send"

2. **Check Email Logs Table:**
   ```sql
   SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 10;
   ```

3. **Test from Admin Panel:**
   - Go to Settings → Email Settings
   - Send a test email

4. **Check SMTP Credentials:**
   - Verify `MAIL_PASSWORD` is set correctly (not empty)
   - Verify `hello@z1stores.com` exists in your Hostinger email accounts

---

## Common Issues

### Issue: "MAIL_PASSWORD is empty"
**Fix:** Add your actual email password in `.env`:
```env
MAIL_PASSWORD="your-actual-password-here"
```

### Issue: "Authentication failed"
**Fix:** 
- Verify email account exists in Hostinger
- Check if 2FA is enabled (disable it for SMTP)
- Try using the full email as username

### Issue: "Connection timeout"
**Fix:**
- Try port 465 with SSL instead of 587 with TLS:
```env
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
```

---

## Testing Checklist

- [ ] Updated `.env` with `QUEUE_CONNECTION=sync`
- [ ] Cleared Laravel cache
- [ ] Verified `MAIL_PASSWORD` is set
- [ ] Sent test email from admin panel
- [ ] Checked `storage/logs/laravel.log` for errors
- [ ] Checked `email_logs` table for sent emails
- [ ] Verified email arrived in inbox (check spam folder)
