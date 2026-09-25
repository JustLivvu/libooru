<?php
declare(strict_types=1);

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
    echo '    <span>Running Libooru <a href="https://github.com/JustLivvu/libooru" target="_blank" rel="noopener noreferrer">open source</a> software</span>';
    echo '  </div>';


    echo '  <div class="gelbooru-counter-wrapper">';
    echo '    <div class="digit-counter">' . $counterHtml . '</div>';
    echo '  </div>';



    echo '</div>';

    View::footer();
}
