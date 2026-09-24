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



class Router
{
    private static function streamHandle($handle, int $length, bool $rateLimited): void
    {
        $remaining = $length;
        $burstRemaining = $rateLimited ? min(VIDEO_RATE_LIMIT_AFTER, $length) : $length;
        $limitedBytes = 0;
        $limitStartedAt = null;

        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            $chunkLength = strlen($chunk);
            echo $chunk;
            $remaining -= $chunkLength;

            if ($rateLimited) {
                $burstBytes = min($burstRemaining, $chunkLength);
                $burstRemaining -= $burstBytes;
                $limitedInChunk = $chunkLength - $burstBytes;
                if ($limitedInChunk > 0) {
                    $limitStartedAt ??= microtime(true);
                    $limitedBytes += $limitedInChunk;
                    $delay = ($limitedBytes / VIDEO_RATE_LIMIT) - (microtime(true) - $limitStartedAt);
                    if ($delay > 0) usleep((int)($delay * 1000000));
                }
            }
        }
    }

    public static function redirect(string $path, array $params = []): never
    {
        $url = SITE_BASE . $path;
        if ($params) $url .= '?' . http_build_query($params);
        header('Location: ' . $url);
        exit;
    }

    public static function serveFile(string $dir, string $name): void
    {

        $name = basename($name);
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'application/octet-stream';
        $size = filesize($path);
        $isVideo = str_starts_with($mime, 'video/');
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000, immutable');



        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            if ($matches[1] === '') {
                $suffixLength = (int)$matches[2];
                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
            } else {
                $start = (int)$matches[1];
                $end = $matches[2] === '' ? $size - 1 : min((int)$matches[2], $size - 1);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }

            $length = $end - $start + 1;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . $length);
            $handle = fopen($path, 'rb');
            fseek($handle, $start);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                self::streamHandle($handle, $length, $isVideo);
            }
            fclose($handle);
            return;
        }

        header('Content-Length: ' . $size);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        $handle = fopen($path, 'rb');
        self::streamHandle($handle, $size, $isVideo);
        fclose($handle);
    }
}

function saveSiteImageUpload(string $field): ?string
{
    $upload = $_FILES[$field] ?? null;
    if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Could not upload the selected image.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Site images must be JPEG, PNG, GIF, or WebP files.');
    }
    if (!is_dir(SITE_ASSET_DIR) && !mkdir(SITE_ASSET_DIR, 0755, true) && !is_dir(SITE_ASSET_DIR)) {
        throw new RuntimeException('Could not create local site-assets directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], SITE_ASSET_DIR . '/' . $filename)) {
        throw new RuntimeException('Could not save the selected image locally.');
    }
    chmod(SITE_ASSET_DIR . '/' . $filename, 0644);
    return SITE_BASE . '/site-assets/' . $filename;
}

function deleteLocalSiteImage(string $url): void
{
    $prefix = SITE_BASE . '/site-assets/';
    if (str_starts_with($url, $prefix)) {
        @unlink(SITE_ASSET_DIR . '/' . basename($url));
    }
}

function saveProfileImageUpload(string $field, int $userId): ?string
{
    $upload = $_FILES[$field] ?? null;
    if ($upload === null || ($upload['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Could not upload the selected profile image.');
    }
    if (filesize($upload['tmp_name']) > 5 * 1024 * 1024) {
        throw new RuntimeException('Each profile image must be no larger than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($upload['tmp_name']);
    if (!isset($extensions[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Profile images must be valid JPEG, PNG, GIF, or WebP files.');
    }
    if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > MAX_MEDIA_PIXELS) {
        throw new RuntimeException('Profile images must contain no more than 20 million pixels.');
    }
    if (!is_dir(SITE_ASSET_DIR) && !mkdir(SITE_ASSET_DIR, 0755, true) && !is_dir(SITE_ASSET_DIR)) {
        throw new RuntimeException('Could not create the profile images directory.');
    }
    $filename = 'profile-' . $userId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], SITE_ASSET_DIR . '/' . $filename)) {
        throw new RuntimeException('Could not save the selected profile image.');
    }
    chmod(SITE_ASSET_DIR . '/' . $filename, 0644);
    return SITE_BASE . '/site-assets/' . $filename;
}


$path   = substr($uri, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];



try {
    dispatch($method, $path);
} catch (Throwable $e) {
    http_response_code(500);
    View::header('Error');
    echo '<h1>Error</h1><p>' . View::e($e->getMessage()) . '</p>';
    View::footer();
}



function dispatch(string $method, string $path): void
{
    $user = Auth::current();


    if ($path === '/robots.txt') {
        page_robots();
    }
    elseif ($path === '/sitemap.xml') {
        page_sitemap_index();
    }
    elseif ($path === '/sitemap-pages.xml') {
        page_sitemap_pages();
    }
    elseif (preg_match('#^/sitemap-posts-(\d+)\.xml$#', $path, $m)) {
        page_sitemap_posts((int)$m[1]);
    }

    elseif ($path === '/' || $path === '') {
        page_home($user);
    }

    elseif ($path === '/posts') {
        page_posts($user);
    }

    elseif ($path === '/comments') {
        page_comments($user);
    }

    elseif (preg_match('#^/post/(\d+)$#', $path, $m)) {
        if ($method === 'POST') {
            post_handle($user, (int)$m[1]);
        } else {
            page_post($user, (int)$m[1]);
        }
    }

    elseif (preg_match('#^/post/(\d+)/edit$#', $path, $m)) {
        page_post_edit($user, (int)$m[1], $method);
    }

    elseif (preg_match('#^/post/(\d+)/delete$#', $path, $m) && $method === 'POST') {
        action_post_delete($user, (int)$m[1]);
    }

    elseif ($path === '/upload') {
        page_upload($user, $method);
    }

    elseif ($path === '/favorites') {
        page_favorites($user);
    }

    elseif ($path === '/favorites/lucky') {
        page_favorites_lucky($user);
    }

    elseif ($path === '/tags') {
        page_tags($user);
    }

    elseif ($path === '/discord') {
        page_discord($user);
    }

    elseif ($path === '/wiki') {
        page_wiki($user);
    }

    elseif ($path === '/wiki/new') {
        page_wiki_edit($user, null, $method);
    }

    elseif (preg_match('#^/wiki/([a-z0-9-]+)/edit$#', $path, $m)) {
        page_wiki_edit($user, $m[1], $method);
    }

    elseif (preg_match('#^/wiki/([a-z0-9-]+)$#', $path, $m)) {
        page_wiki_article($user, $m[1]);
    }

    elseif ($path === '/scraper') {
        page_scraper($user, $method);
    }

    elseif ($path === '/login') {
        page_login($user, $method);
    }

    elseif ($path === '/logout') {
        Auth::logout();
        Router::redirect('/');
    }

    elseif ($path === '/register') {
        page_register($user, $method);
    }

    elseif ($path === '/terms') {
        page_terms($user);
    }

    elseif ($path === '/search-help') {
        page_search_help($user);
    }

    elseif (preg_match('#^/user/([^/]+)/favorites$#', $path, $m)) {
        page_user_favorites($user, rawurldecode($m[1]));
    }

    elseif (preg_match('#^/user/([^/]+)$#', $path, $m)) {
        page_user($user, rawurldecode($m[1]));
    }

    elseif ($path === '/admin') {
        page_admin($user, $method);
    }

    elseif ($path === '/settings') {
        page_settings($user, $method);
    }

    else {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>404 - Not Found</h1>';
        View::footer();
    }
}



function page_home(?array $user): void
{
    $totalPosts = (int)(DB::scalar('SELECT COUNT(*) FROM posts') ?: 0);
    $digits = str_split((string)$totalPosts);
    $counterHtml = '';
    foreach ($digits as $digit) {
        $counterHtml .= '<img src="https://xbooru.com/counter/' . $digit . '.gif" alt="' . $digit . '" class="counter-mascot">';
    }


    $visitors = (int)View::siteSetting('visitor_count', '360459064');
    $visitors++;
    View::setSiteSetting('visitor_count', (string)$visitors);

    View::header(SITE_NAME, $user, null, [
        'canonical' => View::url('/'),
        'image' => false,
        'description' => View::siteSetting('site_description', '')
            ?: 'Browse and discover thousands of tagged images and videos by rating and quality on ' . View::siteSetting('site_name', SITE_NAME) . '.',
    ]);
    View::flash();

    $siteName = View::siteSetting('site_name', SITE_NAME);
    $e = fn($v) => View::e($v);

    echo '<div class="gelbooru-home">';

    $homeHeaderImage = View::siteSetting('home_header_image');
    if ($homeHeaderImage) {
        echo '  <img class="gelbooru-home-header" src="' . $e($homeHeaderImage) . '" alt="' . $e($siteName) . '">';
    } else {
        echo '  <h1 class="gelbooru-title">' . $e($siteName) . '</h1>';
    }


    echo '  <div class="gelbooru-subnav">';
    echo '    <a href="' . View::url('/posts') . '">Browse Posts</a>';
    echo '    <a href="' . View::url('/upload') . '">Upload</a>';
    echo '    <a href="' . View::url('/tags') . '">Tags</a>';
    if ($user) {
        echo '    <a href="' . View::url('/favorites') . '">Favorites</a>';
        echo '    <a style="margin-left: auto;" href="' . View::url('/user/' . rawurlencode($user['name'])) . '">My Account</a>';
        echo '    <a href="' . View::url('/settings') . '">Settings</a>';
        if (Auth::can('access_admin_panel', $user) || Auth::can('manage_post_reports', $user)
            || Auth::can('manage_database_backups', $user) || Auth::can('manage_scraper', $user)) {
            echo '    <a href="' . View::url('/admin') . '">Panel</a>';
        }
        echo '    <a href="' . View::url('/logout') . '">Logout</a>';
    } else {
        echo '    <a style="margin-left: auto;" href="' . View::url('/login') . '">Login</a>';
        echo '    <a href="' . View::url('/register') . '">Register</a>';
    }
    echo '  </div>';


    echo '  <form class="gelbooru-search-form" method="get" action="' . View::url('/posts') . '">';
    echo '    <input type="text" name="q" placeholder="Ex: blue_sky cloud 1girl" autocomplete="off" autofocus class="gelbooru-search-input">';
    echo '    <button type="submit" class="gelbooru-search-button">Search</button>';
    echo '  </form>';


    $postCount = (int)DB::scalar('SELECT COUNT(*) FROM posts');
    echo '  <div class="gelbooru-info-links">';
    echo '    <span>Serving ' . number_format($postCount) . ' posts</span>';
    echo '    - ';
    echo '    <span>Running Libooru closed source software</span>';
    echo '  </div>';


    echo '  <div class="gelbooru-counter-wrapper">';
    echo '    <div class="digit-counter">' . $counterHtml . '</div>';
    echo '  </div>';



    echo '</div>';

    View::footer();
}

function page_posts(?array $user): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q       = trim($_GET['q'] ?? '');
    $rating  = $_GET['rating'] ?? '';
    $quality = in_array($_GET['quality'] ?? '', ['low', 'medium', 'high', 'ultra'], true)
               ? $_GET['quality'] : '';
    $order   = in_array($_GET['order'] ?? '', ['id DESC', 'id ASC', 'score DESC', 'created_at DESC'], true)
               ? $_GET['order'] : 'id DESC';

    $result = Post::search($page, POSTS_PER_PAGE, $q, $rating, $order, $quality);
    Activity::recordPostBrowsing();

    $sidebarTags = DB::rows('SELECT name, count, category FROM tags ORDER BY count DESC LIMIT 50');

    $browseTitle = $q !== '' ? str_replace('_', ' ', $q) . ' posts' : 'Browse Posts';
    $browseDescription = $q !== ''
        ? 'Browse posts tagged ' . str_replace('_', ' ', $q) . ' on ' . View::siteSetting('site_name', SITE_NAME) . '.'
        : 'Browse the newest and top-rated tagged images and videos on ' . View::siteSetting('site_name', SITE_NAME) . '.';
    View::header($browseTitle, $user, $sidebarTags, [
        'description' => $browseDescription,
        'canonical' => View::url('/posts', array_filter(['q' => $q, 'page' => $page > 1 ? $page : null])),
    ]);
    View::flash();

    echo '<section class="post-listing" aria-label="Posts">';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], '/posts', array_filter(['q' => $q, 'rating' => $rating, 'order' => $order, 'quality' => $quality]));
    echo '</section>';
    View::footer();
}

function page_comments(?array $user): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }

    $perPage = 30;
    $total = (int)DB::scalar('SELECT COUNT(*) FROM comments');
    $pages = (int)ceil($total / $perPage);
    $page = min(max(1, (int)($_GET['page'] ?? 1)), max(1, $pages));
    $comments = DB::rows(
        'SELECT c.id, c.post_id, c.guest_name, c.body, c.created_at, u.name AS user_name
         FROM comments c
         LEFT JOIN users u ON u.id = c.user_id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT ? OFFSET ?',
        [$perPage, ($page - 1) * $perPage]
    );

    View::header('Comments', $user, null, [
        'description' => 'Recent comments on posts at ' . View::siteSetting('site_name', SITE_NAME) . '.',
        'canonical' => View::url('/comments', $page > 1 ? ['page' => $page] : []),
    ]);
    if (!$comments) {
        echo '<p>No comments yet.</p>';
    } else {
        echo '<div class="comment-feed">';
        foreach ($comments as $comment) {
            $author = $comment['user_name'] ?? $comment['guest_name'] ?? 'Anonymous';
            $postUrl = View::url('/post/' . (int)$comment['post_id']) . '#comment-' . (int)$comment['id'];
            echo '<article class="comment">';
            echo '<span class="comment-author">' . View::e($author) . '</span> ';
            echo '<span class="comment-date">' . date('Y-m-d H:i', (int)$comment['created_at']) . '</span>';
            echo ' <a class="comment-post-link" href="' . View::e($postUrl) . '">Post #' . (int)$comment['post_id'] . '</a>';
            echo '<p>' . nl2br(View::e($comment['body'])) . '</p>';
            echo '</article>';
        }
        echo '</div>';
    }
    View::paginator($page, $pages, '/comments');
    View::footer();
}

