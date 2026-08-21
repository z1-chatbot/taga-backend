<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Taga API',
        'version' => '1.0.0',
        'documentation' => app()->environment('production')
            ? 'Disabled in production'
            : [
                'swagger_ui' => url('/swagger'),
                'api_docs' => url('/api-docs'),
                'health_check' => url('/api/health'),
            ],
    ]);
});

/*
 * Deployment endpoint for hosting without a terminal.
 *
 * Replaces the 28 loose PHP scripts that used to sit in public/, each guarded
 * by a plaintext secret committed alongside it. Disabled unless a
 * MAINTENANCE_TOKEN of at least 32 characters is set in .env; see
 * App\Http\Controllers\MaintenanceController.
 *
 * Throttled to 6 requests a minute per IP so the token cannot be brute forced.
 */
Route::match(['get', 'post'], '/__ops/{command}', \App\Http\Controllers\MaintenanceController::class)
    ->middleware('throttle:6,1')
    ->where('command', '[a-z-]+');

/*
 * Developer documentation and scratch routes.
 *
 * Gated to non-production. These enumerate every registered endpoint and the
 * /api-docs payload also advertised sample sign-in credentials in plaintext —
 * fine on a laptop, not something to serve publicly from the live API host.
 */
if (! app()->environment('production')) {

Route::get('/api-docs', function () {
    // Get all API routes dynamically
    $routes = Route::getRoutes();
    $apiRoutes = [];
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'api/v1') === 0) {
            $methods = array_filter($route->methods(), function($method) {
                return in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']);
            });
            
            foreach ($methods as $method) {
                $category = 'General';
                if (strpos($uri, 'admin') !== false) $category = 'Admin';
                elseif (strpos($uri, 'auth') !== false || strpos($uri, 'login') !== false) $category = 'Authentication';
                elseif (strpos($uri, 'product') !== false) $category = 'Products';
                elseif (strpos($uri, 'cart') !== false) $category = 'Cart';
                elseif (strpos($uri, 'order') !== false) $category = 'Orders';
                
                $apiRoutes[$category][] = [
                    'method' => $method,
                    'uri' => '/' . $uri,
                    'description' => ucfirst(strtolower($method)) . ' ' . str_replace('api/v1/', '', $uri)
                ];
            }
        }
    }
    
    return response()->json([
        'title' => 'Taga API Documentation',
        'version' => '1.0.0',
        'base_url' => url('/api/v1'),
        'swagger_ui' => url('/swagger'),
        'total_endpoints' => array_sum(array_map('count', $apiRoutes)),
        'endpoints_by_category' => $apiRoutes,
        'quick_links' => [
            'interactive_swagger' => url('/swagger'),
            'auto_generated_spec' => url('/api/swagger-auto.json'),
            'health_check' => url('/api/health')
        ]
    ]);
});

Route::get('/swagger', function () {
    $html = '<!DOCTYPE html>
<html>
<head>
    <title>Taga API — Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui.css" />
    <style>
        .swagger-ui .topbar { display: none; }
        .custom-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
        .auth-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="custom-header">
        <h1>Taga API</h1>
        <p>Interactive API Documentation & Testing</p>
    </div>
    
    <div class="auth-info">
        <strong>🔐 Authentication:</strong> 
        Click "Authorize" button and enter: <code>Bearer YOUR_TOKEN</code><br>
        Sign in via <code>POST /api/v1/login</code> to obtain a token.
    </div>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "' . url('/api/swagger-auto.json') . '",
                dom_id: "#swagger-ui",
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                validatorUrl: null,
                docExpansion: "list",
                operationsSorter: "alpha",
                tagsSorter: "alpha",
                filter: true,
                tryItOutEnabled: true
            });
        };
    </script>
</body>
</html>';
    
    return response($html)->header('Content-Type', 'text/html');
});

Route::get('/api/swagger.json', function () {
    $swaggerPath = public_path('api/swagger.json');
    
    if (!file_exists($swaggerPath)) {
        return response()->json(['error' => 'Swagger specification not found', 'path' => $swaggerPath], 404);
    }
    
    $swagger = file_get_contents($swaggerPath);
    return response($swagger)->header('Content-Type', 'application/json');
});

// Dynamic swagger generation from routes
Route::get('/api/swagger-auto.json', function () {
    $controller = new \App\Http\Controllers\Api\SwaggerController();
    return $controller->generateSwagger();
});

// Test route to check if basic routing works
Route::get('/test', function () {
    return response()->json([
        'message' => 'Test route works!',
        'timestamp' => now(),
        'swagger_file_exists' => file_exists(public_path('api/swagger.json')),
        'available_endpoints' => [
            'swagger_ui' => url('/swagger'),
            'swagger_json' => url('/api/swagger.json'),
            'swagger_auto' => url('/api/swagger-auto.json'),
            'api_docs' => url('/api-docs'),
            'health' => url('/api/health')
        ]
    ]);
});

} // end non-production documentation routes
