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

            CREATE TABLE IF NOT EXISTS favorites (
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL DEFAULT (unixepoch()),
                PRIMARY KEY (user_id, post_id)
            );

            CREATE INDEX IF NOT EXISTS idx_posts_created ON posts(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_post_tags_post ON post_tags(post_id);
            CREATE INDEX IF NOT EXISTS idx_post_tags_tag  ON post_tags(tag_id);
            CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);
        ");

        // Add quality column if it doesn't exist yet (migration)
        $cols = $pdo->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_ASSOC);
        $hasQuality = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'quality') { $hasQuality = true; break; }
        }
        if (!$hasQuality) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN quality TEXT NOT NULL DEFAULT 'medium'");
        }

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

    public static function lastId(): string
    {
        return self::get()->lastInsertId();
    }
}
