<?php
// Router for PHP built-in server: php -S host:port router.php
// Serves static files directly, routes everything else to index.php

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Serve real static files directly
$docRoot = __DIR__;
$filePath = $docRoot . $path;

if ($path !== '/' && is_file($filePath)) {
    return false; // serve as-is
}

// Everything else → index.php
require __DIR__ . '/index.php';
