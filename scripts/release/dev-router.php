<?php

/*
 * Nur Tests/Entwicklung: emuliert fuer den PHP-Entwicklungsserver die .htaccess-Regeln des Installationspakets.
 *   php -S 127.0.0.1:8080 -t <dokumentwurzel> scripts/release/dev-router.php
 * - <base>/app/*, <base>/data/* und versteckte Dateien -> 403
 * - <base>/api/v1/* -> <base>/api/index.php (SCRIPT_NAME wie bei Apache)
 * - alles andere (statische Dateien, setup.php) -> eingebauter Server
 * Mehrere Installationen nebeneinander (Unterordner) werden anhand von api/index.php erkannt.
 */

$docroot = rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/');
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Installationsordner = naechster Ordner (von innen nach aussen) mit api/index.php
$base = '';
$probe = rtrim(dirname($path . 'x'), '/');
while (true) {
    if (is_file($docroot . $probe . '/api/index.php')) {
        $base = $probe;
        break;
    }
    if ($probe === '' || $probe === '/') {
        break;
    }
    $probe = rtrim(dirname($probe), '/');
    $probe = $probe === '.' ? '' : $probe;
}
$rel = substr($path, strlen($base));

if (preg_match('#^/(app|data)(/|$)#', $rel) || preg_match('#(^|/)\.(?!well-known/)#', $rel)) {
    http_response_code(403);
    echo "Forbidden\n";
    return true;
}
if (preg_match('#^/api/v1(/|$)#', $rel)) {
    $_SERVER['SCRIPT_NAME'] = $base . '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $docroot . $base . '/api/index.php';
    require $docroot . $base . '/api/index.php';
    return true;
}
return false;
