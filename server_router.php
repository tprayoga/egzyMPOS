<?php
/**
 * Router for PHP built-in server (php -S).
 * Serves existing static files directly; routes everything else through CodeIgniter.
 */
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath(__DIR__ . $path);
    if ($file !== false
        && strpos($file, __DIR__) === 0
        && is_file($file)
        && basename($file) !== 'index.php'
    ) {
        return false; // let the built-in server serve the static asset
    }
    // Paths with a dot (e.g. /v1.0/...) make php -S set SCRIPT_NAME to the request
    // path, which breaks CodeIgniter's URI detection. Pin it to the front controller.
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
}
require __DIR__ . '/index.php';
