<?php
declare(strict_types=1);

const SITEMAP_POSTS_PER_FILE = 10000;

function seoXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function seoXmlHeaders(): void
{
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
}

function page_robots(): void
{
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');

    $base = SITE_BASE;
    echo "User-agent: *\n";
    echo "Allow: {$base}/\n";
    foreach (['/admin', '/settings', '/upload', '/login', '/register', '/logout', '/favorites', '/api/'] as $path) {
        echo 'Disallow: ' . $base . $path . "\n";
    }
    echo 'Sitemap: ' . View::absoluteUrl(View::url('/sitemap.xml')) . "\n";
}

function page_sitemap_index(): void
{
    seoXmlHeaders();
    $postCount = View::siteSetting('require_login_posts', '0') === '1'
        ? 0
        : (int)(DB::scalar('SELECT COUNT(*) FROM posts') ?: 0);
    $chunks = (int)ceil($postCount / SITEMAP_POSTS_PER_FILE);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<sitemap><loc>' . seoXmlEscape(View::absoluteUrl(View::url('/sitemap-pages.xml'))) . '</loc></sitemap>';
    for ($chunk = 1; $chunk <= $chunks; $chunk++) {
        $url = View::absoluteUrl(View::url('/sitemap-posts-' . $chunk . '.xml'));
        echo '<sitemap><loc>' . seoXmlEscape($url) . '</loc></sitemap>';
    }
    echo '</sitemapindex>';
}

function page_sitemap_pages(): void
{
    seoXmlHeaders();
    $paths = ['/', '/tags', '/search-help', '/terms'];
    if (View::siteSetting('require_login_posts', '0') !== '1') {
        array_splice($paths, 1, 0, ['/posts']);
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($paths as $path) {
        echo '<url><loc>' . seoXmlEscape(View::absoluteUrl(View::url($path))) . '</loc></url>';
    }
    echo '</urlset>';
}

function page_sitemap_posts(int $chunk): void
{
    $postCount = View::siteSetting('require_login_posts', '0') === '1'
        ? 0
        : (int)(DB::scalar('SELECT COUNT(*) FROM posts') ?: 0);
    $chunks = (int)ceil($postCount / SITEMAP_POSTS_PER_FILE);
    if ($chunk < 1 || $chunk > $chunks) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Sitemap not found';
        return;
    }

    $offset = ($chunk - 1) * SITEMAP_POSTS_PER_FILE;
    $posts = DB::rows(
        'SELECT id, filename, mime, created_at FROM posts ORDER BY id LIMIT ? OFFSET ?',
        [SITEMAP_POSTS_PER_FILE, $offset]
    );

    seoXmlHeaders();
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    foreach ($posts as $post) {
        $postUrl = View::absoluteUrl(View::url('/post/' . (int)$post['id']));
        $ext = strtolower(pathinfo((string)$post['filename'], PATHINFO_EXTENSION));
        $isVideo = str_starts_with((string)($post['mime'] ?? ''), 'video/') || in_array($ext, ['mp4', 'webm', 'mov'], true);
        $imageUrl = $isVideo
            ? Image::thumbUrl((string)$post['filename'])
            : Image::fileUrl((string)$post['filename']);

        echo '<url>';
        echo '<loc>' . seoXmlEscape($postUrl) . '</loc>';
        echo '<lastmod>' . date('c', (int)$post['created_at']) . '</lastmod>';
        echo '<image:image><image:loc>' . seoXmlEscape(View::absoluteUrl($imageUrl)) . '</image:loc></image:image>';
        echo '</url>';
    }
    echo '</urlset>';
}
