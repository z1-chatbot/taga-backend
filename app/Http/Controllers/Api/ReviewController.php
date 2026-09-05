<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    /**
     * Get reviews for a product
     */
    public function index(Product $product): JsonResponse
    {
        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $reviews
        ]);
    }

    /**
     * Check if user can review a product
     */
    public function canReview(Product $product): JsonResponse
    {
        $userId = auth()->id();
        
        $hasPurchased = Review::hasUserPurchasedProduct($userId, $product->id);
        $hasReviewed = Review::where('user_id', $userId)
            ->where('product_id', $product->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'can_review' => $hasPurchased && !$hasReviewed,
                'has_purchased' => $hasPurchased,
                'has_reviewed' => $hasReviewed
            ]
        ]);
    }

    /**
     * Create a new review
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $userId = auth()->id();
        
        // Check if user has already reviewed this product
        $existingReview = Review::where('user_id', $userId)
            ->where('product_id', $product->id)
            ->first();
            
        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product'
            ], 422);
        }
        
        // Check if user has purchased this product
        $hasPurchased = Review::hasUserPurchasedProduct($userId, $product->id);
        
        if (!$hasPurchased) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review products you have purchased'
            ], 403);
        }

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string|max:1000'
        ]);

        $review = Review::create([
            'user_id' => $userId,
            'product_id' => $product->id,
            'rating' => $request->rating,
            'title' => $request->title,
            'comment' => $request->comment,
            'is_verified_purchase' => true, // Automatically verified since we checked
            'is_approved' => false // Requires admin approval
        ]);

        /*
         * Tell somebody it is there.
         *
         * Nothing announced a review, so one only went up if an administrator
         * happened to open the queue -- while the customer had been told it
         * would appear once checked. Both the platform (who moderates) and the
         * pharmacy (who is being talked about) are told.
         *
         * Best-effort, like every other alert on the platform: the review is
         * saved and the shopper has been thanked, so a mail outage must not
         * turn that into an error they would answer by writing it again.
         */
        try {
            \App\Services\AdminAlerts::reviewSubmitted($review);
        } catch (\Throwable $e) {
            \Log::error('Could not announce a new review: '.$e->getMessage(), [
                'review_id' => $review->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully and is pending approval',
            'data' => $review
        ], 201);
    }

    /**
     * Update review
     */
    public function update(Request $request, $id): JsonResponse
    {
        $review = Review::where('user_id', auth()->id())->findOrFail($id);

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'required|string|max:255',
            'comment' => 'required|string|max:1000'
        ]);

        $review->update([
            'rating' => $request->rating,
            'title' => $request->title,
            'comment' => $request->comment,
            'is_approved' => false // Requires re-approval after edit
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review updated successfully',
            'data' => $review
        ]);
    }

    /**
     * Delete review
     */
    public function destroy($id): JsonResponse
    {
        $review = Review::where('user_id', auth()->id())->findOrFail($id);
        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted successfully'
        ]);
    }

    /**
     * Admin: Get all reviews
     */
    public function adminIndex(): JsonResponse
    {
        $reviews = Review::with(['user:id,name', 'product:id,name'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $reviews
        ]);
    }

    /**
     * Admin: Approve review
     */
    public function approve($id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Review approved successfully'
        ]);
    }

    /**
     * Admin: Reject review
     */
    public function reject($id): JsonResponse
    {
        $review = Review::findOrFail($id);
        $review->update(['is_approved' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Review rejected successfully'
        ]);
    }
}
