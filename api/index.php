<?php
// api/index.php — Single entry point for Vercel serverless
// All requests come here, we route to the right file.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Strip /pos prefix if present (Vercel routes strip it before reaching us)
if (strpos($uri, '/pos') === 0) {
    $uri = substr($uri, 4);
}
$uri = rtrim($uri, '/') ?: '/';

// Map URI → file
$root = dirname(__DIR__);

$routes = [
    '/'             => '/index.php',
    '/login'        => '/login.php',
    '/setup'        => '/setup.php',
    '/logout'       => '/logout.php',
];

// Exact route match
if (isset($routes[$uri])) {
    require $root . $routes[$uri];
    exit;
}

// pages/* routes
if (preg_match('#^/pages/([a-z_]+)$#', $uri, $m)) {
    $file = $root . '/pages/' . $m[1] . '.php';
    if (is_file($file)) {
        require $file;
        exit;
    }
}

// Static files — let Vercel filesystem handle them (CSS, JS, images)
// If we got here, it's a 404
http_response_code(404);
echo '404 Not Found';
