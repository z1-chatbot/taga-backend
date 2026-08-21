<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\DailySalesReportEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendDailySalesReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $reportData;
    public $reportDate;
    public $recipientEmail;

    /**
     * Create a new job instance.
     */
    public function __construct(array $reportData, string $reportDate, string $recipientEmail)
    {
        $this->reportData = $reportData;
        $this->reportDate = $reportDate;
        $this->recipientEmail = $recipientEmail;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        if (!EmailAutomationSetting::isEnabled(EmailAutomationSetting::DAILY_SALES_REPORT)) {
            \Log::info("Daily sales report automation disabled");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $this->recipientEmail,
                'daily_sales_report',
                'Daily Sales Report - ' . $this->reportDate,
                null,
                null
            );

            // Send the email
            Mail::to($this->recipientEmail)->send(
                new DailySalesReportEmail($this->reportData, $this->reportDate)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Daily sales report sent to {$this->recipientEmail}");

        } catch (\Exception $e) {
            \Log::error("Failed to send daily sales report: " . $e->getMessage());
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
