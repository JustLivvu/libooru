<?php
declare(strict_types=1);




class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $path, array $params = []): string
    {
        $q = $params ? '?' . http_build_query($params) : '';
        return SITE_BASE . $path . $q;
    }

    public static function absoluteUrl(string $url = '/'): string
    {
        if (preg_match('#^https?://#i', $url)) return $url;

        $origin = SITE_URL;
        if ($origin === '') {
            $forwardedProto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
            $scheme = in_array($forwardedProto, ['http', 'https'], true)
                ? $forwardedProto
                : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');

            $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
            $host = $forwardedHost !== '' ? $forwardedHost : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            if (!preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::\d+)?$/i', $host)) {
                $host = 'localhost';
            }
            $origin = $scheme . '://' . $host;
        }

        $path = str_starts_with($url, '/') ? $url : '/' . $url;
        return rtrim($origin, '/') . $path;
    }



    private static ?array $siteSettings = null;

    public static function siteSetting(string $key, string $default = ''): string
    {
        if (self::$siteSettings === null) {
            self::$siteSettings = [];
            try {
                $rows = DB::rows('SELECT key, value FROM site_settings');
                foreach ($rows as $r) {
                    self::$siteSettings[$r['key']] = $r['value'];
                }
            } catch (Throwable) {}
        }
        return self::$siteSettings[$key] ?? $default;
    }

    public static function setSiteSetting(string $key, string $value): void
    {
        DB::exec(
            'INSERT INTO site_settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $value]
        );
        self::$siteSettings = null;
    }



    public static function header(string $title, ?array $user = null, ?array $sidebarTags = null, array $meta = []): void
    {
        $siteName = self::siteSetting('site_name', SITE_NAME);
        $siteLogo = self::siteSetting('site_logo');
        $siteBanner = self::siteSetting('site_banner');
        $adultWarningEnabled = self::siteSetting('enable_adult_warning', '0') === '1';
        $e = fn($v) => self::e($v);

        $fullTitle = ($title === $siteName || $title === SITE_NAME)
            ? $siteName
            : $title . ' - ' . $siteName;
        $description = trim((string)($meta['description'] ?? self::siteSetting(
            'site_description',
            'Browse, search and discover media by tags, rating and quality on ' . $siteName . '.'
        )));
        if ($description === '') {
            $description = 'Browse, search and discover media by tags, rating and quality on ' . $siteName . '.';
        }
        $description = preg_replace('/\s+/', ' ', $description) ?? $description;
        $description = function_exists('mb_substr')
            ? mb_substr($description, 0, 200) : substr($description, 0, 200);

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? self::url('/'));
        $canonical = self::absoluteUrl((string)($meta['canonical'] ?? $requestUri));
        $type = (string)($meta['type'] ?? 'website');
        $requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $relativePath = SITE_BASE !== '' && str_starts_with($requestPath, SITE_BASE)
            ? (substr($requestPath, strlen(SITE_BASE)) ?: '/')
            : $requestPath;
        $isPublicPage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && http_response_code() < 400
            && (bool)preg_match('#^/(?:$|posts$|tags$|terms$|search-help$|post/\d+$)#', $relativePath);
        $robots = (string)($meta['robots'] ?? ($isPublicPage ? 'index,follow,max-image-preview:large' : 'noindex,follow'));
        $image = array_key_exists('image', $meta)
            ? (string)$meta['image']
            : (string)($siteBanner ?: $siteLogo ?: self::url('/static/favicon.png'));
        if ($image !== '') $image = self::absoluteUrl($image);
        $imageAlt = (string)($meta['image_alt'] ?? $fullTitle);
        $themeColor = (string)($meta['theme_color'] ?? '#000000');

        $jsonLd = $meta['json_ld'] ?? [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => self::absoluteUrl(self::url('/')),
            'description' => $description,
        ];
        $jsonLdJson = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $twitterCard = $image !== '' ? 'summary_large_image' : 'summary';
        $imageMeta = '';
        if ($image !== '') {
            $imageMeta .= '<meta property="og:image" content="' . $e($image) . '">' . "\n";
            $imageMeta .= '<meta property="og:image:alt" content="' . $e($imageAlt) . '">' . "\n";
            $imageMeta .= '<meta name="twitter:image" content="' . $e($image) . '">' . "\n";
        }


        if ($siteLogo) {
            $brand = '<img src="' . $e($siteLogo) . '" alt="' . $e($siteName) . '" style="height:32px;vertical-align:middle">';
        } elseif ($siteBanner) {
            $brand = '<img src="' . $e($siteBanner) . '" alt="' . $e($siteName) . '" style="height:130px;vertical-align:middle">';
        } else {
            $brand = $e($siteName);
        }
        $faviconVersion = (string)(@filemtime(__DIR__ . '/static/favicon.png') ?: 1);
        $styleVersion = (string)(@filemtime(__DIR__ . '/static/style.css') ?: 1);
        $autocompleteVersion = (string)(@filemtime(__DIR__ . '/static/autocomplete.js') ?: 1);
        $tagExplanationsVersion = (string)(@filemtime(__DIR__ . '/static/tag-explanations.js') ?: 1);
        $mediaPreconnect = '';
        if (self::siteSetting('storage_driver', 'local') === 's3') {
            $endpoint = self::siteSetting('s3_endpoint');
            $scheme = (string)(parse_url($endpoint, PHP_URL_SCHEME) ?: 'https');
            $host = (string)parse_url($endpoint, PHP_URL_HOST);
            $port = parse_url($endpoint, PHP_URL_PORT);
            if ($host !== '') {
                $origin = $scheme . '://' . $host . ($port ? ':' . $port : '');
                $mediaPreconnect = '<link rel="preconnect" href="' . $e($origin) . '">' . "\n"
                    . '<link rel="dns-prefetch" href="//' . $e($host) . '">' . "\n";
            }
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($fullTitle)}</title>
<meta name="description" content="{$e($description)}">
<meta name="robots" content="{$e($robots)}">
<meta name="rating" content="adult">
<meta name="theme-color" content="{$e($themeColor)}">
<link rel="canonical" href="{$e($canonical)}">
<link rel="icon" type="image/png" href="{$e(SITE_BASE)}/static/favicon.png?v={$faviconVersion}">
<link rel="apple-touch-icon" href="{$e(SITE_BASE)}/static/favicon.png?v={$faviconVersion}">
<meta property="og:site_name" content="{$e($siteName)}">
<meta property="og:title" content="{$e($fullTitle)}">
<meta property="og:description" content="{$e($description)}">
<meta property="og:type" content="{$e($type)}">
<meta property="og:url" content="{$e($canonical)}">
{$imageMeta}<meta name="twitter:card" content="{$e($twitterCard)}">
<meta name="twitter:title" content="{$e($fullTitle)}">
<meta name="twitter:description" content="{$e($description)}">
<script type="application/ld+json">{$jsonLdJson}</script>
{$mediaPreconnect}<link rel="stylesheet" href="{$e(SITE_BASE)}/static/style.css?v={$styleVersion}">
<script src="{$e(SITE_BASE)}/static/autocomplete.js?v={$autocompleteVersion}" defer></script>
<script src="{$e(SITE_BASE)}/static/tag-explanations.js?v={$tagExplanationsVersion}" defer></script>
</head>
<body>
HTML;
        if ($adultWarningEnabled) {
            echo <<<'HTML'
<div id="adult-warning" class="adult-warning" role="dialog" aria-modal="true" aria-labelledby="adult-warning-title" hidden>
  <div class="adult-warning-card">
    <h1 id="adult-warning-title">Are you 18 or older?</h1>
    <p>This website contains adult content. You must be at least 18 years old to continue.</p>
    <div class="adult-warning-actions">
      <button id="adult-warning-yes" type="button">Yes, I am 18+</button>
      <button id="adult-warning-no" type="button" class="secondary">No</button>
    </div>
  </div>
</div>
<script>
(() => {
  const warning = document.getElementById('adult-warning');
  const hasAdultCookie = document.cookie.split('; ').includes('adult=true');
  let accepted = hasAdultCookie;

  try {
    accepted = localStorage.getItem('adult') === 'true' || hasAdultCookie;
  } catch (_) {}

  if (!accepted) {
    warning.hidden = false;
    document.body.classList.add('adult-warning-open');
  }

  document.getElementById('adult-warning-yes').addEventListener('click', () => {
    try {
      localStorage.setItem('adult', 'true');
    } catch (_) {
      document.cookie = 'adult=true; Max-Age=31536000; Path=/; SameSite=Lax';
    }
    warning.hidden = true;
    document.body.classList.remove('adult-warning-open');
  });

  document.getElementById('adult-warning-no').addEventListener('click', () => {
    window.location.replace('https://www.google.com/');
  });
})();
</script>
HTML;
        }
        echo <<<HTML
