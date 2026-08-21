<?php

/**
 * Laravel Application Entry Point (Root Redirect)
 * 
 * This file serves as a proxy to the public/index.php file.
 * It should only be used when the web server's document root
 * cannot be configured to point directly to the public folder.
 */

// Define the path to the public directory
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Check if the request is for a static file in the public directory
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

// Forward the request to the public/index.php file
require_once __DIR__.'/public/index.php';
