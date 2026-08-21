# Making email work on shared hosting

Everything the platform sends — order confirmations, licence approvals, password
resets, low stock alerts, the daily sales report — goes through the same two
mechanisms. Both have to be right or nothing arrives.

Check the whole chain at any time with:

```bash
php artisan mail:doctor
```

Add `--send=you@example.com --mailbox=shop` to push a real test message
through a specific mailbox.

---

## 1. Three mailboxes, three SMTP accounts

An SMTP account may only send as its own address. Sending as anything else is
refused:

```
553 5.7.1 <support@taga.ng>: Sender address rejected:
           not owned by user hello@z1stores.com
```

Every one of the first 53 emails failed exactly this way — connection and login
were fine, it failed at the envelope. So three From addresses means three
logins, not one login and three `from` lines.

| Mailbox | Carries | Replies go to |
|---|---|---|
| `noreply@taga.ng` | sign-up, email verification, password reset | support |
| `shop@taga.ng` | orders, cart, reminders, delivery, payouts, store/agent/logistics updates | support |
| `support@taga.ng` | licence decisions, expiry warnings — anything a person argues with | itself |

Set the credentials in `.env`:

```env
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl

MAIL_NOREPLY_USERNAME=noreply@taga.ng
MAIL_NOREPLY_PASSWORD=...

MAIL_SHOP_USERNAME=shop@taga.ng
MAIL_SHOP_PASSWORD=...

MAIL_SUPPORT_USERNAME=support@taga.ng
MAIL_SUPPORT_PASSWORD=...
```

You do not set a From address anywhere. `config/mail.php` derives each mailer's
From from its own `MAIL_*_USERNAME`, which is what makes the 553 mismatch
impossible to reintroduce by editing one variable. A test enforces it.

Anything left blank falls back to the base `MAIL_*` values, so the platform
keeps sending while the remaining mailboxes are being set up.

### Which mailable goes where

Each class in `app/Mail` declares its own mailbox and applies the trait that
makes the declaration stick:

```php
use App\Mail\Concerns\SendsFromMailbox;

class OrderStatusEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    protected string $mailbox = 'shop';
```

`noreply` — VerifyEmail, ResetPasswordEmail, WelcomeEmail, AgentInvitationEmail,
LogisticsCompanyWelcomeEmail

`shop` — OrderNotificationEmail, OrderStatusEmail, OrderFollowUpEmail,
CartReminderEmail, FirstOrderCouponEmail, DeliveryAssignmentEmail,
DeliveryTrackingUpdateEmail, LowStockAlertEmail, DailySalesReportEmail,
PayoutRequestedEmail, PayoutApprovedEmail, PayoutRejectedEmail

`support` — StoreVerificationDecisionEmail, LicenceExpiryWarningEmail

`test_every_mailable_is_routed_to_a_mailbox` fails if a new one is added
without both the property and the trait.

### Both halves are needed — declaring the mailbox is not enough

The trait is not decoration. Laravel discards a mailable's own choice of mailer
the moment it is dispatched: `Illuminate\Mail\Mailer::sendMailable()` does

```php
$mailable->mailer($this->name)->send($this)
```

writing the name of whichever mailer picked the message up over `$mailer`
before the message is built. `Mail::to()`, `Mail::send()` and `Mail::queue()`
all run through the *default* mailer, so for a while every message the platform
sent went out as the default identity no matter what its class declared —
authenticating as `MAIL_USERNAME` while claiming to be `MAIL_FROM_ADDRESS`,
which is exactly the pair that earns a 553.

`SendsFromMailbox` restores the declared mailbox at the last moment and hands
`send()` the mail *manager* rather than a single mailer, because that is the
only form Laravel consults `$mailer` for. `$mailbox` is a separate property
precisely because `$mailer` is the one the framework overwrites.

The lesson for anything added later: a test that asserts the declaration exists
proves nothing. `test_the_declared_mailbox_survives_dispatch` dispatches the way
the application really does and checks where the message landed.

### Testing a mailbox from the admin screen

The Test button on Email Automation sends through the mailbox that automation's
real message uses — `welcome_email` tests noreply, everything else tests shop —
and the reply names the mailbox and address it went out from. A test from the
default mailer would prove only that the default works, which is how a broken
shop account could sit unnoticed behind a healthy noreply.

### Port and encryption

Pick a matching pair — 465 with implicit SSL, or 587 with STARTTLS. Not one of
each.

### Verify each account separately

```bash
php artisan mail:doctor
php artisan mail:doctor --send=you@example.com --mailbox=noreply
php artisan mail:doctor --send=you@example.com --mailbox=shop
php artisan mail:doctor --send=you@example.com --mailbox=support
```

