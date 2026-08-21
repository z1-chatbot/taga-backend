<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;

class CheckReviewEligibility extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'review:check {user_id} {product_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a user can review a product and show detailed debugging info';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('user_id');
        $productId = $this->argument('product_id');

        $this->info("=== REVIEW ELIGIBILITY CHECK ===\n");

        // Check user
        $user = User::find($userId);
        if (!$user) {
            $this->error("❌ User ID {$userId} not found!");
            return 1;
        }
        $this->info("✅ User: {$user->name} ({$user->email})");

        // Check product
        $product = Product::find($productId);
        if (!$product) {
            $this->error("❌ Product ID {$productId} not found!");
            return 1;
        }
        $this->info("✅ Product: {$product->name}");
        $this->info("   Rating: {$product->average_rating} ({$product->rating_count} reviews)\n");

        // Check orders
        $this->info("--- CHECKING ORDERS ---");
        $orders = Order::where('user_id', $userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            $this->warn("⚠️  User has NO orders");
        } else {
            $this->info("Found {$orders->count()} order(s):");
            foreach ($orders as $order) {
                $hasProduct = $order->items->where('product_id', $productId)->isNotEmpty();
                $icon = $hasProduct ? '📦' : '   ';
                $this->line("{$icon} Order #{$order->order_number}");
                $this->line("    Payment: {$order->payment_status} | Status: {$order->status}");
                if ($hasProduct) {
                    $this->info("    ✅ Contains this product!");
                }
            }
        }

        // Check if purchased with correct payment status
        $this->info("\n--- CHECKING PURCHASE STATUS ---");
        $hasPurchased = Review::hasUserPurchasedProduct($userId, $productId);
        
        if ($hasPurchased) {
            $this->info("✅ User HAS purchased this product (payment_status = 'paid')");
        } else {
            $this->error("❌ User has NOT purchased this product OR payment not confirmed");
            
            // Show orders with this product but wrong payment status
            $pendingOrders = Order::where('user_id', $userId)
                ->whereHas('items', function($q) use ($productId) {
                    $q->where('product_id', $productId);
                })
                ->where('payment_status', '!=', Order::PAYMENT_PAID)
                ->get();
                
            if ($pendingOrders->isNotEmpty()) {
                $this->warn("\n⚠️  Found order(s) with this product but payment not 'paid':");
                foreach ($pendingOrders as $order) {
                    $this->line("   Order #{$order->order_number}: payment_status = '{$order->payment_status}'");
                }
                $this->comment("\n💡 TIP: Update payment_status to 'paid' to enable reviews");
            }
        }

        // Check existing reviews
        $this->info("\n--- CHECKING EXISTING REVIEWS ---");
        $existingReview = Review::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($existingReview) {
            $this->warn("⚠️  User has ALREADY reviewed this product");
            $this->line("   Rating: {$existingReview->rating}/5");
            $this->line("   Approved: " . ($existingReview->is_approved ? 'Yes' : 'No'));
            $this->line("   Created: {$existingReview->created_at}");
        } else {
            $this->info("✅ User has NOT reviewed this product yet");
        }

        // Final verdict
        $this->info("\n=== FINAL VERDICT ===");
        $canReview = $hasPurchased && !$existingReview;
        
        if ($canReview) {
            $this->info("✅ USER CAN REVIEW THIS PRODUCT");
        } else {
            $this->error("❌ USER CANNOT REVIEW THIS PRODUCT");
            $this->line("\nReasons:");
            if (!$hasPurchased) {
                $this->line("  • Has not purchased product (or payment not confirmed)");
            }
            if ($existingReview) {
                $this->line("  • Has already reviewed this product");
            }
        }

        // Product rating info
        $this->info("\n=== PRODUCT RATING INFO ===");
        $allReviews = Review::where('product_id', $productId)->get();
        $approvedReviews = $allReviews->where('is_approved', true);
        
        $this->line("Total reviews: {$allReviews->count()}");
        $this->line("Approved reviews: {$approvedReviews->count()}");
        $this->line("Pending reviews: " . ($allReviews->count() - $approvedReviews->count()));
        $this->line("Database rating_count: {$product->rating_count}");
        $this->line("Database average_rating: {$product->average_rating}");
        $this->line("Accessor reviews_count: {$product->reviews_count}");

        if ($allReviews->count() > $approvedReviews->count()) {
            $this->comment("\n💡 TIP: Approve pending reviews in admin dashboard to update ratings");
        }

        return 0;
    }
}
