<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../post.php';
require_once __DIR__ . '/../view.php';

$options = getopt('', ['tag:', 'task-id:']);
$tag = trim($options['tag'] ?? '');
$taskId = (int)($options['task-id'] ?? 0);

if (!$tag || !$taskId) {
    die("Usage: php realbooru.php --tag <tag> --task-id <id>\n");
}

function logMsg(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

function downloadToFile(string $url, string $destination, $context): bool {
    $input = @fopen($url, 'rb', false, $context);
    if ($input === false) {
        return false;
    }

    $output = @fopen($destination, 'wb');
    if ($output === false) {
        fclose($input);
        return false;
    }

    $bytes = @stream_copy_to_stream($input, $output);
    fclose($output);
    fclose($input);

    return $bytes !== false && $bytes > 0;
}

logMsg("Starting PHP scraper for tag: $tag");

$downloaded = 0;
$skipped = 0;
$errors = 0;


$user = DB::row("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if (!$user) {
    die("No admin user found.\n");
}
Auth::setUser($user);



$progressSource = 'realbooru_oldest_first';
DB::exec(
    'CREATE TABLE IF NOT EXISTS scraper_progress (
        source TEXT NOT NULL,
        tag TEXT NOT NULL COLLATE NOCASE,
        next_pid INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (source, tag)
    )'
);
$page = max(0, (int)(DB::scalar(
    'SELECT next_pid FROM scraper_progress WHERE source = ? AND tag = ?',
    [$progressSource, $tag]
) ?: 0));

if ($page > 0) {
    logMsg("Continuing at pid=$page.");
}



$knownRealbooruIds = [];
foreach (DB::rows("SELECT id, title FROM posts WHERE title LIKE 'Realbooru #%'") as $post) {
    if (preg_match('/^Realbooru #(\d+)$/', (string)$post['title'], $match)) {
        $knownRealbooruIds[$match[1]] = (int)$post['id'];
    }
}
logMsg("Loaded " . count($knownRealbooruIds) . " existing Realbooru post IDs.");

while (true) {


    $searchTags = $tag . ' sort:id:asc';
    $url = "https://realbooru.com/index.php?page=post&s=list&tags=" . urlencode($searchTags) . "&pid=" . $page;
    logMsg("Fetching page list (pid=$page)...");

    $opts = [
        "http" => [
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nReferer: https://realbooru.com/\r\n",
            "timeout" => 15
        ]
    ];
    $context = stream_context_create($opts);

    $html = @file_get_contents($url, false, $context);
    if (!$html) {
        logMsg("Failed to fetch list page or reached end.");
        break;
    }

    if (!preg_match_all('/page=post&(?:amp;)?s=view&(?:amp;)?id=(\d+)/', $html, $matches)) {
        logMsg("No more posts found on list page.");
        break;
    }

    $postIds = array_unique($matches[1]);
    if (empty($postIds)) {
        logMsg("No posts found.");
        break;
    }

    foreach ($postIds as $realbooruId) {
        logMsg("Processing Realbooru #$realbooruId...");

        if (isset($knownRealbooruIds[$realbooruId])) {
            logMsg("  Skipped: Post #$realbooruId already in DB (Post #{$knownRealbooruIds[$realbooruId]}).");
            $skipped++;
            continue;
        }

        $viewUrl = "https://realbooru.com/index.php?page=post&s=view&id=" . $realbooruId;
        $viewHtml = @file_get_contents($viewUrl, false, $context);
        if (!$viewHtml) {
            logMsg("  Error: Failed to fetch view page for #$realbooruId");
            $errors++;
            continue;
        }

        $imgUrl = '';
        if (preg_match('/id=[\"\']image[\"\'][^>]+src=[\"\']([^\"\']+)[\"\']/i', $viewHtml, $m) ||
            preg_match('/src=[\"\']([^\"\']+)[\"\'][^>]+id=[\"\']image[\"\']/i', $viewHtml, $m) ||
            preg_match('/<source\s+[^>]*src=[\"\']([^\"\']+)[\"\']/i', $viewHtml, $m) ||
            preg_match('/<video\s+[^>]*src=[\"\']([^\"\']+)[\"\']/i', $viewHtml, $m)) {
            $imgUrl = $m[1];
        }

        if (!$imgUrl) {
            logMsg("  Error: Could not find image/video URL for #$realbooruId");
            $errors++;
            continue;
        }

        if (strpos($imgUrl, '//') === 0) {
            $imgUrl = 'https:' . $imgUrl;
        } elseif (strpos($imgUrl, '/') === 0) {
            $imgUrl = 'https://realbooru.com' . $imgUrl;
        }

        $imgUrl = preg_replace('#^(https?://realbooru\.com)/+#', '$1/', $imgUrl);

        $tmpFile = tempnam(sys_get_temp_dir(), 'rb_');
        logMsg("  Downloading media for #$realbooruId...");
        if (!downloadToFile($imgUrl, $tmpFile, $context)) {
            logMsg("  Error: Failed to download $imgUrl");
            $errors++;
            @unlink($tmpFile);
            continue;
        }

        $md5 = md5_file($tmpFile);

        $exists = DB::scalar('SELECT id FROM posts WHERE md5 = ?', [$md5]);
        if ($exists) {
            logMsg("  Skipped: MD5 $md5 already in DB (Post #$exists).");
            $skipped++;
            @unlink($tmpFile);
            continue;
        }

        $tags = [];
        if (preg_match_all('/<a class=[\"\'](?:tag-type-[^\"\']+|model)[\"\'] href=[\"\'][^\"\']*tags=([^\"\'&>]+)/i', $viewHtml, $m)) {
            foreach ($m[1] as $t) {
                $tags[] = urldecode($t);
            }
        }
        $tags[] = 'realbooru';
        $tagStr = implode(' ', array_unique($tags));

        $rating = 'q';
        if (preg_match('/Rating:\s*([A-Za-z]+)/i', $viewHtml, $m)) {
            $rStr = strtolower($m[1]);
            if (strpos($rStr, 'safe') !== false) $rating = 's';
            elseif (strpos($rStr, 'explicit') !== false) $rating = 'e';
            elseif (strpos($rStr, 'questionable') !== false) $rating = 'q';
        }

        $meta = [
            'rating' => $rating,
            'source' => $viewUrl,
            'title'  => "Realbooru #" . $realbooruId,
            'tags'   => $tagStr,
        ];

        $file = [
            'name'     => basename(parse_url($imgUrl, PHP_URL_PATH)),
            'type'     => mime_content_type($tmpFile),
            'tmp_name' => $tmpFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpFile),
        ];

        try {
            $postId = Post::upload($file, $meta);
            $knownRealbooruIds[$realbooruId] = $postId;
            logMsg("  Success: Uploaded as Post #$postId.");
            $downloaded++;
        } catch (Throwable $e) {
            logMsg("  Error: " . $e->getMessage());
            $errors++;
        }

        @unlink($tmpFile);
    }

    $page += 42;
    DB::exec(
        'INSERT INTO scraper_progress (source, tag, next_pid, updated_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(source, tag) DO UPDATE SET next_pid = excluded.next_pid, updated_at = excluded.updated_at',
        [$progressSource, $tag, $page, time()]
    );
}

logMsg("Done! Downloaded: $downloaded, Skipped: $skipped, Errors: $errors.");