<header>
  <nav>
    <a href="{$e(SITE_BASE)}/">{$brand}</a>
HTML;
        if ($sidebarTags !== null) {
            echo '    <button class="navbar-hamburger" type="button" onclick="document.getElementById(\'sidebar\').classList.toggle(\'open\')">☰</button>' . "\n";
        }
        echo <<<HTML
    <a href="{$e(SITE_BASE)}/posts">Browse</a>
    <a href="{$e(SITE_BASE)}/upload">Upload</a>
    <a href="{$e(SITE_BASE)}/tags">Tags</a>

HTML;
        if ($user) {
            echo '    <a style="margin-left: auto;" href="' . $e(SITE_BASE) . '/user/' . $e($user['name']) . '">' . $e($user['name']) . '</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/favorites">Favorites</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/settings">Settings</a>' . "\n";
            if (Auth::can('access_admin_panel', $user) || Auth::can('manage_post_reports', $user) || Auth::can('manage_database_backups', $user)) {
                echo '    <a href="' . $e(SITE_BASE) . '/admin">Panel</a>' . "\n";
            }
            if (Auth::can('manage_scraper', $user)) {
                echo '    <a href="' . $e(SITE_BASE) . '/scraper">Scraper</a>' . "\n";
            }
            echo '    <a href="' . $e(SITE_BASE) . '/logout">Logout</a>' . "\n";
        } else {
            echo '    <a style="margin-left: auto;" href="' . $e(SITE_BASE) . '/login">Login</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/register">Register</a>' . "\n";
        }
        echo <<<HTML
  </nav>
