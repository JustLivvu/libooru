<?php



$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);


$docRoot = __DIR__;
$filePath = $docRoot . $path;

if ($path !== '/' && is_file($filePath)) {
    return false;
}


require __DIR__ . '/index.php';
