<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/s3.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/post.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/app/http.php';
require_once __DIR__ . '/app/uploads.php';
require_once __DIR__ . '/app/routes.php';
require_once __DIR__ . '/pages/home.php';
require_once __DIR__ . '/pages/posts.php';
require_once __DIR__ . '/pages/tags.php';
require_once __DIR__ . '/pages/auth.php';
require_once __DIR__ . '/pages/content.php';
require_once __DIR__ . '/pages/users.php';
require_once __DIR__ . '/pages/scraper.php';
require_once __DIR__ . '/pages/admin.php';
require_once __DIR__ . '/pages/settings.php';

Auth::start();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = SITE_BASE;

if (str_starts_with($uri, $base . '/thumb/')) {
    Storage::serveFile('thumb', rawurldecode(substr($uri, strlen($base . '/thumb/'))));
    exit;
}
if (str_starts_with($uri, $base . '/file/')) {
    Storage::serveFile('upload', rawurldecode(substr($uri, strlen($base . '/file/'))));
    exit;
}
if (str_starts_with($uri, $base . '/static/')) {
    Router::serveFile(LIBOORU_ROOT . '/static', rawurldecode(substr($uri, strlen($base . '/static/'))));
    exit;
}
if (str_starts_with($uri, $base . '/site-assets/')) {
    Router::serveFile(SITE_ASSET_DIR, rawurldecode(substr($uri, strlen($base . '/site-assets/'))));
    exit;
}
if (str_starts_with($uri, $base . '/api/')) {
    (new Api())->handle();
    exit;
}

$path = substr($uri, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

try {
    dispatch($method, $path);
} catch (Throwable $e) {
    http_response_code(500);
    View::header('Error');
    echo '<h1>Error</h1><p>' . View::e($e->getMessage()) . '</p>';
    View::footer();
}
