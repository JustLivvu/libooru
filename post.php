<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image.php';

/**
 * Post model: CRUD, tag management, upload.
 */
class Post
{
    // -------- fetch --------

    public static function getById(int $id): ?array
    {
        $post = DB::row('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post) {
            $post['tags'] = self::tagsFor($id);
        }
        return $post;
    }

    public static function list(int $page, int $perPage, array $tagFilter = [], string $rating = '', string $order = 'id DESC', string $quality = ''): array
    {
        $offset = max(0, $page - 1) * $perPage;

        // Translate quality label -> height SQL fragment
        $qualitySql = match($quality) {
            'low'    => '(p.height IS NOT NULL AND p.height < 720)',
            'medium' => '(p.height >= 720 AND p.height < 1080)',
            'high'   => '(p.height >= 1080 AND p.height < 2160)',
            'ultra'  => '(p.height >= 2160)',
            default  => '',
        };

        if ($tagFilter) {
            // Intersection: posts that have ALL given tags
            $placeholders = implode(',', array_fill(0, count($tagFilter), '?'));
            $qualityAnd   = $qualitySql ? "AND $qualitySql" : '';
            $sql = "
                SELECT p.* FROM posts p
                INNER JOIN post_tags pt ON pt.post_id = p.id
                INNER JOIN tags t ON t.id = pt.tag_id
                WHERE t.name IN ($placeholders) COLLATE NOCASE
                " . ($rating ? "AND p.rating = ?" : "") . "
                $qualityAnd
                GROUP BY p.id
                HAVING COUNT(DISTINCT t.id) = " . count($tagFilter) . "
                ORDER BY p.$order
                LIMIT ? OFFSET ?
            ";
            $params = $tagFilter;
            if ($rating) $params[] = $rating;
            $params[] = $perPage;
            $params[] = $offset;

            $countSql = "
                SELECT COUNT(*) FROM (
                    SELECT p.id FROM posts p
                    INNER JOIN post_tags pt ON pt.post_id = p.id
                    INNER JOIN tags t ON t.id = pt.tag_id
                    WHERE t.name IN ($placeholders) COLLATE NOCASE
                    " . ($rating ? "AND p.rating = ?" : "") . "
                    $qualityAnd
                    GROUP BY p.id
                    HAVING COUNT(DISTINCT t.id) = " . count($tagFilter) . "
                )";
            $countParams = $tagFilter;
            if ($rating) $countParams[] = $rating;
        } else {
            $conditions = [];
            if ($rating)    $conditions[] = 'rating = ?';
            if ($qualitySql) $conditions[] = $qualitySql;
            $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

            $sql = "SELECT * FROM posts{$where} ORDER BY $order LIMIT ? OFFSET ?";
            $params = [];
            if ($rating) $params[] = $rating;
            $params[] = $perPage;
            $params[] = $offset;

            $countSql    = "SELECT COUNT(*) FROM posts{$where}";
            $countParams = $rating ? [$rating] : [];
        }

        $posts = DB::rows($sql, $params);
        $total = (int)(DB::scalar($countSql, $countParams) ?: 0);

        return ['posts' => $posts, 'total' => $total, 'pages' => (int)ceil($total / $perPage)];
    }

    // -------- tags --------

    public static function tagsFor(int $postId): array
    {
        return DB::rows(
            'SELECT t.name FROM tags t
             INNER JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id = ?
             ORDER BY t.name',
            [$postId]
        );
    }

