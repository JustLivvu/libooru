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
        $showSiteHeader = $relativePath !== '/';
        $postsPage = $relativePath === '/posts';
        $headerClass = $showSiteHeader ? ' class="site-header"' : '';
        $postsLinkLabel = $showSiteHeader ? 'Posts' : 'Browse';
        $postsLinkClass = ($postsPage || str_starts_with($relativePath, '/post/'))
            ? ' class="navbar-current" aria-current="page"' : '';
        $uploadLinkClass = $relativePath === '/upload' ? ' class="navbar-current" aria-current="page"' : '';
        $tagsLinkClass = $relativePath === '/tags' ? ' class="navbar-current" aria-current="page"' : '';
        $commentsLinkClass = $relativePath === '/comments' ? ' class="navbar-current" aria-current="page"' : '';
        $favoritesLinkClass = $relativePath === '/favorites' ? ' class="navbar-current" aria-current="page"' : '';
        $sidebarToggleLabel = $showSiteHeader ? 'Filters' : 'Search';
        $isPublicPage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && http_response_code() < 400
            && (bool)preg_match('#^/(?:$|posts$|comments$|tags$|terms$|search-help$|post/\d+$)#', $relativePath);
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
            $brand = '<img class="navbar-brand-image" src="' . $e($siteLogo) . '" alt="' . $e($siteName) . '">';
        } elseif ($siteBanner) {
            $brand = '<img class="navbar-brand-image navbar-brand-banner" src="' . $e($siteBanner) . '" alt="' . $e($siteName) . '">';
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
<header{$headerClass}>
  <nav class="site-nav" aria-label="Main navigation">
    <a class="navbar-brand" href="{$e(SITE_BASE)}/">{$brand}</a>
HTML;
        if ($sidebarTags !== null) {
            echo '    <button class="navbar-search-toggle" type="button" aria-controls="sidebar" aria-expanded="false">' . $sidebarToggleLabel . '</button>' . "\n";
        }
        echo <<<HTML
    <button class="navbar-menu-toggle" type="button" aria-controls="navbar-links" aria-expanded="false">Menu <span aria-hidden="true">☰</span></button>
    <div class="navbar-links" id="navbar-links">
    <a{$postsLinkClass} href="{$e(SITE_BASE)}/posts">{$postsLinkLabel}</a>
    <a{$uploadLinkClass} href="{$e(SITE_BASE)}/upload">Upload</a>
    <a{$tagsLinkClass} href="{$e(SITE_BASE)}/tags">Tags</a>
    <a{$commentsLinkClass} href="{$e(SITE_BASE)}/comments">Comments</a>

HTML;
        if ($user) {
            echo '    <a class="navbar-account-link" href="' . $e(SITE_BASE) . '/user/' . $e($user['name']) . '">' . $e($user['name']) . '</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/settings">Settings</a>' . "\n";
            if (Auth::can('access_admin_panel', $user) || Auth::can('manage_post_reports', $user)
                || Auth::can('manage_database_backups', $user) || Auth::can('manage_scraper', $user)) {
                $panelClass = in_array($relativePath, ['/admin', '/scraper'], true)
                    ? ' class="navbar-current"' . ($relativePath === '/admin' ? ' aria-current="page"' : '')
                    : '';
                echo '    <a' . $panelClass . ' href="' . $e(SITE_BASE) . '/admin">Panel</a>' . "\n";
            }
            echo '    <a href="' . $e(SITE_BASE) . '/logout">Logout</a>' . "\n";
        } else {
            echo '    <a class="navbar-account-link" href="' . $e(SITE_BASE) . '/login">Login</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/register">Register</a>' . "\n";
        }
        echo <<<HTML
    </div>
  </nav>
HTML;
        if ($showSiteHeader) {
            echo <<<HTML
  <div class="site-subnav" role="navigation" aria-label="Quick links">
    <a href="{$e(SITE_BASE)}/posts">Listing</a>
    <a href="{$e(SITE_BASE)}/posts?order=score%20DESC">Top</a>
    <a{$favoritesLinkClass} href="{$e(SITE_BASE)}/favorites">Favorites</a>
    <a href="{$e(SITE_BASE)}/search-help">Help</a>
  </div>
HTML;
        }
        echo <<<HTML
</header>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const header = document.querySelector('body > header');
  const updateHeaderHeight = () => {
    document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
  };
  updateHeaderHeight();
  if ('ResizeObserver' in window) {
    new ResizeObserver(updateHeaderHeight).observe(header);
  } else {
    window.addEventListener('resize', updateHeaderHeight);
  }

  const menuButton = document.querySelector('.navbar-menu-toggle');
  const links = document.getElementById('navbar-links');
  const searchButton = document.querySelector('.navbar-search-toggle');
  const sidebar = document.getElementById('sidebar');

  menuButton.addEventListener('click', () => {
    const open = links.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
    if (open && searchButton && sidebar) {
      sidebar.classList.remove('open');
      searchButton.setAttribute('aria-expanded', 'false');
    }
  });

  if (searchButton && sidebar) {
    searchButton.addEventListener('click', () => {
      const open = sidebar.classList.toggle('open');
      searchButton.setAttribute('aria-expanded', String(open));
      if (open) {
        links.classList.remove('open');
        menuButton.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.site-nav') && links.classList.contains('open')) {
      links.classList.remove('open');
      menuButton.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && links.classList.contains('open')) {
      links.classList.remove('open');
      menuButton.setAttribute('aria-expanded', 'false');
      menuButton.focus();
    }
  });
});
</script>
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

        $currentPage = max(1, min($currentPage, $totalPages));
        $chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
        echo '<nav class="pagination" aria-label="Pagination">';

        if ($totalPages <= 7) {
            $pagesToShow = range(1, $totalPages);
        } else {
            if ($currentPage <= 5) {
                $window = range(1, max(5, $currentPage + 2));
            } elseif ($currentPage >= $totalPages - 4) {
                $window = range(min($totalPages - 4, $currentPage - 2), $totalPages);
            } else {
                $window = range($currentPage - 2, $currentPage + 2);
            }
            $pagesToShow = array_values(array_unique(array_merge([1], $window, [$totalPages])));
            sort($pagesToShow);
        }

        if ($currentPage > 1) {
            $url = self::url($basePath, array_merge($params, ['page' => $currentPage - 1]));
            echo '<a class="pagination-arrow" href="' . self::e($url) . '" aria-label="Previous page">' . $chevron . '</a>';
        } else {
            echo '<span class="pagination-arrow is-disabled" aria-hidden="true">' . $chevron . '</span>';
        }

        $prev = null;
        foreach ($pagesToShow as $i) {
            if ($prev !== null && $i === $prev + 2) {
                $missingPage = $prev + 1;
                $missingUrl = self::url($basePath, array_merge($params, ['page' => $missingPage]));
                echo '<a href="' . self::e($missingUrl) . '">' . $missingPage . '</a>';
            } elseif ($prev !== null && $i > $prev + 2) {
                echo '<span class="ellipsis" aria-hidden="true">…</span>';
            }
            $url    = self::url($basePath, array_merge($params, ['page' => $i]));
            $active = ($i === $currentPage) ? ' class="active" aria-current="page"' : '';
            echo '<a href="' . self::e($url) . '"' . $active . '>' . $i . '</a>';
            $prev = $i;
        }

        if ($currentPage < $totalPages) {
            $url = self::url($basePath, array_merge($params, ['page' => $currentPage + 1]));
            echo '<a class="pagination-arrow next" href="' . self::e($url) . '" aria-label="Next page">' . $chevron . '</a>';
        } else {
            echo '<span class="pagination-arrow next is-disabled" aria-hidden="true">' . $chevron . '</span>';
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
