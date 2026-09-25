<?php
declare(strict_types=1);

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

    elseif ($path === '/api-docs') {
        page_api_docs($user);
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
