<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../post.php';
require_once __DIR__ . '/../view.php';

$options = getopt('', ['task-id:']);
$taskId = (int)($options['task-id'] ?? 0);
if (!$taskId) {
    die("Usage: php realbooru_tags_fetcher.php --task-id <id>\n");
}

function logTagFetch(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function realbooruTagsFromHtml(string $html): array
{
    $tags = [];
    if (preg_match_all('/<a class=["\'](?:tag-type-[^"\']+|model)["\'] href=["\'][^"\']*tags=([^"\'&>]+)/i', $html, $matches)) {
        foreach ($matches[1] as $tag) {
            $tags[] = urldecode($tag);
        }
    }
    $tags[] = 'realbooru';
    return array_values(array_unique($tags));
}

$posts = DB::rows("SELECT id, title FROM posts WHERE title LIKE 'Realbooru #%' ORDER BY id ASC");
logTagFetch('Starting tag fetch for ' . count($posts) . ' Realbooru posts.');

$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (compatible; Libooru tag fetcher)\r\nReferer: https://realbooru.com/\r\n",
        'timeout' => 20,
    ],
]);

$updated = 0;
$unchanged = 0;
$errors = 0;
foreach ($posts as $post) {
    if (!preg_match('/^Realbooru #(\d+)$/', (string)$post['title'], $match)) continue;

    $realbooruId = $match[1];
    $url = 'https://realbooru.com/index.php?page=post&s=view&id=' . $realbooruId;
    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        logTagFetch("Post #{$post['id']} / Realbooru #{$realbooruId}: failed to fetch.");
        $errors++;
        continue;
    }

    $remoteTags = realbooruTagsFromHtml($html);
    $currentTags = array_column(Post::tagsFor((int)$post['id']), 'name');
    $mergedTags = array_values(array_unique(array_merge($currentTags, $remoteTags)));
    if (count($mergedTags) !== count($currentTags)) {
        Post::setTags((int)$post['id'], $mergedTags);
        logTagFetch("Post #{$post['id']} / Realbooru #{$realbooruId}: added " . (count($mergedTags) - count($currentTags)) . ' tag(s).');
        $updated++;
    } else {
        $unchanged++;
    }


    usleep(250000);
}

logTagFetch("Done. Updated: {$updated}, unchanged: {$unchanged}, errors: {$errors}.");
