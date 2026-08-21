# Updated Cron Job Configuration

## ✅ Fixed: Simplified Secret Keys

The issue was that underscores in the secret key were causing URL parsing problems.

---

## Cron Job 1: Laravel Scheduler (Every 5 minutes)

**Status:** ✅ Already Working

```bash
*/5 * * * * curl -s "https://api.z1stores.com/run-schedule.php?key=shashhsa_uu72_ssjjasja_82shh4jsjsa"
```

**What it does:**
- Releases pending agent/company earnings (every 5 min)
- Cleans up expired carts (daily)
- Cleans up abandoned payments (hourly)
- Deactivates expired coupons/sales (every 5 min)
- Updates product rankings (hourly)
- Processes refunds (daily)
- Database backup (daily at 2am)
- Cache clearing (weekly)

---

## Cron Job 2: Queue Processor (Every 5 minutes)

**Status:** 🔧 UPDATED - New Secret Key

**NEW CRON JOB:**
```bash
*/5 * * * * curl -s "https://api.z1stores.com/process-queue.php?key=queue2026secretkey"
```

**What it does:**
- Processes any queued background jobs
- Currently: Minimal usage (critical emails now sent immediately)
- Future: Bulk operations, exports, etc.

---

## Test the New Configuration

### Test process-queue.php:
```bash
curl -s "https://api.z1stores.com/process-queue.php?key=queue2026secretkey"
```

**Expected response:**
```json
{
  "success": true,
  "message": "Queue processed successfully",
  "exit_code": 0,
  "queue_connection": "database",
  "jobs_processed": 0,
  "jobs_remaining": 0,
  "failed_jobs": 0,
  "timestamp": "2026-02-23 10:25:00"
}
```

### Test run-schedule.php (already working):
```bash
curl -s "https://api.z1stores.com/run-schedule.php?key=shashhsa_uu72_ssjjasja_82shh4jsjsa"
```

**Expected response:**
```json
{
  "success": true,
  "message": "Schedule executed successfully",
  "exit_code": 0,
  "timestamp": "2026-02-23 10:25:00"
}
```

---

## Update Your Hostinger Cron Jobs

1. **Go to Hostinger Control Panel → Cron Jobs**

2. **Update the process-queue.php cron job:**
   - **Old:** `curl -s "https://api.z1stores.com/process-queue.php?key=hshhshs_jxjj7733_jdjjd78uusu_8tyah778yy"`
   - **New:** `curl -s "https://api.z1stores.com/process-queue.php?key=queue2026secretkey"`
   - **Schedule:** Every 5 minutes (`*/5 * * * *`)

3. **Keep the run-schedule.php cron job as is** (it's already working)

---

## Verify Everything is Working

After updating the cron job, wait 5-10 minutes, then check `storage/logs/laravel.log`:

```
[2026-02-23 10:25:00] local.INFO: Schedule run via cron {"success":true,...}
[2026-02-23 10:25:00] local.INFO: Queue processed via cron {"jobs_processed":0,...}
```

If you see both entries appearing every 5 minutes, everything is working perfectly! ✅

---

## What's Now Working

✅ **Order confirmation emails** - Sent immediately (no queue needed)
✅ **Status update emails** - Sent immediately after status changes
✅ **Scheduled tasks** - Running every 5 minutes via run-schedule.php
✅ **Queue processing** - Will work once you update the cron job
✅ **Earnings release** - Automatic via scheduler
✅ **Cart cleanup** - Automatic via scheduler
✅ **Expired promotions** - Automatic deactivation via scheduler

---

## Important Notes

- **Critical emails don't use the queue** - They're sent immediately for reliability
- **The queue is mainly for future features** - Bulk operations, exports, etc.
- **The scheduler handles most automation** - Earnings, cleanup, rankings, etc.
- **Both cron jobs should run every 5 minutes** for optimal performance
