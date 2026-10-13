<?php

declare(strict_types=1);

/*
 * Router fuer den PHP-Entwicklungsserver (ersetzt .htaccess lokal):
 *   php -S localhost:8080 -t public bin/dev-router.php        (im Ordner backend/)
 * <base>/api/v1/* -> public/api/index.php, /api/docs -> Redoc, sonst statische Dateien.
 * Unterordner-Test: ENV QUITT_DEV_BASE=/kasse (dann z. B. /kasse/api/v1/health).
 */

$base = rtrim((string) getenv('QUITT_DEV_BASE'), '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$public = dirname(__DIR__) . '/public';

if ($base !== '' && str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base));
}

if ($path === '/api/docs') {
    header('Location: ' . $base . '/api/docs/');
    http_response_code(301);
    return true;
}
if ($path === '/api/docs/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($public . '/api/docs/index.html');
    return true;
}
if (str_starts_with($path, '/api/v1/') || $path === '/api/v1') {
    $_SERVER['SCRIPT_NAME'] = $base . '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $public . '/api/index.php';
    require $public . '/api/index.php';
    return true;
}
if ($base !== '' && is_file($public . $path)) {
    $types = ['js' => 'application/javascript', 'json' => 'application/json', 'html' => 'text/html; charset=utf-8', 'css' => 'text/css'];
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($public . $path);
    return true;
}
return false;
