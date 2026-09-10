<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/post.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/api.php';

// ── Bootstrap ──────────────────────────────────────────────────────────────

Auth::start();

// Serve static files (thumb / full image)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = SITE_BASE;

if (str_starts_with($uri, $base . '/thumb/')) {
    Router::serveFile(THUMB_DIR, rawurldecode(substr($uri, strlen($base . '/thumb/'))));
    exit;
}
if (str_starts_with($uri, $base . '/file/')) {
    Router::serveFile(UPLOAD_DIR, rawurldecode(substr($uri, strlen($base . '/file/'))));
    exit;
}
if (str_starts_with($uri, $base . '/static/')) {
    Router::serveFile(LIBOORU_ROOT . '/static', rawurldecode(substr($uri, strlen($base . '/static/'))));
    exit;
}

// API
if (str_starts_with($uri, $base . '/api/')) {
    (new Api())->handle();
    exit;
}

// ── Router ─────────────────────────────────────────────────────────────────

class Router
{
    public static function redirect(string $path, array $params = []): never
    {
        $url = SITE_BASE . $path;
        if ($params) $url .= '?' . http_build_query($params);
        header('Location: ' . $url);
        exit;
    }

    public static function serveFile(string $dir, string $name): void
    {
        // Security: no path traversal
        $name = basename($name);
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($path);
    }
}

