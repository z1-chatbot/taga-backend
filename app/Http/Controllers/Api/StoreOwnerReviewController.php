<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StoreOwnerReviewController extends Controller
{
    /**
     * Get all reviews for store owner's products
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Get store owner's store(s)
        $stores = Store::where('owner_id', $user->id)->pluck('id');
        
        if ($stores->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'stats' => [
                    'total' => 0,
                    'approved' => 0,
                    'pending' => 0,
                    'average_rating' => 0
                ]
            ]);
        }
        
        // Build query for reviews on store owner's products
        $query = Review::with(['user', 'product'])
            ->whereHas('product', function($q) use ($stores) {
                $q->whereIn('store_id', $stores);
            });
        
        // Apply filters
        if ($request->has('status')) {
            if ($request->status === 'approved') {
                $query->where('is_approved', true);
            } elseif ($request->status === 'pending') {
                $query->where('is_approved', false);
            }
        }
        
        if ($request->has('rating') && $request->rating !== 'all') {
            $query->where('rating', $request->rating);
        }
        
        if ($request->has('search') && $request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('comment', 'like', '%' . $request->search . '%')
                  ->orWhereHas('product', function($pq) use ($request) {
                      $pq->where('name', 'like', '%' . $request->search . '%');
                  });
            });
        }
        
        // Apply sorting
        switch ($request->get('sort', 'newest')) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'highest_rated':
                $query->orderBy('rating', 'desc');
                break;
            case 'lowest_rated':
                $query->orderBy('rating', 'asc');
                break;
            case 'most_helpful':
                $query->orderBy('helpful_count', 'desc');
                break;
            default: // newest
                $query->orderBy('created_at', 'desc');
        }
        
        $reviews = $query->paginate(20);
        
        // Calculate stats
        $allReviews = Review::whereHas('product', function($q) use ($stores) {
            $q->whereIn('store_id', $stores);
        });
        
        $stats = [
            'total' => $allReviews->count(),
            'approved' => $allReviews->where('is_approved', true)->count(),
            'pending' => $allReviews->where('is_approved', false)->count(),
            'average_rating' => round($allReviews->avg('rating') ?? 0, 1),
            'by_rating' => [
                5 => $allReviews->where('rating', 5)->count(),
                4 => $allReviews->where('rating', 4)->count(),
                3 => $allReviews->where('rating', 3)->count(),
                2 => $allReviews->where('rating', 2)->count(),
                1 => $allReviews->where('rating', 1)->count(),
            ]
        ];
        
        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total()
            ],
            'stats' => $stats
        ]);
    }
    
    /**
     * Get single review details
     */
    public function show($id): JsonResponse
    {
        $user = Auth::user();
        $stores = Store::where('owner_id', $user->id)->pluck('id');
        
        $review = Review::with(['user', 'product'])
            ->whereHas('product', function($q) use ($stores) {
                $q->whereIn('store_id', $stores);
            })
            ->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $review
        ]);
    }
    
    /**
     * Get review statistics for store owner
     */
    public function stats(): JsonResponse
    {
        $user = Auth::user();
        $stores = Store::where('owner_id', $user->id)->pluck('id');
        
        $allReviews = Review::whereHas('product', function($q) use ($stores) {
            $q->whereIn('store_id', $stores);
        });
        
        $stats = [
            'total' => $allReviews->count(),
            'approved' => $allReviews->where('is_approved', true)->count(),
            'pending' => $allReviews->where('is_approved', false)->count(),
            'average_rating' => round($allReviews->avg('rating') ?? 0, 1),
            'by_rating' => [
                5 => $allReviews->where('rating', 5)->count(),
                4 => $allReviews->where('rating', 4)->count(),
                3 => $allReviews->where('rating', 3)->count(),
                2 => $allReviews->where('rating', 2)->count(),
                1 => $allReviews->where('rating', 1)->count(),
            ],
            'recent_reviews' => $allReviews->orderBy('created_at', 'desc')
                ->with(['user', 'product'])
                ->limit(5)
                ->get()
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