A mismatch in one mailbox kills only that category — password resets can stop
while orders keep sending — so test all three.

---

## 2. Something has to drain the queue

Every email is a queued job (`ShouldQueue`) and `QUEUE_CONNECTION=database`, so
messages sit in the `jobs` table until a worker runs. Shared hosting will not
keep a `queue:work` daemon alive, and 13 order emails once sat unattempted for
weeks because nothing did.

`routes/console.php` schedules a drain every minute:

```php
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --quiet')
    ->everyMinute()
```

which means **one cron entry drives both the schedule and the queue**:

```
* * * * * cd /home/USER/path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Add that in hPanel → Advanced → Cron Jobs. Use the absolute path to the
`backend` directory, and Hostinger's PHP binary if `php` is not on the cron
PATH (often something like `/usr/bin/php82`).

To confirm it is working: `php artisan mail:doctor` and watch the *pending*
count fall. If it never falls, cron is not running the scheduler.

### If you have no cron at all

Set `QUEUE_CONNECTION=sync`. Jobs then run inline — reliable, but every
registration and checkout waits for SMTP before it responds, which is slow and
turns a mail outage into a checkout outage. Prefer the cron.

---

## 3. The scheduler had to be registered

Laravel 11 and 12 do not load `app/Console/Kernel.php`. This app still had one,
full of scheduled tasks, and `schedule:list` reported *"No scheduled tasks have
been defined"* — so the daily sales report, low stock alerts, licence expiry
warnings and earnings release had never run once.

They now live in `routes/console.php`, which `bootstrap/app.php` loads
explicitly. After any change to the schedule, verify with:

```bash
php artisan schedule:list
```

If a task you just added is missing from that output, it will not run.

---

## 4. Before going live

- `APP_ENV=production` and `APP_DEBUG=false`. Debug mode leaks stack traces and
  environment contents to anyone who triggers an error.
- Run `php artisan config:clear` after editing `.env`. The weekly `optimize`
  task caches config, and a cached config beats a changed `.env`.
- Delete `.env.before-cors-fix` — it contains the app key and payment keys.
- Clear any stale queued jobs before the first cron run, or customers will
  receive order emails from weeks ago:

  ```bash
  php artisan queue:clear
  ```

---

## Which automations actually send

Every automation on the screen has something that dispatches it, and every
config field it offers is one the code reads. Tests enforce both.

### Always on — not switchable

Transactional. Someone paid, or created an account, and is owed a message.
`EmailAutomationSetting::isEnabled()` short-circuits for these, so a stale row
or a direct database edit cannot silence an order confirmation, and the API
refuses to toggle them with a 422.

| Automation | Fires on |
|---|---|
| Welcome email | account verifies its email |
| Order confirmed / processing / shipped / delivered / cancelled | order status change |

They still appear on the screen — marked *Always on*, with no power button — so
you can see what the platform sends and still send a test.

### Switchable — campaigns and internal digests

| Automation | Fires on | Configurable |
|---|---|---|
| Abandoned cart 1h / 24h / 3d | hourly `cart:remind` | nothing — the timing is the name |
| Order follow-up | after delivery | `delay_days` |
| Low stock alert | daily 09:00, per pharmacy | admin recipients for platform-owned stock |
| Daily sales report | daily 08:00 | recipients, `include_comparison` |

### Abandoned cart

`cart:remind` runs hourly. For each signed-in shopper with items left behind it
picks the *longest* stage the basket has passed — a basket dead for five days
gets the 3-day message, not "you left something an hour ago" — and refuses to
repeat a stage for the same basket. Guest baskets are skipped; they are keyed by
session id and carry no address.

Dedupe reads `email_logs`, scoped to the current basket, so someone who abandons
again months later hears from us again.

```bash
php artisan cart:remind --dry-run
```

### Config fields that were removed

These were offered on the screen and read by nothing:

| Field | Was on | Now governed by |
|---|---|---|
| `delay_minutes` | welcome, all 3 cart stages | the stage name; welcome sends immediately |
| `send_time` | daily sales report | the schedule (08:00) |
| `check_frequency` | low stock | the schedule (daily) |
| `threshold` | low stock | Settings → General → Low Stock Alert Threshold |

`abandoned_cart_24h` carrying an editable "delay 1440 minutes" was the worst of
them: it invited someone to set 90 and wonder for a week why nothing changed.

### Automations that were deleted

`new_product`, `sale_event`, `price_drop`, `back_in_stock` — nothing could ever
send them. Two showed as "on", which is worse than absent. The jobs, mailables
and templates for the first two went with them, so restoring a row alone will
not bring them back. Write the dispatcher first.