// Parse path
$path   = substr($uri, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ── Dispatch ───────────────────────────────────────────────────────────────

try {
    dispatch($method, $path);
} catch (Throwable $e) {
    http_response_code(500);
    View::header('Error');
    echo '<h1>Error</h1><p>' . View::e($e->getMessage()) . '</p>';
    View::footer();
}

// ── Controller functions ───────────────────────────────────────────────────

function dispatch(string $method, string $path): void
{
    $user = Auth::current();

    // Home
    if ($path === '/' || $path === '') {
        page_home($user);
    }
    // Browse /posts
    elseif ($path === '/posts') {
        page_posts($user);
    }
    // Single post
    elseif (preg_match('#^/post/(\d+)$#', $path, $m)) {
        if ($method === 'POST') {
            post_handle($user, (int)$m[1]);
        } else {
            page_post($user, (int)$m[1]);
        }
    }
    // Edit post
    elseif (preg_match('#^/post/(\d+)/edit$#', $path, $m)) {
        page_post_edit($user, (int)$m[1], $method);
    }
    // Delete post
    elseif (preg_match('#^/post/(\d+)/delete$#', $path, $m) && $method === 'POST') {
        action_post_delete($user, (int)$m[1]);
    }
    // Upload
    elseif ($path === '/upload') {
        page_upload($user, $method);
    }
    // Favorites
    elseif ($path === '/favorites') {
        page_favorites($user);
    }
    // Tags
    elseif ($path === '/tags') {
        page_tags($user);
    }
    // Login
    elseif ($path === '/login') {
        page_login($user, $method);
    }
    // Logout
    elseif ($path === '/logout') {
        Auth::logout();
        Router::redirect('/');
    }
    // Register
    elseif ($path === '/register') {
        page_register($user, $method);
    }
    // User profile
    elseif (preg_match('#^/user/([^/]+)$#', $path, $m)) {
        page_user($user, rawurldecode($m[1]));
    }
    // Admin
    elseif ($path === '/admin') {
        page_admin($user, $method);
    }
    // Settings
    elseif ($path === '/settings') {
        page_settings($user, $method);
    }
    // 404
    else {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>404 - Not Found</h1>';
        View::footer();
    }
}

// ── Pages ──────────────────────────────────────────────────────────────────

function page_home(?array $user): void
{
    $totalPosts = (int)(DB::scalar('SELECT COUNT(*) FROM posts') ?: 0);
    $digits = str_split((string)$totalPosts);
    $counterHtml = '';
    foreach ($digits as $digit) {
        $counterHtml .= '<img src="https://rule34.us/v1/counter/' . $digit . '.gif" alt="' . $digit . '" class="counter-mascot">';
    }

    // Visitor counter
    $visitors = (int)View::siteSetting('visitor_count', '360459064');
    $visitors++;
    View::setSiteSetting('visitor_count', (string)$visitors);

    View::header(SITE_NAME, $user);
    View::flash();
    
    $siteName = View::siteSetting('site_name', SITE_NAME);
    $e = fn($v) => View::e($v);

    echo '<div class="gelbooru-home">';
    
    // Logo
    echo '  <h1 class="gelbooru-title">' . $e($siteName) . '</h1>';
    
    // Sub-navigation
    echo '  <div class="gelbooru-subnav">';
    echo '    <a href="' . View::url('/posts') . '">Browse Posts</a>';
    echo '    <a href="' . View::url('/upload') . '">Upload</a>';
    echo '    <a href="' . View::url('/tags') . '">Tags</a>';
    echo '    <a href="' . View::url('/favorites') . '">Favorites</a>';
    if ($user) {
        echo '    <a href="' . View::url('/user/' . rawurlencode($user['name'])) . '">My Account</a>';
        echo '    <a href="' . View::url('/settings') . '">Settings</a>';
        if ($user['role'] === 'admin') {
            echo '    <a href="' . View::url('/admin') . '">Admin</a>';
        }
        echo '    <a href="' . View::url('/logout') . '">Logout</a>';
    } else {
        echo '    <a href="' . View::url('/login') . '">Login</a>';
        echo '    <a href="' . View::url('/register') . '">Register</a>';
    }
    echo '  </div>';
    
    // Search Form
    echo '  <form class="gelbooru-search-form" method="get" action="' . View::url('/posts') . '">';
    echo '    <input type="text" name="q" placeholder="Ex: blue_sky cloud 1girl" autocomplete="off" autofocus class="gelbooru-search-input">';
    echo '    <button type="submit" class="gelbooru-search-button">Search</button>';
    echo '  </form>';
    
    // Info Links
    echo '  <div class="gelbooru-info-links">';
    echo '    <span>Total visitors: ' . number_format($visitors) . '</span>';
    echo '    &bull; ';
    echo '    <span>Running ' . $e($siteName) . '</span>';
    echo '  </div>';
    
    // Counter Container
    echo '  <div class="gelbooru-counter-wrapper">';
    echo '    <div class="digit-counter">' . $counterHtml . '</div>';
    echo '  </div>';
    

    
    echo '</div>';

    View::footer();
}

function page_posts(?array $user): void
{
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q       = trim($_GET['q'] ?? '');
    $rating  = $_GET['rating'] ?? '';
    $quality = in_array($_GET['quality'] ?? '', ['low', 'medium', 'high', 'ultra'], true)
               ? $_GET['quality'] : '';
    $order   = in_array($_GET['order'] ?? '', ['id DESC', 'id ASC', 'score DESC', 'created_at DESC'], true)
               ? $_GET['order'] : 'id DESC';

    $tags   = $q ? preg_split('/[\s,]+/', $q, -1, PREG_SPLIT_NO_EMPTY) : [];
    $result = Post::list($page, POSTS_PER_PAGE, $tags, $rating, $order, $quality);

    $sidebarTags = DB::rows('SELECT name, count FROM tags ORDER BY count DESC LIMIT 20');

    View::header('Browse Posts', $user, $sidebarTags);
    View::flash();
    echo '<h1>Browse</h1>';

    echo '<form method="get">';
    echo '<input name="q" value="' . View::e($q) . '" placeholder="Search tags…"> ';
    echo '<select name="rating"><option value="">All ratings</option>';
    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        $sel = $rating === $v ? ' selected' : '';
        echo "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }
    echo '</select> ';
    echo '<select name="order">';
    foreach (['id DESC' => 'Newest', 'id ASC' => 'Oldest', 'score DESC' => 'Top rated'] as $v => $l) {
        $sel = $order === $v ? ' selected' : '';
        echo "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }
    echo '</select> ';
    if ($quality !== '') {
        echo '<input type="hidden" name="quality" value="' . View::e($quality) . '"> ';
    }
    echo '<button>Filter</button></form>';

    echo '<p>' . $result['total'] . ' posts</p>';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], '/posts', array_filter(['q' => $q, 'rating' => $rating, 'order' => $order, 'quality' => $quality]));
    View::footer();
}

