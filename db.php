<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

class DB
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS roles (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                slug        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                permissions TEXT NOT NULL DEFAULT '[]',
                is_system   INTEGER NOT NULL DEFAULT 0,
                created_at  INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password   TEXT NOT NULL,
                email      TEXT,
                role       TEXT NOT NULL DEFAULT 'user',
                api_key    TEXT UNIQUE,
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS posts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
                filename   TEXT NOT NULL UNIQUE,
                ext        TEXT NOT NULL,
                mime       TEXT NOT NULL,
                filesize   INTEGER NOT NULL,
                width      INTEGER,
                height     INTEGER,
                md5        TEXT NOT NULL UNIQUE,
                rating     TEXT NOT NULL DEFAULT 'q',
                source     TEXT,
                title      TEXT,
                score      INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS tags (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                count INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS tag_aliases (
                alias     TEXT PRIMARY KEY COLLATE NOCASE,
                canonical TEXT NOT NULL COLLATE NOCASE,
                CHECK (alias <> canonical)
            );
            CREATE INDEX IF NOT EXISTS idx_tag_aliases_canonical ON tag_aliases(canonical);

            CREATE TABLE IF NOT EXISTS tag_explanations (
                tag         TEXT PRIMARY KEY COLLATE NOCASE,
                description TEXT NOT NULL,
                source      TEXT NOT NULL DEFAULT 'generated',
                updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS post_tags (
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                tag_id  INTEGER NOT NULL REFERENCES tags(id)  ON DELETE CASCADE,
                PRIMARY KEY (post_id, tag_id)
            );

            CREATE TABLE IF NOT EXISTS comments (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
                guest_name TEXT,
                body       TEXT NOT NULL,
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS votes (
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                value   INTEGER NOT NULL,
                PRIMARY KEY (post_id, user_id)
            );

            CREATE TABLE IF NOT EXISTS scraper_tasks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tag        TEXT NOT NULL,
                source     TEXT NOT NULL DEFAULT 'realbooru',
                blacklist  TEXT NOT NULL DEFAULT '',
                pid        INTEGER,
                status     TEXT NOT NULL DEFAULT 'running',
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS scraper_progress (
                source     TEXT NOT NULL,
                tag        TEXT NOT NULL COLLATE NOCASE,
                next_pid   INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (source, tag)
            );

            CREATE TABLE IF NOT EXISTS favorites (
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL DEFAULT (unixepoch()),
                PRIMARY KEY (user_id, post_id)
            );

            CREATE TABLE IF NOT EXISTS post_reports (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id          INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                reporter_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                reason           TEXT NOT NULL,
                status           TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'resolved', 'dismissed')),
                resolved_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at       INTEGER NOT NULL DEFAULT (unixepoch()),
                resolved_at      INTEGER,
                UNIQUE(post_id, reporter_user_id)
            );

            CREATE TABLE IF NOT EXISTS site_settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS registration_requests (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                name                TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password            TEXT NOT NULL,
                email               TEXT,
                registration_reason TEXT NOT NULL DEFAULT '',
                created_at          INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket       TEXT NOT NULL,
                subject      TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                count        INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, subject)
            );

            CREATE TABLE IF NOT EXISTS login_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );
            CREATE INDEX IF NOT EXISTS idx_login_events_created_user ON login_events(created_at, user_id);
            CREATE INDEX IF NOT EXISTS idx_login_events_user ON login_events(user_id);

            CREATE TABLE IF NOT EXISTS post_browsing_events (
                created_at INTEGER NOT NULL DEFAULT (unixepoch()),
                ip TEXT NOT NULL,
                PRIMARY KEY (created_at, ip)
            );

            CREATE TABLE IF NOT EXISTS ip_country_cache (
                ip TEXT PRIMARY KEY,
                country_code TEXT NOT NULL DEFAULT '',
                expires_at INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_post_tags_post ON post_tags(post_id);
            CREATE INDEX IF NOT EXISTS idx_post_tags_tag  ON post_tags(tag_id);
            CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);
            CREATE INDEX IF NOT EXISTS idx_post_reports_status_created ON post_reports(status, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_registration_requests_created ON registration_requests(created_at ASC);
        ");


        $pdo->exec("INSERT OR IGNORE INTO roles (name, slug, permissions, is_system) VALUES ('Administrator', 'admin', '[\"*\"]', 1)");
        $pdo->exec("INSERT OR IGNORE INTO roles (name, slug, permissions, is_system) VALUES ('User', 'user', '[]', 1)");


        $userCols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
        foreach (['avatar', 'banner', 'biography', 'display_name', 'last_ip', 'country_code'] as $profileColumn) {
            if (!in_array($profileColumn, array_column($userCols, 'name'), true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN $profileColumn TEXT NOT NULL DEFAULT ''");
            }
        }
        $requestCols = array_column($pdo->query('PRAGMA table_info(registration_requests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['registration_ip', 'country_code'] as $column) {
            if (!in_array($column, $requestCols, true)) {
                $pdo->exec("ALTER TABLE registration_requests ADD COLUMN $column TEXT NOT NULL DEFAULT ''");
            }
        }
        $taskCols = array_column($pdo->query('PRAGMA table_info(scraper_tasks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('source', $taskCols, true)) {
            $pdo->exec("ALTER TABLE scraper_tasks ADD COLUMN source TEXT NOT NULL DEFAULT 'realbooru'");
        }
        if (!in_array('blacklist', $taskCols, true)) {
            $pdo->exec("ALTER TABLE scraper_tasks ADD COLUMN blacklist TEXT NOT NULL DEFAULT ''");
        }
        $hasBlacklist = false;
        foreach ($userCols as $col) {
            if ($col['name'] === 'blacklist') { $hasBlacklist = true; break; }
        }
        if (!$hasBlacklist) {
            $pdo->exec("ALTER TABLE users ADD COLUMN blacklist TEXT NOT NULL DEFAULT ''");
        }


        $hasRegistrationReason = false;
        foreach ($userCols as $col) {
            if ($col['name'] === 'registration_reason') { $hasRegistrationReason = true; break; }
        }
        if (!$hasRegistrationReason) {
            $pdo->exec("ALTER TABLE users ADD COLUMN registration_reason TEXT NOT NULL DEFAULT ''");
        }


        $cols = $pdo->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_ASSOC);
        $hasQuality = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'quality') { $hasQuality = true; break; }
        }
        if (!$hasQuality) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN quality TEXT NOT NULL DEFAULT 'medium'");
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_quality ON posts(quality)');

        // The old source-specific classification is now the general real-life tag.
        // Merge associations when real_life already exists, then refresh its count.
        $legacyRealbooruId = $pdo->query("SELECT id FROM tags WHERE name = 'realbooru' COLLATE NOCASE")->fetchColumn();
        if ($legacyRealbooruId !== false) {
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('real_life')");
                $realLifeId = (int)$pdo->query("SELECT id FROM tags WHERE name = 'real_life' COLLATE NOCASE")->fetchColumn();
                $merge = $pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) SELECT post_id, ? FROM post_tags WHERE tag_id = ?');
                $merge->execute([$realLifeId, (int)$legacyRealbooruId]);
                $deleteLinks = $pdo->prepare('DELETE FROM post_tags WHERE tag_id = ?');
                $deleteLinks->execute([(int)$legacyRealbooruId]);
                $deleteTag = $pdo->prepare('DELETE FROM tags WHERE id = ?');
                $deleteTag->execute([(int)$legacyRealbooruId]);
                $refreshCount = $pdo->prepare('UPDATE tags SET count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = ?) WHERE id = ?');
                $refreshCount->execute([$realLifeId, $realLifeId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $legacyDrawnId = $pdo->query("SELECT id FROM tags WHERE name = 'drawn' COLLATE NOCASE")->fetchColumn();
        if ($legacyDrawnId !== false) {
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('artwork')");
                $artworkId = (int)$pdo->query("SELECT id FROM tags WHERE name = 'artwork' COLLATE NOCASE")->fetchColumn();
                $merge = $pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) SELECT post_id, ? FROM post_tags WHERE tag_id = ?');
                $merge->execute([$artworkId, (int)$legacyDrawnId]);
                $deleteLinks = $pdo->prepare('DELETE FROM post_tags WHERE tag_id = ?');
                $deleteLinks->execute([(int)$legacyDrawnId]);
                $deleteTag = $pdo->prepare('DELETE FROM tags WHERE id = ?');
                $deleteTag->execute([(int)$legacyDrawnId]);
                $refreshCount = $pdo->prepare('UPDATE tags SET count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = ?) WHERE id = ?');
                $refreshCount->execute([$artworkId, $artworkId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // Keep aliases as data, so autocomplete and every importer can use the
        // same registry even after the duplicate tag itself has been removed.
        $saveAlias = $pdo->prepare('INSERT INTO tag_aliases (alias, canonical) VALUES (?, ?)
                                    ON CONFLICT(alias) DO UPDATE SET canonical = excluded.canonical
                                    WHERE tag_aliases.canonical <> excluded.canonical');
        foreach (TAG_ALIASES as $alias => $canonical) {
            if (strcasecmp($alias, $canonical) !== 0) $saveAlias->execute([$alias, $canonical]);
        }

        // Separator-only variants are safe duplicates: close-up, close_up and
        // closeup describe the same tag. Prefer the most-used spelling.
        $currentTagMaxId = (string)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM tags')->fetchColumn() ?: '0');
        $lastSeparatorScan = (string)($pdo->query("SELECT value FROM site_settings WHERE key = 'tag_alias_separator_scan_max_id'")->fetchColumn() ?: '');
        if ($currentTagMaxId !== $lastSeparatorScan) {
            $separatorGroups = [];
            foreach ($pdo->query('SELECT name, count FROM tags')->fetchAll(PDO::FETCH_ASSOC) as $tag) {
                $key = strtolower((string)preg_replace('/[-_\s]+/', '', $tag['name']));
                if ($key !== '') $separatorGroups[$key][] = $tag;
            }
            $saveDiscoveredAlias = $pdo->prepare('INSERT OR IGNORE INTO tag_aliases (alias, canonical) VALUES (?, ?)');
            foreach ($separatorGroups as $group) {
                if (count($group) < 2) continue;
                usort($group, static fn(array $a, array $b): int => ((int)$b['count'] <=> (int)$a['count']) ?: (strlen($a['name']) <=> strlen($b['name'])));
                $canonical = $group[0]['name'];
                foreach (array_slice($group, 1) as $duplicate) {
                    if (strcasecmp($duplicate['name'], $canonical) !== 0) {
                        $saveDiscoveredAlias->execute([$duplicate['name'], $canonical]);
                    }
                }
            }
        }

        // Collapse chains such as self_pic -> selfpic -> selfie so migration
        // never recreates an intermediate alias as if it were canonical.
        $aliasMap = [];
        foreach ($pdo->query('SELECT alias, canonical FROM tag_aliases')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aliasMap[strtolower($row['alias'])] = strtolower($row['canonical']);
        }
        $flattenAlias = $pdo->prepare('UPDATE tag_aliases SET canonical = ? WHERE alias = ? COLLATE NOCASE');
        foreach ($aliasMap as $alias => $canonical) {
            $originalCanonical = $canonical;
            $seen = [$alias => true];
            while (isset($aliasMap[$canonical]) && !isset($seen[$canonical])) {
                $seen[$canonical] = true;
                $canonical = $aliasMap[$canonical];
            }
            if (!isset($seen[$canonical]) && $canonical !== $originalCanonical) {
                $flattenAlias->execute([$canonical, $alias]);
            }
        }

        $hasAliasTags = (bool)$pdo->query("SELECT EXISTS(
            SELECT 1 FROM tags t INNER JOIN tag_aliases a ON a.alias = t.name COLLATE NOCASE
        )")->fetchColumn();
        if ($hasAliasTags) {
            $pdo->beginTransaction();
            try {
            $pdo->exec("INSERT OR IGNORE INTO tags (name)
                        SELECT DISTINCT a.canonical
                        FROM tag_aliases a
                        INNER JOIN tags old ON old.name = a.alias COLLATE NOCASE");
            $pdo->exec("INSERT OR IGNORE INTO post_tags (post_id, tag_id)
                        SELECT pt.post_id, canonical.id
                        FROM post_tags pt
                        INNER JOIN tags old ON old.id = pt.tag_id
                        INNER JOIN tag_aliases a ON a.alias = old.name COLLATE NOCASE
                        INNER JOIN tags canonical ON canonical.name = a.canonical COLLATE NOCASE");
            $pdo->exec("DELETE FROM post_tags
                        WHERE tag_id IN (
                            SELECT old.id FROM tags old
                            INNER JOIN tag_aliases a ON a.alias = old.name COLLATE NOCASE
                        )");
            $pdo->exec("DELETE FROM tags
                        WHERE id IN (
                            SELECT old.id FROM tags old
                            INNER JOIN tag_aliases a ON a.alias = old.name COLLATE NOCASE
                        )");
            $pdo->exec("UPDATE tags
                        SET count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = tags.id)
                        WHERE name IN (SELECT canonical FROM tag_aliases)");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        $finalTagMaxId = (string)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM tags')->fetchColumn() ?: '0');
        $saveSeparatorScan = $pdo->prepare("INSERT INTO site_settings (key, value) VALUES ('tag_alias_separator_scan_max_id', ?)
                                            ON CONFLICT(key) DO UPDATE SET value = excluded.value
                                            WHERE site_settings.value <> excluded.value");
        $saveSeparatorScan->execute([$finalTagMaxId]);

        $missingE621Artwork = (bool)$pdo->query("
            SELECT EXISTS(
                SELECT 1 FROM posts p
                WHERE (p.title LIKE 'e621 #%' OR lower(COALESCE(p.source, '')) LIKE '%e621.net/%')
                  AND NOT EXISTS (
                      SELECT 1 FROM post_tags pt
                      INNER JOIN tags t ON t.id = pt.tag_id
                      WHERE pt.post_id = p.id AND t.name = 'artwork' COLLATE NOCASE
                  )
            )
        ")->fetchColumn();
        if ($missingE621Artwork) {
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('artwork')");
                $artworkId = (int)$pdo->query("SELECT id FROM tags WHERE name = 'artwork' COLLATE NOCASE")->fetchColumn();
                $assignArtwork = $pdo->prepare("
                    INSERT OR IGNORE INTO post_tags (post_id, tag_id)
                    SELECT p.id, ? FROM posts p
                    WHERE p.title LIKE 'e621 #%'
                       OR lower(COALESCE(p.source, '')) LIKE '%e621.net/%'
                ");
                $assignArtwork->execute([$artworkId]);
                $refreshCount = $pdo->prepare('UPDATE tags SET count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = ?) WHERE id = ?');
                $refreshCount->execute([$artworkId, $artworkId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }


        $pdo->exec("
            UPDATE posts SET quality = CASE
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 3840 OR min(COALESCE(width,0), COALESCE(height,0)) >= 2160 THEN 'ultra'
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 1920 OR min(COALESCE(width,0), COALESCE(height,0)) >= 1080 THEN 'high'
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 1280 OR min(COALESCE(width,0), COALESCE(height,0)) >= 720 THEN 'medium'
                ELSE 'low'
            END
            WHERE width IS NOT NULL AND height IS NOT NULL;
        ");


        $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $hash   = password_hash('admin', PASSWORD_DEFAULT);
            $apikey = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, password, email, role, api_key) VALUES ('admin', ?, 'admin@localhost', 'admin', ?)"
            );
            $stmt->execute([$hash, $apikey]);
        }
    }



    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function rows(string $sql, array $params = []): array
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }


    public static function consumeRateLimit(string $bucket, string $subject, int $max, int $windowSeconds): bool
    {
        $now = time();
        $cutoff = $now - $windowSeconds;
        self::exec(
            'INSERT INTO rate_limits (bucket, subject, window_start, count) VALUES (?, ?, ?, 1)
             ON CONFLICT(bucket, subject) DO UPDATE SET
                 count = CASE WHEN rate_limits.window_start <= ? THEN 1 ELSE rate_limits.count + 1 END,
                 window_start = CASE WHEN rate_limits.window_start <= ? THEN excluded.window_start ELSE rate_limits.window_start END',
            [$bucket, $subject, $now, $cutoff, $cutoff]
        );
        $count = (int)self::scalar(
            'SELECT count FROM rate_limits WHERE bucket = ? AND subject = ?',
            [$bucket, $subject]
        );

        if (random_int(1, 100) === 1) {
            self::exec('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }

        return $count <= $max;
    }

    public static function lastId(): string
    {
        return self::get()->lastInsertId();
    }
}
