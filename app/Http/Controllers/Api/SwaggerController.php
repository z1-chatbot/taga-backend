<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

class SwaggerController extends Controller
{
    /**
     * Generate complete Swagger specification from routes
     */
    public function generateSwagger(): JsonResponse
    {
        $routes = Route::getRoutes();
        $paths = [];
        
        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = $route->methods();
            
            // Only include API routes
            if (strpos($uri, 'api/v1') === 0) {
                $path = '/' . str_replace('api/v1/', '', $uri);
                $path = str_replace('{', '{', $path);
                
                foreach ($methods as $method) {
                    if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
                        $paths[$path][strtolower($method)] = $this->generateEndpointSpec($route, $method);
                    }
                }
            }
        }

        $swagger = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Hair Ecommerce API - Complete',
                'description' => 'Auto-generated complete API documentation with all endpoints',
                'version' => '1.0.0'
            ],
            'servers' => [
                ['url' => url('/api/v1'), 'description' => 'API Server']
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ],
                'schemas' => $this->getSchemas()
            ],
            'paths' => $paths,
            'tags' => [
                ['name' => 'Authentication', 'description' => 'User authentication'],
                ['name' => 'Products', 'description' => 'Product management'],
                ['name' => 'Categories', 'description' => 'Category management'],
                ['name' => 'Cart', 'description' => 'Shopping cart'],
                ['name' => 'Orders', 'description' => 'Order management'],
                ['name' => 'Reviews', 'description' => 'Product reviews'],
                ['name' => 'Wishlist', 'description' => 'User wishlist'],
                ['name' => 'Payments', 'description' => 'Payment processing'],
                ['name' => 'Admin - Dashboard', 'description' => 'Admin dashboard'],
                ['name' => 'Admin - Automation', 'description' => 'Automation controls'],
                ['name' => 'Admin - Coupons', 'description' => 'Coupon management'],
                ['name' => 'Admin - Sales', 'description' => 'Sale event management']
            ]
        ];

        return response()->json($swagger);
    }

    /**
     * Generate endpoint specification
     */
    private function generateEndpointSpec($route, $method)
    {
        $uri = $route->uri();
        $action = $route->getActionName();
        
        // Determine tag based on URI
        $tag = 'General';
        if (strpos($uri, 'admin') !== false) {
            if (strpos($uri, 'automation') !== false) $tag = 'Admin - Automation';
            elseif (strpos($uri, 'coupon') !== false) $tag = 'Admin - Coupons';
            elseif (strpos($uri, 'sale-event') !== false) $tag = 'Admin - Sales';
            elseif (strpos($uri, 'dashboard') !== false) $tag = 'Admin - Dashboard';
            else $tag = 'Admin';
        } elseif (strpos($uri, 'auth') !== false || strpos($uri, 'login') !== false || strpos($uri, 'register') !== false) {
            $tag = 'Authentication';
        } elseif (strpos($uri, 'product') !== false) {
            $tag = 'Products';
        } elseif (strpos($uri, 'categor') !== false) {
            $tag = 'Categories';
        } elseif (strpos($uri, 'cart') !== false) {
            $tag = 'Cart';
        } elseif (strpos($uri, 'order') !== false) {
            $tag = 'Orders';
        } elseif (strpos($uri, 'review') !== false) {
            $tag = 'Reviews';
        } elseif (strpos($uri, 'wishlist') !== false) {
            $tag = 'Wishlist';
        } elseif (strpos($uri, 'payment') !== false) {
            $tag = 'Payments';
        }

        $spec = [
            'tags' => [$tag],
            'summary' => $this->generateSummary($uri, $method),
            'responses' => $this->getResponsesForEndpoint($uri, $method)
        ];

        // Add security for protected routes
        if (strpos($uri, 'admin') !== false || 
            in_array($uri, ['api/v1/orders', 'api/v1/wishlist', 'api/v1/logout'])) {
            $spec['security'] = [['bearerAuth' => []]];
        }

        // Add parameters for routes with placeholders
        if (strpos($uri, '{') !== false) {
            preg_match_all('/\{([^}]+)\}/', $uri, $matches);
            $spec['parameters'] = [];
            foreach ($matches[1] as $param) {
                $spec['parameters'][] = [
                    'name' => $param,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string']
                ];
            }
        }

        return $spec;
    }

    /**
     * Generate summary from URI and method
     */
    private function generateSummary($uri, $method)
    {
        $method = strtoupper($method);
        $path = str_replace('api/v1/', '', $uri);
        
        switch ($method) {
            case 'GET':
                return strpos($path, '{') !== false ? 'Get single ' . $this->getResourceName($path) : 'Get ' . $this->getResourceName($path);
            case 'POST':
                return 'Create ' . $this->getResourceName($path);
            case 'PUT':
            case 'PATCH':
                return 'Update ' . $this->getResourceName($path);
            case 'DELETE':
                return 'Delete ' . $this->getResourceName($path);
            default:
                return ucfirst(strtolower($method)) . ' ' . $path;
        }
    }

    /**
     * Extract resource name from path
     */
    private function getResourceName($path)
    {
        $parts = explode('/', $path);
        $resource = $parts[0];
        
        // Handle special cases
        if ($resource === 'admin') {
            return isset($parts[1]) ? $parts[1] : 'admin resource';
        }
        
        return $resource;
    }

    /**
     * Get response schemas for specific endpoints
     */
    private function getResponsesForEndpoint($uri, $method)
    {
        $method = strtoupper($method);
        $responses = [
            '401' => ['description' => 'Unauthorized'],
            '422' => ['description' => 'Validation Error'],
            '500' => ['description' => 'Server Error']
        ];

        // Login endpoint
        if (strpos($uri, 'login') !== false && $method === 'POST') {
            $responses['200'] = [
                'description' => 'Login successful',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/LoginResponse']
                    ]
                ]
            ];
        }
        // Products endpoint
        elseif (strpos($uri, 'products') !== false && $method === 'GET') {
            $responses['200'] = [
                'description' => 'Products retrieved successfully',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ProductsResponse']
                    ]
                ]
            ];
        }
        // Single product
        elseif (strpos($uri, 'products/{') !== false && $method === 'GET') {
            $responses['200'] = [
                'description' => 'Product retrieved successfully',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ProductResponse']
                    ]
                ]
            ];
            $responses['404'] = ['description' => 'Product not found'];
        }
        // Cart endpoints
        elseif (strpos($uri, 'cart') !== false && $method === 'GET') {
            $responses['200'] = [
                'description' => 'Cart retrieved successfully',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CartResponse']
                    ]
                ]
            ];
        }
        // Orders
        elseif (strpos($uri, 'orders') !== false && $method === 'GET') {
            $responses['200'] = [
                'description' => 'Orders retrieved successfully',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/OrdersResponse']
                    ]
                ]
            ];
        }
        // Admin automation status
        elseif (strpos($uri, 'automation/status') !== false && $method === 'GET') {
            $responses['200'] = [
                'description' => 'Automation status retrieved',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/AutomationStatusResponse']
                    ]
                ]
            ];
        }
        // Default success response
        else {
            $responses['200'] = [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SuccessResponse']
                    ]
                ]
            ];
        }

        return $responses;
    }

    /**
     * Get all schema definitions
     */
    private function getSchemas()
    {
        return [
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Operation successful'],
                    'data' => ['type' => 'object']
                ]
            ],
            'LoginResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'Login successful'],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'user' => ['$ref' => '#/components/schemas/User'],
                            'token' => ['type' => 'string', 'example' => '1|abc123...'],
                            'token_type' => ['type' => 'string', 'example' => 'Bearer']
                        ]
                    ]
                ]
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'John Doe'],
                    'email' => ['type' => 'string', 'example' => 'john@example.com'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'customer'], 'example' => 'customer'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time']
                ]
            ],
            'Product' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Brazilian Straight Lace Front Wig'],
                    'description' => ['type' => 'string'],
                    'price' => ['type' => 'number', 'format' => 'float', 'example' => 299.99],
                    'sale_price' => ['type' => 'number', 'format' => 'float', 'example' => 249.99],
                    'generic_name' => ['type' => 'string', 'example' => 'Paracetamol'],
                    'brand_name' => ['type' => 'string', 'example' => 'Panadol'],
                    'manufacturer' => ['type' => 'string', 'example' => 'GSK'],
                    'strength' => ['type' => 'string', 'example' => '500mg'],
                    'dosage_form' => ['type' => 'string', 'example' => 'Tablet'],
                    'pack_size' => ['type' => 'string', 'example' => '20 tablets'],
                    'requires_prescription' => ['type' => 'boolean', 'example' => false],
                    'is_controlled_substance' => ['type' => 'boolean', 'example' => false],
                    'expiry_date' => ['type' => 'string', 'format' => 'date', 'example' => '2027-12-31'],
                    'stock_quantity' => ['type' => 'integer', 'example' => 25],
                    'is_featured' => ['type' => 'boolean', 'example' => true],
                    'images' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['paracetamol-1.jpg']]
                ]
            ],
            'ProductsResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/Product']
                    ],
                    'meta' => [
                        'type' => 'object',
                        'properties' => [
                            'total' => ['type' => 'integer', 'example' => 50],
                            'per_page' => ['type' => 'integer', 'example' => 20],
                            'current_page' => ['type' => 'integer', 'example' => 1]
                        ]
                    ]
                ]
            ],
            'ProductResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['$ref' => '#/components/schemas/Product']
                ]
            ],
            'CartItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'product_id' => ['type' => 'integer', 'example' => 1],
                    'quantity' => ['type' => 'integer', 'example' => 2],
                    'price' => ['type' => 'number', 'format' => 'float', 'example' => 299.99],
                    'total' => ['type' => 'number', 'format' => 'float', 'example' => 599.98],
                    'product' => ['$ref' => '#/components/schemas/Product']
                ]
            ],
            'CartResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CartItem']],
                            'total' => ['type' => 'number', 'format' => 'float', 'example' => 599.98],
                            'count' => ['type' => 'integer', 'example' => 2]
                        ]
                    ]
                ]
            ],
            'Order' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'order_number' => ['type' => 'string', 'example' => 'ORD-2024-001'],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'], 'example' => 'pending'],
                    'total_amount' => ['type' => 'number', 'format' => 'float', 'example' => 599.98],
                    'payment_status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'failed', 'refunded'], 'example' => 'pending'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time']
                ]
            ],
            'OrdersResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Order']]
                ]
            ],
            'AutomationStatusResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'sales_automation' => ['type' => 'boolean', 'example' => true],
                            'inventory_automation' => ['type' => 'boolean', 'example' => true],
                            'holiday_sales' => [
                                'type' => 'object',
                                'properties' => [
                                    'black_friday' => ['type' => 'boolean', 'example' => true],
                                    'cyber_monday' => ['type' => 'boolean', 'example' => true],
                                    'new_year' => ['type' => 'boolean', 'example' => true]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
}
