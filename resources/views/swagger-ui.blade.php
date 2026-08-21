<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hair Ecommerce API - Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui.css" />
    <style>
        html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin:0; background: #fafafa; }
        .swagger-ui .topbar { display: none; }
        .custom-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
        .auth-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="custom-header">
        <h1>🎯 Hair Ecommerce API</h1>
        <p>Interactive API Documentation & Testing</p>
    </div>
    
    <div class="auth-info">
        <strong>🔐 Authentication:</strong> 
        Click "Authorize" button and enter: <code>Bearer YOUR_TOKEN</code><br>
        <strong>Admin Login:</strong> admin@hairlux.com / password123 | 
        <strong>Customer Login:</strong> customer1@example.com / password123
    </div>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: '{{ url("/api/swagger.json") }}',
                dom_id: '#swagger-ui',
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
                tryItOutEnabled: true,
                requestInterceptor: function(request) {
                    // Add any custom headers here
                    return request;
                }
            });
        };
    </script>
</body>
</html>
