<?php
declare(strict_types=1);

/**
 * Minimal templating helpers.
 */
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

    // ── Site settings ─────────────────────────────────────────────────────────

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
        self::$siteSettings = null; // bust cache
    }

    // ── Layout ────────────────────────────────────────────────────────────────

    public static function header(string $title, ?array $user = null, ?array $sidebarTags = null): void
    {
        $siteName = self::siteSetting('site_name', SITE_NAME);
        $siteLogo = self::siteSetting('site_logo');
        $siteBanner = self::siteSetting('site_banner');
        $e = fn($v) => self::e($v);

        // Logo / banner brand markup
        if ($siteLogo) {
            $brand = '<img src="' . $e($siteLogo) . '" alt="' . $e($siteName) . '" style="height:32px;vertical-align:middle">';
        } elseif ($siteBanner) {
            $brand = '<img src="' . $e($siteBanner) . '" alt="' . $e($siteName) . '" style="height:130px;vertical-align:middle">';
        } else {
            $brand = $e($siteName);
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($title)} - {$e($siteName)}</title>
<link rel="stylesheet" href="{$e(SITE_BASE)}/static/style.css">
<script src="{$e(SITE_BASE)}/static/autocomplete.js" defer></script>
</head>
<body>
<header>
  <nav>
    <a href="{$e(SITE_BASE)}/">{$brand}</a>
    <a href="{$e(SITE_BASE)}/posts">Browse</a>
    <a href="{$e(SITE_BASE)}/upload">Upload</a>
    <a href="{$e(SITE_BASE)}/tags">Tags</a>

HTML;
        if ($user) {
            echo '    <a href="' . $e(SITE_BASE) . '/user/' . $e($user['name']) . '">' . $e($user['name']) . '</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/favorites">Favorites</a>' . "\n";
            echo '    <a href="' . $e(SITE_BASE) . '/settings">Settings</a>' . "\n";
            if ($user['role'] === 'admin') {
                echo '    <a href="' . $e(SITE_BASE) . '/admin">Panel</a>' . "\n";
            }
            echo '    <a href="' . $e(SITE_BASE) . '/logout">Logout</a>' . "\n";
        } else {
            echo '    <a href="' . $e(SITE_BASE) . '/login">Login</a>' . "\n";
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

    // ── Flash messages ────────────────────────────────────────────────────────

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

    // ── Pagination ────────────────────────────────────────────────────────────

    public static function paginator(int $currentPage, int $totalPages, string $basePath, array $params = []): void
    {
        if ($totalPages <= 1) return;

        echo '<nav class="pagination">';

        if ($totalPages <= 10) {
            $pagesToShow = range(1, $totalPages);
        } else {
            $pagesToShow = range(1, 5);
            if ($currentPage > 5 && $currentPage < $totalPages - 4) {
                $pagesToShow[] = $currentPage;
            }
            for ($i = $totalPages - 4; $i <= $totalPages; $i++) {
                $pagesToShow[] = $i;
            }
            $pagesToShow = array_unique($pagesToShow);
            sort($pagesToShow);
        }

        $prev = null;
        foreach ($pagesToShow as $i) {
            if ($prev !== null && $i > $prev + 1) {
                echo '<span class="ellipsis">[ ... ]</span> ';
            }
            $url    = self::url($basePath, array_merge($params, ['page' => $i]));
            $active = ($i === $currentPage) ? ' class="active"' : '';
            echo "<a href=\"{$url}\"{$active}>{$i}</a> ";
            $prev = $i;
        }

        echo '</nav>';
    }

    // ── Post grid ─────────────────────────────────────────────────────────────

    public static function postGrid(array $posts): void
    {
        if (!$posts) {
            echo '<p>No posts found.</p>';
            return;
        }
        echo '<div class="post-grid">';
        foreach ($posts as $p) {
            $thumbUrl  = Image::thumbUrl($p['filename']);
            $postUrl   = View::url('/post/' . $p['id']);
            $mime      = $p['mime'] ?? '';
            $ext       = strtolower(pathinfo($p['filename'], PATHINFO_EXTENSION));

            $typeClass = '';
            if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm'], true)) {
                $typeClass = ' is-video';
            } elseif ($mime === 'image/gif' || $ext === 'gif') {
                $typeClass = ' is-gif';
            }

            echo '<a href="' . self::e($postUrl) . '" class="post-thumb' . $typeClass . '">';
            echo '<img src="' . self::e($thumbUrl) . '" alt="post #' . self::e($p['id']) . '" loading="lazy">';
            echo '</a>';
        }
        echo '</div>';
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

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

    // ── Sidebar ───────────────────────────────────────────────────────────────

    public static function sidebar(array $tags = [], ?array $user = null): void
    {
        $quality = in_array($_GET['quality'] ?? '', ['low', 'medium', 'high', 'ultra'], true)
                   ? $_GET['quality'] : '';
        $qualities = [
            ''       => 'All Qualities',
            'low'    => 'Low (<720p)',
            'medium' => 'Medium (720p)',
            'high'   => 'High (FHD)',
            'ultra'  => 'Ultra (4K)',
        ];

        echo '<div id="sidebar">';
        echo '<h5>Search</h5>';
        echo '<form method="get" action="' . self::url('/posts') . '">';
        echo '<div class="form-group"><input type="text" name="q" placeholder="Tags..." value="' . self::e($_GET['q'] ?? '') . '" style="width:100%"></div>';
        echo '<div class="form-group"><select name="quality" style="width:100%">';
        foreach ($qualities as $val => $label) {
            $sel = $quality === $val ? ' selected' : '';
            echo '<option value="' . self::e($val) . '"' . $sel . '>' . self::e($label) . '</option>';
        }
        echo '</select></div>';
        if (!empty($_GET['rating'])) {
            echo '<input type="hidden" name="rating" value="' . self::e($_GET['rating']) . '">';
        }
        if (!empty($_GET['order'])) {
            echo '<input type="hidden" name="order" value="' . self::e($_GET['order']) . '">';
        }
        echo '<button type="submit">Search</button>';
        echo '</form>';

        if ($tags) {
            if (!$user && class_exists('Auth')) {
                $user = Auth::current();
            }
            if ($user && !empty($user['blacklist'])) {
                $blacklisted = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
                $blacklistedMap = array_flip($blacklisted);
                $tags = array_values(array_filter($tags, fn($t) => !isset($blacklistedMap[strtolower($t['name'])])));
            }
            $tags = array_slice($tags, 0, 20);
            if ($tags) {
                echo '<h5>Tags</h5>';
                echo '<ul class="tag-list">';
                foreach ($tags as $t) {
                    echo '<li><a href="' . self::url('/posts', ['q' => $t['name']]) . '">' . self::e($t['name']) . '</a>';
                    if (isset($t['count'])) echo ' <span>(' . $t['count'] . ')</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }

        echo '</div>';
    }
}
