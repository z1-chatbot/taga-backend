# Cron Job Setup Guide for Shared Hosting

## Current Issues & Solutions

### Issue 1: Order Confirmation Emails Not Being Received
**Status:** ✅ FIXED
- Order confirmation emails (with delivery code) were being queued instead of sent immediately
- Changed to send synchronously in PaystackService.php
- Emails now sent immediately after payment confirmation

### Issue 2: process-queue.php Returns "Unauthorized"
**Status:** 🔧 DEBUGGING

Your current cron job:
```bash
curl -s "https://api.z1stores.com/process-queue.php?key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"
```

**Test the endpoint manually first:**
```bash
curl -v "https://api.z1stores.com/process-queue.php?key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"
```

The response will now show debug info:
```json
{
  "success": false,
  "message": "Unauthorized access",
  "debug": {
    "provided_key_length": 0,
    "expected_key_length": 43,
    "keys_match": false
  }
}
```

**Possible causes:**
1. **URL encoding issue** - The underscore characters might be getting encoded
2. **Server rewrite rules** - .htaccess might be stripping query parameters
3. **Quotes issue** - Try without quotes or with single quotes

**Try these alternatives:**

```bash
# Option 1: URL encode the key
curl -s "https://api.z1stores.com/process-queue.php?key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"

# Option 2: POST instead of GET
curl -s -X POST "https://api.z1stores.com/process-queue.php" -d "key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"

# Option 3: Simpler key without special characters
# Change SECRET_KEY in process-queue.php to: 'myqueue2026key'
curl -s "https://api.z1stores.com/process-queue.php?key=myqueue2026key"
```

### Issue 3: Abandoned Cart Reminders Not Being Sent
**Status:** ⚠️ NEEDS IMPLEMENTATION

The scheduled email system doesn't exist yet. You need to:

1. **Create the scheduled email processor command**
2. **Add it to the schedule in Kernel.php**
3. **Ensure run-schedule.php is calling it**

---

## Recommended Cron Job Configuration

### On Hostinger Cron Jobs Panel:

**Cron Job 1: Run Laravel Scheduler (Every 5 minutes)**
```
*/5 * * * * curl -s "https://api.z1stores.com/run-schedule.php?key=shashhsa_uu72_ssjjasja_82shh4jsjsa"
```
✅ This is working (you see success response)

**Cron Job 2: Process Queue (Every 5 minutes)**
```
*/5 * * * * curl -s "https://api.z1stores.com/process-queue.php?key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"
```
❌ This returns "Unauthorized" - needs debugging

---

## What Each Cron Job Does

### run-schedule.php (Working ✅)
Runs Laravel scheduled tasks including:
- Release pending agent/company earnings (every 5 min)
- Clean up expired carts (daily)
- Clean up abandoned payments (hourly)
- Deactivate expired coupons/sales (every 5 min)
- Update product rankings (hourly)
- **Will process scheduled emails once implemented**

### process-queue.php (Not Working ❌)
Processes queued jobs including:
- **Currently: Nothing critical** (order confirmation emails now sent immediately)
- Future: Background jobs, bulk operations, etc.

---

## Immediate Action Required

1. **Test process-queue.php manually** with curl to see the debug output
2. **Share the debug output** so we can fix the authentication issue
3. **For now, critical emails work** (order confirmation with delivery code)
4. **Abandoned cart reminders** need to be implemented separately

---

## Alternative: Simplify the Queue Key

Edit `public/process-queue.php` line 15:
```php
// Change from:
$SECRET_KEY = 'hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy';

// To something simpler:
$SECRET_KEY = 'queue2026secret';
```

Then update your cron job:
```bash
curl -s "https://api.z1stores.com/process-queue.php?key=queue2026secret"
```

---

## Verify Everything Works

After fixing the cron jobs, check `storage/logs/laravel.log` for:

```
[2026-02-23 XX:XX:XX] local.INFO: Schedule run via cron {"success":true,...}
[2026-02-23 XX:XX:XX] local.INFO: Queue processed via cron {"jobs_processed":X,...}
```

If you see these entries every 5 minutes, cron jobs are working!
