<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

class ExportController extends Controller
{
    /**
     * Export orders to CSV
     */
    public function exportOrders(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'format' => 'in:csv,json',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'status' => 'nullable|string',
                'payment_status' => 'nullable|string',
            ]);

            $query = Order::with(['items.product', 'coupon']);

            // Apply filters
            if ($request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            if ($request->status) {
                $query->where('status', $request->status);
            }
            
            if ($request->payment_status) {
                $query->where('payment_status', $request->payment_status);
            }

            $orders = $query->orderBy('created_at', 'desc')->get();

            $format = $request->get('format', 'csv');
            
            if ($format === 'csv') {
                return $this->generateOrdersCsv($orders);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $orders,
                    'total' => $orders->count(),
                    'exported_at' => now()->toISOString()
                ]);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export users to CSV
     */
    public function exportUsers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'format' => 'in:csv,json',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'role' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            $query = User::with(['orders']);

            // Apply filters
            if ($request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            if ($request->role) {
                $query->where('role', $request->role);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $users = $query->orderBy('created_at', 'desc')->get();

            $format = $request->get('format', 'csv');
            
            if ($format === 'csv') {
                return $this->generateUsersCsv($users);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $users,
                    'total' => $users->count(),
                    'exported_at' => now()->toISOString()
                ]);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export products to CSV
     */
    public function exportProducts(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'format' => 'in:csv,json',
                'category' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'stock_status' => 'nullable|in:in_stock,out_of_stock,low_stock',
            ]);

            $query = Product::query()->with('category');

            // Apply filters — accepts a category id or slug.
            if ($request->category) {
                $query->byCategory($request->category);
            }
            
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            if ($request->has('is_featured')) {
                $query->where('is_featured', $request->boolean('is_featured'));
            }
            
            if ($request->stock_status) {
                switch ($request->stock_status) {
                    case 'out_of_stock':
                        $query->where('stock_quantity', 0);
                        break;
                    case 'low_stock':
                        $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
                        break;
                    case 'in_stock':
                        $query->where('stock_quantity', '>', 10);
                        break;
                }
            }

            $products = $query->orderBy('created_at', 'desc')->get();

            $format = $request->get('format', 'csv');
            
            if ($format === 'csv') {
                return $this->generateProductsCsv($products);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $products,
                    'total' => $products->count(),
                    'exported_at' => now()->toISOString()
                ]);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate CSV for orders
     */
    private function generateOrdersCsv($orders)
    {
        $filename = 'orders_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $csvData = [];
        
        // CSV Headers
        $csvData[] = [
            'Order Number',
            'Customer Name',
            'Customer Email',
            'Status',
            'Payment Status',
            'Payment Method',
            'Subtotal',
            'Tax Amount',
            'Shipping Amount',
            'Discount Amount',
            'Total Amount',
            'Currency',
            'Items Count',
            'Products',
            'Quantities',
            'Shipping Address',
            'Coupon Code',
            'Order Date',
            'Updated Date',
            'Notes'
        ];

        // CSV Data
        foreach ($orders as $order) {
            $shippingAddr = $order->shipping_address;
            $customerName = '';
            $customerEmail = '';
            
            if (is_array($shippingAddr)) {
                $customerName = ($shippingAddr['firstName'] ?? '') . ' ' . ($shippingAddr['lastName'] ?? '');
                $customerName = trim($customerName) ?: ($shippingAddr['name'] ?? 'N/A');
                $customerEmail = $shippingAddr['email'] ?? 'N/A';
            }

            $products = $order->items->pluck('product.name')->join(', ');
            $quantities = $order->items->pluck('quantity')->join(', ');
            
            $fullAddress = '';
            if (is_array($shippingAddr)) {
                $addressParts = [
                    $shippingAddr['address'] ?? $shippingAddr['address_line_1'] ?? '',
                    $shippingAddr['address_line_2'] ?? '',
                    $shippingAddr['city'] ?? '',
                    $shippingAddr['state'] ?? '',
                    $shippingAddr['postalCode'] ?? $shippingAddr['postal_code'] ?? '',
                    $shippingAddr['country'] ?? ''
                ];
                $fullAddress = implode(', ', array_filter($addressParts));
            }

            $csvData[] = [
                $order->order_number,
                $customerName,
                $customerEmail,
                $order->status,
                $order->payment_status,
                $order->payment_method ?? 'N/A',
                $order->subtotal,
                $order->tax_amount,
                $order->shipping_amount,
                $order->discount_amount,
                $order->total_amount,
                $order->currency,
                $order->items->count(),
                $products,
                $quantities,
                $fullAddress,
                $order->coupon_code ?? 'N/A',
                $order->created_at->format('Y-m-d H:i:s'),
                $order->updated_at->format('Y-m-d H:i:s'),
                $order->notes ?? 'N/A'
            ];
        }

        $csvContent = '';
        foreach ($csvData as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'content' => base64_encode($csvContent),
            'total_records' => count($orders),
            'exported_at' => now()->toISOString()
        ]);
    }

    /**
     * Generate CSV for users
     */
    private function generateUsersCsv($users)
    {
        $filename = 'users_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        $csvData = [];
        
        // CSV Headers
        $csvData[] = [
            'ID',
            'Name',
            'Email',
            'Phone',
            'Role',
            'Is Active',
            'Email Verified',
            'Total Orders',
            'Total Spent',
            'Registration Date',
            'Last Updated'
        ];

        // CSV Data
        foreach ($users as $user) {
            $totalOrders = $user->orders->count();
            $totalSpent = $user->orders->sum('total_amount');

            $csvData[] = [
                $user->id,
                $user->name,
                $user->email,
                $user->phone ?? 'N/A',
                $user->role,
                $user->is_active ? 'Yes' : 'No',
                $user->email_verified_at ? 'Yes' : 'No',
                $totalOrders,
                $totalSpent,
                $user->created_at->format('Y-m-d H:i:s'),
                $user->updated_at->format('Y-m-d H:i:s')
            ];
        }

        $csvContent = '';
        foreach ($csvData as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'content' => base64_encode($csvContent),
            'total_records' => count($users),
            'exported_at' => now()->toISOString()
        ]);
    }

    /**
     * Generate CSV for products
     */
    private function generateProductsCsv($products)
    {
        $filename = 'products_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        $csvData = [];
        
        // CSV Headers
        $csvData[] = [
            'ID',
            'Name',
            'SKU',
            'Category',
            'Price',
            'Sale Price',
            'Stock Quantity',
            'Generic Name',
            'Brand Name',
            'Manufacturer',
            'Strength',
            'Dosage Form',
            'Pack Size',
            'Route of Administration',
            'Requires Prescription',
            'Controlled Substance',
            'Drug Schedule',
            'NAFDAC Number',
            'Batch Number',
            'Expiry Date',
            'Storage Conditions',
            'Is Featured',
            'Is Active',
            'Weight (kg)',
            'Images Count',
            'Created Date',
            'Updated Date'
        ];

        // CSV Data
        foreach ($products as $product) {
            $csvData[] = [
                $product->id,
                $product->name,
                $product->sku,
                $product->category?->name ?? 'N/A',
                $product->price,
                $product->sale_price ?? 'N/A',
                $product->stock_quantity,
                $product->generic_name ?? 'N/A',
                $product->brand_name ?? 'N/A',
                $product->manufacturer ?? 'N/A',
                $product->strength ?? 'N/A',
                $product->dosage_form ?? 'N/A',
                $product->pack_size ?? 'N/A',
                $product->route_of_administration ?? 'N/A',
                $product->requires_prescription ? 'Yes' : 'No',
                $product->is_controlled_substance ? 'Yes' : 'No',
                $product->drug_schedule ?? 'N/A',
                $product->nafdac_number ?? 'N/A',
                $product->batch_number ?? 'N/A',
                $product->expiry_date?->format('Y-m-d') ?? 'N/A',
                $product->storage_conditions ?? 'N/A',
                $product->is_featured ? 'Yes' : 'No',
                $product->is_active ? 'Yes' : 'No',
                $product->weight_kg ?? 'N/A',
                is_array($product->images) ? count($product->images) : 0,
                $product->created_at->format('Y-m-d H:i:s'),
                $product->updated_at->format('Y-m-d H:i:s')
            ];
        }

        $csvContent = '';
        foreach ($csvData as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'content' => base64_encode($csvContent),
            'total_records' => count($products),
            'exported_at' => now()->toISOString()
        ]);
    }

    /**
     * Get export statistics
     */
    public function getExportStats(): JsonResponse
    {
        try {
            $stats = [
                'orders' => [
                    'total' => Order::count(),
                    'this_month' => Order::whereMonth('created_at', now()->month)->count(),
                    'pending' => Order::where('status', 'pending')->count(),
                    'completed' => Order::where('status', 'completed')->count(),
                ],
                'users' => [
                    'total' => User::count(),
                    'active' => User::where('is_active', true)->count(),
                    'customers' => User::where('role', 'customer')->count(),
                    'this_month' => User::whereMonth('created_at', now()->month)->count(),
                ],
                'products' => [
                    'total' => Product::count(),
                    'active' => Product::where('is_active', true)->count(),
                    'featured' => Product::where('is_featured', true)->count(),
                    'out_of_stock' => Product::where('stock_quantity', 0)->count(),
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get export stats: ' . $e->getMessage()
            ], 500);
        }
    }
}