function page_post(?array $user, int $id): void
{
    $post = Post::getById($id);
    if (!$post) {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>Post not found</h1>';
        View::footer();
        return;
    }
    $comments = Post::commentsFor($id);
    $tags     = $post['tags'];
    // Try to get counts for these tags if possible, or just pass as is
    // Actually $post['tags'] already has 'name'. View::sidebar handles missing 'count'.

    View::header('Post #' . $id, $user, $tags);
    View::flash();

    $fileUrl  = Image::fileUrl($post['filename']);
    $isOwner  = $user && (int)$user['id'] === (int)$post['user_id'];
    $isAdmin  = $user && $user['role'] === 'admin';

    echo '<article class="post-view">';
    echo '<h1>Post #' . View::e($id) . '</h1>';

    echo '<div class="post-image">';
    $ext = pathinfo($post['filename'], PATHINFO_EXTENSION);
    if (in_array(strtolower($ext), ['mp4', 'webm'], true)) {
        echo '<video src="' . View::e($fileUrl) . '" controls loop></video>';
    } else {
        echo '<a href="' . View::e($fileUrl) . '">';
        echo '<img src="' . View::e($fileUrl) . '" alt="post #' . View::e($id) . '">';
        echo '</a>';
    }
    echo '</div>';

    // Info
    echo '<dl class="post-info">';
    echo '<dt>Rating</dt><dd>' . View::e(match($post['rating']) {'s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit', default => $post['rating']}) . '</dd>';
    echo '<dt>Score</dt><dd>' . View::e($post['score']);
    if ($user) {
        echo ' <form method="post" action="' . View::url('/post/' . $id) . '" style="display:inline">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="vote">';
        echo '<button name="value" value="1">+</button> ';
        echo '<button name="value" value="-1">−</button>';
        echo '</form>';
    }
    echo '</dd>';
    if ($post['title']) echo '<dt>Title</dt><dd>' . View::e($post['title']) . '</dd>';
    if ($post['source']) echo '<dt>Source</dt><dd><a href="' . View::e($post['source']) . '" rel="nofollow">' . View::e($post['source']) . '</a></dd>';
    echo '<dt>Size</dt><dd>' . View::e($post['width'] . '×' . $post['height']) . ' — ' . View::e(round($post['filesize'] / 1024, 1)) . ' KB</dd>';
    echo '<dt>MD5</dt><dd><code>' . View::e($post['md5']) . '</code></dd>';
    $posterName = $post['user_id']
        ? (DB::scalar('SELECT name FROM users WHERE id = ?', [$post['user_id']]) ?: 'unknown')
        : 'Anonymous';
    echo '<dt>Uploaded by</dt><dd>' . View::e($posterName) . '</dd>';
    echo '<dt>Date</dt><dd>' . date('Y-m-d H:i', (int)$post['created_at']) . '</dd>';
    echo '</dl>';

    // Tags are in the sidebar

    // Actions
    echo '<div class="post-actions">';
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
    }
    if ($isOwner || $isAdmin) {
        echo '<a href="' . View::url('/post/' . $id . '/edit') . '"><button type="button">Edit</button></a> ';
        echo '<form method="post" action="' . View::url('/post/' . $id . '/delete') . '" style="display:inline" onsubmit="return confirm(\'Delete post #' . $id . '?\');">';
        View::csrfField();
        echo '<button>Delete</button>';
        echo '</form>';
    }
    echo '</div>';

    echo '</article>';

    // Comments
    echo '<section class="comments">';
    echo '<h2>Comments (' . count($comments) . ')</h2>';
    foreach ($comments as $c) {
        $author = $c['user_name'] ?? $c['guest_name'] ?? 'Anonymous';
        echo '<div class="comment">';
        echo '<span class="comment-author">' . View::e($author) . '</span> ';
        echo '<span class="comment-date">' . date('Y-m-d H:i', (int)$c['created_at']) . '</span>';
        if ($isAdmin) {
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
    echo '<form method="post" action="' . View::url('/post/' . $id) . '">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="comment">';
    if (!$user) {
        echo '<input name="guest_name" placeholder="Your name (optional)"><br>';
    }
    echo '<textarea name="body" rows="4" cols="60" required></textarea><br>';
    echo '<button>Post comment</button>';
    echo '</form>';
    echo '</section>';

    View::footer();
}

function post_handle(?array $user, int $id): void
{
    View::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'comment') {
        $body      = $_POST['body'] ?? '';
        $guestName = $user ? null : ($_POST['guest_name'] ?? 'Anonymous');
        try {
            Post::addComment($id, $body, Auth::id(), $guestName);
            View::setFlash('Comment posted.', 'ok');
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
    } elseif ($action === 'delete_comment' && $user && $user['role'] === 'admin') {
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
    if ((int)$user['id'] !== (int)$post['user_id'] && $user['role'] !== 'admin') {
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
    if ((int)$user['id'] !== (int)$post['user_id'] && $user['role'] !== 'admin') {
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
    echo '<h1>Upload</h1>';
    echo '<form method="post" enctype="multipart/form-data">';
    View::csrfField();
    $maxMb = MAX_FILE_SIZE / 1024 / 1024;
    echo '<label>Image/Video (JPEG, PNG, GIF, WebP, MP4, WebM — max ' . $maxMb . ' MB)<br>';
    echo '<input type="file" name="file" accept="image/*,video/mp4,video/webm" required></label><br>';
    echo '<label>Tags (space-separated)<br><input name="tags" size="60" placeholder="character:foo artist:bar general_tag"></label><br>';
    echo '<label>Rating<br><select name="rating">';
    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        echo "<option value=\"{$v}\">{$l}</option>";
    }
    echo '</select></label><br>';
    echo '<label>Source URL<br><input name="source" size="60" placeholder="https://…"></label><br>';
    echo '<label>Title<br><input name="title" size="60"></label><br>';
    echo '<button>Upload</button>';
    echo '</form>';
    View::footer();
}

function page_tags(?array $user): void
{
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q      = trim($_GET['q'] ?? '');
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    if ($q !== '') {
        $tags  = DB::rows('SELECT name, count FROM tags WHERE name LIKE ? ORDER BY count DESC LIMIT ? OFFSET ?', ['%' . $q . '%', $limit, $offset]);
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE name LIKE ?', ['%' . $q . '%']);
    } else {
        $tags  = DB::rows('SELECT name, count FROM tags ORDER BY count DESC LIMIT ? OFFSET ?', [$limit, $offset]);
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags');
    }
    $pages = (int)ceil($total / $limit);

    View::header('Tags', $user);
    View::flash();
    echo '<h1>Tags</h1>';
    echo '<form method="get"><input name="q" value="' . View::e($q) . '" placeholder="Search tags…"> <button>Search</button></form>';
    echo '<p>' . $total . ' tags</p>';
    echo '<ul class="tag-list">';
    foreach ($tags as $t) {
        echo '<li><a href="' . View::url('/posts', ['q' => $t['name']]) . '">' . View::e($t['name']) . '</a> <span>(' . View::e($t['count']) . ')</span></li>';
    }
    echo '</ul>';
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
        } else {
            $error = 'Invalid username or password.';
        }
    }

    View::header('Login', null);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<h1>Login</h1>';
    echo '<form method="post">';
    View::csrfField();
    echo '<label>Username<br><input name="name" required autofocus></label><br>';
    echo '<label>Password<br><input type="password" name="password" required></label><br>';
    echo '<button>Login</button> ';
    echo '<a href="' . View::url('/register') . '">Register</a>';
    echo '</form>';
    View::footer();
}

function page_register(?array $user, string $method): void
{
    if ($user) Router::redirect('/');
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $name  = trim($_POST['name'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $id = Auth::register($name, $pass, $email);
        if ($id) {
            Auth::login($name, $pass);
            Router::redirect('/');
        } else {
            $error = 'Registration failed. Username may be taken or too short (min 2 chars, password min 4 chars).';
        }
    }

    View::header('Register', null);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<h1>Register</h1>';
    echo '<form method="post">';
    View::csrfField();
    echo '<label>Username (2–32 chars)<br><input name="name" required autofocus></label><br>';
    echo '<label>Password (min 4 chars)<br><input type="password" name="password" required></label><br>';
    echo '<label>Email (optional)<br><input type="email" name="email"></label><br>';
    echo '<button>Register</button>';
    echo '</form>';
    View::footer();
}

function page_user(?array $user, string $targetName): void
{
    $target = DB::row('SELECT id, name, email, role, api_key, created_at FROM users WHERE name = ?', [$targetName]);
    if (!$target) {
        http_response_code(404);
        View::header('User not found', $user);
        echo '<h1>User not found</h1>';
        View::footer();
        return;
    }

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $result = Post::list($page, POSTS_PER_PAGE, [], '', 'id DESC');
    // Filter by user
    $myPosts = DB::rows(
        'SELECT * FROM posts WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
        [(int)$target['id'], POSTS_PER_PAGE, ($page - 1) * POSTS_PER_PAGE]
    );
    $myTotal = (int)DB::scalar('SELECT COUNT(*) FROM posts WHERE user_id = ?', [(int)$target['id']]);
    $myPages = (int)ceil($myTotal / POSTS_PER_PAGE);

    $isSelf = $user && (int)$user['id'] === (int)$target['id'];
    $favTotal = (int)DB::scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [(int)$target['id']]);

    View::header('User: ' . $target['name'], $user);
    View::flash();
    echo '<h1>User: ' . View::e($target['name']) . '</h1>';
    echo '<dl>';
    echo '<dt>Role</dt><dd>' . View::e($target['role']) . '</dd>';
    echo '<dt>Member since</dt><dd>' . date('Y-m-d', (int)$target['created_at']) . '</dd>';
    echo '<dt>Posts</dt><dd>' . $myTotal . '</dd>';
    echo '<dt>Favorites</dt><dd>' . $favTotal . '</dd>';
    if ($isSelf) {
        echo '<dt>Email</dt><dd>' . View::e($target['email'] ?: '—') . '</dd>';
        echo '<dt>API Key</dt><dd><code>' . View::e($target['api_key']) . '</code></dd>';
    }
    echo '</dl>';
    echo '<h2>Posts</h2>';
    View::postGrid($myPosts);
    View::paginator($page, $myPages, '/user/' . rawurlencode($target['name']));
    View::footer();
}

function page_favorites(?array $user): void
{
    Auth::require();
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $result = Post::listFavorites((int)$user['id'], $page, POSTS_PER_PAGE);

    $sidebarTags = DB::rows('SELECT name, count FROM tags ORDER BY count DESC LIMIT 20');
    View::header('Favorites', $user, $sidebarTags);
    View::flash();
    echo '<h1>My Favorites</h1>';
    echo '<p>' . $result['total'] . ' favorite posts</p>';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], '/favorites');
    View::footer();
}