function page_post(?array $user, int $id): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }
    $post = Post::getById($id);
    if (!$post) {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>Post not found</h1>';
        View::footer();
        return;
    }
    if ($user && !empty($user['blacklist'])) {
        $blacklisted = Post::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
        $postTagNames = array_map(fn($t) => strtolower($t['name']), $post['tags']);
        if (array_intersect($blacklisted, $postTagNames)) {
            http_response_code(404);
            View::header('Not Found', $user);
            echo '<h1>Post not found</h1>';
            View::footer();
            return;
        }
    }
    Activity::recordPostBrowsing();
    $comments = Post::commentsFor($id);
    $tags     = $post['tags'];



    $fileUrl = Image::fileUrl($post['filename']);
    $thumbUrl = Image::thumbUrl($post['filename']);
    $tagNames = array_column($tags, 'name');
    $readableTags = array_map(fn($tag) => str_replace('_', ' ', $tag), array_slice($tagNames, 0, 8));
    $postTitle = trim((string)($post['title'] ?? ''));
    $postHeading = $postTitle !== '' ? $postTitle : 'Post #' . $id;
    if ($postTitle === '') {
        $postTitle = $readableTags
            ? implode(', ', array_slice($readableTags, 0, 4)) . ' - Post #' . $id
            : 'Post #' . $id;
    }
    $description = 'View post #' . $id;
    if ($readableTags) $description .= ' tagged ' . implode(', ', $readableTags);
    $description .= ' on ' . View::siteSetting('site_name', SITE_NAME) . '.';
    $posterName = $post['user_id']
        ? (DB::scalar('SELECT name FROM users WHERE id = ?', [$post['user_id']]) ?: 'unknown')
        : 'Anonymous';
    $ratingLabel = match($post['rating']) {
        's' => 'Safe',
        'q' => 'Questionable',
        'e' => 'Explicit',
        default => (string)$post['rating'],
    };

    View::header($postTitle, $user, $tags, [
        'description' => $description,
        'canonical' => View::url('/post/' . $id),
        'type' => 'article',
        'image' => $thumbUrl,
        'image_alt' => $readableTags ? implode(', ', $readableTags) : 'Post #' . $id,
        'sidebar' => [
            'group_tags' => true,
            'source' => (string)($post['source'] ?? ''),
            'details' => [
                'Rating' => $ratingLabel,
                'Size' => $post['width'] . '×' . $post['height'] . ' — ' . round($post['filesize'] / 1024, 1) . ' KB',
                'MD5' => (string)$post['md5'],
                'Uploaded by' => (string)$posterName,
                'Date' => date('Y-m-d H:i', (int)$post['created_at']),
            ],
        ],
        'json_ld' => [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'name' => $postTitle,
            'description' => $description,
            'contentUrl' => View::absoluteUrl($fileUrl),
            'thumbnailUrl' => View::absoluteUrl($thumbUrl),
            'width' => (int)$post['width'],
            'height' => (int)$post['height'],
            'uploadDate' => date(DATE_ATOM, (int)$post['created_at']),
        ],
    ]);
    View::flash();

    $isOwner  = $user && (int)$user['id'] === (int)$post['user_id'];
    $canModeratePosts = $user && Auth::can('moderate_posts', $user);
    $canModerateComments = $user && Auth::can('moderate_comments', $user);
    $hasPendingReport = $user && Post::hasPendingReport($id, (int)$user['id']);

    echo '<article class="post-view">';
    echo '<h1>' . View::e($postHeading) . '</h1>';

    echo '<div class="post-image">';
    $ext = pathinfo($post['filename'], PATHINFO_EXTENSION);
    if (in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true)) {
        echo '<video src="' . View::e($fileUrl) . '" controls loop playsinline preload="metadata"></video>';
    } else {
        echo '<a href="' . View::e($fileUrl) . '">';
        echo '<img src="' . View::e($fileUrl) . '" alt="' . View::e($readableTags ? implode(', ', $readableTags) : 'Post #' . $id) . '">';
        echo '</a>';
    }
    echo '</div>';


    echo '<div class="post-actions">';
    $upChevron = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>';
    $downChevron = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    if ($user) {
        echo '<form class="post-score-control" method="post" action="' . View::url('/post/' . $id) . '">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="vote">';
        echo '<button type="submit" name="value" value="1" aria-label="Upvote">' . $upChevron . '</button>';
        echo '<span class="post-score-value" aria-label="Score ' . View::e($post['score']) . '">' . View::e($post['score']) . '</span>';
        echo '<button type="submit" name="value" value="-1" aria-label="Downvote">' . $downChevron . '</button>';
        echo '</form>';
    } else {
        echo '<div class="post-score-control" aria-label="Score ' . View::e($post['score']) . '">';
        echo '<span class="post-score-button is-disabled">' . $upChevron . '</span>';
        echo '<span class="post-score-value">' . View::e($post['score']) . '</span>';
        echo '<span class="post-score-button is-disabled">' . $downChevron . '</span>';
        echo '</div>';
    }
    if ($user) {
        $isFav = Post::isFavorite($id, (int)$user['id']);
        echo '<form method="post" action="' . View::url('/post/' . $id) . '" style="display:inline">';
        View::csrfField();
        if ($isFav) {
            echo '<input type="hidden" name="action" value="unfavorite">';
            echo '<button type="submit">★ Remove from Favorites</button>';
        } else {
            echo '<input type="hidden" name="action" value="favorite">';
            echo '<button type="submit">☆ Add to Favorites</button>';
        }
        echo '</form> ';
        if ($hasPendingReport) {
            echo '<span>Report pending review.</span> ';
        } else {
            $reportDialogId = 'report-post-dialog-' . $id;
            echo '<button type="button" class="report-dialog-open" data-report-dialog="' . View::e($reportDialogId) . '">Report post</button>';
            echo '<dialog class="report-dialog" id="' . View::e($reportDialogId) . '">';
            echo '<div class="report-dialog-titlebar">';
            echo '<strong>Report post #' . View::e($id) . '</strong>';
            echo '<button type="button" class="report-dialog-close" aria-label="Close report window">×</button>';
            echo '</div>';
            echo '<form class="report-dialog-form" method="post" action="' . View::url('/post/' . $id) . '">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="report">';
            echo '<label><span>Reason</span><textarea name="reason" rows="3" minlength="3" maxlength="' . MAX_POST_REPORT_LENGTH . '" required></textarea></label>';
            echo '<div class="report-dialog-actions"><button type="submit">Submit report</button><button type="button" class="report-dialog-cancel">Cancel</button></div>';
            echo '</form></dialog>';
            echo '<script>(()=>{';
            echo 'const dialog=document.getElementById(' . json_encode($reportDialogId) . ');';
            echo 'const opener=document.querySelector(`[data-report-dialog="${dialog.id}"]`);';
            echo 'const titlebar=dialog.querySelector(".report-dialog-titlebar");';
            echo 'const close=()=>dialog.close();';
            echo 'opener.addEventListener("click",()=>{if(!dialog.open)dialog.showModal();});';
            echo 'dialog.querySelector(".report-dialog-close").addEventListener("click",close);';
            echo 'dialog.querySelector(".report-dialog-cancel").addEventListener("click",close);';
            echo 'let drag=null;';
            echo 'titlebar.addEventListener("pointerdown",event=>{';
            echo 'if(event.button!==0||event.target.closest("button"))return;';
            echo 'const rect=dialog.getBoundingClientRect();';
            echo 'dialog.style.transform="none";dialog.style.left=rect.left+"px";dialog.style.top=rect.top+"px";';
            echo 'drag={id:event.pointerId,x:event.clientX-rect.left,y:event.clientY-rect.top};';
            echo 'titlebar.setPointerCapture(event.pointerId);event.preventDefault();});';
            echo 'titlebar.addEventListener("pointermove",event=>{if(!drag||event.pointerId!==drag.id)return;';
            echo 'const maxLeft=Math.max(0,window.innerWidth-dialog.offsetWidth);';
            echo 'const maxTop=Math.max(0,window.innerHeight-dialog.offsetHeight);';
            echo 'dialog.style.left=Math.min(maxLeft,Math.max(0,event.clientX-drag.x))+"px";';
            echo 'dialog.style.top=Math.min(maxTop,Math.max(0,event.clientY-drag.y))+"px";});';
            echo 'const stop=event=>{if(drag&&event.pointerId===drag.id)drag=null;};';
            echo 'titlebar.addEventListener("pointerup",stop);titlebar.addEventListener("pointercancel",stop);';
            echo '})();</script>';
        }
    }
    if ($isOwner || $canModeratePosts) {
        echo '<a href="' . View::url('/post/' . $id . '/edit') . '"><button type="button">Edit</button></a> ';
        echo '<form method="post" action="' . View::url('/post/' . $id . '/delete') . '" style="display:inline" onsubmit="return confirm(\'Delete post #' . $id . '?\');">';
        View::csrfField();
        echo '<button>Delete</button>';
        echo '</form>';
    }
    echo '</div>';

    echo '</article>';


    echo '<section class="comments">';
    echo '<h2>Comments (' . count($comments) . ')</h2>';
    foreach ($comments as $c) {
        $author = $c['user_name'] ?? $c['guest_name'] ?? 'Anonymous';
        echo '<div class="comment" id="comment-' . (int)$c['id'] . '">';
        echo '<span class="comment-author">' . View::e($author) . '</span> ';
        echo '<span class="comment-date">' . date('Y-m-d H:i', (int)$c['created_at']) . '</span>';
        if ($canModerateComments) {
            echo ' <form method="post" action="' . View::url('/post/' . $id) . '" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="delete_comment">';
            echo '<input type="hidden" name="comment_id" value="' . View::e($c['id']) . '">';
            echo '<button>×</button>';
            echo '</form>';
        }
        echo '<p>' . nl2br(View::e($c['body'])) . '</p>';
        echo '</div>';
    }

    echo '<h3>Add comment</h3>';
    if ($user) {
        echo '<form method="post" action="' . View::url('/post/' . $id) . '">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="comment">';
        echo '<textarea name="body" rows="4" cols="60" maxlength="' . MAX_COMMENT_LENGTH . '" required></textarea><br>';
        echo '<button>Post comment</button>';
        echo '</form>';
    } else {
        echo '<p><a href="' . View::url('/login') . '">Log in</a> to post a comment.</p>';
    }
    echo '</section>';

    View::footer();
}

function post_handle(?array $user, int $id): void
{
    View::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'comment') {
        Auth::require();
        $body = $_POST['body'] ?? '';
        try {
            if (!DB::consumeRateLimit('comment', Auth::requestSubject(), COMMENT_RATE_LIMIT, COMMENT_RATE_WINDOW)) {
                throw new RuntimeException('Too many comments. Please try again later.', 429);
            }
            Post::addComment($id, $body, Auth::id());
            View::setFlash('Comment posted.', 'ok');
        } catch (RuntimeException $e) {
            View::setFlash($e->getMessage(), 'error');
        }
    } elseif ($action === 'report') {
        Auth::require();
        try {
            if (!DB::consumeRateLimit('post_report', 'user:' . (int)$user['id'], POST_REPORT_RATE_LIMIT, POST_REPORT_RATE_WINDOW)) {
                throw new RuntimeException('Too many reports. Please try again later.');
            }
            Post::report($id, (int)$user['id'], (string)($_POST['reason'] ?? ''));
            View::setFlash('Post reported. Thank you.', 'ok');
        } catch (RuntimeException $e) {
            View::setFlash($e->getMessage(), 'error');
        }
    } elseif ($action === 'favorite' && $user) {
        Post::addFavorite($id, (int)$user['id']);
        View::setFlash('Added to favorites.', 'ok');
    } elseif ($action === 'unfavorite' && $user) {
        Post::removeFavorite($id, (int)$user['id']);
        View::setFlash('Removed from favorites.', 'ok');
    } elseif ($action === 'vote' && $user) {
        $value = (int)($_POST['value'] ?? 0);
        Post::vote($id, (int)$user['id'], $value);
    } elseif ($action === 'delete_comment' && $user && Auth::can('moderate_comments', $user)) {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid) Post::deleteComment($cid);
        View::setFlash('Comment deleted.', 'ok');
    }

    Router::redirect('/post/' . $id);
}

