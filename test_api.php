<?php

// Get token from command line argument
$token = $argv[1] ?? null;

if (!$token) {
    echo "Usage: php test_api.php <token>\n";
    exit(1);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/v1/stores/my-store');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
