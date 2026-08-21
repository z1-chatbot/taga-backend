<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hair Ecommerce API Documentation</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f8fafc; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0; }
        .content { padding: 30px; }
        .endpoint { background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .method { display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .get { background: #28a745; color: white; }
        .post { background: #007bff; color: white; }
        .put { background: #ffc107; color: black; }
        .delete { background: #dc3545; color: white; }
        .section { margin: 30px 0; }
        .section h3 { color: #495057; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }
        .auth-note { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .sample-code { background: #2d3748; color: #e2e8f0; padding: 20px; border-radius: 4px; overflow-x: auto; }
        .quick-test { background: #e8f5e8; border: 1px solid #c3e6c3; padding: 20px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 Hair Ecommerce API Documentation</h1>
            <p>Complete REST API for Hair Ecommerce Backend System</p>
            <p><strong>Base URL:</strong> {{ url('/api/v1') }}</p>
            <p><strong>Version:</strong> 1.0.0</p>
        </div>
        
        <div class="content">
            <div class="quick-test">
                <h3>🚀 Quick Test</h3>
                <p><strong>🔥 Interactive Swagger UI:</strong> <a href="{{ url('/swagger') }}" target="_blank" style="color: #007bff; font-weight: bold;">{{ url('/swagger') }}</a> - Test APIs directly!</p>
                <p><strong>Health Check:</strong> <a href="{{ url('/api/health') }}" target="_blank">{{ url('/api/health') }}</a></p>
                <p><strong>API Documentation JSON:</strong> <a href="{{ url('/api/docs') }}" target="_blank">{{ url('/api/docs') }}</a></p>
                <p><strong>Sample Products:</strong> <a href="{{ url('/api/v1/products') }}" target="_blank">{{ url('/api/v1/products') }}</a></p>
            </div>

            <div class="auth-note">
                <h4>🔐 Authentication</h4>
                <p><strong>Type:</strong> Bearer Token</p>
                <p><strong>Header:</strong> <code>Authorization: Bearer {your_token}</code></p>
                <p><strong>Get Token:</strong> Use the login endpoint with credentials</p>
                <p><strong>Admin Login:</strong> admin@hairlux.com / password123</p>
                <p><strong>Customer Login:</strong> customer1@example.com / password123</p>
            </div>

            <div class="section">
                <h3>🔑 Authentication Endpoints</h3>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/register</strong>
                    <p>Register a new customer account</p>
                </div>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/login</strong>
                    <p>Login and get authentication token</p>
                </div>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/logout</strong>
                    <p>Logout and invalidate token (requires auth)</p>
                </div>
            </div>

            <div class="section">
                <h3>🛍️ Product Endpoints</h3>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/products</strong>
                    <p>Get all products with advanced filtering</p>
                    <small>Filters: hair_type, hair_length, hair_color, hair_texture, category_id, min_price, max_price, featured</small>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/products/{id}</strong>
                    <p>Get single product with details and related products</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/products/featured</strong>
                    <p>Get featured products</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/products/search</strong>
                    <p>Search products by name, description, or attributes</p>
                </div>
            </div>

            <div class="section">
                <h3>📂 Category Endpoints</h3>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/categories</strong>
                    <p>Get all categories with hierarchy</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/categories/{id}/products</strong>
                    <p>Get products in specific category</p>
                </div>
            </div>

            <div class="section">
                <h3>🛒 Shopping Cart (Guest & Authenticated)</h3>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/cart</strong>
                    <p>Get cart items (session-based for guests)</p>
                </div>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/cart</strong>
                    <p>Add item to cart</p>
                </div>
                <div class="endpoint">
                    <span class="method put">PUT</span> <strong>/api/v1/cart/{id}</strong>
                    <p>Update cart item quantity</p>
                </div>
                <div class="endpoint">
                    <span class="method delete">DELETE</span> <strong>/api/v1/cart/{id}</strong>
                    <p>Remove item from cart</p>
                </div>
            </div>

            <div class="section">
                <h3>💳 Payment & Orders (Requires Authentication)</h3>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/payments/initialize</strong>
                    <p>Initialize Paystack payment</p>
                </div>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/payments/verify</strong>
                    <p>Verify payment status</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/orders</strong>
                    <p>Get user's order history</p>
                </div>
                <div class="endpoint">
                    <span class="method post">POST</span> <strong>/api/v1/orders</strong>
                    <p>Create new order</p>
                </div>
            </div>

            <div class="section">
                <h3>👨‍💼 Admin Endpoints (Admin Authentication Required)</h3>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/admin/dashboard/overview</strong>
                    <p>Get dashboard analytics overview</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/admin/automation/settings</strong>
                    <p>Get all automation settings</p>
                </div>
                <div class="endpoint">
                    <span class="method put">PUT</span> <strong>/api/v1/admin/automation/holiday/black_friday</strong>
                    <p>Update Black Friday automation settings</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/admin/coupons</strong>
                    <p>Manage discount coupons</p>
                </div>
                <div class="endpoint">
                    <span class="method get">GET</span> <strong>/api/v1/admin/sale-events</strong>
                    <p>Manage sale events</p>
                </div>
            </div>

            <div class="section">
                <h3>💻 Sample API Calls</h3>
                <div class="sample-code">
<pre>
# Login to get token
curl -X POST {{ url('/api/v1/login') }} \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@hairlux.com",
    "password": "password123"
  }'

# Get products with filters
curl "{{ url('/api/v1/products') }}?hair_type=human&hair_length=22&min_price=100"

# Add to cart
curl -X POST {{ url('/api/v1/cart') }} \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 1,
    "quantity": 2
  }'

# Get automation status (admin only)
curl {{ url('/api/v1/admin/automation/status') }} \
  -H "Authorization: Bearer YOUR_TOKEN"
</pre>
                </div>
            </div>

            <div class="section">
                <h3>🎛️ Automation Control Features</h3>
                <ul>
                    <li><strong>Holiday Sales:</strong> Turn Black Friday, Cyber Monday, etc. ON/OFF</li>
                    <li><strong>Custom Discounts:</strong> Set any percentage (1-100%)</li>
                    <li><strong>Sale Duration:</strong> Control how long sales last (1-30 days)</li>
                    <li><strong>Inventory Management:</strong> Auto-reorder suggestions and low stock alerts</li>
                    <li><strong>Clearance Automation:</strong> Automatic sales for slow-moving items</li>
                    <li><strong>Test Mode:</strong> Preview automation without affecting live data</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
