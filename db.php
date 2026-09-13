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
                pid        INTEGER,
                status     TEXT NOT NULL DEFAULT 'running',
                created_at INTEGER NOT NULL DEFAULT (unixepoch())
            );

            CREATE TABLE IF NOT EXISTS favorites (
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL DEFAULT (unixepoch()),
                PRIMARY KEY (user_id, post_id)
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

            CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_post_tags_post ON post_tags(post_id);
            CREATE INDEX IF NOT EXISTS idx_post_tags_tag  ON post_tags(tag_id);
            CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);
            CREATE INDEX IF NOT EXISTS idx_posts_quality  ON posts(quality);
            CREATE INDEX IF NOT EXISTS idx_registration_requests_created ON registration_requests(created_at ASC);
        ");

        // Built-in roles are immutable safeguards. Custom roles are managed in /admin.
        $pdo->exec("INSERT OR IGNORE INTO roles (name, slug, permissions, is_system) VALUES ('Administrator', 'admin', '[\"*\"]', 1)");
        $pdo->exec("INSERT OR IGNORE INTO roles (name, slug, permissions, is_system) VALUES ('User', 'user', '[]', 1)");

        // Add blacklist column to users if missing
        $userCols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
        $hasBlacklist = false;
        foreach ($userCols as $col) {
            if ($col['name'] === 'blacklist') { $hasBlacklist = true; break; }
        }
        if (!$hasBlacklist) {
            $pdo->exec("ALTER TABLE users ADD COLUMN blacklist TEXT NOT NULL DEFAULT ''");
        }

        // Store the optional reason supplied during registration.
        $hasRegistrationReason = false;
        foreach ($userCols as $col) {
            if ($col['name'] === 'registration_reason') { $hasRegistrationReason = true; break; }
        }
        if (!$hasRegistrationReason) {
            $pdo->exec("ALTER TABLE users ADD COLUMN registration_reason TEXT NOT NULL DEFAULT ''");
        }

        // Add quality column if it doesn't exist yet (migration)
        $cols = $pdo->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_ASSOC);
        $hasQuality = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'quality') { $hasQuality = true; break; }
        }
        if (!$hasQuality) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN quality TEXT NOT NULL DEFAULT 'medium'");
        }

        // Auto-calculate quality for posts based on resolution (width & height)
        $pdo->exec("
            UPDATE posts SET quality = CASE 
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 3840 OR min(COALESCE(width,0), COALESCE(height,0)) >= 2160 THEN 'ultra'
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 1920 OR min(COALESCE(width,0), COALESCE(height,0)) >= 1080 THEN 'high'
                WHEN max(COALESCE(width,0), COALESCE(height,0)) >= 1280 OR min(COALESCE(width,0), COALESCE(height,0)) >= 720 THEN 'medium'
                ELSE 'low'
            END
            WHERE width IS NOT NULL AND height IS NOT NULL;
        ");

        // Seed admin user if no users exist
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

    // ---------- helpers ----------

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

    /** Atomically consume one action from a fixed-window rate limit. */
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