function page_post_edit(?array $user, int $id, string $method): void
{
    Auth::require();
    $post = Post::getById($id);
    if (!$post) {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>Post not found</h1>';
        View::footer();
        return;
    }
    if ((int)$user['id'] !== (int)$post['user_id'] && !Auth::can('moderate_posts', $user)) {
        Router::redirect('/post/' . $id);
    }

    $error = '';
    if ($method === 'POST') {
        View::verifyCsrf();
        try {
            Post::update($id, $_POST);
            View::setFlash('Post updated.', 'ok');
            Router::redirect('/post/' . $id);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    $tagStr = implode(' ', array_column($post['tags'], 'name'));

    View::header('Edit Post #' . $id, $user);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<h1>Edit Post #' . View::e($id) . '</h1>';
    echo '<form method="post">';
    View::csrfField();
    echo '<label>Tags<br><input name="tags" value="' . View::e($tagStr) . '" size="60"></label><br>';
    echo '<label>Rating<br><select name="rating">';

    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        $sel = $post['rating'] === $v ? ' selected' : '';
        echo "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }
    echo '</select></label><br>';
    echo '<label>Source<br><input name="source" value="' . View::e($post['source'] ?? '') . '" size="60"></label><br>';
    echo '<label>Title<br><input name="title" value="' . View::e($post['title'] ?? '') . '" size="60"></label><br>';
    echo '<button>Save</button> <a href="' . View::url('/post/' . $id) . '">Cancel</a>';
    echo '</form>';
    View::footer();
}

function action_post_delete(?array $user, int $id): void
{
    Auth::require();
    View::verifyCsrf();
    $post = Post::getById($id);
    if (!$post) Router::redirect('/posts');
    if ((int)$user['id'] !== (int)$post['user_id'] && !Auth::can('moderate_posts', $user)) {
        Router::redirect('/post/' . $id);
    }
    Post::delete($id);
    View::setFlash('Post #' . $id . ' deleted.', 'ok');
    Router::redirect('/posts');
}

function page_upload(?array $user, string $method): void
{
    Auth::require();
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        try {
            $id = Post::upload($_FILES['file'] ?? [], $_POST);
            View::setFlash('Post #' . $id . ' uploaded.', 'ok');
            Router::redirect('/post/' . $id);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    View::header('Upload', $user);
    View::flash();
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<form method="post" enctype="multipart/form-data" class="upload-form">';
    View::csrfField();
    $maxMb = MAX_FILE_SIZE / 1024 / 1024;
    echo '<label><span>Image/Video <small>(JPEG, PNG, GIF, WebP, MP4, WebM, MOV — max ' . $maxMb . ' MB)</small></span>';
    echo '<input type="file" name="file" accept="image/*,video/mp4,video/webm,video/quicktime,.mov" required></label>';
    echo '<fieldset class="upload-content-type"><legend>Content type</legend><div>';
    echo '<label><input type="radio" name="content_type" value="artwork" checked><span>Artwork</span></label>';
    echo '<label><input type="radio" name="content_type" value="real_life"><span>Real Life</span></label>';
    echo '</div></fieldset>';
    echo '<label><span>Tags <small>(space-separated)</small></span><input name="tags" placeholder="character:foo artist:bar general_tag"></label>';
    echo '<label class="upload-rating"><span>Rating</span><select name="rating">';
    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        echo "<option value=\"{$v}\">{$l}</option>";
    }
    echo '</select></label>';
    echo '<label><span>Source URL</span><input name="source" placeholder="https://…"></label>';
    echo '<label><span>Title</span><input name="title"></label>';
    echo '<button>Upload</button>';
    echo '</form>';
    View::footer();
}

function page_tags(?array $user): void
{
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q      = trim($_GET['q'] ?? '');
    $q = Post::canonicalTagName($q);
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $blacklisted = [];
    if ($user && !empty($user['blacklist'])) {
        $blacklisted = Post::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
    }
    $notInSql = '';
    $notInParams = [];
    if ($blacklisted) {
        $notInPlaceholders = implode(',', array_fill(0, count($blacklisted), '?'));
        $notInSql = " AND name NOT IN ($notInPlaceholders) COLLATE NOCASE";
        $notInParams = $blacklisted;
    }

    if ($q !== '') {
        $tags  = DB::rows('SELECT name, count, category FROM tags WHERE name LIKE ?' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge(['%' . $q . '%'], $notInParams, [$limit, $offset]));
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE name LIKE ?' . $notInSql, array_merge(['%' . $q . '%'], $notInParams));
    } else {
        $tags  = DB::rows('SELECT name, count, category FROM tags WHERE 1=1' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge($notInParams, [$limit, $offset]));
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE 1=1' . $notInSql, $notInParams);
    }
    $pages = (int)ceil($total / $limit);

    View::header('Tags', $user);
    View::flash();
    echo '<table class="tags-table">';
    echo '<thead><tr><th scope="col">Tag</th><th scope="col">Posts</th></tr></thead><tbody>';
    foreach ($tags as $t) {
        echo '<tr><td><a href="' . View::e(View::url('/posts', ['q' => $t['name']])) . '">' . View::e($t['name']) . '</a></td><td>' . View::e($t['count']) . '</td></tr>';
    }
    echo '</tbody></table>';
    View::paginator($page, $pages, '/tags', $q ? ['q' => $q] : []);
    View::footer();
}

function page_login(?array $user, string $method): void
{
    if ($user) Router::redirect('/');
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $name = trim($_POST['name'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (Auth::login($name, $pass)) {
            Router::redirect('/');
        } elseif (Auth::hasPendingRegistration($name)) {
            $error = 'Your account exists but it has not been approved yet.';
        } else {
            $error = 'Invalid username or password.';
        }
    }

    View::header('Login', null);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<form method="post" style="display: flex; flex-direction: column; gap: 16px; max-width: 300px;">';
    View::csrfField();
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Username</span><input name="name" required autofocus></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Password</span><input type="password" name="password" required></label>';
    echo '<div style="display: flex; align-items: center; gap: 16px;">';
    echo '<button type="submit">Login</button>';
    echo '<a href="' . View::url('/register') . '" style="font-size: 13px; color: var(--text-muted);">Register</a>';
    echo '</div>';
    echo '</form>';
    View::footer();
}

function page_register(?array $user, string $method): void
{
    if (View::siteSetting('disable_registrations', '0') === '1') {
        View::header('Register', null);
        echo '<p>Registrations are currently disabled.</p>';
        View::footer();
        return;
    }
    if ($user) Router::redirect('/');
    $error = '';
    $success = '';
    $acceptedTerms = false;
    $requireRegistrationReason = View::siteSetting('require_registration_reason', '0') === '1';
    $requiresRegistrationApproval = View::siteSetting('registration_requires_approval', '0') === '1';
    $registrationCaptchaEnabled = View::siteSetting('enable_registration_captcha', '0') === '1';
    $turnstileSiteKey = View::siteSetting('turnstile_site_key', '');
    $turnstileSecretKey = View::siteSetting('turnstile_secret_key', '');
    $registrationReason = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $name  = trim($_POST['name'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $registrationReason = trim($_POST['registration_reason'] ?? '');
        $acceptedTerms = isset($_POST['accept_terms']);
        $id = false;
        if (!$acceptedTerms) {
            $error = 'You must accept the Terms of Service to register.';
        } elseif ($requireRegistrationReason && $registrationReason === '') {
            $error = 'Please provide a reason for registration.';
        } elseif ($registrationCaptchaEnabled && ($turnstileSiteKey === '' || $turnstileSecretKey === '')) {
            $error = 'Registration CAPTCHA is not configured correctly. Please contact the administrator.';
        } elseif ($registrationCaptchaEnabled && !Auth::verifyTurnstile(trim((string)($_POST['cf-turnstile-response'] ?? '')), $turnstileSecretKey)) {
            $error = 'CAPTCHA verification failed. Please try again.';
        } elseif ($requiresRegistrationApproval) {
            $requestId = Auth::requestRegistration($name, $pass, $email, $registrationReason);
            if ($requestId) {
                $success = 'Your registration request has been sent for approval.';
            }
        } else {
            $id = Auth::register($name, $pass, $email, $registrationReason);
        }
        if ($id) {
            Auth::login($name, $pass);
            Router::redirect('/');
        } elseif ($error === '' && $success === '') {
            $error = 'Registration failed. Username may be taken or too short (min 2 chars, password min 4 chars).';
        }
    }

    View::header('Register', null);
    if ($registrationCaptchaEnabled && $turnstileSiteKey !== '') {
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    if ($success) echo '<p class="flash flash-ok">' . View::e($success) . '</p>';
    echo '<form method="post" style="display: flex; flex-direction: column; gap: 16px; max-width: 300px;">';
    View::csrfField();
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Username (2–32 chars)</span><input name="name" required autofocus></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Password (min 4 chars)</span><input type="password" name="password" required></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Email (optional)</span><input type="email" name="email"></label>';
    if ($requireRegistrationReason) {
        echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Reason for registration</span><textarea name="registration_reason" required rows="4">' . View::e($registrationReason) . '</textarea></label>';
    }
    echo '<label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="accept_terms" value="1" required' . ($acceptedTerms ? ' checked' : '') . '> <span>I accept <a href="' . View::url('/terms') . '" target="_blank" rel="noopener" style="text-decoration:underline;">Terms of Service</a></span></label>';
    if ($registrationCaptchaEnabled && $turnstileSiteKey !== '') {
        echo '<div class="cf-turnstile" data-sitekey="' . View::e($turnstileSiteKey) . '" data-theme="auto" data-action="register"></div>';
    }
    echo '<div style="display: flex; align-items: center; gap: 16px;">';
    echo '<button type="submit">Register</button>';
    echo '<a href="' . View::url('/login') . '" style="font-size: 13px; color: var(--text-muted);">Back to login</a>';
    echo '</div>';
    echo '</form>';
    View::footer();
}

function page_terms(?array $user): void
{
    $terms = trim(View::siteSetting('terms_of_service', ''));
    if ($terms === '') $terms = 'Terms of Service have not been published yet.';
    View::header('Terms of Service', $user);
    echo '<div style="max-width:800px; white-space:pre-wrap; line-height:1.6;">' . View::e($terms) . '</div>';
    View::footer();
}

function wiki_slug(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated)) $value = $transliterated;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function page_wiki(?array $user): void
{
    $pages = DB::rows(
        'SELECT w.*, u.name AS author_name
         FROM wiki_pages w
         LEFT JOIN users u ON u.id = w.user_id
         ORDER BY w.title COLLATE NOCASE ASC'
    );

    View::header('Wiki', $user, null, [
        'description' => 'Community information and guides for ' . View::siteSetting('site_name', SITE_NAME) . '.',
        'canonical' => View::url('/wiki'),
        'image' => false,
    ]);
    View::flash();

    if (Auth::isAdmin()) {
        echo '<div class="wiki-toolbar"><a class="wiki-action" href="' . View::url('/wiki/new') . '">New article</a></div>';
    }

    if (!$pages) {
        echo '<p class="wiki-empty">No wiki articles have been published yet.</p>';
    } else {
        echo '<div class="wiki-list">';
        foreach ($pages as $page) {
            echo '<article class="wiki-list-item">';
            echo '<a class="wiki-list-title" href="' . View::url('/wiki/' . $page['slug']) . '">' . View::e($page['title']) . '</a>';
            if ($page['summary'] !== '') {
                echo '<p>' . View::e($page['summary']) . '</p>';
            }
            echo '<small>Updated ' . View::e(date('Y-m-d H:i', (int)$page['updated_at'])) . '</small>';
            echo '</article>';
        }
        echo '</div>';
    }

    View::footer();
}

function page_wiki_article(?array $user, string $slug): void
{
    $page = DB::row(
        'SELECT w.*, u.name AS author_name
         FROM wiki_pages w
         LEFT JOIN users u ON u.id = w.user_id
         WHERE w.slug = ? COLLATE NOCASE',
        [$slug]
    );
    if (!$page) {
        http_response_code(404);
        View::header('Wiki article not found', $user);
        echo '<p>Wiki article not found.</p>';
        View::footer();
        return;
    }

    $description = trim((string)$page['summary']);
    if ($description === '') {
        $plainBody = preg_replace('/\s+/', ' ', trim((string)$page['body'])) ?? '';
        $description = function_exists('mb_substr') ? mb_substr($plainBody, 0, 180) : substr($plainBody, 0, 180);
    }
    View::header((string)$page['title'], $user, null, [
        'description' => $description,
        'canonical' => View::url('/wiki/' . $page['slug']),
        'image' => false,
    ]);
    View::flash();

    echo '<article class="wiki-article">';
    echo '<div class="wiki-article-heading"><h1>' . View::e($page['title']) . '</h1>';
    if (Auth::isAdmin()) {
        echo '<a class="wiki-action" href="' . View::url('/wiki/' . $page['slug'] . '/edit') . '">Edit</a>';
    }
    echo '</div>';
    if ($page['summary'] !== '') echo '<p class="wiki-summary">' . View::e($page['summary']) . '</p>';
    echo '<div class="wiki-body markdown-body">' . WikiMarkdown::render((string)$page['body']) . '</div>';
    echo '<p class="wiki-meta">Updated ' . View::e(date('Y-m-d H:i', (int)$page['updated_at']));
    if (!empty($page['author_name'])) echo ' by ' . View::e($page['author_name']);
    echo '</p></article>';
    View::footer();
}

function page_wiki_edit(?array $user, ?string $slug, string $method): void
{
    Auth::requireAdmin();
    $page = $slug === null ? null : DB::row('SELECT * FROM wiki_pages WHERE slug = ? COLLATE NOCASE', [$slug]);
    if ($slug !== null && !$page) {
        http_response_code(404);
        View::header('Wiki article not found', $user);
        echo '<p>Wiki article not found.</p>';
        View::footer();
        return;
    }

    $title = (string)($page['title'] ?? '');
    $pageSlug = (string)($page['slug'] ?? '');
    $summary = (string)($page['summary'] ?? '');
    $body = (string)($page['body'] ?? '');
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $title = trim((string)($_POST['title'] ?? ''));
        $pageSlug = wiki_slug((string)($_POST['slug'] ?? ''));
        if ($pageSlug === '') $pageSlug = wiki_slug($title);
        $summary = trim((string)($_POST['summary'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($title === '' || strlen($title) > 160) {
            $error = 'Title is required and must be no longer than 160 characters.';
        } elseif ($pageSlug === '' || strlen($pageSlug) > 120) {
            $error = 'Enter a valid article URL no longer than 120 characters.';
        } elseif ($pageSlug === 'new') {
            $error = 'This article URL is reserved.';
        } elseif (strlen($summary) > 300) {
            $error = 'Summary must be no longer than 300 characters.';
        } elseif ($body === '' || strlen($body) > 100000) {
            $error = 'Content is required and must be no longer than 100,000 characters.';
        } else {
            $duplicate = DB::row(
                'SELECT id FROM wiki_pages WHERE slug = ? COLLATE NOCASE AND id <> ?',
                [$pageSlug, (int)($page['id'] ?? 0)]
            );
            if ($duplicate) {
                $error = 'Another wiki article already uses this URL.';
            }
        }

        if ($error === '') {
            if ($page) {
                DB::exec(
                    'UPDATE wiki_pages SET slug = ?, title = ?, summary = ?, body = ?, user_id = ?, updated_at = unixepoch() WHERE id = ?',
                    [$pageSlug, $title, $summary, $body, (int)$user['id'], (int)$page['id']]
                );
                View::setFlash('Wiki article updated.', 'ok');
            } else {
                DB::exec(
                    'INSERT INTO wiki_pages (slug, title, summary, body, user_id) VALUES (?, ?, ?, ?, ?)',
                    [$pageSlug, $title, $summary, $body, (int)$user['id']]
                );
                View::setFlash('Wiki article published.', 'ok');
            }
            Router::redirect('/wiki/' . $pageSlug);
        }
    }

    View::header($page ? 'Edit wiki article' : 'New wiki article', $user, null, [
        'robots' => 'noindex,nofollow',
    ]);
    if ($error !== '') echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<form class="wiki-editor" method="post">';
    View::csrfField();
    echo '<label><span>Title</span><input type="text" name="title" maxlength="160" value="' . View::e($title) . '" required autofocus></label>';
    echo '<label><span>URL</span><div class="wiki-slug-field"><span>' . View::e(View::url('/wiki/')) . '</span><input type="text" name="slug" maxlength="120" pattern="[a-z0-9-]+" value="' . View::e($pageSlug) . '" placeholder="generated-from-title"></div></label>';
    echo '<label><span>Summary</span><textarea name="summary" rows="3" maxlength="300" placeholder="Short description shown on the wiki list">' . View::e($summary) . '</textarea></label>';
    echo '<label><span>Content <small>GitHub Flavored Markdown and safe HTML such as &lt;a&gt; are supported.</small></span><textarea name="body" rows="20" maxlength="100000" required>' . View::e($body) . '</textarea></label>';
    echo '<div class="wiki-editor-actions"><button type="submit">' . ($page ? 'Save changes' : 'Publish article') . '</button><a href="' . View::url($page ? '/wiki/' . $page['slug'] : '/wiki') . '">Cancel</a></div>';
    echo '</form>';
    View::footer();
}

function page_discord(?array $user): void
{
    $siteName = View::siteSetting('site_name', SITE_NAME);
    $discordUrl = trim(View::siteSetting('discord_url', ''));
    $hasInvite = filter_var($discordUrl, FILTER_VALIDATE_URL)
        && in_array(strtolower((string)parse_url($discordUrl, PHP_URL_SCHEME)), ['http', 'https'], true);

    View::header('Discord', $user, null, [
        'description' => 'Join the ' . $siteName . ' community on Discord.',
        'canonical' => View::url('/discord'),
        'image' => false,
    ]);

    echo '<section class="discord-page">';
    echo '<p class="discord-lead">Join the ' . View::e($siteName) . ' community on Discord.</p>';
    echo '<ul class="discord-info">';
    echo '<li>Talk with other members of the community.</li>';
    echo '<li>Share feedback, ideas and suggestions for the site.</li>';
    echo '<li>Follow site news and important announcements.</li>';
    echo '</ul>';
    if ($hasInvite) {
        echo '<a class="discord-join-button" href="' . View::e($discordUrl) . '" target="_blank" rel="noopener noreferrer">Join Discord</a>';
    } else {
        echo '<p class="discord-unavailable">The Discord invitation is currently unavailable.</p>';
    }
    echo '</section>';
    View::footer();
}

function page_search_help(?array $user): void
{
    View::header('Search Help', $user, null, [
        'description' => 'Learn how to search Librebooru using tags, aliases, ratings, quality filters, and sorting.',
        'canonical' => View::url('/search-help'),
        'image' => false,
    ]);

    $example = static function (string $label, array $params): string {
        return '<a href="' . View::url('/posts', $params) . '"><code>' . View::e($label) . '</code></a>';
    };

    $row = static function (string $query, string $description) use ($example): string {
        return '<div>' . $example($query, ['q' => $query]) . '<span>' . $description . '</span></div>';
    };

    echo '<article class="search-help-page">';
    echo '<nav class="search-help-toc"><a href="#basics">Basics</a><a href="#species">Species & tag colors</a><a href="#sorting">Sorting</a><a href="#rating">Rating & files</a><a href="#size">Size & counts</a><a href="#text">Text & users</a><a href="#dates">Dates</a><a href="#ranges">Ranges</a></nav>';

    echo '<section id="basics"><h2>Basics</h2><div class="search-help-table">';
    echo $row('1girl long_hair', 'Posts tagged with both <code>1girl</code> and <code>long_hair</code>. Separate tags with spaces.');
    echo $row('~brown_hair ~black_hair', 'Posts containing either tag, or both. Prefix OR terms with <code>~</code>.');
    echo $row('female -long_hair', 'Posts tagged <code>female</code> that do not have <code>long_hair</code>.');
    echo $row('blonde_*', 'Wildcard search: match any tag beginning with <code>blonde_</code>. <code>*hair</code> and <code>*hair*</code> also work.');
    echo $row('( ~brown_hair ~black_hair ) ( ~1girl ~1boy )', 'Require one hair-color tag and one character-count tag. Keep spaces around parentheses.');
    echo $row('boobs', 'Aliases resolve automatically; this example searches for the canonical tag <code>breasts</code>.');
    echo '</div></section>';

    echo '<section id="species"><h2>Species and tag colors</h2>';
    echo '<p class="search-help-note"><strong>Species</strong> means the animal or creature shown in a post. Species tags are green. Search using the actual species name, such as <code>wolf</code>, <code>fox</code>, or <code>canine</code> — do not add <code>species:</code> before it.</p>';
    echo '<div class="search-help-table">';
    echo $row('wolf type:image', 'Photos and images tagged <code>wolf</code>. Replace <code>wolf</code> with any species name.');
    echo $row('wolf type:video', 'Videos tagged <code>wolf</code>. This includes MP4, WebM, and MOV files.');
    echo $row('fox canine', 'Posts that contain both species tags.');
    echo $row('~wolf ~fox type:image', 'Images containing either <code>wolf</code> or <code>fox</code>.');
    echo $row('canine -wolf', 'Canine posts that are not tagged <code>wolf</code>.');
    echo '</div>';
    echo '<h3>Adding species while uploading</h3>';
    echo '<p class="search-help-note">When posting an image or video, enter the species as a normal space-separated tag in the <strong>Tags</strong> field: <code>wolf blue_eyes solo</code>. Known species are classified automatically and appear green after upload.</p>';
    echo '<div class="tag-color-legend" aria-label="Tag color legend">';
    echo '<span class="tag-category-artist">Artist / model</span>';
    echo '<span class="tag-category-character">Character</span>';
    echo '<span class="tag-category-copyright">Copyright</span>';
    echo '<span class="tag-category-species">Species</span>';
    echo '<span class="tag-category-meta">Meta</span>';
    echo '<span class="tag-category-general">General</span>';
    echo '</div></section>';

    echo '<section id="sorting"><h2>Sorting and result limit</h2><div class="search-help-table">';
    echo $row('order:id', 'Oldest posts first. Use <code>order:id_desc</code> for newest first.');
    echo $row('order:score', 'Highest score first. Use <code>order:score_asc</code> for lowest first.');
    echo $row('order:favcount', 'Most favorited first. The <code>_asc</code> suffix reverses the order.');
    echo $row('order:comment_count', 'Most commented first.');
    echo $row('order:mpixels', 'Largest resolution first.');
    echo $row('order:filesize', 'Largest files first.');
    echo $row('order:landscape', 'Widest aspect ratios first. Use <code>order:portrait</code> for tallest first.');
    echo $row('order:random limit:12', 'Random order with 12 results per page. Limits from 1 to 200 are accepted.');
    echo '</div></section>';

    echo '<section id="rating"><h2>Rating, quality, and file types</h2><div class="search-help-table">';
    echo $row('rating:s', 'Safe posts. Also accepts <code>rating:q</code>, <code>rating:e</code>, and the full rating names.');
    echo $row('quality:ultra', '4K-quality posts. Other values: <code>low</code>, <code>medium</code>, and <code>high</code>.');
    echo $row('filetype:png', 'Posts with the selected extension: jpg, png, gif, webp, mp4, webm, or mov.');
    echo $row('type:video', 'Video files. Other values: <code>image</code> and <code>animation</code>.');
    echo '</div></section>';

    echo '<section id="size"><h2>IDs, dimensions, and counts</h2><div class="search-help-table">';
    echo $row('id:10000..20000', 'Posts with an ID in the specified range. Comma-separated exact IDs are also supported.');
    echo $row('score:>=10', 'Posts with a score of at least 10.');
    echo $row('width:1920..', 'Images or videos at least 1920 pixels wide. <code>height:</code> works the same way.');
    echo $row('mpixels:2..8', 'Posts between 2 and 8 megapixels.');
    echo $row('ratio:>=1.5', 'Posts whose width-to-height ratio is at least 1.5.');
    echo $row('filesize:1mb..10mb', 'Files between 1 MB and 10 MB. Units B, KB, MB, and GB are supported.');
    echo $row('tagcount:>=20', 'Posts with at least 20 tags.');
    echo $row('favcount:>=5', 'Posts favorited at least five times.');
    echo $row('comment_count:>=1', 'Posts with at least one comment.');
    echo '</div></section>';

    echo '<section id="text"><h2>Text, hashes, and users</h2><div class="search-help-table">';
    echo $row('source:*e621.net*', 'Posts whose source URL contains <code>e621.net</code>.');
    echo $row('source:none', 'Posts without a source URL. Use <code>source:any</code> for posts with a source.');
    echo $row('hassource:true', 'Posts with a source. Boolean values may also be negated with a leading <code>-</code>.');
    echo $row('hasdescription:true', 'Posts with a stored title or description.');
    echo $row('description:Realbooru', 'Posts whose stored title contains the supplied text.');
    echo $row('md5:d41d8cd98f00b204e9800998ecf8427e', 'Find the post with one exact MD5 hash.');
    echo $row('user:admin', 'Posts uploaded by a username. <code>user_id:1</code> searches by numeric user ID.');
    echo $row('fav:me', 'Posts favorited by the signed-in user. A username can be used instead of <code>me</code>.');
    echo $row('commenter:any', 'Posts with comments. Use a username or <code>commenter:none</code>.');
    echo '</div></section>';

    echo '<section id="dates"><h2>Upload dates</h2><div class="search-help-table">';
    echo $row('date:today', 'Posts uploaded today. <code>date:yesterday</code> is also supported.');
    echo $row('date:2026-01-01', 'Posts uploaded on one exact calendar date.');
    echo $row('date:2026-01-01..2026-01-31', 'Posts uploaded within an inclusive date range. Open-ended ranges also work.');
    echo '</div></section>';

    echo '<section id="ranges"><h2>Range syntax</h2><div class="search-help-table">';
    echo $row('score:25', 'Exactly 25.');
    echo $row('score:25..50', 'Between 25 and 50, inclusive.');
    echo $row('score:25..', '25 or greater.');
    echo $row('score:..50', '50 or less.');
    echo $row('score:>25', 'Greater than 25. Operators <code>&gt;</code>, <code>&gt;=</code>, <code>&lt;</code>, and <code>&lt;=</code> are supported.');
    echo $row('-score:>25', 'Negate any metatag by adding <code>-</code> before it.');
    echo '</div></section>';

    echo '<p><a class="button" href="' . View::url('/posts') . '">Back to search</a></p>';
    echo '</article>';
    View::footer();
}

function page_user(?array $user, string $targetName): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }
    $target = DB::row('SELECT id, name, email, role, created_at, avatar, banner, biography, display_name FROM users WHERE name = ?', [$targetName]);
    if (!$target) {
        http_response_code(404);
        View::header('User not found', $user);
        echo '<h1>User not found</h1>';
        View::footer();
        return;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $profilePosts = Post::listByUser((int)$target['id'], $page, POSTS_PER_PAGE);
    $myPosts = $profilePosts['posts'];
    $myTotal = $profilePosts['total'];
    $myPages = $profilePosts['pages'];

    $isSelf = $user && (int)$user['id'] === (int)$target['id'];
    $canSeeFavorites = $isSelf || ($user && View::siteSetting('show_user_favorites', '0') === '1');
    $favTotal = $canSeeFavorites
        ? (int)DB::scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [(int)$target['id']])
        : 0;

    $displayName = $target['display_name'] !== '' ? $target['display_name'] : $target['name'];
    View::header($displayName, $user);
    View::flash();
    echo '<section class="user-profile">';
    if ($target['banner'] !== '') {
        echo '<img class="profile-banner" src="' . View::e($target['banner']) . '" alt="' . View::e($target['name']) . ' profile banner">';
    }
    echo '<div class="profile-heading">';
    if ($target['avatar'] !== '') {
        echo '<img class="profile-avatar" src="' . View::e($target['avatar']) . '" alt="' . View::e($target['name']) . ' profile picture">';
    }
    echo '<h1>' . View::e($displayName) . '</h1>';
    echo '</div>';
    if ($target['biography'] !== '') {
        echo '<div class="profile-biography">' . View::e($target['biography']) . '</div>';
    }
    echo '</section>';
    echo '<div style="overflow-x:auto; max-width:560px; margin-bottom:24px;"><table style="width:100%; border-collapse:collapse;">';
    echo '<tbody>';
    echo '<tr><th scope="row" style="text-align:left; padding:8px 12px; border:1px solid var(--border); width:40%;">Role</th><td style="padding:8px 12px; border:1px solid var(--border);">' . View::e($target['role']) . '</td></tr>';
    echo '<tr><th scope="row" style="text-align:left; padding:8px 12px; border:1px solid var(--border);">Member since</th><td style="padding:8px 12px; border:1px solid var(--border);">' . View::e(date('Y-m-d', (int)$target['created_at'])) . '</td></tr>';
    echo '<tr><th scope="row" style="text-align:left; padding:8px 12px; border:1px solid var(--border);">Posts</th><td style="padding:8px 12px; border:1px solid var(--border);">' . $myTotal . '</td></tr>';
    if ($canSeeFavorites) {
        $favoritesUrl = $isSelf
            ? '/favorites'
            : '/user/' . rawurlencode($target['name']) . '/favorites';
        $favoritesValue = '<a href="' . View::url($favoritesUrl) . '">' . $favTotal . '</a>';
        echo '<tr><th scope="row" style="text-align:left; padding:8px 12px; border:1px solid var(--border);">Favorites</th><td style="padding:8px 12px; border:1px solid var(--border);">' . $favoritesValue . '</td></tr>';
    }
    if ($isSelf) {
        echo '<tr><th scope="row" style="text-align:left; padding:8px 12px; border:1px solid var(--border);">Email</th><td style="padding:8px 12px; border:1px solid var(--border);">' . View::e($target['email'] ?: '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<h2>Posts</h2>';
    echo '<section class="post-listing" aria-label="Posts">';
    View::postGrid($myPosts);
    View::paginator($page, $myPages, '/user/' . rawurlencode($target['name']));
    echo '</section>';
    View::footer();
}

function page_user_favorites(?array $user, string $targetName): void
{
    Auth::require();
    $target = DB::row('SELECT id, name FROM users WHERE name = ?', [$targetName]);
    if (!$target) {
        http_response_code(404);
        View::header('User not found', $user);
        echo '<h1>User not found</h1>';
        View::footer();
        return;
    }

    $isSelf = (int)$user['id'] === (int)$target['id'];
    if (!$isSelf && View::siteSetting('show_user_favorites', '0') !== '1') {
        View::setFlash('This user’s favorites are private.', 'error');
        Router::redirect('/user/' . rawurlencode($target['name']));
    }
    if ($isSelf) {
        Router::redirect('/favorites');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $result = Post::listFavorites((int)$target['id'], $page, POSTS_PER_PAGE);
    $favoritesPath = '/user/' . rawurlencode($target['name']) . '/favorites';
    $sidebarTags = DB::rows('SELECT name, count, category FROM tags ORDER BY count DESC LIMIT 50');

    View::header($target['name'] . ' Favorites', $user, $sidebarTags);
    View::flash();
    echo '<section class="post-listing" aria-label="Favorite posts">';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], $favoritesPath);
    echo '</section>';
    View::footer();
}

function page_favorites(?array $user): void
{
    Auth::require();
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $result = Post::listFavorites((int)$user['id'], $page, POSTS_PER_PAGE);

    $sidebarTags = DB::rows('SELECT name, count, category FROM tags ORDER BY count DESC LIMIT 50');
    View::header('Favorites', $user, $sidebarTags);
    View::flash();
    if ($result['total'] > 0) {
        echo '<p><a href="' . View::url('/favorites/lucky') . '" class="button">Lucky draw</a></p>';
    }
    echo '<section class="post-listing" aria-label="Favorite posts">';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], '/favorites');
    echo '</section>';
    View::footer();
}

function page_favorites_lucky(?array $user): void
{
    Auth::require();
    $post = Post::getRandomFavorite((int)$user['id']);
    if (!$post) {
        Router::redirect('/favorites');
    }

    $fileUrl = Image::fileUrl($post['filename']);
    $nextUrl = View::url('/favorites/lucky');
    $backUrl = View::url('/favorites');

    $ext = pathinfo($post['filename'], PATHINFO_EXTENSION);
    $isVideo = in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true);


    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Lucky Draw</title>
    <style>
        body, html { margin: 0; padding: 0; width: 100%; height: 100%; background: #000; overflow: hidden; display: flex; align-items: center; justify-content: center; font-family: sans-serif; }
        img, video { max-width: 100%; max-height: 100%; object-fit: contain; }
        .controls { position: absolute; bottom: 20px; text-align: center; width: 100%; }
        .btn { background: rgba(0,0,0,0.5); color: #fff; border: 1px solid #fff; padding: 10px 20px; margin: 0 10px; text-decoration: none; font-size: 16px; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: rgba(255,255,255,0.2); }
    </style>
</head>
<body>
    ';

    if ($isVideo) {
        echo '<video src="' . htmlspecialchars($fileUrl) . '" autoplay loop controls playsinline preload="metadata"></video>';
    } else {
        echo '<img src="' . htmlspecialchars($fileUrl) . '" alt="Lucky Draw Image">';
    }

    echo '
    <div class="controls">
        <a href="' . $backUrl . '" class="btn">Back</a>
        <a href="' . $nextUrl . '" class="btn" id="random-btn">Random</a>
    </div>
    <script>
        document.addEventListener("keydown", function(e) {
            if (e.code === "Space") {
                e.preventDefault();
                document.getElementById("random-btn").click();
            }
        });
    </script>
</body>
</html>';
    exit;
}

function scraperTaskIsRunning(array $task): bool
{
    $pid = (int)($task['pid'] ?? 0);
    $taskId = (int)($task['id'] ?? 0);
    if ($pid < 1 || $taskId < 1) return false;

    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdline) || $cmdline === '') {
        $cmdline = (string)@shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null');
    }
    $script = basename((string)($task['source'] ?? 'realbooru')) . '.php';
    if (($task['tag'] ?? '') === 'Tags fetcher (all Realbooru posts)') {
        $script = 'realbooru_tags_fetcher.php';
    }

    return str_contains($cmdline, $script)
        && (bool)preg_match('/--task-id(?:=|\s+)' . $taskId . '(?:\s|\x00|$)/', $cmdline);
}

function renderAdminTabs(?array $user, string $active): void
{
    echo '<nav class="admin-tabs" aria-label="Admin sections">';
    echo '<a' . ($active === 'panel' ? ' class="active" aria-current="page"' : '')
        . ' href="' . View::url('/admin') . '">Panel</a>';
    if (Auth::can('manage_scraper', $user)) {
        echo '<a' . ($active === 'scraper' ? ' class="active" aria-current="page"' : '')
            . ' href="' . View::url('/scraper') . '">Scraper</a>';
    }
    echo '</nav>';
}

function page_scraper(?array $user, string $method): void
{
    Auth::requirePermission('manage_scraper');

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'start') {
            $tag = trim($_POST['tag'] ?? '');
            $source = in_array($_POST['source'] ?? '', ['realbooru', 'e621', 'rule34'], true) ? $_POST['source'] : 'realbooru';
            $blacklist = $source === 'e621' ? trim($_POST['blacklist'] ?? '') : '';
            $canStart = $tag !== '';
            if (!$canStart) View::setFlash('Tag cannot be empty.', 'error');

            if ($source === 'rule34') {
                $submittedUserId = trim($_POST['rule34_user_id'] ?? '');
                $submittedApiKey = trim($_POST['rule34_api_key'] ?? '');
                if ($submittedUserId !== '') {
                    if (ctype_digit($submittedUserId)) {
                        View::setSiteSetting('rule34_user_id', $submittedUserId);
                    } else {
                        View::setFlash('Rule34.xxx User ID must be a number.', 'error');
                        $canStart = false;
                    }
                }
                if ($submittedApiKey !== '') View::setSiteSetting('rule34_api_key', $submittedApiKey);
                if (View::siteSetting('rule34_user_id') === '' || View::siteSetting('rule34_api_key') === '') {
                    View::setFlash('Rule34.xxx User ID and API key are required.', 'error');
                    $canStart = false;
                }
            }

            if ($canStart) {
                $script = __DIR__ . '/scrapers/' . $source . '.php';

                DB::exec('INSERT INTO scraper_tasks (tag, source, blacklist, status) VALUES (?, ?, ?, ?)', [$tag, $source, $blacklist, 'starting']);
                $taskId = (int)DB::lastId();
                $logFile = __DIR__ . '/data/scraper_' . $taskId . '.log';

                if ($source === 'e621') {
                    $cmd = sprintf(
                        'php %s --tag %s --blacklist %s --task-id %d > %s 2>&1 & echo $!',
                        escapeshellarg($script),
                        escapeshellarg($tag),
                        escapeshellarg($blacklist),
                        $taskId,
                        escapeshellarg($logFile)
                    );
                } else {
                    $cmd = sprintf(
                        'php %s --tag %s --task-id %d > %s 2>&1 & echo $!',
                        escapeshellarg($script),
                        escapeshellarg($tag),
                        $taskId,
                        escapeshellarg($logFile)
                    );
                }
                $pid = (int)shell_exec($cmd);
                if ($pid > 0) {
                    DB::exec('UPDATE scraper_tasks SET pid = ?, status = ? WHERE id = ?', [$pid, 'running', $taskId]);
                    $sourceLabel = match ($source) {
                        'e621' => 'e621',
                        'rule34' => 'Rule34.xxx',
                        default => 'Realbooru',
                    };
                    View::setFlash("Started $sourceLabel scraper for tag: $tag (PID: $pid)", 'ok');
                } else {
                    DB::exec('UPDATE scraper_tasks SET status = ? WHERE id = ?', ['error', $taskId]);
                    View::setFlash("Failed to start scraper process.", 'error');
                }
            }
        } elseif ($action === 'restart') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $task = DB::row('SELECT * FROM scraper_tasks WHERE id = ?', [$taskId]);
            if (!$task || !in_array($task['source'], ['realbooru', 'e621', 'rule34'], true)) {
                View::setFlash('Scraper task not found.', 'error');
            } elseif (!scraperTaskIsRunning($task)) {
                View::setFlash('The scraper process is no longer running.', 'error');
                DB::exec("UPDATE scraper_tasks SET status = 'completed' WHERE id = ?", [$taskId]);
            } else {
                $oldPid = (int)$task['pid'];
                posix_kill($oldPid, SIGTERM);
                for ($attempt = 0; $attempt < 50 && scraperTaskIsRunning($task); $attempt++) {
                    usleep(100_000);
                }

                if (scraperTaskIsRunning($task)) {
                    View::setFlash('Could not stop the previous scraper process.', 'error');
                } else {
                    $isRealbooruTagFetcher = ($task['tag'] ?? '') === 'Tags fetcher (all Realbooru posts)';
                    $script = $isRealbooruTagFetcher
                        ? __DIR__ . '/scrapers/realbooru_tags_fetcher.php'
                        : __DIR__ . '/scrapers/' . $task['source'] . '.php';
                    $logFile = __DIR__ . '/data/scraper_' . $taskId . '.log';
                    $blacklistArg = $task['source'] === 'e621' && !$isRealbooruTagFetcher
                        ? ' --blacklist ' . escapeshellarg((string)$task['blacklist'])
                        : '';
                    $tagArg = $isRealbooruTagFetcher ? '' : ' --tag ' . escapeshellarg((string)$task['tag']);
                    $cmd = 'php ' . escapeshellarg($script)
                        . $tagArg . $blacklistArg
                        . ' --task-id ' . $taskId
                        . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
                    $newPid = (int)shell_exec($cmd);
                    if ($newPid > 0) {
                        DB::exec("UPDATE scraper_tasks SET pid = ?, status = 'running' WHERE id = ?", [$newPid, $taskId]);
                        View::setFlash("Restarted scraper task #{$taskId} (PID: {$newPid}).", 'ok');
                    } else {
                        DB::exec("UPDATE scraper_tasks SET status = 'error' WHERE id = ?", [$taskId]);
                        View::setFlash('Could not restart the scraper process.', 'error');
                    }
                }
            }
        } elseif ($action === 'fetch_tags') {
            $script = __DIR__ . '/scrapers/realbooru_tags_fetcher.php';
            DB::exec('INSERT INTO scraper_tasks (tag, status) VALUES (?, ?)', ['Tags fetcher (all Realbooru posts)', 'starting']);
            $taskId = (int)DB::lastId();
            $logFile = __DIR__ . '/data/scraper_' . $taskId . '.log';
            $cmd = sprintf(
                'php %s --task-id %d > %s 2>&1 & echo $!',
                escapeshellarg($script),
                $taskId,
                escapeshellarg($logFile)
            );
            $pid = (int)shell_exec($cmd);
            if ($pid > 0) {
                DB::exec('UPDATE scraper_tasks SET pid = ?, status = ? WHERE id = ?', [$pid, 'running', $taskId]);
                View::setFlash("Started tags fetcher for all Realbooru posts (PID: $pid)", 'ok');
            } else {
                DB::exec('UPDATE scraper_tasks SET status = ? WHERE id = ?', ['error', $taskId]);
                View::setFlash('Failed to start tags fetcher.', 'error');
            }
        } elseif ($action === 'clean') {
            $completed = DB::rows("SELECT id FROM scraper_tasks WHERE status != 'running'");
            foreach ($completed as $c) {
                @unlink(__DIR__ . '/data/scraper_' . $c['id'] . '.log');
            }
            DB::exec("DELETE FROM scraper_tasks WHERE status != 'running'");
            View::setFlash("Cleaned up completed tasks.", 'ok');
        }
        Router::redirect('/scraper');
    }

    $tasks = DB::rows('SELECT * FROM scraper_tasks ORDER BY created_at DESC LIMIT 50');


    foreach ($tasks as &$task) {
        if ($task['status'] === 'running' && $task['pid'] > 0) {



            if (!scraperTaskIsRunning($task)) {
                DB::exec("UPDATE scraper_tasks SET status = 'completed' WHERE id = ?", [$task['id']]);
                $task['status'] = 'completed';
            }
        }
    }
    unset($task);

    View::header('Scraper', $user);
    View::flash();

    $rule34UserId = View::siteSetting('rule34_user_id');
    $rule34ApiConfigured = View::siteSetting('rule34_api_key') !== '';

    renderAdminTabs($user, 'scraper');

    echo '<div class="form-container">';
    echo '<h2>Start New Scraper</h2>';
    echo '<form method="post" action="' . View::url('/scraper') . '" class="scraper-start-form">';
    echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
    echo '  <input type="hidden" name="action" value="start">';
    echo '  <div class="form-group">';
    echo '    <label for="scraper-source">Source</label>';
    echo '    <select id="scraper-source" name="source"><option value="realbooru">Realbooru</option><option value="e621">e621</option><option value="rule34">Rule34.xxx</option></select>';
    echo '  </div>';
    echo '  <div class="form-group">';
    echo '    <label>Tag to scrape</label>';
    echo '    <input type="text" name="tag" required placeholder="e.g. femboy">';
    echo '  </div>';
    echo '  <div class="form-group" id="e621-blacklist" hidden>';
    echo '    <label>Blacklist tags <small>(space, comma or line separated)</small></label>';
    echo '    <textarea name="blacklist" rows="4" placeholder="gore scat feral"></textarea>';
    echo '    <small>e621 posts containing any of these tags will be skipped.</small>';
    echo '  </div>';
    echo '  <div class="form-group" id="rule34-credentials" hidden>';
    echo '    <label>Rule34.xxx User ID</label>';
    echo '    <input name="rule34_user_id" inputmode="numeric" value="' . View::e($rule34UserId) . '" placeholder="Numeric user ID">';
    echo '    <label>Rule34.xxx API key</label>';
    echo '    <input type="password" name="rule34_api_key" value="" autocomplete="new-password" placeholder="' . ($rule34ApiConfigured ? 'Configured — leave blank to keep it' : 'Enter API key') . '">';
    echo '    <small>Generate credentials in Rule34.xxx account options under API Access Credentials.</small>';
    echo '  </div>';
    echo '  <button type="submit" class="button">Start Scraper</button>';
    echo '</form>';
    echo '<script>(() => { const source = document.getElementById("scraper-source"); const blacklist = document.getElementById("e621-blacklist"); const credentials = document.getElementById("rule34-credentials"); const update = () => { blacklist.hidden = source.value !== "e621"; credentials.hidden = source.value !== "rule34"; }; source.addEventListener("change", update); update(); })();</script>';
    echo '</div>';

    echo '<div class="form-container">';
    echo '<h2>Tags fetcher</h2>';
    echo '<p>Rechecks every imported Realbooru post and adds any missing source tags, including yellow model tags. Existing tags are kept.</p>';
    echo '<form method="post" action="' . View::url('/scraper') . '">';
    echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
    echo '  <input type="hidden" name="action" value="fetch_tags">';
    echo '  <button type="submit" class="button" onclick="return confirm(\'Recheck tags for every imported Realbooru post?\')">Start Tags Fetcher</button>';
    echo '</form>';
    echo '</div>';

    echo '<h2>Recent Tasks</h2>';
    if ($tasks) {
        echo '<form method="post" action="' . View::url('/scraper') . '" style="margin-bottom: 10px;">';
        echo '  <input type="hidden" name="csrf_token" value="' . View::e(View::csrfToken()) . '">';
        echo '  <input type="hidden" name="action" value="clean">';
        echo '  <button type="submit" class="button">Clean Completed</button>';
        echo '</form>';

        echo '<table class="data-table">';
        echo '<tr><th>ID</th><th>Source</th><th>Tag</th><th>Blacklist</th><th>PID</th><th>Status</th><th>Started</th><th>Action</th></tr>';
        foreach ($tasks as $t) {
            $statusColor = $t['status'] === 'running' ? 'color: orange;' : 'color: green;';
            echo '<tr>';
            echo '<td>' . $t['id'] . '</td>';
            $taskSource = match ($t['source'] ?? 'realbooru') {
                'e621' => 'e621',
                'rule34' => 'Rule34.xxx',
                default => 'Realbooru',
            };
            echo '<td>' . View::e($taskSource) . '</td>';
            echo '<td>' . View::e($t['tag']) . '</td>';
            echo '<td>' . View::e($t['blacklist'] ?? '') . '</td>';
            echo '<td>' . $t['pid'] . '</td>';
            echo '<td style="font-weight:bold; ' . $statusColor . '">' . View::e($t['status']) . '</td>';
            echo '<td>' . date('Y-m-d H:i:s', $t['created_at']) . '</td>';
            echo '<td><a href="' . View::url('/scraper', ['log_id' => $t['id']]) . '">View Log</a>';
            if ($t['status'] === 'running') {
                echo ' <form method="post" action="' . View::url('/scraper') . '" style="display:inline">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="restart">';
                echo '<input type="hidden" name="task_id" value="' . (int)$t['id'] . '">';
                echo '<button type="submit" onclick="return confirm(\'Restart this scraper from its saved progress?\')">Restart</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No scraper tasks found.</p>';
    }

    if (isset($_GET['log_id'])) {
        $logId = (int)$_GET['log_id'];
        $logFile = __DIR__ . '/data/scraper_' . $logId . '.log';
        echo '<h2 id="log">Log for Task #' . $logId . ' <a href="' . View::url('/scraper') . '">(Close)</a></h2>';
        echo '<div style="background: #111; color: #ccc; padding: 10px; border-radius: 5px; height: 400px; overflow-y: auto; font-family: monospace; white-space: pre-wrap;">';
        if (file_exists($logFile)) {
            echo View::e(file_get_contents($logFile));
        } else {
            echo 'Log file not found or empty.';
        }
        echo '</div>';


        echo '<script>
            var logDiv = document.querySelector("#log").nextElementSibling;
            logDiv.scrollTop = logDiv.scrollHeight;
            location.hash = "#log";
        </script>';
    }

    View::footer();
}

function renderActivityStatistics(bool $browsing = false): void
{
    $activity = $browsing ? Activity::browsingStatistics() : Activity::statistics();
    $unit = $browsing ? 'unique IP addresses' : 'unique users';
    $graphId = $browsing ? 'browsing' : 'login';
    $description = $browsing ? 'Unique IP addresses browsing the post list or individual posts' : 'Unique users with successful logins';
    echo '<h3>' . ($browsing ? 'Post browsing activity' : 'Login activity') . '</h3><div class="activity-counts">';
    foreach (['today' => 'Today', 'last_12h' => 'Last 12 hours', 'last_6h' => 'Last 6 hours', 'last_1h' => 'Last hour'] as $key => $label) {
        echo '<div class="activity-count"><span>' . $label . '</span><strong>' . $activity['counts'][$key] . '</strong><small>' . $unit . '</small></div>';
    }
    echo '</div><h3>Activity graph</h3>';
    echo '<p class="activity-note">' . $description . ' in each hour of the last 24 hours. Today starts at midnight in ' . View::e(ACTIVITY_TIMEZONE) . '. Recording starts when this feature is enabled.</p>';
    $max = max(1, max(array_column($activity['hours'], 'users')));
    echo '<div class="activity-chart"><svg viewBox="0 0 960 240" role="img" aria-labelledby="' . $graphId . '-graph-title ' . $graphId . '-graph-description">';
    echo '<title id="' . $graphId . '-graph-title">' . $description . ' during the last 24 hours</title><desc id="' . $graphId . '-graph-description">Exact hourly counts and times are available in the table below.</desc>';
    echo '<line x1="40" y1="200" x2="952" y2="200" class="activity-grid" />';
    echo '<line x1="40" y1="30" x2="952" y2="30" class="activity-grid" />';
    echo '<text x="30" y="204" text-anchor="end">0</text><text x="30" y="34" text-anchor="end">' . $max . '</text>';
    foreach ($activity['hours'] as $i => $hour) {
        $x = 44 + $i * 38;
        $height = round($hour['users'] / $max * 170, 2);
        echo '<rect class="activity-bar" x="' . $x . '" y="' . (200 - $height) . '" width="28" height="' . $height . '" rx="3"><title>' . View::e($hour['label']) . ': ' . $hour['users'] . ' ' . $unit . '</title></rect>';
        if ($hour['users'] > 0) echo '<text x="' . ($x + 14) . '" y="' . (192 - $height) . '" text-anchor="middle">' . $hour['users'] . '</text>';
    }
    echo '<text x="44" y="228">24 hours ago</text><text x="500" y="228" text-anchor="middle">12 hours ago</text><text x="952" y="228" text-anchor="end">Now</text></svg></div>';
    if (array_sum(array_column($activity['hours'], 'users')) === 0) echo '<p class="activity-note">' . ($browsing ? 'No post browsing' : 'No successful logins') . ' in the last 24 hours.</p>';
    echo '<details class="activity-hourly"><summary>Hourly details</summary><div style="overflow-x:auto"><table><thead><tr><th>Time (' . View::e(ACTIVITY_TIMEZONE) . ')</th><th>' . ucfirst($unit) . '</th></tr></thead><tbody>';
    foreach ($activity['hours'] as $hour) echo '<tr><td>' . View::e($hour['label']) . '</td><td>' . $hour['users'] . '</td></tr>';
    echo '</tbody></table></div></details>';
}

function page_admin(?array $user, string $method): void
{
    if (!Auth::can('access_admin_panel', $user) && !Auth::can('manage_post_reports', $user)
        && !Auth::can('manage_database_backups', $user) && !Auth::can('manage_scraper', $user)) {
        Router::redirect('/');
    }
    $permissionCatalog = Auth::permissionCatalog();

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';
        $permissionByAction = [
            'delete_user' => 'manage_users',
            'regen_api' => 'manage_users',
            'reset_password' => 'manage_users',
            'set_role' => 'manage_roles',
            'create_role' => 'manage_roles',
            'update_role' => 'manage_roles',
            'delete_role' => 'manage_roles',
            'site_settings' => 'manage_site_settings',
            'global_tag_blacklist' => 'manage_site_settings',
            'webhook_settings' => 'manage_site_settings',
            'test_discord_webhook' => 'manage_site_settings',
            'storage_settings' => 'manage_storage_settings',
            'registrations_settings' => 'manage_registration_settings',
            'approve_registration_request' => 'manage_registration_requests',
            'decline_registration_request' => 'manage_registration_requests',
            'resolve_post_report' => 'manage_post_reports',
            'dismiss_post_report' => 'manage_post_reports',
            'backup_settings' => 'manage_database_backups',
            'create_database_backup' => 'manage_database_backups',
            'restore_database_backup' => 'manage_database_backups',
        ];
        if (isset($permissionByAction[$action]) && !Auth::can($permissionByAction[$action], $user)) {
            View::setFlash('You do not have permission to perform this action.', 'error');
            Router::redirect('/admin');
        }

        if ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['id']) {
                DB::exec('DELETE FROM users WHERE id = ?', [$uid]);
                View::setFlash('User deleted.', 'ok');
            }
        } elseif ($action === 'set_role') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = trim((string)($_POST['role'] ?? ''));
            $roleExists = (bool)DB::scalar('SELECT id FROM roles WHERE slug = ?', [$role]);
            if ($uid === (int)$user['id'] && $role !== $user['role']) {
                View::setFlash('You cannot change your own role.', 'error');
            } elseif (!$uid || !$roleExists) {
                View::setFlash('Invalid user or role.', 'error');
            } else {
                DB::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $uid]);
                View::setFlash('Role updated.', 'ok');
            }
        } elseif ($action === 'create_role') {
            $name = trim((string)($_POST['role_name'] ?? ''));
            $permissions = array_values(array_intersect(array_keys($permissionCatalog), array_map('strval', (array)($_POST['permissions'] ?? []))));
            $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
            if (strlen($name) < 2 || strlen($name) > 40) {
                View::setFlash('Role name must contain between 2 and 40 characters.', 'error');
            } else {
                if ($slug === '') $slug = 'role-' . bin2hex(random_bytes(4));
                try {
                    DB::exec(
                        'INSERT INTO roles (name, slug, permissions) VALUES (?, ?, ?)',
                        [$name, $slug, json_encode($permissions, JSON_THROW_ON_ERROR)]
                    );
                    View::setFlash('Role created.', 'ok');
                } catch (PDOException $e) {
                    View::setFlash('A role with this name already exists.', 'error');
                }
            }
        } elseif ($action === 'update_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $name = trim((string)($_POST['role_name'] ?? ''));
            $permissions = array_values(array_intersect(array_keys($permissionCatalog), array_map('strval', (array)($_POST['permissions'] ?? []))));
            $role = DB::row('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role || (int)$role['is_system'] === 1) {
                View::setFlash('System roles cannot be changed.', 'error');
            } elseif ($role['slug'] === $user['role']) {
                View::setFlash('You cannot change the role currently assigned to your account.', 'error');
            } elseif (strlen($name) < 2 || strlen($name) > 40) {
                View::setFlash('Role name must contain between 2 and 40 characters.', 'error');
            } else {
                try {
                    DB::exec('UPDATE roles SET name = ?, permissions = ? WHERE id = ?', [
                        $name,
                        json_encode($permissions, JSON_THROW_ON_ERROR),
                        $roleId,
                    ]);
                    View::setFlash('Role updated.', 'ok');
                } catch (PDOException $e) {
                    View::setFlash('A role with this name already exists.', 'error');
                }
            }
        } elseif ($action === 'delete_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $role = DB::row('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role || (int)$role['is_system'] === 1) {
                View::setFlash('System roles cannot be deleted.', 'error');
            } elseif ($role['slug'] === $user['role']) {
                View::setFlash('You cannot delete the role currently assigned to your account.', 'error');
            } else {
                $pdo = DB::get();
                $pdo->beginTransaction();
                try {
                    DB::exec("UPDATE users SET role = 'user' WHERE role = ?", [$role['slug']]);
                    DB::exec('DELETE FROM roles WHERE id = ?', [$roleId]);
                    $pdo->commit();
                    View::setFlash('Role deleted. Its users were moved to the User role.', 'ok');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    View::setFlash('Could not delete the role.', 'error');
                }
            }
        } elseif ($action === 'regen_api') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $key = bin2hex(random_bytes(16));
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', [$key, $uid]);
            View::setFlash('API key regenerated.', 'ok');
        } elseif ($action === 'reset_password') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $target = $uid > 0 ? DB::row('SELECT id, name FROM users WHERE id = ?', [$uid]) : null;
            $temporaryPassword = $target ? Auth::resetPassword($uid) : null;
            if ($target && $temporaryPassword !== null) {
                $_SESSION['admin_password_reset'] = [
                    'username' => (string)$target['name'],
                    'password' => $temporaryPassword,
                ];
                View::setFlash('Password reset. Copy the temporary password shown below.', 'ok');
            } else {
                View::setFlash('User not found. Password was not changed.', 'error');
            }
        } elseif (in_array($action, ['webhook_settings', 'test_discord_webhook'], true)) {
            $submittedUrl = trim((string)($_POST['discord_webhook_url'] ?? ''));
            $clearWebhook = isset($_POST['clear_discord_webhook']);
            $enabled = isset($_POST['discord_webhook_enabled']) ? '1' : '0';
            $savedUrl = View::siteSetting('discord_webhook_url', '');

            if ($clearWebhook) {
                View::setSiteSetting('discord_webhook_url', '');
                View::setSiteSetting('discord_webhook_enabled', '0');
                View::setFlash('Discord webhook removed.', 'ok');
            } elseif ($submittedUrl !== '' && !DiscordWebhook::isValidUrl($submittedUrl)) {
                View::setFlash('Enter a valid Discord webhook URL.', 'error');
            } else {
                if ($submittedUrl !== '') {
                    View::setSiteSetting('discord_webhook_url', $submittedUrl);
                    $savedUrl = $submittedUrl;
                }
                if ($enabled === '1' && !DiscordWebhook::isValidUrl($savedUrl)) {
                    View::setFlash('Add a valid Discord webhook URL before enabling notifications.', 'error');
                } else {
                    View::setSiteSetting('discord_webhook_enabled', $enabled);
                    View::setSiteSetting('webhook_site_url', rtrim(View::absoluteUrl(View::url('/')), '/'));
                    if ($action === 'test_discord_webhook') {
                        $testSent = DiscordWebhook::sendTest();
                        View::setFlash(
                            $testSent ? 'Test message sent to Discord.' : 'Discord rejected the test message.',
                            $testSent ? 'ok' : 'error'
                        );
                    } else {
                        View::setFlash('Webhook settings saved.', 'ok');
                    }
                }
            }
        } elseif ($action === 'global_tag_blacklist') {
            try {
                Post::saveGlobalBlockedRules((string)($_POST['global_tag_blacklist'] ?? ''));
                $removed = isset($_POST['purge_matching_posts']) ? Post::purgeGloballyBlockedPosts() : 0;
                View::setFlash(
                    'Global tag rules saved.' . ($removed > 0 ? " Removed {$removed} matching post(s)." : ''),
                    'ok'
                );
            } catch (Throwable $e) {
                View::setFlash('Could not save global tag rules: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'site_settings') {
            $discordUrl = trim((string)($_POST['discord_url'] ?? ''));
            if ($discordUrl !== '' && (strlen($discordUrl) > 2048 || !filter_var($discordUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($discordUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                View::setFlash('Discord URL must be a valid HTTP or HTTPS address.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            $name    = trim($_POST['site_name'] ?? '');
            $description = trim($_POST['site_description'] ?? '');
            $default = trim($_POST['default_blacklist'] ?? '');
            $terms   = trim($_POST['terms_of_service'] ?? '');
            if ($name !== '') View::setSiteSetting('site_name', $name);
            View::setSiteSetting('site_description', $description);
            View::setSiteSetting('discord_url', $discordUrl);
            foreach (['site_logo_upload' => 'site_logo', 'site_banner_upload' => 'site_banner', 'home_header_upload' => 'home_header_image'] as $field => $setting) {
                $oldImage = View::siteSetting($setting);
                if (isset($_POST['clear_' . $setting])) {
                    View::setSiteSetting($setting, '');
                    deleteLocalSiteImage($oldImage);
                } else {
                    $newImage = saveSiteImageUpload($field);
                    if ($newImage !== null) {
                        View::setSiteSetting($setting, $newImage);
                        deleteLocalSiteImage($oldImage);
                    }
                }
            }
            View::setSiteSetting('default_blacklist', $default);
            View::setSiteSetting('terms_of_service', $terms);
            View::setFlash('Site settings saved.', 'ok');
        } elseif ($action === 'storage_settings') {
            $driver    = in_array($_POST['storage_driver'] ?? '', ['local', 's3'], true) ? $_POST['storage_driver'] : 'local';
            $endpoint  = trim($_POST['s3_endpoint'] ?? '');
            $region    = trim($_POST['s3_region'] ?? 'us-east-1');
            $bucket    = trim($_POST['s3_bucket'] ?? '');
            $accessKey = trim($_POST['s3_access_key'] ?? '');
            $secretKey = trim($_POST['s3_secret_key'] ?? '');

            View::setSiteSetting('storage_driver', $driver);
            View::setSiteSetting('s3_endpoint',   $endpoint);
            View::setSiteSetting('s3_region',     $region);
            View::setSiteSetting('s3_bucket',     $bucket);
            View::setSiteSetting('s3_access_key', $accessKey);
            View::setSiteSetting('s3_secret_key', $secretKey);
            View::setFlash('Storage settings saved.', 'ok');
        } elseif ($action === 'backup_settings') {
            $endpoint = trim((string)($_POST['backup_s3_endpoint'] ?? ''));
            $region = trim((string)($_POST['backup_s3_region'] ?? 'us-east-1'));
            $bucket = trim((string)($_POST['backup_s3_bucket'] ?? ''));
            $accessKey = trim((string)($_POST['backup_s3_access_key'] ?? ''));
            $secretKey = trim((string)($_POST['backup_s3_secret_key'] ?? ''));
            View::setSiteSetting('backup_s3_endpoint', $endpoint);
            View::setSiteSetting('backup_s3_region', $region ?: 'us-east-1');
            View::setSiteSetting('backup_s3_bucket', $bucket);
            View::setSiteSetting('backup_s3_access_key', $accessKey);
            if ($secretKey !== '') View::setSiteSetting('backup_s3_secret_key', $secretKey);
            View::setFlash('Database backup settings saved.', 'ok');
        } elseif ($action === 'create_database_backup') {
            try {
                $backup = DatabaseBackup::create();
                View::setFlash('Database backup created: ' . $backup['key'], 'ok');
            } catch (Throwable $e) {
                View::setFlash('Could not create database backup: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'restore_database_backup') {
            try {
                $result = DatabaseBackup::restore((string)($_POST['backup_key'] ?? ''));
                View::setFlash(
                    'Database restored. A pre-rollback S3 backup and local emergency copy were created at '
                    . $result['local_emergency_path'] . '.',
                    'ok'
                );
            } catch (Throwable $e) {
                View::setFlash('Could not restore database backup: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'registrations_settings') {
            $disableReg = isset($_POST['disable_registrations']) ? '1' : '0';
            $requireRegistrationReason = isset($_POST['require_registration_reason']) ? '1' : '0';
            $registrationRequiresApproval = isset($_POST['registration_requires_approval']) ? '1' : '0';
            $requireLogin = isset($_POST['require_login_posts']) ? '1' : '0';
            $enableAdultWarning = isset($_POST['enable_adult_warning']) ? '1' : '0';
            $showUserFavorites = isset($_POST['show_user_favorites']) ? '1' : '0';
            $enableRegistrationCaptcha = isset($_POST['enable_registration_captcha']) ? '1' : '0';
            $turnstileSiteKey = trim($_POST['turnstile_site_key'] ?? '');
            $turnstileSecretKey = trim($_POST['turnstile_secret_key'] ?? '');
            $savedTurnstileSecretKey = $turnstileSecretKey !== ''
                ? $turnstileSecretKey
                : View::siteSetting('turnstile_secret_key', '');
            View::setSiteSetting('disable_registrations', $disableReg);
            View::setSiteSetting('require_registration_reason', $requireRegistrationReason);
            View::setSiteSetting('registration_requires_approval', $registrationRequiresApproval);
            View::setSiteSetting('require_login_posts', $requireLogin);
            View::setSiteSetting('enable_adult_warning', $enableAdultWarning);
            View::setSiteSetting('show_user_favorites', $showUserFavorites);
            View::setSiteSetting('turnstile_site_key', $turnstileSiteKey);
            if ($turnstileSecretKey !== '') View::setSiteSetting('turnstile_secret_key', $turnstileSecretKey);
            if ($enableRegistrationCaptcha === '1' && ($turnstileSiteKey === '' || $savedTurnstileSecretKey === '')) {
                View::setSiteSetting('enable_registration_captcha', '0');
                View::setFlash('Registration CAPTCHA was not enabled. Enter both Turnstile keys.', 'error');
            } else {
                View::setSiteSetting('enable_registration_captcha', $enableRegistrationCaptcha);
                View::setFlash('Registrations & Content settings saved.', 'ok');
            }
        } elseif (in_array($action, ['resolve_post_report', 'dismiss_post_report'], true)) {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $status = $action === 'resolve_post_report' ? 'resolved' : 'dismissed';
            $updated = $reportId > 0 ? DB::exec(
                "UPDATE post_reports SET status = ?, resolved_by = ?, resolved_at = unixepoch()
                 WHERE id = ? AND status = 'pending'",
                [$status, (int)$user['id'], $reportId]
            ) : 0;
            View::setFlash($updated ? 'Post report ' . $status . '.' : 'Report was not found or was already reviewed.', $updated ? 'ok' : 'error');
        } elseif ($action === 'approve_registration_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $approved = Auth::approveRegistrationRequest($requestId);
            View::setFlash($approved ? 'Registration request approved.' : 'Could not approve this registration request.', $approved ? 'ok' : 'error');
        } elseif ($action === 'decline_registration_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            View::setFlash(Auth::declineRegistrationRequest($requestId) ? 'Registration request declined.' : 'Registration request was not found.', 'ok');
        }

        $sectionByAction = [
            'site_settings' => 'site-settings',
            'global_tag_blacklist' => 'banned-tags',
            'webhook_settings' => 'webhooks',
            'test_discord_webhook' => 'webhooks',
            'storage_settings' => 'media-storage',
            'registrations_settings' => 'registrations-content',
            'resolve_post_report' => 'post-reports',
            'dismiss_post_report' => 'post-reports',
            'backup_settings' => 'database-backups',
            'create_database_backup' => 'database-backups',
            'restore_database_backup' => 'database-backups',
            'approve_registration_request' => 'registration-requests',
            'decline_registration_request' => 'registration-requests',
            'delete_user' => 'users',
            'set_role' => 'users',
            'regen_api' => 'users',
            'reset_password' => 'users',
            'create_role' => 'roles',
            'update_role' => 'roles',
            'delete_role' => 'roles',
        ];
        $redirectParams = isset($sectionByAction[$action]) ? ['open' => $sectionByAction[$action]] : [];
        Router::redirect('/admin', $redirectParams);
    }

    $openSection = $_GET['open'] ?? '';
    $roles = DB::rows(
        'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) AS user_count
         FROM roles r ORDER BY r.is_system DESC, r.name COLLATE NOCASE'
    );
    $roleNames = array_column($roles, 'name', 'slug');
    $users        = DB::rows('SELECT id, name, email, registration_reason, role, api_key, created_at, country_code FROM users ORDER BY id DESC');
    $passwordResetResult = is_array($_SESSION['admin_password_reset'] ?? null)
        ? $_SESSION['admin_password_reset']
        : null;
    unset($_SESSION['admin_password_reset']);
    $registrationRequests = DB::rows('SELECT id, name, email, registration_reason, created_at FROM registration_requests ORDER BY created_at ASC');
    $postReports = [];
    $pendingPostReportCount = 0;
    if (Auth::can('manage_post_reports', $user)) {
        $postReports = DB::rows(
            "SELECT pr.*, reporter.name AS reporter_name, resolver.name AS resolver_name
             FROM post_reports pr
             LEFT JOIN users reporter ON reporter.id = pr.reporter_user_id
             LEFT JOIN users resolver ON resolver.id = pr.resolved_by
             ORDER BY CASE pr.status WHEN 'pending' THEN 0 ELSE 1 END, pr.created_at DESC"
        );
        $pendingPostReportCount = (int)DB::scalar("SELECT COUNT(*) FROM post_reports WHERE status = 'pending'");
    }
    $postCount    = (int)DB::scalar('SELECT COUNT(*) FROM posts');
    $tagCount     = (int)DB::scalar('SELECT COUNT(*) FROM tags');
    $commentCount = (int)DB::scalar('SELECT COUNT(*) FROM comments');

    $curName             = View::siteSetting('site_name', SITE_NAME);
    $curDescription      = View::siteSetting('site_description', '');
    $curDiscordUrl       = View::siteSetting('discord_url', '');
    $curLogo             = View::siteSetting('site_logo');
    $curBanner           = View::siteSetting('site_banner');
    $curHomeHeaderImage  = View::siteSetting('home_header_image');
    $curDefaultBlacklist = View::siteSetting('default_blacklist', '');
    $curGlobalTagBlacklist = View::siteSetting('global_tag_blacklist', '');
    $globallyBlockedPostCount = count(Post::globallyBlockedPostIds());
    $curTermsOfService   = View::siteSetting('terms_of_service', '');
    $discordWebhookConfigured = DiscordWebhook::isValidUrl(View::siteSetting('discord_webhook_url', ''));
    $discordWebhookEnabled = View::siteSetting('discord_webhook_enabled', '0') === '1';

    $curStorageDriver = View::siteSetting('storage_driver', 'local');
    $curS3Endpoint    = View::siteSetting('s3_endpoint', '');
    $curS3Region      = View::siteSetting('s3_region', 'us-east-1');
    $curS3Bucket      = View::siteSetting('s3_bucket', '');
    $curS3AccessKey   = View::siteSetting('s3_access_key', '');
    $curS3SecretKey   = View::siteSetting('s3_secret_key', '');
    $curBackupS3Endpoint = View::siteSetting('backup_s3_endpoint', '');
    $curBackupS3Region = View::siteSetting('backup_s3_region', 'us-east-1');
    $curBackupS3Bucket = View::siteSetting('backup_s3_bucket', '');
    $curBackupS3AccessKey = View::siteSetting('backup_s3_access_key', '');
    $backupS3SecretConfigured = View::siteSetting('backup_s3_secret_key', '') !== '';
    $databaseBackups = [];
    $databaseBackupListError = '';
    if (Auth::can('manage_database_backups', $user) && DatabaseBackup::isConfigured()) {
        try {
            $databaseBackups = DatabaseBackup::list();
        } catch (Throwable $e) {
            $databaseBackupListError = $e->getMessage();
        }
    }


    View::header('Panel', $user);
    View::flash();
    renderAdminTabs($user, 'panel');


    if (Auth::can('access_admin_panel', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'statistics' ? ' open' : '') . '>';
    echo '<summary>Statistics</summary>';
    echo '<div class="admin-section-content">';
    echo '<table>';
    echo '<tbody>';
    echo '<tr><th style="text-align:left; padding:8px;">Posts</th><td style="padding:8px;">' . $postCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Tags</th><td style="padding:8px;">' . $tagCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Comments</th><td style="padding:8px;">' . $commentCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Users</th><td style="padding:8px;">' . count($users) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    renderActivityStatistics();
    renderActivityStatistics(true);
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'banned-tags' ? ' open' : '') . '>';
    echo '<summary>Banned Tags <span class="admin-section-count">' . count(Post::globalBlockedRules()) . ' rules</span></summary>';
    echo '<div class="admin-section-content">';
    echo '<p>These rules reject posts across every uploader and scraper. Put one rule per line. Multiple tags on one line mean that all of them must occur together.</p>';
    echo '<form method="post" style="max-width:600px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="global_tag_blacklist">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Global banned tag rules</span><textarea name="global_tag_blacklist" rows="8" style="width:100%" placeholder="child&#10;young_anthro&#10;animal human&#10;anthro human">' . View::e($curGlobalTagBlacklist) . '</textarea></label>';
    echo '<label style="display:flex; align-items:flex-start; gap:8px;"><input type="checkbox" name="purge_matching_posts" value="1" checked> <span>Delete posts already matching these rules, including their media files (' . $globallyBlockedPostCount . ' currently match)</span></label>';
    echo '<button style="align-self:flex-start;">Save Banned Tags</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'site-settings' ? ' open' : '') . '>';
    echo '<summary>Site Settings</summary>';
    echo '<div class="admin-section-content">';
    echo '<p style="color:var(--muted-text);font-size:12px">All site images are saved in local storage.</p>';
    echo '<form method="post" enctype="multipart/form-data" style="max-width:500px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="site_settings">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Site name</span><input name="site_name" value="' . View::e($curName) . '" style="width:100%"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Discord URL <small>(leave empty to hide the homepage link)</small></span><input type="url" name="discord_url" value="' . View::e($curDiscordUrl) . '" maxlength="2048" placeholder="https://discord.gg/your-invite" style="width:100%"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>SEO description <small>(used by search engines and Discord)</small></span><textarea name="site_description" rows="3" maxlength="200" style="width:100%" placeholder="Describe the site in one concise sentence…">' . View::e($curDescription) . '</textarea></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Navbar logo <small>(saved locally)</small></span><input type="file" name="site_logo_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curLogo) echo '<label><input type="checkbox" name="clear_site_logo"> Remove current navbar logo</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Navbar banner <small>(saved locally; used when no logo is set)</small></span><input type="file" name="site_banner_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curBanner) echo '<label><input type="checkbox" name="clear_site_banner"> Remove current navbar banner</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Homepage header <small>(shown on / instead of the site-name text; saved locally)</small></span><input type="file" name="home_header_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curHomeHeaderImage) echo '<label><input type="checkbox" name="clear_home_header_image"> Remove current homepage header</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Default Blacklist Tags for New Users <small>(space or line separated)</small></span><textarea name="default_blacklist" rows="2" style="width:100%" placeholder="e.g. nsfw gore">' . View::e($curDefaultBlacklist) . '</textarea></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Terms of Service <small>(shown at /terms)</small></span><textarea name="terms_of_service" rows="12" style="width:100%" placeholder="Write your Terms of Service…">' . View::e($curTermsOfService) . '</textarea></label>';
    echo '<button style="align-self:flex-start;">Save Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'webhooks' ? ' open' : '') . '>';
    echo '<summary>Webhooks</summary>';
    echo '<div class="admin-section-content">';
    echo '<form method="post" style="max-width:600px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Discord webhook URL</span><input type="password" name="discord_webhook_url" value="" autocomplete="new-password" placeholder="' . ($discordWebhookConfigured ? 'Configured — leave blank to keep it' : 'https://discord.com/api/webhooks/...') . '"></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;"><input type="checkbox" name="discord_webhook_enabled" value="1"' . ($discordWebhookEnabled ? ' checked' : '') . '> <span>Send a notification when a new post is created</span></label>';
    if ($discordWebhookConfigured) {
        echo '<label style="display:flex; align-items:center; gap:5px;"><input type="checkbox" name="clear_discord_webhook" value="1"> <span>Remove the saved webhook</span></label>';
    }
    echo '<small style="color:var(--text-muted)">Messages contain the post link and its tags. Discord mentions are disabled.</small>';
    echo '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
    echo '<button type="submit" name="action" value="webhook_settings">Save webhook</button>';
    echo '<button type="submit" name="action" value="test_discord_webhook">Send test</button>';
    echo '</div>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_storage_settings', $user)) {
    $isLocal = $curStorageDriver === 'local';
    $isS3    = $curStorageDriver === 's3';
    echo '<details class="admin-section"' . ($openSection === 'media-storage' ? ' open' : '') . '>';
    echo '<summary>Media Storage Settings</summary>';
    echo '<div class="admin-section-content">';

    $totalBytes = 0;
    if (file_exists(__DIR__ . '/data/uploads')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/data/uploads', FilesystemIterator::SKIP_DOTS)) as $file) { $totalBytes += $file->getSize(); }
    }
    if (file_exists(__DIR__ . '/data/thumbs')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/data/thumbs', FilesystemIterator::SKIP_DOTS)) as $file) { $totalBytes += $file->getSize(); }
    }
    $gbTaken = number_format($totalBytes / (1024 * 1024 * 1024), 2);


    $s3BytesEstimate = (int)DB::scalar('SELECT SUM(filesize) FROM posts') + ($postCount * 51200);
    $s3GbTaken = number_format($s3BytesEstimate / (1024 * 1024 * 1024), 2);

    echo '<div style="display:flex; gap:20px; margin-bottom:15px; flex-wrap:wrap;">';
    echo '<p style="color:var(--muted-text);font-size:14px;margin:0;">Local Storage taken: <strong>' . $gbTaken . ' GB</strong></p>';
    echo '<p style="color:var(--muted-text);font-size:14px;margin:0;">S3 Storage taken (est.): <strong>' . $s3GbTaken . ' GB</strong></p>';
    echo '</div>';

    echo '<form method="post" style="max-width:500px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="storage_settings">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Storage Method</span>';
    echo '<select name="storage_driver" style="width:100%" onchange="document.getElementById(\'s3-fields\').style.display = this.value === \'s3\' ? \'flex\' : \'none\';">';
    echo '<option value="local"' . ($isLocal ? ' selected' : '') . '>Local (data/uploads, data/thumbs)</option>';
    echo '<option value="s3"' . ($isS3 ? ' selected' : '') . '>S3 (Amazon S3, MinIO, R2, Wasabi, etc.)</option>';
    echo '</select></label>';

    echo '<div id="s3-fields" style="display:' . ($isS3 ? 'flex' : 'none') . '; flex-direction:column; gap:15px; border: 1px solid var(--border); padding: 15px; border-radius: 4px;">';
    echo '<h3 style="margin:0;font-size:15px">S3 Configuration</h3>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Endpoint <small>(e.g. https://s3.amazonaws.com)</small></span><input name="s3_endpoint" value="' . View::e($curS3Endpoint) . '" style="width:100%" placeholder="https://s3.us-east-1.amazonaws.com"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Bucket Name</span><input name="s3_bucket" value="' . View::e($curS3Bucket) . '" style="width:100%" placeholder="my-libooru-bucket"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Region <small>(default: us-east-1)</small></span><input name="s3_region" value="' . View::e($curS3Region) . '" style="width:100%" placeholder="us-east-1"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Access Key</span><input name="s3_access_key" value="' . View::e($curS3AccessKey) . '" style="width:100%" placeholder="AKIA..."></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Secret Key</span><input type="password" name="s3_secret_key" value="' . View::e($curS3SecretKey) . '" style="width:100%"></label>';
    echo '</div>';

    echo '<button style="align-self:flex-start;">Save Storage Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_database_backups', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'database-backups' ? ' open' : '') . '>';
        echo '<summary>Database backups <span class="admin-section-count">' . count($databaseBackups) . '</span></summary>';
        echo '<div class="admin-section-content">';
        echo '<p style="color:var(--text-muted)">Backups contain the SQLite database only. Media files are not included.</p>';
        echo '<h3>Backup S3 settings</h3>';
        echo '<form method="post" style="max-width:500px;display:flex;flex-direction:column;gap:15px">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="backup_settings">';
        echo '<label><span>Endpoint</span><input name="backup_s3_endpoint" value="' . View::e($curBackupS3Endpoint) . '" placeholder="https://s3.amazonaws.com"></label>';
        echo '<label><span>Region</span><input name="backup_s3_region" value="' . View::e($curBackupS3Region) . '" placeholder="us-east-1"></label>';
        echo '<label><span>Backup bucket</span><input name="backup_s3_bucket" value="' . View::e($curBackupS3Bucket) . '"></label>';
        echo '<label><span>Access key</span><input name="backup_s3_access_key" value="' . View::e($curBackupS3AccessKey) . '" autocomplete="off"></label>';
        echo '<label><span>Secret key</span><input type="password" name="backup_s3_secret_key" value="" autocomplete="new-password" placeholder="' . ($backupS3SecretConfigured ? 'Configured — leave blank to keep it' : 'Enter secret key') . '"></label>';
        echo '<button style="align-self:flex-start">Save backup settings</button>';
        echo '</form>';

        if (DatabaseBackup::isConfigured()) {
            echo '<form method="post" style="margin:24px 0">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="create_database_backup">';
            echo '<button>Create backup now</button>';
            echo '</form>';

            if ($databaseBackupListError !== '') {
                echo '<p class="flash error">Could not list backups: ' . View::e($databaseBackupListError) . '</p>';
            } elseif (!$databaseBackups) {
                echo '<p>No backups found in the configured bucket.</p>';
            } else {
                echo '<div style="overflow-x:auto"><table style="width:100%">';
                echo '<thead><tr><th>Backup</th><th>Created</th><th>Size</th><th>Action</th></tr></thead><tbody>';
                foreach ($databaseBackups as $backupObject) {
                    $modified = strtotime($backupObject['last_modified']);
                    echo '<tr>';
                    echo '<td><code>' . View::e($backupObject['key']) . '</code></td>';
                    echo '<td>' . View::e($modified ? date('Y-m-d H:i:s', $modified) : $backupObject['last_modified']) . '</td>';
                    echo '<td>' . View::e(number_format($backupObject['size'] / 1048576, 2)) . ' MB</td>';
                    echo '<td><form method="post" onsubmit="return confirm(\'Rollback the live database to this backup? A pre-rollback backup will be created first.\')">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="restore_database_backup">';
                    echo '<input type="hidden" name="backup_key" value="' . View::e($backupObject['key']) . '">';
                    echo '<button>Rollback to this backup</button>';
                    echo '</form></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        } else {
            echo '<p>Save a complete backup S3 configuration to create and restore backups.</p>';
        }

        echo '</div></details>';
    }


    if (Auth::can('manage_registration_settings', $user)) {
    $curDisableReg = View::siteSetting('disable_registrations', '0');
    $curRequireRegistrationReason = View::siteSetting('require_registration_reason', '0');
    $curRegistrationRequiresApproval = View::siteSetting('registration_requires_approval', '0');
    $curRequireLogin = View::siteSetting('require_login_posts', '0');
    $curEnableAdultWarning = View::siteSetting('enable_adult_warning', '0');
    $curShowUserFavorites = View::siteSetting('show_user_favorites', '0');
    $curEnableRegistrationCaptcha = View::siteSetting('enable_registration_captcha', '0');
    $curTurnstileSiteKey = View::siteSetting('turnstile_site_key', '');
    $hasTurnstileSecretKey = View::siteSetting('turnstile_secret_key', '') !== '';
    echo '<details class="admin-section"' . ($openSection === 'registrations-content' ? ' open' : '') . '>';
    echo '<summary>Registrations &amp; Content</summary>';
    echo '<div class="admin-section-content">';
    echo '<form method="post" style="max-width:500px; display:flex; flex-direction:column; gap:15px; margin-bottom: 20px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="registrations_settings">';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="disable_registrations" value="1"' . ($curDisableReg === '1' ? ' checked' : '') . '> ';
    echo '<span>Turn off registrations</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="require_registration_reason" value="1"' . ($curRequireRegistrationReason === '1' ? ' checked' : '') . '> ';
    echo '<span>Require a reason for registration</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="registration_requires_approval" value="1"' . ($curRegistrationRequiresApproval === '1' ? ' checked' : '') . '> ';
    echo '<span>Require administrator approval for registrations</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="require_login_posts" value="1"' . ($curRequireLogin === '1' ? ' checked' : '') . '> ';
    echo '<span>Forbid viewing posts for logged out users</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="enable_adult_warning" value="1"' . ($curEnableAdultWarning === '1' ? ' checked' : '') . '> ';
    echo '<span>Enable 18+ warning</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="show_user_favorites" value="1"' . ($curShowUserFavorites === '1' ? ' checked' : '') . '> ';
    echo '<span>Let users see other users’ favorites</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="enable_registration_captcha" value="1" aria-controls="turnstile-fields" onchange="document.getElementById(\'turnstile-fields\').style.display = this.checked ? \'flex\' : \'none\';"' . ($curEnableRegistrationCaptcha === '1' ? ' checked' : '') . '> ';
    echo '<span>Enable registration captcha</span></label>';
    echo '<div id="turnstile-fields" style="display:' . ($curEnableRegistrationCaptcha === '1' ? 'flex' : 'none') . '; flex-direction:column; gap:15px; border:1px solid var(--border-color); padding:15px;">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Cloudflare Turnstile Site Key</span><input name="turnstile_site_key" value="' . View::e($curTurnstileSiteKey) . '" autocomplete="off"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Cloudflare Turnstile Secret Key</span><input type="password" name="turnstile_secret_key" value="" autocomplete="new-password" placeholder="' . ($hasTurnstileSecretKey ? 'Configured — leave blank to keep it' : 'Enter secret key') . '"></label>';
    echo '<small style="color:var(--text-muted)">Create a Turnstile widget in Cloudflare and enter its site key and secret key here.</small>';
    echo '</div>';
    echo '<button style="align-self:flex-start;">Save Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_post_reports', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'post-reports' ? ' open' : '') . '>';
        echo '<summary>Post reports <span class="admin-section-count">' . $pendingPostReportCount . ' pending</span></summary>';
        echo '<div class="admin-section-content">';
        if (!$postReports) {
            echo '<p>No post reports.</p>';
        } else {
            echo '<div style="overflow-x:auto"><table>';
            echo '<thead><tr><th>Post</th><th>Reporter</th><th>Reason</th><th>Reported</th><th>Status</th><th>Reviewed by</th><th>Actions</th></tr></thead><tbody>';
            foreach ($postReports as $report) {
                $resolvedBy = $report['resolver_name'] ?: '—';
                if ($report['resolved_at']) {
                    $resolvedBy .= ' (' . date('Y-m-d H:i', (int)$report['resolved_at']) . ')';
                }
                echo '<tr>';
                echo '<td><a href="' . View::url('/post/' . (int)$report['post_id']) . '">#' . (int)$report['post_id'] . '</a></td>';
                echo '<td>' . View::e($report['reporter_name'] ?: 'Deleted user') . '</td>';
                echo '<td style="min-width:240px">' . nl2br(View::e($report['reason'])) . '</td>';
                echo '<td>' . View::e(date('Y-m-d H:i', (int)$report['created_at'])) . '</td>';
                echo '<td>' . View::e(ucfirst($report['status'])) . '</td>';
                echo '<td>' . View::e($resolvedBy) . '</td>';
                echo '<td style="white-space:nowrap">';
                if ($report['status'] === 'pending') {
                    echo '<form method="post" style="display:inline">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="resolve_post_report"><input type="hidden" name="report_id" value="' . (int)$report['id'] . '"><button>Resolve</button></form> ';
                    echo '<form method="post" style="display:inline">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="dismiss_post_report"><input type="hidden" name="report_id" value="' . (int)$report['id'] . '"><button>Dismiss</button></form>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></details>';
    }


    if (Auth::can('manage_registration_requests', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'registration-requests' ? ' open' : '') . '>';
    echo '<summary>Registration requests <span class="admin-section-count">' . count($registrationRequests) . '</span></summary>';
    echo '<div class="admin-section-content">';
    if (!$registrationRequests) {
        echo '<p>No pending registration requests.</p>';
    } else {
        echo '<div style="overflow-x:auto"><table>';
        echo '<thead><tr><th>Username</th><th>Email</th><th>Registration reason</th><th>Requested</th><th>Actions</th></tr></thead><tbody>';
        foreach ($registrationRequests as $request) {
            echo '<tr><td>' . View::e($request['name']) . '</td>';
            echo '<td>' . View::e($request['email'] ?: '—') . '</td>';
            echo '<td>' . View::e($request['registration_reason'] ?: '—') . '</td>';
            echo '<td>' . View::e(date('Y-m-d H:i', (int)$request['created_at'])) . '</td><td>';
            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="approve_registration_request"><input type="hidden" name="request_id" value="' . View::e($request['id']) . '"><button>Approve</button></form> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Decline this registration request?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="decline_registration_request"><input type="hidden" name="request_id" value="' . View::e($request['id']) . '"><button>Decline</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></details>';
    }


    if (Auth::can('manage_roles', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'roles' ? ' open' : '') . '>';
        echo '<summary>Roles <span class="admin-section-count">' . count($roles) . '</span></summary>';
        echo '<div class="admin-section-content">';
        echo '<h3>Create role</h3>';
        echo '<form method="post" class="admin-role-editor">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="create_role">';
        echo '<label><span>Role name</span><input name="role_name" maxlength="40" required placeholder="e.g. Moderator"></label>';
        echo '<fieldset><legend>Permissions</legend><div class="admin-permissions">';
        foreach ($permissionCatalog as $permission => $label) {
            echo '<label><input type="checkbox" name="permissions[]" value="' . View::e($permission) . '"> <span>' . View::e($label) . '</span></label>';
        }
        echo '</div></fieldset>';
        echo '<button style="align-self:flex-start">Create Role</button>';
        echo '</form>';

        echo '<h3 style="margin-top:28px">Existing roles</h3>';
        foreach ($roles as $role) {
            $rolePermissions = json_decode((string)$role['permissions'], true);
            if (!is_array($rolePermissions)) $rolePermissions = [];
            echo '<div class="admin-role-card">';
            echo '<div class="admin-role-heading"><strong>' . View::e($role['name']) . '</strong> ';
            echo '<code>' . View::e($role['slug']) . '</code> ';
            echo '<span class="admin-section-count">' . (int)$role['user_count'] . ' users</span></div>';
            if ((int)$role['is_system'] === 1) {
                echo '<p class="admin-role-note">' . ($role['slug'] === 'admin' ? 'System role with all permissions.' : 'Default system role for regular users.') . '</p>';
            } elseif ($role['slug'] === $user['role']) {
                echo '<p class="admin-role-note">This role is assigned to your account, so you cannot edit or delete it yourself.</p>';
            } else {
                echo '<form method="post" class="admin-role-editor">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="update_role">';
                echo '<input type="hidden" name="role_id" value="' . (int)$role['id'] . '">';
                echo '<label><span>Role name</span><input name="role_name" maxlength="40" required value="' . View::e($role['name']) . '"></label>';
                echo '<fieldset><legend>Permissions</legend><div class="admin-permissions">';
                foreach ($permissionCatalog as $permission => $label) {
                    $checked = in_array($permission, $rolePermissions, true) ? ' checked' : '';
                    echo '<label><input type="checkbox" name="permissions[]" value="' . View::e($permission) . '"' . $checked . '> <span>' . View::e($label) . '</span></label>';
                }
                echo '</div></fieldset>';
                echo '<button style="align-self:flex-start">Save Role</button>';
                echo '</form>';
                echo '<form method="post" onsubmit="return confirm(\'Delete this role? Assigned users will become regular users.\')" style="margin-top:10px">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="delete_role">';
                echo '<input type="hidden" name="role_id" value="' . (int)$role['id'] . '">';
                echo '<button>Delete Role</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        echo '</div></details>';
    }


    if (Auth::can('manage_users', $user) || Auth::can('manage_roles', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'users' ? ' open' : '') . '>';
    echo '<summary>Users <span class="admin-section-count">' . count($users) . '</span></summary>';
    echo '<div class="admin-section-content">';
    if ($passwordResetResult !== null) {
        echo '<div class="admin-password-reset-result">';
        echo '<strong>Temporary password for ' . View::e((string)$passwordResetResult['username']) . '</strong>';
        echo '<p>Copy it now. It will not be shown again after leaving or refreshing this page.</p>';
        echo '<div><input id="admin-generated-password" type="text" readonly value="' . View::e((string)$passwordResetResult['password']) . '" autocomplete="off" spellcheck="false">';
        echo '<button type="button" id="admin-copy-password">Copy</button></div>';
        echo '</div>';
        echo '<script>(()=>{const input=document.getElementById("admin-generated-password");const button=document.getElementById("admin-copy-password");if(!input||!button)return;button.addEventListener("click",async()=>{let copied=false;try{await navigator.clipboard.writeText(input.value);copied=true;}catch(e){input.select();copied=document.execCommand("copy");}if(copied){button.textContent="Copied";setTimeout(()=>button.textContent="Copy",1600);}});})();</script>';
    }
    echo '<div style="overflow-x:auto"><table>';
    echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Reason</th><th>Role</th>';
    if (Auth::can('manage_users', $user)) echo '<th>API Key</th>';
    echo '<th>Actions</th></tr></thead>';
    echo '<tbody>';
    foreach ($users as $u) {
        echo '<tr>';
        echo '<td>' . View::e($u['id']) . '</td>';
        $countryFlag = Activity::flag($u['country_code']);
        $countryLabel = $countryFlag !== ''
            ? '<span class="user-country" title="IP country: ' . View::e($u['country_code']) . '" aria-label="IP country: ' . View::e($u['country_code']) . '">' . $countryFlag . '</span>'
            : '<span class="user-country-unknown" title="Country is not available yet">Unknown</span>';
        echo '<td><a href="' . View::url('/user/' . rawurlencode($u['name'])) . '">' . View::e($u['name']) . '</a> ' . $countryLabel . '</td>';
        echo '<td>' . View::e($u['email'] ?: '—') . '</td>';
        echo '<td>' . View::e($u['registration_reason'] ?: '—') . '</td>';
        echo '<td>' . View::e($roleNames[$u['role']] ?? $u['role']) . '</td>';
        if (Auth::can('manage_users', $user)) echo '<td><code style="font-size:11px">' . View::e($u['api_key']) . '</code></td>';
        echo '<td style="white-space:nowrap">';

        if (Auth::can('manage_roles', $user) && (int)$u['id'] !== (int)$user['id']) {
            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="set_role">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<select name="role">';
            foreach ($roles as $availableRole) {
                $sel = $u['role'] === $availableRole['slug'] ? ' selected' : '';
                echo '<option value="' . View::e($availableRole['slug']) . '"' . $sel . '>' . View::e($availableRole['name']) . '</option>';
            }
            echo '</select> <button>Set</button>';
            echo '</form> ';
        }

        if (Auth::can('manage_users', $user)) {
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Reset this user password?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="reset_password">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Reset password</button>';
            echo '</form> ';

            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="regen_api">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Regen API</button>';
            echo '</form> ';
        }

        if (Auth::can('manage_users', $user) && (int)$u['id'] !== (int)$user['id']) {
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete user ' . View::e($u['name']) . '?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="delete_user">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Delete</button>';
            echo '</form>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div></details>';
    }
    View::footer();
}



function page_settings(?array $user, string $method): void
{
    Auth::require();

    $error = '';
    $apiKey = null;
    $biography = $user['biography'] ?? '';
    $displayName = $user['display_name'] ?? '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_profile') {
            $biography = trim(is_string($_POST['biography'] ?? null) ? $_POST['biography'] : '');
            $displayName = trim(is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : ($user['display_name'] ?? ''));
            $newImages = [];
            try {
                if (!mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName, 'UTF-8') > 64
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $displayName)) {
                    throw new RuntimeException('Display name must contain no more than 64 characters and no control characters.');
                }
                if (!mb_check_encoding($biography, 'UTF-8') || mb_strlen($biography, 'UTF-8') > 2000) {
                    throw new RuntimeException('Biography must be valid text with no more than 2,000 characters.');
                }
                $profile = DB::row('SELECT avatar, banner FROM users WHERE id = ?', [(int)$user['id']]);
                $updated = $profile;
                foreach (['avatar', 'banner'] as $field) {
                    $image = saveProfileImageUpload($field . '_upload', (int)$user['id']);
                    if ($image !== null) {
                        $newImages[] = $image;
                        $updated[$field] = $image;
                    } elseif (isset($_POST['remove_' . $field])) {
                        $updated[$field] = '';
                    }
                }
                DB::exec('UPDATE users SET avatar = ?, banner = ?, biography = ?, display_name = ? WHERE id = ?',
                    [$updated['avatar'], $updated['banner'], $biography, $displayName, (int)$user['id']]);
            } catch (Throwable $e) {
                foreach ($newImages as $image) deleteLocalSiteImage($image);
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save your profile. Please try again.';
            }
            if ($error === '') {
                foreach (['avatar', 'banner'] as $field) {
                    if ($profile[$field] !== $updated[$field]) deleteLocalSiteImage($profile[$field]);
                }
                View::setFlash('Profile updated.', 'ok');
                Router::redirect('/settings');
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $dbUser = DB::row('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
            if (!password_verify($current, $dbUser['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 4) {
                $error = 'New password must be at least 4 characters.';
            } elseif ($new !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                DB::exec('UPDATE users SET password = ? WHERE id = ?', [$hash, (int)$user['id']]);
                View::setFlash('Password changed successfully.', 'ok');
                Router::redirect('/settings');
            }

        } elseif ($action === 'regen_api') {
            $key = bin2hex(random_bytes(16));
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', [$key, (int)$user['id']]);
            $apiKey = $key;
            View::setFlash('API key regenerated. Copy it now — it will not be shown again.', 'ok');

        } elseif ($action === 'delete_api') {
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', ['', (int)$user['id']]);
            View::setFlash('API key deleted.', 'ok');
            Router::redirect('/settings');

        } elseif ($action === 'save_blacklist') {
            $blacklist = trim($_POST['blacklist'] ?? '');
            DB::exec('UPDATE users SET blacklist = ? WHERE id = ?', [$blacklist, (int)$user['id']]);
            View::setFlash('Blacklist updated.', 'ok');
            Router::redirect('/settings');
        }
    }


    $user = DB::row('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
    $hasKey = !empty($user['api_key']);

    View::header('Settings', $user);
    View::flash();
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';

    echo '<h2>Profile</h2>';
    echo '<p><a href="' . View::url('/user/' . rawurlencode($user['name'])) . '">View your profile</a></p>';
    echo '<form method="post" enctype="multipart/form-data" class="profile-settings">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="save_profile">';
    echo '<label>Display name <small>(up to 64 characters)</small><input type="text" name="display_name" maxlength="64" value="' . View::e($displayName) . '" placeholder="' . View::e($user['name']) . '"></label>';
    echo '<p class="profile-help">Shown on your profile. Leave empty to use your username. Your login and profile URL stay the same.</p>';
    echo '<p class="profile-help">JPEG, PNG, GIF or WebP, up to 5 MB per image. A square profile picture and a wide banner work best. These images and your biography are visible on your profile.</p>';
    foreach (['avatar' => 'Profile picture', 'banner' => 'Banner'] as $field => $label) {
        echo '<label>' . $label . '<input type="file" name="' . $field . '_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
        if ($user[$field] !== '') {
            echo '<img class="' . ($field === 'avatar' ? 'profile-avatar' : 'profile-banner') . '" src="' . View::e($user[$field]) . '" alt="Current ' . strtolower($label) . '">';
            echo '<label><input type="checkbox" name="remove_' . $field . '" value="1"> Remove current ' . strtolower($label) . '</label>';
        }
    }
    echo '<label>Biography <small>(up to 2,000 characters)</small><textarea name="biography" rows="6" maxlength="2000" placeholder="Tell others about yourself">' . View::e($biography) . '</textarea></label>';
    echo '<button type="submit">Save profile</button></form>';


    echo '<h2>Tag Blacklist</h2>';
    echo '<p style="color:var(--muted-text);font-size:12px">Tags listed here will be hidden from top tags in the left sidebar.</p>';
    echo '<form method="post" style="max-width:400px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="save_blacklist">';
    echo '<label>Blacklisted tags <small>(space or line separated)</small><br>';
    echo '<textarea name="blacklist" rows="3" style="width:100%" placeholder="e.g. tag1 tag2">' . View::e($user['blacklist'] ?? '') . '</textarea></label><br><br>';
    echo '<button>Save Blacklist</button>';
    echo '</form>';


    echo '<h2 style="margin-top:30px">Change Password</h2>';
    echo '<form method="post" style="max-width:400px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="change_password">';
    echo '<label>Current password<br><input type="password" name="current_password" required autocomplete="current-password" style="width:100%"></label><br><br>';
    echo '<label>New password<br><input type="password" name="new_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<label>Confirm new password<br><input type="password" name="confirm_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<button>Change Password</button>';
    echo '</form>';


    echo '<h2 style="margin-top:30px">API Key</h2>';

    if ($apiKey !== null) {

        echo '<p class="flash flash-ok" style="font-family:monospace;word-break:break-all">' . View::e($apiKey) . '</p>';
        echo '<p style="color:var(--muted-text);font-size:12px">This is the only time this key will be shown. Copy it now.</p>';
    }

    if ($hasKey && $apiKey === null) {
        echo '<p>You have an active API key. <strong>The key value is not shown for security.</strong></p>';
    } elseif (!$hasKey) {
        echo '<p>You do not have an API key.</p>';
    }

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">';


    echo '<form method="post">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="regen_api">';
    echo '<button>' . ($hasKey ? 'Reset API Key' : 'Generate API Key') . '</button>';
    echo '</form>';


    if ($hasKey) {
        echo '<form method="post" onsubmit="return confirm(\'Delete your API key? Importers using it will stop working.\')">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="delete_api">';
        echo '<button>Delete API Key</button>';
        echo '</form>';
    }

    echo '</div>';
    View::footer();
}
