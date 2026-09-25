<?php
declare(strict_types=1);

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
