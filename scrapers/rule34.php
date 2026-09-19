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

if ($tag === '' || $taskId < 1) {
    die("Usage: php rule34.php --tag <tag query> --task-id <id>\n");
}

function rule34Log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function rule34Context(bool $json = false): mixed
{
    $accept = $json ? "Accept: application/json\r\n" : '';
    return stream_context_create([
        'http' => [
            'header' => "User-Agent: Libooru/1.0 (self-hosted media scraper)\r\n{$accept}Referer: https://rule34.xxx/\r\n",
            'timeout' => 60,
            'ignore_errors' => $json,
        ],
    ]);
}

function rule34Download(string $url, string $destination): bool
{
    $input = @fopen($url, 'rb', false, rule34Context());
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

$userId = trim(View::siteSetting('rule34_user_id'));
$apiKey = trim(View::siteSetting('rule34_api_key'));
if ($userId === '' || $apiKey === '') {
    die("Rule34.xxx API credentials are not configured.\n");
}

$admin = DB::row("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if (!$admin) die("No admin user found.\n");
Auth::setUser($admin);

$afterId = max(0, (int)(DB::scalar(
    'SELECT next_pid FROM scraper_progress WHERE source = ? AND tag = ?',
    ['rule34_oldest_first', $tag]
) ?: 0));

$knownIds = [];
foreach (DB::rows("SELECT id, title FROM posts WHERE title LIKE 'Rule34.xxx #%'") as $post) {
    if (preg_match('/^Rule34\.xxx #(\d+)$/', (string)$post['title'], $match)) {
        $knownIds[$match[1]] = (int)$post['id'];
    }
}

rule34Log("Starting Rule34.xxx scraper for query: $tag");
rule34Log('Loaded ' . count($knownIds) . ' existing Rule34.xxx post IDs.');
if ($afterId > 0) rule34Log("Continuing after Rule34.xxx post #$afterId.");

$downloaded = 0;
$skipped = 0;
$errors = 0;
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'];

while (true) {
    $searchTags = trim($tag . ' sort:id:asc id:>' . $afterId);
    $query = http_build_query([
        'page' => 'dapi',
        's' => 'post',
        'q' => 'index',
        'json' => 1,
        'limit' => 1000,
        'tags' => $searchTags,
        'api_key' => $apiKey,
        'user_id' => $userId,
    ]);
    rule34Log("Fetching posts after ID $afterId...");

    $json = @file_get_contents('https://api.rule34.xxx/index.php?' . $query, false, rule34Context(true));
    $payload = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($payload)) {
        $message = is_string($json) ? trim($json, " \t\n\r\0\x0B\"") : 'request failed';
        rule34Log('Error: invalid API response' . ($message !== '' ? ' (' . substr($message, 0, 180) . ')' : '') . '.');
        $errors++;
        break;
    }
    if (!$payload) {
        rule34Log('No more posts found.');
        break;
    }

    $pageMaxId = $afterId;
    foreach ($payload as $remotePost) {
        if (!is_array($remotePost)) continue;
        $rule34Id = (int)($remotePost['id'] ?? 0);
        if ($rule34Id < 1) continue;
        $pageMaxId = max($pageMaxId, $rule34Id);

        if (isset($knownIds[(string)$rule34Id])) {
            rule34Log("Skipping Rule34.xxx #$rule34Id (already Post #{$knownIds[(string)$rule34Id]}). ");
            $skipped++;
            continue;
        }

        $fileUrl = html_entity_decode((string)($remotePost['file_url'] ?? ''), ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($fileUrl, '//')) $fileUrl = 'https:' . $fileUrl;
        $extension = strtolower(pathinfo((string)parse_url($fileUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($fileUrl === '' || !in_array($extension, $allowedExtensions, true)) {
            rule34Log("Skipping Rule34.xxx #$rule34Id (missing URL or unsupported extension: $extension). ");
            $skipped++;
            continue;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'r34_');
        if ($tmpFile === false) {
            rule34Log("Error: could not create a temporary file for Rule34.xxx #$rule34Id.");
            $errors++;
            continue;
        }

        rule34Log("Downloading Rule34.xxx #$rule34Id...");
        if (!rule34Download($fileUrl, $tmpFile)) {
            rule34Log("Error: download failed for Rule34.xxx #$rule34Id.");
            $errors++;
            @unlink($tmpFile);
            continue;
        }

        $md5 = md5_file($tmpFile);
        $existingId = $md5 ? DB::scalar('SELECT id FROM posts WHERE md5 = ?', [$md5]) : false;
        if ($existingId) {
            rule34Log("Skipping Rule34.xxx #$rule34Id (same file already exists as Post #$existingId). ");
            $skipped++;
            @unlink($tmpFile);
            continue;
        }

        $remoteTags = preg_split('/\s+/', trim((string)($remotePost['tags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $postUrl = 'https://rule34.xxx/index.php?page=post&s=view&id=' . $rule34Id;
        $remoteRating = strtolower((string)($remotePost['rating'] ?? ''));
        $rating = match ($remoteRating) {
            's', 'safe' => 's',
            'q', 'questionable' => 'q',
            default => 'e',
        };
        $meta = [
            'rating' => $rating,
            'source' => $postUrl,
            'title' => 'Rule34.xxx #' . $rule34Id,
            'tags' => implode(' ', $remoteTags),
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
            $knownIds[(string)$rule34Id] = $postId;
            rule34Log("Uploaded Rule34.xxx #$rule34Id as Post #$postId.");
            $downloaded++;
        } catch (Throwable $e) {
            rule34Log("Error importing Rule34.xxx #$rule34Id: " . $e->getMessage());
            $errors++;
        } finally {
            @unlink($tmpFile);
        }
    }

    if ($pageMaxId <= $afterId) {
        rule34Log('Error: API pagination did not advance.');
        $errors++;
        break;
    }
    $afterId = $pageMaxId;
    DB::exec(
        'INSERT INTO scraper_progress (source, tag, next_pid, updated_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(source, tag) DO UPDATE SET next_pid = excluded.next_pid, updated_at = excluded.updated_at',
        ['rule34_oldest_first', $tag, $afterId, time()]
    );

    sleep(1);
}

rule34Log("Done! Downloaded: $downloaded, Skipped: $skipped, Errors: $errors.");
