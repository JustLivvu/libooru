<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../post.php';
require_once __DIR__ . '/../view.php';

$options = getopt('', ['task-id:', 'order:']);
$taskId = (int)($options['task-id'] ?? 0);
$order = strtolower((string)($options['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
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
    $categories = [];
    if (preg_match_all('/<a class=["\']([^"\']*(?:tag-type-[^"\']+|model|metadata)[^"\']*)["\'] href=["\'][^"\']*tags=([^"\'&>]+)/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tag = strtolower(urldecode($match[2]));
            $category = preg_match('/tag-type-([a-z_-]+)/i', $match[1], $typeMatch)
                ? $typeMatch[1]
                : (preg_match('/(?:^|\s)model(?:\s|$)/i', $match[1])
                    ? 'model'
                    : (preg_match('/(?:^|\s)metadata(?:\s|$)/i', $match[1]) ? 'meta' : 'general'));
            $tags[] = $tag;
            $categories[$tag] = Post::normalizeTagCategory($category);
        }
    }
    $tags[] = 'real_life';
    return [array_values(array_unique($tags)), $categories];
}

$posts = DB::rows("SELECT id, title FROM posts WHERE title LIKE 'Realbooru #%' ORDER BY id $order");
logTagFetch('Starting tag fetch for ' . count($posts) . ' Realbooru posts (' . strtolower($order) . 'ending local ID).');

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

    [$remoteTags, $tagCategories] = realbooruTagsFromHtml($html);
    $currentTags = array_column(Post::tagsFor((int)$post['id']), 'name');
    $mergedTags = array_values(array_unique(array_merge($currentTags, $remoteTags)));
    if (count($mergedTags) !== count($currentTags)) {
        Post::setTags((int)$post['id'], $mergedTags, $tagCategories);
        logTagFetch("Post #{$post['id']} / Realbooru #{$realbooruId}: added " . (count($mergedTags) - count($currentTags)) . ' tag(s).');
        $updated++;
    } else {
        Post::setTagCategories($tagCategories);
        $unchanged++;
    }


    usleep(250000);
}

logTagFetch("Done. Updated: {$updated}, unchanged: {$unchanged}, errors: {$errors}.");
