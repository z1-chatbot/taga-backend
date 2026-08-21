<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailAutomationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled',
        'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
    ];

    /*
     * Email automation types.
     *
     * new_product, sale_event, price_drop and back_in_stock were removed: they
     * offered an operator a switch with nothing behind it. The first two had a
     * job and a template that nothing ever dispatched; the last two never had
     * any code at all. Rather than leave four switches that quietly do nothing,
     * the rows, jobs, mailables and templates are gone.
     *
     * Adding any of them back means writing the dispatcher first — the switch
     * is the last step, not the first.
     */
    const CART_REMINDER = 'cart_reminder';
    const ORDER_FOLLOW_UP = 'order_follow_up';
    const WELCOME_EMAIL = 'welcome_email';
    const ABANDONED_CART_1H = 'abandoned_cart_1h';
    const ABANDONED_CART_24H = 'abandoned_cart_24h';
    const ABANDONED_CART_3D = 'abandoned_cart_3d';
    
    // Order status emails
    const ORDER_STATUS_CONFIRMED = 'order_status_confirmed';
    const ORDER_STATUS_PROCESSING = 'order_status_processing';
    const ORDER_STATUS_SHIPPED = 'order_status_shipped';
    const ORDER_STATUS_DELIVERED = 'order_status_delivered';
    const ORDER_STATUS_CANCELLED = 'order_status_cancelled';
    
    // Admin notifications
    const LOW_STOCK_ALERT = 'low_stock_alert';
    const DAILY_SALES_REPORT = 'daily_sales_report';

    /**
     * Get setting by key.
     */
    public static function getByKey(string $key)
    {
        return static::where('key', $key)->first();
    }

    /**
     * Emails the platform sends as a matter of course.
     *
     * Everything a customer touches: their account, their basket, their order.
     * These are default behaviour in any shop, so there is nothing for an
     * operator to decide — no switch, and nothing to configure either, because
     * the timing follows the event rather than a setting.
     *
     * They still appear on the Email Automation screen, marked always-on, with
     * a test button, so you can see what the platform sends and prove it works.
     *
     * What is left switchable is the internal reporting — low stock alerts and
     * the daily sales digest — where recipients genuinely need choosing and
     * there are real reasons to pause.
     */
    public const ALWAYS_ON = [
        // Customer engagement — an account is created, or a basket is left
        // behind. Both are ordinary behaviour a shopper expects.
        self::WELCOME_EMAIL,
        self::ABANDONED_CART_1H,
        self::ABANDONED_CART_24H,
        self::ABANDONED_CART_3D,

        // Order management — someone paid, and is owed word of what happened.
        self::ORDER_STATUS_CONFIRMED,
        self::ORDER_STATUS_PROCESSING,
        self::ORDER_STATUS_SHIPPED,
        self::ORDER_STATUS_DELIVERED,
        self::ORDER_STATUS_CANCELLED,
        self::ORDER_FOLLOW_UP,
    ];

    public static function isAlwaysOn(string $key): bool
    {
        return in_array($key, self::ALWAYS_ON, true);
    }

    /**
     * The mailable each automation actually sends.
     *
     * Kept so that a test send can leave through the same mailbox as the real
     * message. "Email works" measured from the default mailer proves nothing
     * here: every mailbox is a separate SMTP account with its own password, so
     * noreply can be perfectly healthy while shop rejects everything.
     */
    public const MAILABLES = [
        self::WELCOME_EMAIL => \App\Mail\WelcomeEmail::class,
        self::ABANDONED_CART_1H => \App\Mail\CartReminderEmail::class,
        self::ABANDONED_CART_24H => \App\Mail\CartReminderEmail::class,
        self::ABANDONED_CART_3D => \App\Mail\CartReminderEmail::class,
        self::ORDER_FOLLOW_UP => \App\Mail\OrderFollowUpEmail::class,
        self::ORDER_STATUS_CONFIRMED => \App\Mail\OrderStatusEmail::class,
        self::ORDER_STATUS_PROCESSING => \App\Mail\OrderStatusEmail::class,
        self::ORDER_STATUS_SHIPPED => \App\Mail\OrderStatusEmail::class,
        self::ORDER_STATUS_DELIVERED => \App\Mail\OrderStatusEmail::class,
        self::ORDER_STATUS_CANCELLED => \App\Mail\OrderStatusEmail::class,
        self::LOW_STOCK_ALERT => \App\Mail\LowStockAlertEmail::class,
        self::DAILY_SALES_REPORT => \App\Mail\DailySalesReportEmail::class,
    ];

    /**
     * Which mailbox an automation's mail goes out from.
     *
     * Read off the mailable rather than restated here, so moving a message to
     * a different mailbox moves its test along with it.
     */
    public static function mailboxFor(?string $key): string
    {
        $mailable = $key ? (self::MAILABLES[$key] ?? null) : null;

        if (! $mailable || ! class_exists($mailable)) {
            return 'shop';
        }

        return (new \ReflectionClass($mailable))->getDefaultProperties()['mailbox'] ?? 'shop';
    }

    /**
     * Check if automation is enabled.
     *
     * Short-circuits for the always-on set rather than trusting the stored
     * flag, so a stale `is_enabled = false` row — or a toggle slipped past the
     * API — cannot silence an order confirmation.
     */
    public static function isEnabled(string $key): bool
    {
        if (self::isAlwaysOn($key)) {
            return true;
        }

        $setting = static::getByKey($key);

        return $setting ? $setting->is_enabled : false;
    }

    /**
     * Get configuration for automation.
     */
    public static function getConfig(string $key, $default = [])
    {
        $setting = static::getByKey($key);
        return $setting ? ($setting->config ?? $default) : $default;
    }

    /**
     * The automations this platform ships with.
     *
     * Public so it can be asserted against: an entry here is a promise that
     * something dispatches it, and a test holds that promise to account.
     *
     * @return array<int, array{key: string, name: string, description: string, is_enabled: bool, config: array}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => self::WELCOME_EMAIL,
                'name' => 'Welcome Email',
                'description' => 'Send welcome email after email verification',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ABANDONED_CART_1H,
                'name' => 'Abandoned Cart - 1 Hour',
                'description' => 'Remind users about items in cart after 1 hour',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ABANDONED_CART_24H,
                'name' => 'Abandoned Cart - 24 Hours',
                'description' => 'Remind users about items in cart after 24 hours',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ABANDONED_CART_3D,
                'name' => 'Abandoned Cart - 3 Days',
                'description' => 'Final reminder about items in cart after 3 days',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ORDER_FOLLOW_UP,
                'name' => 'Order Follow-up',
                'description' => 'Request review after order delivery',
                'is_enabled' => true,
                'config' => ['delay_days' => 7],
            ],
            
            // Order Status Emails
            [
                'key' => self::ORDER_STATUS_CONFIRMED,
                'name' => 'Order Confirmed Email',
                'description' => 'Send email when order is confirmed',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ORDER_STATUS_PROCESSING,
                'name' => 'Order Processing Email',
                'description' => 'Send email when order is being processed',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ORDER_STATUS_SHIPPED,
                'name' => 'Order Shipped Email',
                'description' => 'Send email when order is shipped with tracking',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ORDER_STATUS_DELIVERED,
                'name' => 'Order Delivered Email',
                'description' => 'Send email when order is delivered',
                'is_enabled' => true,
                'config' => [],
            ],
            [
                'key' => self::ORDER_STATUS_CANCELLED,
                'name' => 'Order Cancelled Email',
                'description' => 'Send email when order is cancelled',
                'is_enabled' => true,
                'config' => [],
            ],
            
            // Admin Notifications
            [
                'key' => self::LOW_STOCK_ALERT,
                'name' => 'Low Stock Alert',
                'description' => 'Send alert to admin when product stock is low',
                'is_enabled' => true,
                // The stock level that counts as low lives on the Settings page
                // (SystemSetting::lowStockThreshold()) so one number drives the
                // alert and every dashboard. Frequency is the schedule's, not a
                // setting. Only the admin recipients belong here — a pharmacy is
                // found from its own record.
                'config' => [
                    'recipient_emails' => [],
                ],
            ],
            [
                'key' => self::DAILY_SALES_REPORT,
                'name' => 'Daily Sales Report',
                'description' => 'Send daily sales summary to admin',
                'is_enabled' => true,
                // send_time was decoration: routes/console.php schedules this
                // at 08:00 and nothing read the setting.
                'config' => [
                    'recipient_emails' => [],
                    'include_comparison' => true,
                ],
            ],
        ];
    }

    /**
     * Initialize default automation settings.
     */
    public static function initializeDefaults()
    {
        foreach (self::defaults() as $default) {
            static::updateOrCreate(
                ['key' => $default['key']],
                $default
            );
        }
    }
}
