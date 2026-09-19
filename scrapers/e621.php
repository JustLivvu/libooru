<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../post.php';
require_once __DIR__ . '/../view.php';

$options = getopt('', ['tag:', 'blacklist:', 'task-id:']);
$tag = trim($options['tag'] ?? '');
$blacklistInput = trim($options['blacklist'] ?? '');
$taskId = (int)($options['task-id'] ?? 0);

if ($tag === '' || $taskId < 1) {
    die("Usage: php e621.php --tag <tag query> --blacklist <tags> --task-id <id>\n");
}

function e621Log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function e621Context(): mixed
{
    return stream_context_create([
        'http' => [
            'header' => "User-Agent: Libooru/1.0 (self-hosted media scraper)\r\nAccept: application/json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
}

function e621Download(string $url, string $destination): bool
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Libooru/1.0 (self-hosted media scraper)\r\nReferer: https://e621.net/\r\n",
            'timeout' => 60,
        ],
    ]);
    $input = @fopen($url, 'rb', false, $context);
    if ($input === false) return false;

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

function e621PostTags(array $post): array
{
    $tags = [];
    $categories = [];
    foreach (($post['tags'] ?? []) as $group => $groupTags) {
        if (is_array($groupTags)) {
            $category = Post::normalizeTagCategory((string)$group);
            foreach ($groupTags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tag = strtolower($tag);
                    $tags[] = $tag;
                    $categories[$tag] = $category;
                }
            }
        }
    }
    return [array_values(array_unique($tags)), $categories];
}

$blacklist = preg_split('/[\s,]+/', strtolower($blacklistInput), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$blacklist = array_values(array_unique($blacklist));
$progressKey = $tag . "\nblacklist:" . implode(',', $blacklist);

$user = DB::row("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if (!$user) die("No admin user found.\n");
Auth::setUser($user);

$afterId = max(0, (int)(DB::scalar(
    'SELECT next_pid FROM scraper_progress WHERE source = ? AND tag = ?',
    ['e621_oldest_first', $progressKey]
) ?: 0));

$knownIds = [];
foreach (DB::rows("SELECT id, title FROM posts WHERE title LIKE 'e621 #%'") as $post) {
    if (preg_match('/^e621 #(\d+)$/', (string)$post['title'], $match)) {
        $knownIds[$match[1]] = (int)$post['id'];
    }
}

e621Log("Starting e621 scraper for query: $tag");
e621Log('Blacklist: ' . ($blacklist ? implode(', ', $blacklist) : 'none'));
e621Log('Loaded ' . count($knownIds) . ' existing e621 post IDs.');
if ($afterId > 0) e621Log("Continuing after e621 post #$afterId.");

$downloaded = 0;
$skipped = 0;
$blacklisted = 0;
$errors = 0;
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov'];

while (true) {
    $query = http_build_query([
        'tags' => $tag . ' order:id_asc',
        'limit' => 320,
        'page' => 'a' . $afterId,
    ]);
    $url = 'https://e621.net/posts.json?' . $query;
    e621Log("Fetching posts after ID $afterId...");

    $json = @file_get_contents($url, false, e621Context());
    $payload = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($payload) || !isset($payload['posts']) || !is_array($payload['posts'])) {
        e621Log('Error: e621 returned an invalid response. Stopping without advancing progress.');
        $errors++;
        break;
    }

    $posts = $payload['posts'];
    if (!$posts) {
        e621Log('No more posts found.');
        break;
    }

    $pageMaxId = $afterId;
    foreach ($posts as $remotePost) {
        if (!is_array($remotePost)) continue;
        $e621Id = (int)($remotePost['id'] ?? 0);
        if ($e621Id < 1) continue;
        $pageMaxId = max($pageMaxId, $e621Id);
        [$remoteTags, $tagCategories] = e621PostTags($remotePost);

        $blockedTags = array_values(array_intersect($blacklist, $remoteTags));
        if ($blockedTags) {
            e621Log("Skipping e621 #$e621Id (blacklist: " . implode(', ', $blockedTags) . ').');
            $blacklisted++;
            continue;
        }
        if (isset($knownIds[(string)$e621Id])) {
            e621Log("Skipping e621 #$e621Id (already Post #{$knownIds[(string)$e621Id]}). ");
            $skipped++;
            continue;
        }

        $fileData = is_array($remotePost['file'] ?? null) ? $remotePost['file'] : [];
        $fileUrl = is_string($fileData['url'] ?? null) ? $fileData['url'] : '';
        $extension = strtolower((string)($fileData['ext'] ?? ''));
        if ($fileUrl === '' || !in_array($extension, $allowedExtensions, true)) {
            e621Log("Skipping e621 #$e621Id (missing URL or unsupported extension: $extension). ");
            $skipped++;
            continue;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'e621_');
        if ($tmpFile === false) {
            e621Log("Error: could not create a temporary file for e621 #$e621Id.");
            $errors++;
            continue;
        }

        e621Log("Downloading e621 #$e621Id...");
        if (!e621Download($fileUrl, $tmpFile)) {
            e621Log("Error: download failed for e621 #$e621Id.");
            $errors++;
            @unlink($tmpFile);
            continue;
        }

        $md5 = md5_file($tmpFile);
        $existingId = $md5 ? DB::scalar('SELECT id FROM posts WHERE md5 = ?', [$md5]) : false;
        if ($existingId) {
            e621Log("Skipping e621 #$e621Id (same file already exists as Post #$existingId). ");
            $skipped++;
            @unlink($tmpFile);
            continue;
        }

        $postUrl = 'https://e621.net/posts/' . $e621Id;
        $rating = in_array($remotePost['rating'] ?? '', ['s', 'q', 'e'], true) ? $remotePost['rating'] : 'q';
        $meta = [
            'rating' => $rating,
            'source' => $postUrl,
            'title' => 'e621 #' . $e621Id,
            'tags' => implode(' ', $remoteTags),
            'tag_categories' => $tagCategories,
            'content_type' => 'artwork',
        ];
        $file = [
            'name' => basename((string)parse_url($fileUrl, PHP_URL_PATH)),
            'type' => mime_content_type($tmpFile) ?: 'application/octet-stream',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile),
        ];

        try {
            $postId = Post::upload($file, $meta);
            $knownIds[(string)$e621Id] = $postId;
            e621Log("Uploaded e621 #$e621Id as Post #$postId.");
            $downloaded++;
        } catch (Throwable $e) {
            e621Log("Error importing e621 #$e621Id: " . $e->getMessage());
            $errors++;
        } finally {
            @unlink($tmpFile);
        }
    }

    if ($pageMaxId <= $afterId) {
        e621Log('Error: API pagination did not advance.');
        $errors++;
        break;
    }
    $afterId = $pageMaxId;
    DB::exec(
        'INSERT INTO scraper_progress (source, tag, next_pid, updated_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(source, tag) DO UPDATE SET next_pid = excluded.next_pid, updated_at = excluded.updated_at',
        ['e621_oldest_first', $progressKey, $afterId, time()]
    );

    // e621 asks API clients to stay at or below two requests per second.
    usleep(500000);
}

e621Log("Done! Downloaded: $downloaded, Skipped: $skipped, Blacklisted: $blacklisted, Errors: $errors.");