    public static function setTags(int $postId, array $tagNames): void
    {
        // Remove old tags — update counts
        $old = DB::rows(
            'SELECT t.id FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?',
            [$postId]
        );
        DB::exec('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        foreach ($old as $row) {
            DB::exec('UPDATE tags SET count = MAX(0, count - 1) WHERE id = ?', [$row['id']]);
        }

        // Clean & deduplicate tag names
        $tagNames = array_unique(array_filter(array_map(
            fn($t) => strtolower(preg_replace('/\s+/', '_', trim($t))),
            $tagNames
        )));

        foreach ($tagNames as $name) {
            if ($name === '') continue;
            // Upsert tag
            DB::exec('INSERT OR IGNORE INTO tags (name) VALUES (?)', [$name]);
            $tagId = (int)DB::scalar('SELECT id FROM tags WHERE name = ?', [$name]);
            DB::exec('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tagId]);
            DB::exec('UPDATE tags SET count = count + 1 WHERE id = ?', [$tagId]);
        }
    }

    // -------- upload --------

    /**
     * Handle upload from $_FILES['file'].
     * Returns ['id' => int] on success or throws RuntimeException.
     */
    public static function upload(array $file, array $meta): int
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError($file['error']));
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new RuntimeException('File too large (max ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB).');
        }

        // Detect real MIME type from file content
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset(ALLOWED_TYPES[$mime])) {
            throw new RuntimeException('File type not allowed: ' . htmlspecialchars($mime));
        }
        $ext = ALLOWED_TYPES[$mime];

        $md5 = md5_file($file['tmp_name']);
        if (!$md5) throw new RuntimeException('Failed to hash file.');

        // Check duplicate
        $existing = DB::scalar('SELECT id FROM posts WHERE md5 = ?', [$md5]);
        if ($existing) {
            throw new RuntimeException('Duplicate image (post #' . $existing . ' already exists).', 409);
        }

        $filename = $md5 . '.' . $ext;
        $destPath = UPLOAD_DIR . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }
        chmod($destPath, 0644);

        [$w, $h] = Image::getDimensions($destPath, $mime);

        // Make thumbnail
        Image::makeThumbnail($destPath, Image::thumbPath($filename), $mime);

        $userId = Auth::id();
        $rating = in_array($meta['rating'] ?? '', ['s', 'q', 'e'], true) ? $meta['rating'] : 'q';

        DB::exec(
            'INSERT INTO posts (user_id, filename, ext, mime, filesize, width, height, md5, rating, source, title)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $filename,
                $ext,
                $mime,
                $file['size'],
                $w ?: null,
                $h ?: null,
                $md5,
                $rating,
                trim($meta['source'] ?? '') ?: null,
                trim($meta['title'] ?? '') ?: null,
            ]
        );
        $postId = (int)DB::lastId();

        $tags = preg_split('/[\s,]+/', trim($meta['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if ($tags) self::setTags($postId, $tags);

        return $postId;
    }

    public static function update(int $id, array $meta): void
    {
        $rating = in_array($meta['rating'] ?? '', ['s', 'q', 'e'], true) ? $meta['rating'] : 'q';
        DB::exec(
            'UPDATE posts SET rating = ?, source = ?, title = ? WHERE id = ?',
            [
                $rating,
                trim($meta['source'] ?? '') ?: null,
                trim($meta['title'] ?? '') ?: null,
                $id,
            ]
        );
        if (isset($meta['tags'])) {
            $tags = preg_split('/[\s,]+/', trim($meta['tags']), -1, PREG_SPLIT_NO_EMPTY);
            self::setTags($id, $tags);
        }
    }

    public static function delete(int $id): void
    {
        $post = DB::row('SELECT filename FROM posts WHERE id = ?', [$id]);
        if (!$post) return;
        // Remove files
        @unlink(UPLOAD_DIR . '/' . $post['filename']);
        @unlink(Image::thumbPath($post['filename']));
        // Remove tags count
        $tags = DB::rows(
            'SELECT t.id FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?',
            [$id]
        );
        DB::exec('DELETE FROM post_tags WHERE post_id = ?', [$id]);
        foreach ($tags as $t) {
            DB::exec('UPDATE tags SET count = MAX(0, count - 1) WHERE id = ?', [$t['id']]);
        }
        DB::exec('DELETE FROM posts WHERE id = ?', [$id]);
    }

    // -------- comments --------

    public static function addComment(int $postId, string $body, ?int $userId, ?string $guestName): int
    {
        $body = trim($body);
        if ($body === '') throw new RuntimeException('Comment cannot be empty.');
        if (!$userId && ($guestName === null || trim($guestName) === '')) {
            $guestName = 'Anonymous';
        }
        DB::exec(
            'INSERT INTO comments (post_id, user_id, guest_name, body) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $guestName ? trim($guestName) : null, $body]
        );
        return (int)DB::lastId();
    }

    public static function commentsFor(int $postId): array
    {
        return DB::rows(
            'SELECT c.*, u.name as user_name FROM comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.post_id = ?
             ORDER BY c.created_at ASC',
            [$postId]
        );
    }

    public static function deleteComment(int $commentId): void
    {
        DB::exec('DELETE FROM comments WHERE id = ?', [$commentId]);
    }

    // -------- vote --------

    public static function vote(int $postId, int $userId, int $value): void
    {
        $value = $value > 0 ? 1 : ($value < 0 ? -1 : 0);
        if ($value === 0) {
            DB::exec('DELETE FROM votes WHERE post_id = ? AND user_id = ?', [$postId, $userId]);
        } else {
            DB::exec(
                'INSERT INTO votes (post_id, user_id, value) VALUES (?, ?, ?)
                 ON CONFLICT(post_id, user_id) DO UPDATE SET value = excluded.value',
                [$postId, $userId, $value]
            );
        }
        $score = (int)DB::scalar(
            'SELECT COALESCE(SUM(value), 0) FROM votes WHERE post_id = ?',
            [$postId]
        );
        DB::exec('UPDATE posts SET score = ? WHERE id = ?', [$score, $postId]);
    }

    // -------- favorites --------

    public static function addFavorite(int $postId, int $userId): void
    {
        DB::exec(
            'INSERT OR IGNORE INTO favorites (user_id, post_id) VALUES (?, ?)',
            [$userId, $postId]
        );
    }

    public static function removeFavorite(int $postId, int $userId): void
    {
        DB::exec(
            'DELETE FROM favorites WHERE user_id = ? AND post_id = ?',
            [$userId, $postId]
        );
    }

    public static function isFavorite(int $postId, int $userId): bool
    {
        return (bool)DB::scalar(
            'SELECT 1 FROM favorites WHERE user_id = ? AND post_id = ?',
            [$userId, $postId]
        );
    }

    public static function listFavorites(int $userId, int $page, int $perPage): array
    {
        $offset = max(0, $page - 1) * $perPage;
        $posts = DB::rows(
            'SELECT p.* FROM posts p
             INNER JOIN favorites f ON f.post_id = p.id
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $perPage, $offset]
        );
        $total = (int)(DB::scalar(
            'SELECT COUNT(*) FROM favorites WHERE user_id = ?',
            [$userId]
        ) ?: 0);

        return ['posts' => $posts, 'total' => $total, 'pages' => (int)ceil($total / $perPage)];
    }

    // -------- search helpers --------

    public static function popularTags(int $limit = 20): array
    {
        return DB::rows(
            'SELECT name, count FROM tags ORDER BY count DESC LIMIT ?',
            [$limit]
        );
    }

    // -------- misc --------

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension.',
            default => 'Unknown upload error.',
        };
    }
}
