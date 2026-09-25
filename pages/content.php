<?php
declare(strict_types=1);

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

function page_api_docs(?array $user): void
{
    $apiEnabled = View::siteSetting('api_enabled', '1') === '1';
    $rateLimit = min(100000, max(1, (int)View::siteSetting('api_rate_limit', '120')));
    $baseUrl = View::absoluteUrl(View::url('/api/v1'));
    $readAccess = View::siteSetting('require_login_posts', '0') === '1' ? 'API key' : 'Public';
    $endpoints = [
        ['GET', '/posts', $readAccess, 'List and search posts. Parameters: page, limit (1–100), tags, rating, quality.'],
        ['GET', '/posts/{id}', $readAccess, 'Get one post together with its tags and comments.'],
        ['POST', '/posts', 'API key', 'Upload a post using multipart/form-data.'],
        ['PUT', '/posts/{id}', 'Owner or moderator', 'Update tags, rating, source, and title using JSON.'],
        ['DELETE', '/posts/{id}', 'Owner or moderator', 'Delete a post and its stored media.'],
        ['GET', '/tags', $readAccess, 'List tags. Parameters: page, limit (1–200), q.'],
        ['GET', '/tags/autocomplete', $readAccess, 'Autocomplete tags. Parameters: q and limit (1–10).'],
        ['GET', '/tags/explanation', $readAccess, 'Explain a tag. Required parameter: tag.'],
        ['GET', '/comments/{postId}', $readAccess, 'List comments belonging to a post.'],
        ['POST', '/comments/{postId}', 'API key', 'Add a comment using a JSON body with the body field.'],
        ['DELETE', '/comments/{commentId}', 'Moderator', 'Delete a comment.'],
        ['POST', '/votes/{postId}', 'API key', 'Vote using JSON value 1, -1, or 0 to remove the vote.'],
        ['GET', '/users/me', 'API key', 'Return information about the authenticated account.'],
    ];

    View::header('API Documentation', $user, null, [
        'description' => 'API endpoints, authentication, rate limits, and request examples for ' . View::siteSetting('site_name', SITE_NAME) . '.',
        'canonical' => View::url('/api-docs'),
        'image' => false,
    ]);

    echo '<article class="api-docs">';
    echo '<div class="api-docs-intro"><div><h1>API Documentation</h1><p>Use the JSON API to browse content and perform authenticated account actions.</p></div>';
    echo '<span class="api-status ' . ($apiEnabled ? 'is-enabled' : 'is-disabled') . '">' . ($apiEnabled ? 'API enabled' : 'API disabled') . '</span></div>';

    echo '<section><h2>Connection</h2><dl class="api-facts">';
    echo '<div><dt>Base URL</dt><dd><code>' . View::e($baseUrl) . '</code></dd></div>';
    echo '<div><dt>Format</dt><dd><code>application/json</code></dd></div>';
    echo '<div><dt>Rate limit</dt><dd>' . $rateLimit . ' requests per minute for each authenticated user or IP address</dd></div>';
    echo '</dl></section>';

    echo '<section><h2>Authentication</h2>';
    echo '<p>Send your key in the <code>X-API-Key</code> request header. You can view or regenerate the key in <a href="' . View::url('/settings') . '">user settings</a>.</p>';
    echo '<pre><code>curl -H "X-API-Key: YOUR_API_KEY" \\&#10;  "' . View::e($baseUrl) . '/users/me"</code></pre>';
    echo '<p>Public read endpoints do not require a key unless the administrator requires login to view posts.</p>';
    echo '</section>';

    echo '<section><h2>Endpoints</h2><div class="api-endpoint-table"><table><thead><tr><th>Method</th><th>Path</th><th>Access</th><th>Description</th></tr></thead><tbody>';
    foreach ($endpoints as [$endpointMethod, $endpointPath, $endpointAccess, $endpointDescription]) {
        echo '<tr><td><span class="api-method api-method-' . strtolower($endpointMethod) . '">' . $endpointMethod . '</span></td>';
        echo '<td><code>' . View::e($endpointPath) . '</code></td><td>' . View::e($endpointAccess) . '</td><td>' . View::e($endpointDescription) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';

    echo '<section><h2>Examples</h2>';
    echo '<h3>Search posts</h3><pre><code>curl "' . View::e($baseUrl) . '/posts?tags=wolf&amp;rating=s&amp;page=1&amp;limit=25"</code></pre>';
    echo '<h3>Upload a post</h3><pre><code>curl -X POST \\&#10;  -H "X-API-Key: YOUR_API_KEY" \\&#10;  -F "file=@image.png" \\&#10;  -F "tags=wolf solo blue_eyes" \\&#10;  -F "rating=s" \\&#10;  -F "source=https://example.com/source" \\&#10;  "' . View::e($baseUrl) . '/posts"</code></pre>';
    echo '<h3>Update a post</h3><pre><code>curl -X PUT \\&#10;  -H "X-API-Key: YOUR_API_KEY" \\&#10;  -H "Content-Type: application/json" \\&#10;  -d \'{"tags":"wolf solo","rating":"q","source":"https://example.com","title":"Example"}\' \\&#10;  "' . View::e($baseUrl) . '/posts/123"</code></pre>';
    echo '<h3>Add a comment</h3><pre><code>curl -X POST \\&#10;  -H "X-API-Key: YOUR_API_KEY" \\&#10;  -H "Content-Type: application/json" \\&#10;  -d \'{"body":"Nice post"}\' \\&#10;  "' . View::e($baseUrl) . '/comments/123"</code></pre>';
    echo '</section>';

    echo '<section><h2>Responses and errors</h2>';
    echo '<p>Successful requests return JSON. Uploads and new comments return HTTP <code>201</code>. Deletions and updates return <code>{"ok":true}</code>.</p>';
    echo '<pre><code>{"error":"Description of the error"}</code></pre>';
    echo '<div class="api-error-codes"><span><code>400</code> Invalid request</span><span><code>401</code> Missing or invalid authentication</span><span><code>403</code> Insufficient permission</span><span><code>404</code> Resource not found</span><span><code>429</code> Rate limit exceeded</span><span><code>503</code> API disabled</span></div>';
    echo '</section>';
    echo '</article>';
    View::footer();
}
