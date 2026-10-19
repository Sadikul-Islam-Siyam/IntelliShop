<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 * Emulates Apache mod_rewrite for the built-in PHP web server.
 * Ensures reliable execution both locally and inside Docker containers.
 */

$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