</header>
<div id="container">
HTML;
        if ($sidebarTags !== null) {
            self::sidebar($sidebarTags, $user);
            echo '<main>';
        } else {
            echo '<main class="full-width">';
        }
    }

    public static function footer(): void
    {
        echo <<<HTML
</main>
</div>
</body>
</html>
HTML;
    }



    public static function flash(string $key = 'flash'): void
    {
        if (!empty($_SESSION[$key])) {
            $type = $_SESSION[$key . '_type'] ?? 'info';
            echo '<p class="flash flash-' . self::e($type) . '">' . self::e($_SESSION[$key]) . '</p>';
            unset($_SESSION[$key], $_SESSION[$key . '_type']);
        }
    }

    public static function setFlash(string $msg, string $type = 'info'): void
    {
        $_SESSION['flash']      = $msg;
        $_SESSION['flash_type'] = $type;
    }



    public static function paginator(int $currentPage, int $totalPages, string $basePath, array $params = []): void
    {
        if ($totalPages <= 1) return;

        echo '<nav class="pagination">';

        if ($totalPages <= 10) {
            $pagesToShow = range(1, $totalPages);
        } else {


            $pagesToShow = array_merge(
                [1, 2],
                range(max(1, $currentPage - 2), min($totalPages, $currentPage + 2)),
                [$totalPages - 1, $totalPages]
            );
            $pagesToShow = array_values(array_unique(array_filter(
                $pagesToShow,
                fn(int $page) => $page >= 1 && $page <= $totalPages
            )));
            sort($pagesToShow);
        }

        if ($currentPage > 1) {
            $url = self::url($basePath, array_merge($params, ['page' => $currentPage - 1]));
            echo '<a href="' . self::e($url) . '" aria-label="Previous page">‹ Prev</a> ';
        }

        $prev = null;
        foreach ($pagesToShow as $i) {
            if ($prev !== null && $i > $prev + 1) {
                echo '<span class="ellipsis">[ ... ]</span> ';
            }
            $url    = self::url($basePath, array_merge($params, ['page' => $i]));
            $active = ($i === $currentPage) ? ' class="active" aria-current="page"' : '';
            echo "<a href=\"{$url}\"{$active}>{$i}</a> ";
            $prev = $i;
        }

        if ($currentPage < $totalPages) {
            $url = self::url($basePath, array_merge($params, ['page' => $currentPage + 1]));
            echo '<a href="' . self::e($url) . '" aria-label="Next page">Next ›</a>';
        }

        echo '</nav>';
    }



    public static function postGrid(array $posts): void
    {
        if (!$posts) {
            echo '<p>No posts found.</p>';
            return;
        }
        echo '<div class="post-grid">';
        foreach ($posts as $index => $p) {
            $thumbUrl  = Image::thumbUrl($p['filename']);
            $postUrl   = View::url('/post/' . $p['id']);
            $mime      = $p['mime'] ?? '';
            $ext       = strtolower(pathinfo($p['filename'], PATHINFO_EXTENSION));

            $typeClass = '';
            if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov'], true)) {
                $typeClass = ' is-video';
            } elseif ($mime === 'image/gif' || $ext === 'gif') {
                $typeClass = ' is-gif';
            }

            echo '<a href="' . self::e($postUrl) . '" class="post-thumb' . $typeClass . '">';
            $loading = $index < 8 ? 'eager' : 'lazy';
            $priority = $index < 4 ? 'high' : 'auto';
            echo '<img src="' . self::e($thumbUrl) . '" alt="post #' . self::e($p['id']) . '" loading="' . $loading . '" fetchpriority="' . $priority . '" decoding="async" width="' . THUMB_WIDTH . '" height="' . THUMB_HEIGHT . '">';
            echo '</a>';
        }
        echo '</div>';
    }



    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): void
    {
        echo '<input type="hidden" name="csrf_token" value="' . self::e(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals(self::csrfToken(), $token)) {
            http_response_code(403);
            die('CSRF token mismatch.');
        }
    }



    public static function sidebar(array $tags = [], ?array $user = null): void
    {
        echo '<div id="sidebar">';
        echo '<div class="sidebar-search-heading"><h5>Search</h5><a href="' . self::url('/search-help') . '">[ search help ]</a></div>';
        echo '<form method="get" action="' . self::url('/posts') . '">';
        echo '<div class="form-group"><input type="text" name="q" placeholder="Tags..." value="' . self::e($_GET['q'] ?? '') . '" style="width:100%"></div>';
        echo '<button type="submit">Search</button>';
        echo '</form>';

        if ($tags) {
            if (!$user && class_exists('Auth')) {
                $user = Auth::current();
            }
            if ($user && !empty($user['blacklist'])) {
                $blacklisted = Post::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
                $blacklistedMap = array_flip($blacklisted);
                $tags = array_values(array_filter($tags, fn($t) => !isset($blacklistedMap[strtolower($t['name'])])));
            }
            $tags = array_slice($tags, 0, 40);
            if ($tags) {
                echo '<h5>Tags</h5>';
                echo '<ul class="tag-list">';
                foreach ($tags as $t) {
                    $category = Post::normalizeTagCategory((string)($t['category'] ?? 'general'));
                    echo '<li class="tag-category-' . self::e($category) . '"><span class="tag-link-wrap"><button type="button" class="tag-help" data-tag="' . self::e($t['name']) . '" aria-label="Explain tag ' . self::e($t['name']) . '" title="Explain this tag">?</button>';
                    echo '<a href="' . self::url('/posts', ['q' => $t['name']]) . '">' . self::e($t['name']) . '</a></span>';
                    if (isset($t['count'])) echo ' <span class="tag-count">(' . $t['count'] . ')</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }

        echo '</div>';
    }
}
