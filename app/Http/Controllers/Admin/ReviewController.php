<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    use ScopesToStore;

    /**
     * Reviews of the caller's own products.
     *
     * A review is tied to a shop through the product it is about — reviews have
     * no store column of their own.
     */
    private function scopeReviewsToStore($query, Request $request)
    {
        $user = $request->user();

        if (! $user || $user->isPlatformAdmin()) {
            return $query;
        }

        $storeId = $user->storeScopeId();

        return $storeId === null
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('product', fn ($q) => $q->where('store_id', $storeId));
    }

    /**
     * A review the caller is allowed to act on, or null.
     *
     * approve(), reject(), destroy() and the bulk actions carried no ownership
     * check at all, so any store role holding reviews.approve could approve or
     * delete reviews of another pharmacy's products.
     */
    private function reviewInScope(Request $request, $id): ?Review
    {
        return $this->scopeReviewsToStore(Review::query(), $request)
            ->whereKey($id)
            ->first();
    }

    /**
     * Get all reviews with filtering and sorting
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Review::with(['user', 'product']);

        $this->scopeReviewsToStore($query, $request);

        // Apply filters
        if ($request->has('status')) {
            if ($request->status === 'pending') {
                $query->where('is_approved', false);
            } elseif ($request->status === 'approved') {
                $query->where('is_approved', true);
            }
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('user', function($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('product', function($productQuery) use ($search) {
                    $productQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('comment', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $sortBy = $request->get('sort', 'newest');
        switch ($sortBy) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'rating_high':
                $query->orderBy('rating', 'desc');
                break;
            case 'rating_low':
                $query->orderBy('rating', 'asc');
                break;
            case 'helpful':
                $query->orderBy('helpful_count', 'desc');
                break;
            default: // newest
                $query->orderBy('created_at', 'desc');
                break;
        }

        $reviews = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total()
            ]
        ]);
    }

    /**
     * Get review statistics
     */
    public function stats(Request $request): JsonResponse
    {
        // Counted across every pharmacy's reviews, so a shop's moderation tiles
        // showed the whole platform's backlog.
        $scoped = fn () => $this->scopeReviewsToStore(Review::query(), $request);

        $stats = [
            'total' => $scoped()->count(),
            'pending' => $scoped()->where('is_approved', false)->count(),
            'approved' => $scoped()->where('is_approved', true)->count(),
            'average_rating' => $scoped()->avg('rating') ?: 0,
            'by_rating' => []
        ];

        // Get count by rating
        for ($i = 1; $i <= 5; $i++) {
            $stats['by_rating'][$i] = $scoped()->where('rating', $i)->count();
        }

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Approve a review
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $review = $this->reviewInScope($request, $id);

        if (! $review) {
            return $this->notFoundForCaller('Review');
        }

        $review->update([
            'is_approved' => true
        ]);

        // Manually trigger rating update to ensure it happens
        $review->product->updateRating();

        return response()->json([
            'success' => true,
            'message' => 'Review approved successfully',
            'data' => $review->load(['user', 'product'])
        ]);
    }

    /**
     * Reject/unapprove a review
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $review = $this->reviewInScope($request, $id);

        if (! $review) {
            return $this->notFoundForCaller('Review');
        }

        $review->update([
            'is_approved' => false
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review rejected successfully',
            'data' => $review->load(['user', 'product'])
        ]);
    }

    /**
     * Delete a review
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $review = $this->reviewInScope($request, $id);

        if (! $review) {
            return $this->notFoundForCaller('Review');
        }

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted successfully'
        ]);
    }

    /**
     * Get review details
     */
    public function show(Request $request, $id): JsonResponse
    {
        $review = $this->reviewInScope($request, $id);

        if (! $review) {
            return $this->notFoundForCaller('Review');
        }

        $review->load(['user', 'product']);

        return response()->json([
            'success' => true,
            'data' => $review
        ]);
    }

    /**
     * Bulk approve reviews
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'review_ids' => 'required|array',
            'review_ids.*' => 'exists:reviews,id'
        ]);

        // Only the ids in the caller's own scope. A bulk call used to act on
        // whatever ids were posted, which made it the easiest way past the
        // per-review checks.
        $reviews = $this->scopeReviewsToStore(Review::query(), $request)
            ->whereIn('id', $request->review_ids)
            ->get();

        foreach ($reviews as $review) {
            $review->update(['is_approved' => true]);
            $review->product->updateRating();
        }

        return response()->json([
            'success' => true,
            'message' => count($reviews) . " reviews approved successfully"
        ]);
    }

    /**
     * Bulk reject reviews
     */
    public function bulkReject(Request $request): JsonResponse
    {
        $request->validate([
            'review_ids' => 'required|array',
            'review_ids.*' => 'exists:reviews,id'
        ]);

        $updated = $this->scopeReviewsToStore(Review::query(), $request)
                        ->whereIn('id', $request->review_ids)
                        ->update(['is_approved' => false]);

        return response()->json([
            'success' => true,
            'message' => "{$updated} reviews rejected successfully"
        ]);
    }

    /**
     * Bulk delete reviews
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'review_ids' => 'required|array',
            'review_ids.*' => 'exists:reviews,id'
        ]);

        $deleted = $this->scopeReviewsToStore(Review::query(), $request)
            ->whereIn('id', $request->review_ids)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} reviews deleted successfully"
        ]);
    }
}