function page_admin(?array $user, string $method): void
{
    Auth::requireAdmin();

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['id']) {
                DB::exec('DELETE FROM users WHERE id = ?', [$uid]);
                View::setFlash('User deleted.', 'ok');
            }
        } elseif ($action === 'set_role') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $role = in_array($_POST['role'] ?? '', ['admin', 'user'], true) ? $_POST['role'] : 'user';
            DB::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $uid]);
            View::setFlash('Role updated.', 'ok');
        } elseif ($action === 'regen_api') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $key = bin2hex(random_bytes(16));
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', [$key, $uid]);
            View::setFlash('API key regenerated.', 'ok');
        } elseif ($action === 'site_settings') {
            $name   = trim($_POST['site_name'] ?? '');
            $logo   = trim($_POST['site_logo'] ?? '');
            $banner = trim($_POST['site_banner'] ?? '');
            if ($name !== '') View::setSiteSetting('site_name', $name);
            View::setSiteSetting('site_logo',   $logo);
            View::setSiteSetting('site_banner', $banner);
            View::setFlash('Site settings saved.', 'ok');
        }

        Router::redirect('/admin');
    }

    $users        = DB::rows('SELECT id, name, email, role, api_key, created_at FROM users ORDER BY id DESC');
    $postCount    = (int)DB::scalar('SELECT COUNT(*) FROM posts');
    $tagCount     = (int)DB::scalar('SELECT COUNT(*) FROM tags');
    $commentCount = (int)DB::scalar('SELECT COUNT(*) FROM comments');

    $curName   = View::siteSetting('site_name', SITE_NAME);
    $curLogo   = View::siteSetting('site_logo');
    $curBanner = View::siteSetting('site_banner');

    View::header('Admin', $user);
    View::flash();
    echo '<h1>Admin</h1>';

    // Stats
    echo '<h2>Statistics</h2>';
    echo '<dl>';
    echo '<dt>Posts</dt><dd>' . $postCount . '</dd>';
    echo '<dt>Tags</dt><dd>' . $tagCount . '</dd>';
    echo '<dt>Comments</dt><dd>' . $commentCount . '</dd>';
    echo '<dt>Users</dt><dd>' . count($users) . '</dd>';
    echo '</dl>';

    // Site settings
    echo '<h2>Site Settings</h2>';
    echo '<p style="color:var(--muted-text);font-size:12px">Set logo <strong>or</strong> banner — if logo is set it takes priority. Leave blank to show site name as text.</p>';
    echo '<form method="post" style="max-width:500px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="site_settings">';
    echo '<label>Site name<br><input name="site_name" value="' . View::e($curName) . '" style="width:100%"></label><br><br>';
    echo '<label>Logo URL <small>(small icon, ~32px tall)</small><br><input name="site_logo" value="' . View::e($curLogo) . '" style="width:100%" placeholder="https://…"></label><br><br>';
    echo '<label>Banner URL <small>(used instead of logo, ~40px tall)</small><br><input name="site_banner" value="' . View::e($curBanner) . '" style="width:100%" placeholder="https://…"></label><br><br>';
    echo '<button>Save Settings</button>';
    echo '</form>';

    // Users table
    echo '<h2>Users</h2>';
    echo '<div style="overflow-x:auto"><table>';
    echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>API Key</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    foreach ($users as $u) {
        echo '<tr>';
        echo '<td>' . View::e($u['id']) . '</td>';
        echo '<td><a href="' . View::url('/user/' . rawurlencode($u['name'])) . '">' . View::e($u['name']) . '</a></td>';
        echo '<td>' . View::e($u['email'] ?: '—') . '</td>';
        echo '<td>' . View::e($u['role']) . '</td>';
        echo '<td><code style="font-size:11px">' . View::e($u['api_key']) . '</code></td>';
        echo '<td style="white-space:nowrap">';
        // Change role
        echo '<form method="post" style="display:inline">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="set_role">';
        echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
        echo '<select name="role">';
        foreach (['user', 'admin'] as $r) {
            $sel = $u['role'] === $r ? ' selected' : '';
            echo "<option value=\"{$r}\"{$sel}>{$r}</option>";
        }
        echo '</select> <button>Set</button>';
        echo '</form> ';
        // Regen API key
        echo '<form method="post" style="display:inline">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="regen_api">';
        echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
        echo '<button>Regen API</button>';
        echo '</form> ';
        // Delete
        if ((int)$u['id'] !== (int)$user['id']) {
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
    View::footer();
}

// ─────────────────────────────────────────────────────────────────────────────

function page_settings(?array $user, string $method): void
{
    Auth::require();

    $error = '';
    $apiKey = null;

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'change_password') {
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
            $apiKey = $key; // show once
            View::setFlash('API key regenerated. Copy it now — it will not be shown again.', 'ok');

        } elseif ($action === 'delete_api') {
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', ['', (int)$user['id']]);
            View::setFlash('API key deleted.', 'ok');
            Router::redirect('/settings');
        }
    }

    // Refresh user
    $user = DB::row('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
    $hasKey = !empty($user['api_key']);

    View::header('Settings', $user);
    View::flash();
    echo '<h1>Account Settings</h1>';

    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';

    // Change password
    echo '<h2>Change Password</h2>';
    echo '<form method="post" style="max-width:400px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="change_password">';
    echo '<label>Current password<br><input type="password" name="current_password" required autocomplete="current-password" style="width:100%"></label><br><br>';
    echo '<label>New password<br><input type="password" name="new_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<label>Confirm new password<br><input type="password" name="confirm_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<button>Change Password</button>';
    echo '</form>';

    // API Key
    echo '<h2 style="margin-top:30px">API Key</h2>';

    if ($apiKey !== null) {
        // Just regenerated — show once
        echo '<p class="flash flash-ok" style="font-family:monospace;word-break:break-all">' . View::e($apiKey) . '</p>';
        echo '<p style="color:var(--muted-text);font-size:12px">This is the only time this key will be shown. Copy it now.</p>';
    }

    if ($hasKey && $apiKey === null) {
        echo '<p>You have an active API key. <strong>The key value is not shown for security.</strong></p>';
    } elseif (!$hasKey) {
        echo '<p>You do not have an API key.</p>';
    }

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">';

    // Regen
    echo '<form method="post">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="regen_api">';
    echo '<button>' . ($hasKey ? 'Reset API Key' : 'Generate API Key') . '</button>';
    echo '</form>';

    // Delete
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
