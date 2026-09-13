<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/storage.php';

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

    public static function determineQuality(?int $w, ?int $h): string
    {
        if (!$w || !$h) return 'medium';
        $max = max($w, $h);
        $min = min($w, $h);
        if ($max >= 3840 || $min >= 2160) return 'ultra';
        if ($max >= 1920 || $min >= 1080) return 'high';
        if ($max >= 1280 || $min >= 720)  return 'medium';
        return 'low';
    }

    public static function list(int $page, int $perPage, array $tagFilter = [], string $rating = '', string $order = 'id DESC', string $quality = ''): array
    {
        $offset = max(0, $page - 1) * $perPage;
        $validQuality = in_array($quality, ['low', 'medium', 'high', 'ultra'], true) ? $quality : '';

        $user = class_exists('Auth') ? Auth::current() : null;
        $blacklist = [];
        if ($user && !empty($user['blacklist'])) {
            $blacklist = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
        }

        $blSql = '';
        $blParams = [];
        if ($blacklist) {
            $blPlaceholders = implode(',', array_fill(0, count($blacklist), '?'));
            $blSql = "p.id NOT IN (SELECT pt_bl.post_id FROM post_tags pt_bl INNER JOIN tags t_bl ON t_bl.id = pt_bl.tag_id WHERE t_bl.name IN ($blPlaceholders) COLLATE NOCASE)";
            $blParams = $blacklist;
        }

        if ($tagFilter) {
            // Intersection: posts that have ALL given tags
            $placeholders = implode(',', array_fill(0, count($tagFilter), '?'));
            $qualityAnd   = $validQuality ? "AND p.quality = ?" : '';
            $ratingAnd    = $rating ? "AND p.rating = ?" : '';
            $blAnd        = $blSql ? "AND $blSql" : '';

            $sql = "
                SELECT p.* FROM posts p
                INNER JOIN post_tags pt ON pt.post_id = p.id
                INNER JOIN tags t ON t.id = pt.tag_id
                WHERE t.name IN ($placeholders) COLLATE NOCASE
                $ratingAnd
                $qualityAnd
                $blAnd
                GROUP BY p.id
                HAVING COUNT(DISTINCT t.id) = " . count($tagFilter) . "
                ORDER BY p.$order
                LIMIT ? OFFSET ?
            ";
            $params = $tagFilter;
            if ($rating)       $params[] = $rating;
            if ($validQuality) $params[] = $validQuality;
            if ($blParams)     $params = array_merge($params, $blParams);
            $params[] = $perPage;
            $params[] = $offset;

            $countSql = "
                SELECT COUNT(*) FROM (
                    SELECT p.id FROM posts p
                    INNER JOIN post_tags pt ON pt.post_id = p.id
                    INNER JOIN tags t ON t.id = pt.tag_id
                    WHERE t.name IN ($placeholders) COLLATE NOCASE
                    $ratingAnd
                    $qualityAnd
                    $blAnd
                    GROUP BY p.id
                    HAVING COUNT(DISTINCT t.id) = " . count($tagFilter) . "
                )";
            $countParams = $tagFilter;
            if ($rating)       $countParams[] = $rating;
            if ($validQuality) $countParams[] = $validQuality;
            if ($blParams)     $countParams = array_merge($countParams, $blParams);
        } else {
            $conditions = [];
            if ($rating)       $conditions[] = 'p.rating = ?';
            if ($validQuality) $conditions[] = 'p.quality = ?';
            if ($blSql)        $conditions[] = $blSql;
            $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

            $sql = "SELECT p.* FROM posts p{$where} ORDER BY p.$order LIMIT ? OFFSET ?";
            $params = [];
            if ($rating)       $params[] = $rating;
            if ($validQuality) $params[] = $validQuality;
            if ($blParams)     $params = array_merge($params, $blParams);
            $params[] = $perPage;
            $params[] = $offset;

            $countSql    = "SELECT COUNT(*) FROM posts p{$where}";
            $countParams = [];
            if ($rating)       $countParams[] = $rating;
            if ($validQuality) $countParams[] = $validQuality;
            if ($blParams)     $countParams = array_merge($countParams, $blParams);
        }

        $posts = DB::rows($sql, $params);
        $total = (int)(DB::scalar($countSql, $countParams) ?: 0);

        return ['posts' => $posts, 'total' => $total, 'pages' => (int)ceil($total / $perPage)];
    }

    /** List posts for a profile while respecting the current viewer's blacklist. */
    public static function listByUser(int $userId, int $page, int $perPage): array
    {
        $offset = max(0, $page - 1) * $perPage;
        $conditions = ['p.user_id = ?'];
        $params = [$userId];

        $viewer = class_exists('Auth') ? Auth::current() : null;
        $blacklist = $viewer && !empty($viewer['blacklist'])
            ? preg_split('/[\s,]+/', strtolower(trim($viewer['blacklist'])), -1, PREG_SPLIT_NO_EMPTY)
            : [];
        if ($blacklist) {
            $placeholders = implode(',', array_fill(0, count($blacklist), '?'));
            $conditions[] = "p.id NOT IN (SELECT pt.post_id FROM post_tags pt INNER JOIN tags t ON t.id = pt.tag_id WHERE t.name IN ($placeholders) COLLATE NOCASE)";
            $params = array_merge($params, $blacklist);
        }

        $where = implode(' AND ', $conditions);
        $posts = DB::rows(
            "SELECT p.* FROM posts p WHERE $where ORDER BY p.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        $total = (int)DB::scalar("SELECT COUNT(*) FROM posts p WHERE $where", $params);

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
        if ($mime === 'video/x-m4v') {
            $mime = 'video/mp4';
        }

        $md5 = md5_file($file['tmp_name']);
        if (!$md5) throw new RuntimeException('Failed to hash file.');

        // Check duplicate
        $existing = DB::scalar('SELECT id FROM posts WHERE md5 = ?', [$md5]);
        if ($existing) {
            throw new RuntimeException('Duplicate image (post #' . $existing . ' already exists).', 409);
        }

        $filename = $md5 . '.' . $ext;

        [$w, $h] = Image::getDimensions($file['tmp_name'], $mime);
        if ($w > 0 && $h > 0 && $w > intdiv(MAX_MEDIA_PIXELS, $h)) {
            throw new RuntimeException('Media dimensions are too large.', 413);
        }
        $quality = self::determineQuality($w ?: null, $h ?: null);

        // Make temporary thumbnail locally
        $tempThumbDir = sys_get_temp_dir();
        $thumbFilename = in_array(strtolower($ext), ['mp4', 'webm'], true)
            ? $md5 . '.jpg'
            : $filename;
        $tempThumbPath = $tempThumbDir . '/thumb_' . $thumbFilename;
        $thumbMime = in_array(strtolower($ext), ['mp4', 'webm'], true) ? 'image/jpeg' : $mime;

        if (!Image::makeThumbnail($file['tmp_name'], $tempThumbPath, $mime)) {
            throw new RuntimeException('Failed to generate thumbnail.');
        }

        // Store media & thumbnail using Storage layer
        Storage::putMedia($filename, $file['tmp_name'], $tempThumbPath, $mime, $thumbMime);

        $userId = Auth::id();
        $rating = in_array($meta['rating'] ?? '', ['s', 'q', 'e'], true) ? $meta['rating'] : 'q';

        DB::exec(
            'INSERT INTO posts (user_id, filename, ext, mime, filesize, width, height, md5, rating, source, title, quality)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $quality,
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
        Storage::deleteMedia($post['filename']);
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

    public static function addComment(int $postId, string $body, ?int $userId): int
    {
        if (!$userId) {
            throw new RuntimeException('Authentication is required to comment.', 401);
        }
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Comment cannot be empty.', 400);
        }
        if (strlen($body) > MAX_COMMENT_LENGTH) {
            throw new RuntimeException('Comment is too long (maximum ' . MAX_COMMENT_LENGTH . ' bytes).', 413);
        }
        if (!self::getById($postId)) {
            throw new RuntimeException('Post not found.', 404);
        }
        DB::exec(
            'INSERT INTO comments (post_id, user_id, guest_name, body) VALUES (?, ?, NULL, ?)',
            [$postId, $userId, $body]
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

        $user = class_exists('Auth') ? Auth::current() : null;
        $blacklist = [];
        if ($user && !empty($user['blacklist'])) {
            $blacklist = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
        }
        $blSql = '';
        $blParams = [];
        if ($blacklist) {
            $blPlaceholders = implode(',', array_fill(0, count($blacklist), '?'));
            $blSql = "AND p.id NOT IN (SELECT pt_bl.post_id FROM post_tags pt_bl INNER JOIN tags t_bl ON t_bl.id = pt_bl.tag_id WHERE t_bl.name IN ($blPlaceholders) COLLATE NOCASE)";
            $blParams = $blacklist;
        }

        $sql = 'SELECT p.* FROM posts p
                INNER JOIN favorites f ON f.post_id = p.id
                WHERE f.user_id = ? ' . $blSql . '
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?';
        $params = array_merge([$userId], $blParams, [$perPage, $offset]);

        $countSql = 'SELECT COUNT(*) FROM favorites f
                     INNER JOIN posts p ON p.id = f.post_id
                     WHERE f.user_id = ? ' . $blSql;
        $countParams = array_merge([$userId], $blParams);

        $posts = DB::rows($sql, $params);
        $total = (int)(DB::scalar($countSql, $countParams) ?: 0);

        return ['posts' => $posts, 'total' => $total, 'pages' => (int)ceil($total / $perPage)];
    }

    public static function getRandomFavorite(int $userId): ?array
    {
        $sql = 'SELECT p.* FROM posts p
                INNER JOIN favorites f ON f.post_id = p.id
                WHERE f.user_id = ?
                ORDER BY RANDOM()
                LIMIT 1';
        return DB::row($sql, [$userId]) ?: null;
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
