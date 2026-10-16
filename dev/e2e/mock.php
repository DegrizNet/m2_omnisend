<?php
/**
 * Fake Omnisend API for tests: php -S 127.0.0.1:18765 mock.php
 * Every request is appended to requests.jsonl next to this file, with the status it was answered.
 * Special: a body containing "mock-fail-500" gets a 500, "mock-fail-400" a 400.
 */
$dir = __DIR__;
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$entry = [
    'time' => gmdate('c'),
    'method' => $method,
    'path' => $path,
    'query' => $_GET,
    'auth' => $headers['Authorization'] ?? ($headers['X-API-KEY'] ?? null),
    'version' => $headers['Omnisend-Version'] ?? null,
    'integration' => $headers['X-INTEGRATION-NAME'] ?? null,
    'body' => json_decode($raw, true),
];
register_shutdown_function(function () use ($dir, $entry): void {
    $entry['status'] = http_response_code();
    file_put_contents($dir . '/requests.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
});

header('Content-Type: application/json');
if (str_contains($raw, 'mock-fail-500')) {
    http_response_code(500);
    echo '{"title":"boom"}';
    return;
}
if (str_contains($raw, 'mock-fail-400')) {
    http_response_code(400);
    echo '{"title":"Validation failed","errors":[{"field":"x","message":"bad"}]}';
    return;
}
$prefix = '#^/(api|v5)/#';
$route = preg_replace($prefix, '', $path);
$categories = is_file($dir . '/categories.json') ? json_decode(file_get_contents($dir . '/categories.json'), true) : [];

switch (true) {
    case $method === 'GET' && $route === 'brands/current':
        echo '{"brandID":"mock-brand-123","name":"Mock brand","website":"https://shop.example.com","currency":"EUR"}';
        break;
    case $method === 'POST' && $route === 'contacts':
        http_response_code(200);
        echo '{"id":"mock-contact-1","contactID":"mock-contact-1"}';
        break;
    case $method === 'POST' && $route === 'events':
        http_response_code(202);
        break;
    case $method === 'PUT' && str_starts_with($route, 'products/'):
        // Like Omnisend: PUT replaces, an unknown product is 404.
        $products = is_file($dir . '/products.json') ? json_decode(file_get_contents($dir . '/products.json'), true) : [];
        if (!isset($products[substr($route, 9)])) {
            http_response_code(404);
            echo '{"title":"Not found","detail":"product not found"}';
            break;
        }
        http_response_code(200);
        echo '{"id":"' . substr($route, 9) . '"}';
        break;
    case $method === 'POST' && $route === 'products':
        $products = is_file($dir . '/products.json') ? json_decode(file_get_contents($dir . '/products.json'), true) : [];
        $id = (string)(json_decode($raw, true)['id'] ?? '');
        if (isset($products[$id])) {
            http_response_code(409);
            echo '{"title":"Conflict","detail":"product already exists"}';
            break;
        }
        $products[$id] = true;
        file_put_contents($dir . '/products.json', json_encode($products));
        http_response_code(201);
        echo '{"id":"' . $id . '"}';
        break;
    case $method === 'DELETE' && str_starts_with($route, 'products/'):
        $products = is_file($dir . '/products.json') ? json_decode(file_get_contents($dir . '/products.json'), true) : [];
        if (!isset($products[substr($route, 9)])) {
            http_response_code(404);
            echo '{"title":"not found"}';
            break;
        }
        unset($products[substr($route, 9)]);
        file_put_contents($dir . '/products.json', json_encode($products));
        http_response_code(204);
        break;
    case $method === 'POST' && $route === 'product-categories':
        $body = json_decode($raw, true);
        $id = (string)($body['categoryID'] ?? '');
        if (isset($categories[$id])) {
            http_response_code(409);
            echo '{"title":"already exists"}';
            break;
        }
        $categories[$id] = $body['title'] ?? '';
        file_put_contents($dir . '/categories.json', json_encode($categories));
        http_response_code(201);
        echo '{"categoryID":"' . $id . '"}';
        break;
    case $method === 'PATCH' && str_starts_with($route, 'product-categories/'):
        http_response_code(201);
        echo '{}';
        break;
    case $method === 'DELETE' && str_starts_with($route, 'product-categories/'):
        http_response_code(204);
        break;
    default:
        http_response_code(404);
        echo '{"title":"no such mock route"}';
}
