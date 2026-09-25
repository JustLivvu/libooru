<?php
declare(strict_types=1);

function page_tags(?array $user): void
{
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q      = trim($_GET['q'] ?? '');
    $q = Post::canonicalTagName($q);
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $blacklisted = [];
    if ($user && !empty($user['blacklist'])) {
        $blacklisted = Post::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
    }
    $notInSql = '';
    $notInParams = [];
    if ($blacklisted) {
        $notInPlaceholders = implode(',', array_fill(0, count($blacklisted), '?'));
        $notInSql = " AND name NOT IN ($notInPlaceholders) COLLATE NOCASE";
        $notInParams = $blacklisted;
    }

    if ($q !== '') {
        $tags  = DB::rows('SELECT name, count, category FROM tags WHERE name LIKE ?' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge(['%' . $q . '%'], $notInParams, [$limit, $offset]));
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE name LIKE ?' . $notInSql, array_merge(['%' . $q . '%'], $notInParams));
    } else {
        $tags  = DB::rows('SELECT name, count, category FROM tags WHERE 1=1' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge($notInParams, [$limit, $offset]));
        $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE 1=1' . $notInSql, $notInParams);
    }
    $pages = (int)ceil($total / $limit);

    View::header('Tags', $user);
    View::flash();
    echo '<table class="tags-table">';
    echo '<thead><tr><th scope="col">Tag</th><th scope="col">Posts</th></tr></thead><tbody>';
    foreach ($tags as $t) {
        echo '<tr><td><a href="' . View::e(View::url('/posts', ['q' => $t['name']])) . '">' . View::e($t['name']) . '</a></td><td>' . View::e($t['count']) . '</td></tr>';
    }
    echo '</tbody></table>';
    View::paginator($page, $pages, '/tags', $q ? ['q' => $q] : []);
    View::footer();
}
