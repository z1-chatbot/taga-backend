<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\EmailAutomationSetting;
use App\Jobs\SendDailySalesReport;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendDailySalesReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales:daily-report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send daily sales report';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating daily sales report...');

        // Get recipients from settings
        $config = EmailAutomationSetting::getConfig(
            EmailAutomationSetting::DAILY_SALES_REPORT,
            ['recipient_emails' => [], 'include_comparison' => true]
        );
        
        $recipients = $config['recipient_emails'] ?? [];
        $includeComparison = $config['include_comparison'] ?? true;
        
        if (empty($recipients)) {
            $this->warn('No recipient emails configured for daily sales report.');
            $this->info('Please configure recipient emails in the email automation settings.');
            return 0;
        }
        
        $yesterday = Carbon::yesterday();
        $reportDate = $yesterday->format('F j, Y');
        
        $this->info("Generating report for: {$reportDate}");
        
        // Calculate yesterday's stats
        $yesterdayOrders = Order::whereDate('created_at', $yesterday)->get();
        $totalRevenue = $yesterdayOrders->sum('total_amount');
        $totalOrders = $yesterdayOrders->count();
        $totalItemsSold = OrderItem::whereIn('order_id', $yesterdayOrders->pluck('id'))->sum('quantity');
        
        // Orders by status
        $ordersByStatus = [];
        foreach ($yesterdayOrders->groupBy('status') as $status => $orders) {
            $ordersByStatus[$status] = [
                'count' => $orders->count(),
                'revenue' => $orders->sum('total_amount'),
            ];
        }
        
        // Top products
        $topProducts = OrderItem::whereIn('order_id', $yesterdayOrders->pluck('id'))
            ->selectRaw('product_name, SUM(quantity) as quantity, SUM(price * quantity) as revenue')
            ->groupBy('product_name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'revenue' => $item->revenue,
                ];
            })
            ->toArray();
        
        // Customer insights
        $newCustomers = User::whereDate('created_at', $yesterday)->count();
        $returningCustomers = $totalOrders - $newCustomers;
        
        // Comparison with day before (if enabled)
        $comparison = null;
        if ($includeComparison) {
            $dayBeforeYesterday = Carbon::yesterday()->subDay();
            $previousDayRevenue = Order::whereDate('created_at', $dayBeforeYesterday)->sum('total_amount');
            $revenueChange = $previousDayRevenue > 0 
                ? (($totalRevenue - $previousDayRevenue) / $previousDayRevenue) * 100 
                : 0;
            
            $comparison = [
                'revenue_change' => round($revenueChange, 1),
            ];
        }
        
        $reportData = [
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'average_order_value' => $totalOrders > 0 ? $totalRevenue / $totalOrders : 0,
            'total_items_sold' => $totalItemsSold,
            'orders_by_status' => $ordersByStatus,
            'top_products' => $topProducts,
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
            'comparison' => $comparison,
        ];
        
        $this->info("Total Revenue: ₦" . number_format($totalRevenue, 2));
        $this->info("Total Orders: {$totalOrders}");
        
        // Send to each recipient
        foreach ($recipients as $email) {
            SendDailySalesReport::dispatch($reportData, $reportDate, $email);
            $this->info("Report queued for: {$email}");
        }
        
        $this->info('Daily sales report sent to ' . count($recipients) . ' recipient(s).');
        
        return 0;
    }
}
