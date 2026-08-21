<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class FirstOrderCouponService
{
    /**
     * Check if user is eligible for first order coupon
     */
    public function isEligibleForFirstOrderCoupon($userId): bool
    {
        if (!$userId) {
            return false; // Guest users not eligible
        }
        
        // Check if user has any paid orders
        $hasPaidOrder = Order::where('user_id', $userId)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->exists();
        
        return !$hasPaidOrder;
    }
    
    /**
     * Generate first order coupon for user
     */
    public function generateFirstOrderCoupon($userId): ?Coupon
    {
        if (!$this->isEligibleForFirstOrderCoupon($userId)) {
            return null;
        }
        
        $user = User::find($userId);
        if (!$user) {
            return null;
        }
        
        // Check if user already has a first order coupon
        $existingCoupon = Coupon::where('code', 'LIKE', 'FIRST-' . $user->id . '-%')
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();
        
        if ($existingCoupon) {
            return $existingCoupon;
        }
        
        // Create new first order coupon
        $couponCode = 'FIRST-' . $user->id . '-' . strtoupper(substr(md5(time()), 0, 6));
        
        $coupon = Coupon::create([
            'code' => $couponCode,
            'type' => 'percentage',
            'value' => 10, // 10% discount
            'min_purchase_amount' => 5000, // Minimum ₦5,000
            'max_discount_amount' => 2000, // Maximum ₦2,000 discount
            'usage_limit' => 1,
            'usage_per_user' => 1,
            'is_active' => true,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30), // Valid for 30 days
            'description' => 'Welcome! Enjoy 10% off your first order',
            'is_first_order_coupon' => true
        ]);
        
        Log::info('First order coupon generated', [
            'user_id' => $userId,
            'coupon_code' => $couponCode,
            'expires_at' => $coupon->expires_at
        ]);
        
        return $coupon;
    }
    
    /**
     * Send first order coupon email to user
     */
    public function sendFirstOrderCouponEmail(User $user, Coupon $coupon)
    {
        try {
            \Mail::to($user->email)->send(
                new \App\Mail\FirstOrderCouponEmail($user, $coupon)
            );
            
            Log::info('First order coupon email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
                'coupon_code' => $coupon->code
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send first order coupon email: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'coupon_code' => $coupon->code
            ]);
        }
    }
    
    /**
     * Process first order completion - generate coupon for next purchase
     */
    public function processFirstOrderCompletion(Order $order)
    {
        if (!$order->user_id) {
            return; // Guest orders not eligible
        }
        
        // Check if this is truly the first paid order
        $paidOrdersCount = Order::where('user_id', $order->user_id)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->count();
        
        if ($paidOrdersCount === 1) {
            // This is the first order, generate coupon for next purchase
            $coupon = $this->generateFirstOrderCoupon($order->user_id);
            
            if ($coupon && $order->user) {
                $this->sendFirstOrderCouponEmail($order->user, $coupon);
            }
        }
    }
}
