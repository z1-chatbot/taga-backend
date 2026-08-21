<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\LowStockAlertEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendLowStockAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $lowStockProducts;
    public $threshold;
    public $recipientEmail;

    /** The pharmacy this list belongs to; null for platform-owned stock. */
    public ?string $storeName;

    public function __construct(
        array $lowStockProducts,
        int $threshold,
        string $recipientEmail,
        ?string $storeName = null
    ) {
        $this->lowStockProducts = $lowStockProducts;
        $this->threshold = $threshold;
        $this->recipientEmail = $recipientEmail;
        $this->storeName = $storeName;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        if (!EmailAutomationSetting::isEnabled(EmailAutomationSetting::LOW_STOCK_ALERT)) {
            \Log::info("Low stock alert automation disabled");
            return;
        }

        // Don't send if no products
        if (empty($this->lowStockProducts)) {
            \Log::info("No low stock products to report");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $this->recipientEmail,
                'low_stock_alert',
                'Low Stock Alert - ' . count($this->lowStockProducts) . ' products',
                null,
                null
            );

            // Send the email
            Mail::to($this->recipientEmail)->send(
                new LowStockAlertEmail($this->lowStockProducts, $this->threshold, $this->storeName)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Low stock alert sent to {$this->recipientEmail}");

        } catch (\Exception $e) {
            \Log::error("Failed to send low stock alert: " . $e->getMessage());
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
