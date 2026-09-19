<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../post.php';

const E621_CATEGORY_BATCH_SIZE = 150;

function categoryBackfillLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function e621CategoryContext(): mixed
{
    return stream_context_create([
        'http' => [
            'header' => "User-Agent: Libooru/1.0 (self-hosted tag category backfill)\r\nAccept: application/json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
}

$remoteIds = [];
foreach (DB::rows("SELECT title FROM posts WHERE title LIKE 'e621 #%'") as $post) {
    if (preg_match('/^e621 #(\d+)$/', (string)$post['title'], $match)) {
        $remoteIds[] = (int)$match[1];
    }
}
$remoteIds = array_values(array_unique($remoteIds));
sort($remoteIds, SORT_NUMERIC);

categoryBackfillLog('Backfilling categories for ' . count($remoteIds) . ' e621 posts.');
$requests = 0;
$postsRead = 0;
$updatedTags = 0;
$errors = 0;

foreach (array_chunk($remoteIds, E621_CATEGORY_BATCH_SIZE) as $batch) {
    $query = http_build_query([
        'limit' => min(320, count($batch)),
        'tags' => 'id:' . implode(',', $batch),
    ]);
    $body = @file_get_contents('https://e621.net/posts.json?' . $query, false, e621CategoryContext());
    $payload = is_string($body) ? json_decode($body, true) : null;
    $requests++;
    if (!is_array($payload) || !is_array($payload['posts'] ?? null)) {
        categoryBackfillLog('Request ' . $requests . ' returned an invalid response; continuing.');
        $errors++;
        usleep(1_000_000);
        continue;
    }

    $categories = [];
    foreach ($payload['posts'] as $remotePost) {
        if (!is_array($remotePost)) continue;
        $postsRead++;
        foreach (($remotePost['tags'] ?? []) as $category => $tags) {
            if (!is_array($tags)) continue;
            $normalizedCategory = Post::normalizeTagCategory((string)$category);
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $categories[strtolower($tag)] = $normalizedCategory;
                }
            }
        }
    }
    $updatedTags += Post::setTagCategories($categories);
    categoryBackfillLog("Request {$requests}: read {$postsRead} posts, updated {$updatedTags} tags.");
    usleep(600_000);
}

categoryBackfillLog("Done. Requests: {$requests}, posts: {$postsRead}, tag updates: {$updatedTags}, errors: {$errors}.");
