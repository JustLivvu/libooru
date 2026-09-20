<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/discord_webhook.php';




class Post
{
    private static ?array $globalBlockedRules = null;

    public const TAG_CATEGORIES = [
        'artist', 'model', 'contributor', 'character', 'copyright',
        'species', 'lore', 'meta', 'invalid', 'general',
    ];

    public static function normalizeTagCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $category = match ($category) {
            'metadata' => 'meta',
            'creator' => 'artist',
            default => $category,
        };
        return in_array($category, self::TAG_CATEGORIES, true) ? $category : 'general';
    }

    private static function categoryFromName(string $name): string
    {
        $prefix = strtolower((string)strtok($name, ':'));
        return in_array($prefix, ['artist', 'model', 'character', 'copyright', 'species'], true)
            ? $prefix
            : 'general';
    }

    private static function canonicalTagCategories(array $categories): array
    {
        $result = [];
        foreach ($categories as $name => $category) {
            if (!is_string($name)) continue;
            $canonical = self::canonicalTagName($name);
            if ($canonical === '') continue;
            $normalized = self::normalizeTagCategory((string)$category);
            if ($normalized !== 'general' || !isset($result[$canonical])) {
                $result[$canonical] = $normalized;
            }
        }
        return $result;
    }

    public static function canonicalTagName(string $tag): string
    {
        $name = strtolower((string)preg_replace('/\s+/', '_', trim($tag)));
        static $aliases = null;
        if ($aliases === null) {
            $aliases = TAG_ALIASES;
            foreach (DB::rows('SELECT alias, canonical FROM tag_aliases') as $alias) {
                $aliases[strtolower($alias['alias'])] = strtolower($alias['canonical']);
            }
        }
        return $aliases[$name] ?? $name;
    }

    public static function canonicalizeTags(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn($tag) => self::canonicalTagName((string)$tag),
            $tags
        ))));
    }

    public static function parseGlobalBlockedRules(string $input): array
    {
        $rules = [];
        foreach (preg_split('/\R+/', strtolower(trim($input)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $tags = self::canonicalizeTags(
                preg_split('/[\s,]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: []
            );
            if (!$tags) continue;
            sort($tags, SORT_STRING);
            $rules[implode("\0", $tags)] = $tags;
        }
        return array_values($rules);
    }

    public static function globalBlockedRules(): array
    {
        if (self::$globalBlockedRules === null) {
            $value = DB::scalar("SELECT value FROM site_settings WHERE key = 'global_tag_blacklist'");
            self::$globalBlockedRules = self::parseGlobalBlockedRules(is_string($value) ? $value : '');
        }
        return self::$globalBlockedRules;
    }

    public static function saveGlobalBlockedRules(string $input): array
    {
        $rules = self::parseGlobalBlockedRules($input);
        if (count($rules) > 100) {
            throw new RuntimeException('A maximum of 100 global tag rules is allowed.');
        }
        foreach ($rules as $rule) {
            if (count($rule) > 10) {
                throw new RuntimeException('A global tag rule may contain at most 10 tags.');
            }
        }
        $value = implode("\n", array_map(static fn(array $rule): string => implode(' ', $rule), $rules));
        DB::exec(
            "INSERT INTO site_settings (key, value) VALUES ('global_tag_blacklist', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [$value]
        );
        self::$globalBlockedRules = $rules;
        return $rules;
    }

    public static function matchingGlobalBlockedRule(array $tags): ?array
    {
        $tagMap = array_fill_keys(self::canonicalizeTags($tags), true);
        foreach (self::globalBlockedRules() as $rule) {
            $matches = true;
            foreach ($rule as $tag) {
                if (!isset($tagMap[$tag])) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) return $rule;
        }
        return null;
    }

    public static function assertTagsAllowed(array $tags): void
    {
        $rule = self::matchingGlobalBlockedRule($tags);
        if ($rule !== null) {
            throw new RuntimeException('Post blocked by global tag rule: ' . implode(' + ', $rule), 422);
        }
    }

    public static function globallyBlockedPostIds(): array
    {
        $ids = [];
        foreach (self::globalBlockedRules() as $rule) {
            $placeholders = implode(',', array_fill(0, count($rule), '?'));
            $rows = DB::rows(
                "SELECT pt.post_id
                 FROM post_tags pt
                 INNER JOIN tags t ON t.id = pt.tag_id
                 WHERE t.name COLLATE NOCASE IN ({$placeholders})
                 GROUP BY pt.post_id
                 HAVING COUNT(DISTINCT LOWER(t.name)) = " . count($rule),
                $rule
            );
            foreach ($rows as $row) $ids[(int)$row['post_id']] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    public static function purgeGloballyBlockedPosts(): int
    {
        $postIds = self::globallyBlockedPostIds();
        if (!$postIds) return 0;

        $filenames = [];
        foreach (array_chunk($postIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::rows("SELECT filename FROM posts WHERE id IN ({$placeholders})", $chunk) as $post) {
                $filenames[] = (string)$post['filename'];
            }
        }
        Storage::deleteMediaBatch($filenames);

        $pdo = DB::get();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) $pdo->beginTransaction();
        try {
            foreach (array_chunk($postIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                DB::exec("DELETE FROM posts WHERE id IN ({$placeholders})", $chunk);
            }
            DB::exec('UPDATE tags SET count = (SELECT COUNT(*) FROM post_tags WHERE tag_id = tags.id)');
            if ($startedTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return count($postIds);
    }

    public static function aliasTargetsForQuery(string $query): array
    {
        $needle = strtolower((string)preg_replace('/\s+/', '_', trim($query)));
        if ($needle === '') return [];

        return array_values(array_unique(array_column(
            DB::rows('SELECT canonical FROM tag_aliases WHERE alias LIKE ? ORDER BY alias', [$needle . '%']),
            'canonical'
        )));
    }


    public static function getById(int $id): ?array
    {
        $post = DB::row('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($post) {
            $post['tags'] = self::tagsFor($id);
            if (self::matchingGlobalBlockedRule(array_column($post['tags'], 'name')) !== null) return null;
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

    public static function search(int $page, int $perPage, string $query, string $rating = '', string $order = 'id DESC', string $quality = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $conditions = [];
        $params = [];
        $orTerms = [];
        $validOrders = ['id DESC', 'id ASC', 'score DESC', 'score ASC', 'created_at DESC'];
        $sqlOrder = in_array($order, $validOrders, true) ? 'p.' . $order : 'p.id DESC';

        if (in_array($rating, ['s', 'q', 'e'], true)) {
            $conditions[] = 'p.rating = ?';
            $params[] = $rating;
        }
        if (in_array($quality, ['low', 'medium', 'high', 'ultra'], true)) {
            $conditions[] = 'p.quality = ?';
            $params[] = $quality;
        }

        $tokens = self::tokenizeSearch($query);
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth = 1;
                $groupTokens = [];
                while (++$i < $count && $depth > 0) {
                    if ($tokens[$i] === '(') { $depth++; continue; }
                    if ($tokens[$i] === ')') { $depth--; if ($depth === 0) break; continue; }
                    $groupTokens[] = $tokens[$i];
                }
                $groupConditions = [];
                $groupParams = [];
                foreach ($groupTokens as $groupToken) {
                    $groupToken = ltrim($groupToken, '~');
                    if ($groupToken === '' || str_starts_with($groupToken, '-')) continue;
                    foreach (self::splitTagToken($groupToken) as $groupTag) {
                        [$condition, $conditionParams] = self::tagSearchCondition($groupTag, false);
                        if ($condition !== '') {
                            $groupConditions[] = $condition;
                            array_push($groupParams, ...$conditionParams);
                        }
                    }
                }
                if ($groupConditions) {
                    $conditions[] = '(' . implode(' OR ', $groupConditions) . ')';
                    array_push($params, ...$groupParams);
                }
                continue;
            }
            if ($token === ')') continue;

            $negated = str_starts_with($token, '-');
            $isOr = str_starts_with($token, '~');
            if ($negated || $isOr) $token = substr($token, 1);
            if ($token === '') continue;

            if (self::applySearchMetatag($token, $negated, $conditions, $params, $sqlOrder, $perPage)) {
                continue;
            }

            foreach (self::splitTagToken($token) as $tag) {
                [$condition, $conditionParams] = self::tagSearchCondition($tag, $negated);
                if ($condition === '') continue;
                if ($isOr && !$negated) {
                    $orTerms[] = [$condition, $conditionParams];
                } else {
                    $conditions[] = $condition;
                    array_push($params, ...$conditionParams);
                }
            }
        }

        if ($orTerms) {
            $conditions[] = '(' . implode(' OR ', array_column($orTerms, 0)) . ')';
            foreach ($orTerms as [, $orParams]) array_push($params, ...$orParams);
        }

        foreach (self::globalBlockedRules() as $rule) {
            $ruleConditions = [];
            foreach ($rule as $blockedTag) {
                [$condition, $conditionParams] = self::tagSearchCondition($blockedTag, false);
                $ruleConditions[] = $condition;
                array_push($params, ...$conditionParams);
            }
            $conditions[] = 'NOT (' . implode(' AND ', $ruleConditions) . ')';
        }

        $user = class_exists('Auth') ? Auth::current() : null;
        if ($user && !empty($user['blacklist'])) {
            foreach (self::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY)) as $blockedTag) {
                [$condition, $conditionParams] = self::tagSearchCondition($blockedTag, true);
                $conditions[] = $condition;
                array_push($params, ...$conditionParams);
            }
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $offset = ($page - 1) * $perPage;
        $posts = DB::rows("SELECT p.* FROM posts p{$where} ORDER BY {$sqlOrder} LIMIT ? OFFSET ?", array_merge($params, [$perPage, $offset]));
        $total = (int)(DB::scalar("SELECT COUNT(*) FROM posts p{$where}", $params) ?: 0);
        return ['posts' => $posts, 'total' => $total, 'pages' => (int)ceil($total / $perPage), 'per_page' => $perPage];
    }

    private static function tokenizeSearch(string $query): array
    {
        preg_match_all('/\(|\)|(?:[^\s()"]+|"[^"]*")+/', trim($query), $matches);
        return array_slice($matches[0] ?? [], 0, 80);
    }

    private static function splitTagToken(string $token): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $token)), static fn(string $tag): bool => $tag !== ''));
    }

    private static function tagSearchCondition(string $tag, bool $negated): array
    {
        $tag = strtolower(trim($tag, " \t\n\r\0\x0B\""));
        if ($tag === '') return ['', []];
        $operator = $negated ? 'NOT EXISTS' : 'EXISTS';
        if (str_contains($tag, '*')) {
            $pattern = str_replace(['\\', '%', '_', '*'], ['\\\\', '\\%', '\\_', '%'], $tag);
            return ["{$operator} (SELECT 1 FROM post_tags pts JOIN tags ts ON ts.id = pts.tag_id WHERE pts.post_id = p.id AND ts.name LIKE ? ESCAPE '\\' COLLATE NOCASE)", [$pattern]];
        }
        $tag = self::canonicalTagName($tag);
        return ["{$operator} (SELECT 1 FROM post_tags pts JOIN tags ts ON ts.id = pts.tag_id WHERE pts.post_id = p.id AND ts.name = ? COLLATE NOCASE)", [$tag]];
    }

    private static function applySearchMetatag(string $token, bool $negated, array &$conditions, array &$params, string &$order, int &$limit): bool
    {
        if (!str_contains($token, ':')) return false;
        [$key, $value] = explode(':', $token, 2);
        $key = strtolower($key);
        $value = trim($value, "\"");
        $recognized = ['rating','quality','id','score','width','height','filesize','mpixels','ratio','tagcount','favcount','comment_count','date','filetype','type','md5','source','description','hassource','hasdescription','user','user_id','fav','favoritedby','commenter','order','limit'];
        if (!in_array($key, $recognized, true)) return false;

        if ($key === 'order') {
            if (!$negated) {
                $orders = [
                    'id' => 'p.id ASC', 'id_asc' => 'p.id ASC', 'id_desc' => 'p.id DESC',
                    'created' => 'p.created_at DESC', 'created_asc' => 'p.created_at ASC',
                    'score' => 'p.score DESC', 'score_asc' => 'p.score ASC',
                    'filesize' => 'p.filesize DESC', 'filesize_asc' => 'p.filesize ASC',
                    'mpixels' => '(COALESCE(p.width,0) * COALESCE(p.height,0)) DESC',
                    'mpixels_asc' => '(COALESCE(p.width,0) * COALESCE(p.height,0)) ASC',
                    'favcount' => '(SELECT COUNT(*) FROM favorites fo WHERE fo.post_id = p.id) DESC',
                    'favcount_asc' => '(SELECT COUNT(*) FROM favorites fo WHERE fo.post_id = p.id) ASC',
                    'comment_count' => '(SELECT COUNT(*) FROM comments co WHERE co.post_id = p.id) DESC',
                    'comment_count_asc' => '(SELECT COUNT(*) FROM comments co WHERE co.post_id = p.id) ASC',
                    'tagcount' => '(SELECT COUNT(*) FROM post_tags po WHERE po.post_id = p.id) DESC',
                    'tagcount_asc' => '(SELECT COUNT(*) FROM post_tags po WHERE po.post_id = p.id) ASC',
                    'landscape' => '(COALESCE(p.width,0) * 1.0 / MAX(COALESCE(p.height,1),1)) DESC',
                    'portrait' => '(COALESCE(p.width,0) * 1.0 / MAX(COALESCE(p.height,1),1)) ASC',
                    'random' => 'RANDOM()',
                ];
                if (isset($orders[strtolower($value)])) $order = $orders[strtolower($value)];
            }
            return true;
        }
        if ($key === 'limit') {
            if (!$negated && ctype_digit($value)) $limit = max(1, min(200, (int)$value));
            return true;
        }

        $condition = '';
        $conditionParams = [];
        if ($key === 'rating') {
            $ratings = ['safe' => 's', 'questionable' => 'q', 'explicit' => 'e', 's' => 's', 'q' => 'q', 'e' => 'e'];
            if (isset($ratings[strtolower($value)])) { $condition = 'p.rating = ?'; $conditionParams[] = $ratings[strtolower($value)]; }
        } elseif ($key === 'quality') {
            if (in_array(strtolower($value), ['low','medium','high','ultra'], true)) { $condition = 'p.quality = ?'; $conditionParams[] = strtolower($value); }
        } elseif (in_array($key, ['id','score','width','height','filesize','mpixels','ratio','tagcount','favcount','comment_count'], true)) {
            $expressions = [
                'id' => 'p.id', 'score' => 'p.score', 'width' => 'COALESCE(p.width,0)', 'height' => 'COALESCE(p.height,0)',
                'filesize' => 'p.filesize', 'mpixels' => '(COALESCE(p.width,0) * COALESCE(p.height,0) / 1000000.0)',
                'ratio' => '(COALESCE(p.width,0) * 1.0 / MAX(COALESCE(p.height,1),1))',
                'tagcount' => '(SELECT COUNT(*) FROM post_tags pc WHERE pc.post_id = p.id)',
                'favcount' => '(SELECT COUNT(*) FROM favorites fc WHERE fc.post_id = p.id)',
                'comment_count' => '(SELECT COUNT(*) FROM comments cc WHERE cc.post_id = p.id)',
            ];
            [$condition, $conditionParams] = self::numericSearchCondition($expressions[$key], $value, $key === 'filesize');
        } elseif ($key === 'date') {
            [$condition, $conditionParams] = self::dateSearchCondition($value);
        } elseif ($key === 'filetype') {
            $ext = strtolower(ltrim($value, '.'));
            if ($ext === 'jpeg') $ext = 'jpg';
            if (in_array($ext, ['jpg','png','gif','webp','mp4','webm','mov'], true)) { $condition = 'LOWER(p.ext) = ?'; $conditionParams[] = $ext; }
        } elseif ($key === 'type') {
            $type = strtolower($value);
            if ($type === 'image') $condition = "p.mime LIKE 'image/%'";
            elseif ($type === 'video') $condition = "p.mime LIKE 'video/%'";
            elseif ($type === 'animation') $condition = "LOWER(p.ext) IN ('gif','webp')";
        } elseif ($key === 'md5') {
            if (preg_match('/^[a-f0-9]{32}$/i', $value)) { $condition = 'p.md5 = ? COLLATE NOCASE'; $conditionParams[] = strtolower($value); }
        } elseif ($key === 'source') {
            if (strtolower($value) === 'none') $condition = "COALESCE(p.source,'') = ''";
            elseif (strtolower($value) === 'any') $condition = "COALESCE(p.source,'') <> ''";
            else { $condition = 'COALESCE(p.source,\'\') LIKE ? COLLATE NOCASE'; $conditionParams[] = str_replace('*', '%', $value); }
        } elseif ($key === 'description') {
            $condition = 'COALESCE(p.title,\'\') LIKE ? COLLATE NOCASE';
            $conditionParams[] = '%' . str_replace('*', '%', $value) . '%';
        } elseif ($key === 'hassource' || $key === 'hasdescription') {
            $truthy = in_array(strtolower($value), ['true','yes','1'], true);
            $column = $key === 'hassource' ? 'p.source' : 'p.title';
            $condition = "COALESCE({$column},'') " . ($truthy ? '<>' : '=') . " ''";
        } elseif ($key === 'user_id' && ctype_digit($value)) {
            $condition = 'p.user_id = ?'; $conditionParams[] = (int)$value;
        } elseif ($key === 'user') {
            $condition = 'EXISTS (SELECT 1 FROM users su WHERE su.id = p.user_id AND su.name = ? COLLATE NOCASE)'; $conditionParams[] = $value;
        } elseif ($key === 'fav' || $key === 'favoritedby') {
            $name = strtolower($value) === 'me' && class_exists('Auth') && Auth::current() ? Auth::current()['name'] : $value;
            $condition = 'EXISTS (SELECT 1 FROM favorites sf JOIN users fu ON fu.id = sf.user_id WHERE sf.post_id = p.id AND fu.name = ? COLLATE NOCASE)'; $conditionParams[] = $name;
        } elseif ($key === 'commenter') {
            if (strtolower($value) === 'any') $condition = 'EXISTS (SELECT 1 FROM comments sc WHERE sc.post_id = p.id)';
            elseif (strtolower($value) === 'none') $condition = 'NOT EXISTS (SELECT 1 FROM comments sc WHERE sc.post_id = p.id)';
            else { $condition = 'EXISTS (SELECT 1 FROM comments sc JOIN users cu ON cu.id = sc.user_id WHERE sc.post_id = p.id AND cu.name = ? COLLATE NOCASE)'; $conditionParams[] = $value; }
        }

        if ($condition !== '') {
            $conditions[] = $negated ? 'NOT (' . $condition . ')' : $condition;
            array_push($params, ...$conditionParams);
        }
        return true;
    }

    private static function numericSearchCondition(string $expression, string $value, bool $fileSize = false): array
    {
        $convert = static function (string $number) use ($fileSize): ?float {
            if (!preg_match('/^(-?\d+(?:\.\d+)?)(kb|mb|gb)?$/i', trim($number), $match)) return null;
            $result = (float)$match[1];
            if ($fileSize) $result *= match (strtolower($match[2] ?? '')) { 'kb' => 1024, 'mb' => 1048576, 'gb' => 1073741824, default => 1 };
            return $result;
        };
        if (str_contains($value, ',')) {
            $numbers = array_values(array_filter(array_map($convert, explode(',', $value)), static fn(?float $number): bool => $number !== null));
            if (!$numbers) return ['', []];
            return ["{$expression} IN (" . implode(',', array_fill(0, count($numbers), 'CAST(? AS REAL)')) . ')', $numbers];
        }
        if (str_contains($value, '..')) {
            [$min, $max] = explode('..', $value, 2);
            $parts = []; $params = [];
            if ($min !== '' && ($number = $convert($min)) !== null) { $parts[] = "{$expression} >= CAST(? AS REAL)"; $params[] = $number; }
            if ($max !== '' && ($number = $convert($max)) !== null) { $parts[] = "{$expression} <= CAST(? AS REAL)"; $params[] = $number; }
            return [$parts ? '(' . implode(' AND ', $parts) . ')' : '', $params];
        }
        if (preg_match('/^(>=|<=|>|<|=)?(.+)$/', $value, $match) && ($number = $convert($match[2])) !== null) {
            return ["{$expression} " . ($match[1] ?: '=') . ' CAST(? AS REAL)', [$number]];
        }
        return ['', []];
    }

    private static function dateSearchCondition(string $value): array
    {
        $dayRange = static function (string $date): ?array {
            $date = strtolower($date);
            if ($date === 'today') $start = strtotime('today');
            elseif ($date === 'yesterday') $start = strtotime('yesterday');
            elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $start = strtotime($date . ' 00:00:00');
            else return null;
            return [$start, $start + 86400 - 1];
        };
        if (str_contains($value, '..')) {
            [$from, $to] = explode('..', $value, 2);
            $parts = []; $params = [];
            if ($from !== '' && ($range = $dayRange($from))) { $parts[] = 'p.created_at >= ?'; $params[] = $range[0]; }
            if ($to !== '' && ($range = $dayRange($to))) { $parts[] = 'p.created_at <= ?'; $params[] = $range[1]; }
            return [$parts ? '(' . implode(' AND ', $parts) . ')' : '', $params];
        }
        $range = $dayRange($value);
        return $range ? ['p.created_at BETWEEN ? AND ?', $range] : ['', []];
    }

    public static function list(int $page, int $perPage, array $tagFilter = [], string $rating = '', string $order = 'id DESC', string $quality = ''): array
    {
        $tagFilter = self::canonicalizeTags($tagFilter);
        $offset = max(0, $page - 1) * $perPage;
        $validQuality = in_array($quality, ['low', 'medium', 'high', 'ultra'], true) ? $quality : '';

        $user = class_exists('Auth') ? Auth::current() : null;
        $blacklist = [];
        if ($user && !empty($user['blacklist'])) {
            $blacklist = self::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
        }

        $blSql = '';
        $blParams = [];
        if ($blacklist) {
            $blPlaceholders = implode(',', array_fill(0, count($blacklist), '?'));
            $blSql = "p.id NOT IN (SELECT pt_bl.post_id FROM post_tags pt_bl INNER JOIN tags t_bl ON t_bl.id = pt_bl.tag_id WHERE t_bl.name IN ($blPlaceholders) COLLATE NOCASE)";
            $blParams = $blacklist;
        }

        if ($tagFilter) {

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


    public static function listByUser(int $userId, int $page, int $perPage): array
    {
        $offset = max(0, $page - 1) * $perPage;
        $conditions = ['p.user_id = ?'];
        $params = [$userId];

        $viewer = class_exists('Auth') ? Auth::current() : null;
        $blacklist = $viewer && !empty($viewer['blacklist'])
            ? preg_split('/[\s,]+/', strtolower(trim($viewer['blacklist'])), -1, PREG_SPLIT_NO_EMPTY)
            : [];
        $blacklist = self::canonicalizeTags($blacklist);
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



    public static function tagsFor(int $postId): array
    {
        return DB::rows(
            "SELECT t.name, t.category FROM tags t
             INNER JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id = ?
             ORDER BY CASE t.category
                WHEN 'artist' THEN 0 WHEN 'model' THEN 0 WHEN 'contributor' THEN 0
                WHEN 'character' THEN 1 WHEN 'copyright' THEN 2 WHEN 'species' THEN 3
                WHEN 'lore' THEN 4 WHEN 'meta' THEN 5 WHEN 'invalid' THEN 6 ELSE 7 END,
                t.name",
            [$postId]
        );
    }

    public static function setTags(int $postId, array $tagNames, array $tagCategories = []): void
    {

        self::assertTagsAllowed($tagNames);

        $old = DB::rows(
            'SELECT t.id FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ?',
            [$postId]
        );
        DB::exec('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
        foreach ($old as $row) {
            DB::exec('UPDATE tags SET count = MAX(0, count - 1) WHERE id = ?', [$row['id']]);
        }


        $tagNames = self::canonicalizeTags($tagNames);
        $tagCategories = self::canonicalTagCategories($tagCategories);

        foreach ($tagNames as $name) {
            if ($name === '') continue;

            $category = $tagCategories[$name] ?? self::categoryFromName($name);
            DB::exec('INSERT OR IGNORE INTO tags (name, category) VALUES (?, ?)', [$name, $category]);
            if ($category !== 'general') {
                DB::exec('UPDATE tags SET category = ? WHERE name = ? COLLATE NOCASE', [$category, $name]);
            }
            $tagId = (int)DB::scalar('SELECT id FROM tags WHERE name = ?', [$name]);
            DB::exec('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $tagId]);
            DB::exec('UPDATE tags SET count = count + 1 WHERE id = ?', [$tagId]);
        }
    }

    public static function setTagCategories(array $tagCategories): int
    {
        $tagCategories = self::canonicalTagCategories($tagCategories);
        if (!$tagCategories) return 0;

        $updated = 0;
        $pdo = DB::get();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('UPDATE tags SET category = ? WHERE name = ? COLLATE NOCASE AND category <> ?');
            foreach ($tagCategories as $name => $category) {
                if ($category === 'general') continue;
                $statement->execute([$category, $name, $category]);
                $updated += $statement->rowCount();
            }
            if ($startedTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $updated;
    }







    public static function upload(array $file, array $meta): int
    {
        $contentType = $meta['content_type'] ?? null;
        if ($contentType === 'drawn') $contentType = 'artwork';
        if ($contentType !== null && !in_array($contentType, ['artwork', 'real_life'], true)) {
            throw new RuntimeException('Invalid content type.');
        }
        $tags = preg_split('/[\s,]+/', trim($meta['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($contentType !== null) {
            $tags = array_values(array_filter(
                $tags,
                fn($tag) => !in_array(strtolower($tag), ['drawn', 'artwork', 'real_life'], true)
            ));
            $tags[] = $contentType;
        }
        self::assertTagsAllowed($tags);
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError($file['error']));
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new RuntimeException('File too large (max ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB).');
        }


        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset(ALLOWED_TYPES[$mime])) {
            throw new RuntimeException('File type not allowed: ' . htmlspecialchars($mime));
        }
        $ext = ALLOWED_TYPES[$mime];
        if ($mime === 'video/x-m4v') {
            $mime = 'video/mp4';
        } elseif (in_array($mime, ['video/x-quicktime', 'application/quicktime'], true)) {
            $mime = 'video/quicktime';
        }

        $md5 = md5_file($file['tmp_name']);
        if (!$md5) throw new RuntimeException('Failed to hash file.');


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


        $tempThumbDir = sys_get_temp_dir();
        $thumbFilename = in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true)
            ? $md5 . '.jpg'
            : $filename;
        $tempThumbPath = $tempThumbDir . '/thumb_' . $thumbFilename;
        $thumbMime = in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true) ? 'image/jpeg' : $mime;

        if (!Image::makeThumbnail($file['tmp_name'], $tempThumbPath, $mime)) {
            throw new RuntimeException('Failed to generate thumbnail.');
        }


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

        if ($tags) self::setTags($postId, $tags, is_array($meta['tag_categories'] ?? null) ? $meta['tag_categories'] : []);

        try {
            DiscordWebhook::notifyNewPost($postId);
        } catch (Throwable $e) {
            error_log('Discord webhook failed for post #' . $postId . ': ' . $e->getMessage());
        }

        return $postId;
    }

    public static function update(int $id, array $meta): void
    {
        $tags = null;
        if (isset($meta['tags'])) {
            $tags = preg_split('/[\s,]+/', trim($meta['tags']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            self::assertTagsAllowed($tags);
        }
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
        if ($tags !== null) {
            self::setTags($id, $tags);
        }
    }

    public static function delete(int $id): void
    {
        $post = DB::row('SELECT filename FROM posts WHERE id = ?', [$id]);
        if (!$post) return;

        Storage::deleteMedia($post['filename']);

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



    public static function report(int $postId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > MAX_POST_REPORT_LENGTH) {
            throw new RuntimeException('Report reason must contain between 3 and ' . MAX_POST_REPORT_LENGTH . ' characters.');
        }
        if (!DB::scalar('SELECT id FROM posts WHERE id = ?', [$postId])) {
            throw new RuntimeException('Post not found.');
        }
        if (DB::scalar("SELECT id FROM post_reports WHERE post_id = ? AND reporter_user_id = ? AND status = 'pending'", [$postId, $userId])) {
            throw new RuntimeException('You have already reported this post.');
        }

        DB::exec(
            "INSERT INTO post_reports (post_id, reporter_user_id, reason)
             VALUES (?, ?, ?)
             ON CONFLICT(post_id, reporter_user_id) DO UPDATE SET
                reason = excluded.reason,
                status = 'pending',
                resolved_by = NULL,
                resolved_at = NULL,
                created_at = unixepoch()",
            [$postId, $userId, $reason]
        );
    }

    public static function hasPendingReport(int $postId, int $userId): bool
    {
        return (bool)DB::scalar("SELECT id FROM post_reports WHERE post_id = ? AND reporter_user_id = ? AND status = 'pending'", [$postId, $userId]);
    }


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
            $blacklist = self::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
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



    public static function popularTags(int $limit = 20): array
    {
        return DB::rows(
            'SELECT name, count, category FROM tags ORDER BY count DESC LIMIT ?',
            [$limit]
        );
    }



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
