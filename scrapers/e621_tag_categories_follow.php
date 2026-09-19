<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../post.php';

$options = getopt('', ['pid:']);
$watchedPid = (int)($options['pid'] ?? 0);
if ($watchedPid < 1) {
    die("Usage: php e621_tag_categories_follow.php --pid <running-e621-pid>\n");
}

function followerLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function watchedE621ImporterIsRunning(int $pid): bool
{
    $command = (string)@shell_exec('ps -p ' . $pid . ' -o command= 2>/dev/null');
    return str_contains($command, 'scrapers/e621.php');
}

function fetchE621Categories(array $remoteIds): ?array
{
    $query = http_build_query([
        'limit' => min(320, count($remoteIds)),
        'tags' => 'id:' . implode(',', $remoteIds),
    ]);
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Libooru/1.0 (self-hosted live tag category sync)\r\nAccept: application/json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents('https://e621.net/posts.json?' . $query, false, $context);
    $payload = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($payload) || !is_array($payload['posts'] ?? null)) return null;

    $categories = [];
    foreach ($payload['posts'] as $remotePost) {
        if (!is_array($remotePost)) continue;
        foreach (($remotePost['tags'] ?? []) as $category => $tags) {
            if (!is_array($tags)) continue;
            $category = Post::normalizeTagCategory((string)$category);
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') $categories[strtolower($tag)] = $category;
            }
        }
    }
    return $categories;
}

$lastLocalId = 0;
$processedPosts = 0;
$updatedTags = 0;
followerLog("Following e621 importer PID {$watchedPid}.");

while (true) {
    $rows = DB::rows(
        "SELECT id, title FROM posts WHERE id > ? AND title LIKE 'e621 #%'
         ORDER BY id ASC LIMIT 150",
        [$lastLocalId]
    );

    if ($rows) {
        $remoteIds = [];
        foreach ($rows as $row) {
            if (preg_match('/^e621 #(\d+)$/', (string)$row['title'], $match)) {
                $remoteIds[] = (int)$match[1];
            }
        }
        $categories = fetchE621Categories($remoteIds);
        if ($categories === null) {
            followerLog('e621 returned an invalid response; retrying this batch.');
            sleep(5);
            continue;
        }
        $updatedTags += Post::setTagCategories($categories);
        $lastLocalId = (int)end($rows)['id'];
        $processedPosts += count($rows);
        followerLog("Processed {$processedPosts} posts; updated {$updatedTags} tag categories.");
        usleep(600_000);
        continue;
    }

    if (!watchedE621ImporterIsRunning($watchedPid)) {
        followerLog("Importer PID {$watchedPid} stopped. Live category sync complete.");
        break;
    }
    sleep(20);
}
