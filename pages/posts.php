<?php
declare(strict_types=1);

function page_posts(?array $user): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $q       = trim($_GET['q'] ?? '');
    $rating  = $_GET['rating'] ?? '';
    $quality = in_array($_GET['quality'] ?? '', ['low', 'medium', 'high', 'ultra'], true)
               ? $_GET['quality'] : '';
    $order   = in_array($_GET['order'] ?? '', ['id DESC', 'id ASC', 'score DESC', 'created_at DESC'], true)
               ? $_GET['order'] : 'id DESC';

    $result = Post::search($page, POSTS_PER_PAGE, $q, $rating, $order, $quality);
    Activity::recordPostBrowsing();

    $sidebarTags = DB::rows('SELECT name, count, category FROM tags ORDER BY count DESC LIMIT 50');

    $browseTitle = $q !== '' ? str_replace('_', ' ', $q) . ' posts' : 'Browse Posts';
    $browseDescription = $q !== ''
        ? 'Browse posts tagged ' . str_replace('_', ' ', $q) . ' on ' . View::siteSetting('site_name', SITE_NAME) . '.'
        : 'Browse the newest and top-rated tagged images and videos on ' . View::siteSetting('site_name', SITE_NAME) . '.';
    View::header($browseTitle, $user, $sidebarTags, [
        'description' => $browseDescription,
        'canonical' => View::url('/posts', array_filter(['q' => $q, 'page' => $page > 1 ? $page : null])),
    ]);
    View::flash();

    echo '<section class="post-listing" aria-label="Posts">';
    View::postGrid($result['posts']);
    View::paginator($page, $result['pages'], '/posts', array_filter(['q' => $q, 'rating' => $rating, 'order' => $order, 'quality' => $quality]));
    echo '</section>';
    View::footer();
}

function page_comments(?array $user): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }

    $perPage = 30;
    $total = (int)DB::scalar('SELECT COUNT(*) FROM comments');
    $pages = (int)ceil($total / $perPage);
    $page = min(max(1, (int)($_GET['page'] ?? 1)), max(1, $pages));
    $comments = DB::rows(
        'SELECT c.id, c.post_id, c.guest_name, c.body, c.created_at, u.name AS user_name
         FROM comments c
         LEFT JOIN users u ON u.id = c.user_id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT ? OFFSET ?',
        [$perPage, ($page - 1) * $perPage]
    );

    View::header('Comments', $user, null, [
        'description' => 'Recent comments on posts at ' . View::siteSetting('site_name', SITE_NAME) . '.',
        'canonical' => View::url('/comments', $page > 1 ? ['page' => $page] : []),
    ]);
    if (!$comments) {
        echo '<p>No comments yet.</p>';
    } else {
        echo '<div class="comment-feed">';
        foreach ($comments as $comment) {
            $author = $comment['user_name'] ?? $comment['guest_name'] ?? 'Anonymous';
            $postUrl = View::url('/post/' . (int)$comment['post_id']) . '#comment-' . (int)$comment['id'];
            echo '<article class="comment">';
            echo '<span class="comment-author">' . View::e($author) . '</span> ';
            echo '<span class="comment-date">' . date('Y-m-d H:i', (int)$comment['created_at']) . '</span>';
            echo ' <a class="comment-post-link" href="' . View::e($postUrl) . '">Post #' . (int)$comment['post_id'] . '</a>';
            echo '<p>' . nl2br(View::e($comment['body'])) . '</p>';
            echo '</article>';
        }
        echo '</div>';
    }
    View::paginator($page, $pages, '/comments');
    View::footer();
}

function page_post(?array $user, int $id): void
{
    if (!$user && View::siteSetting('require_login_posts', '0') === '1') {
        Router::redirect('/login');
    }
    $post = Post::getById($id);
    if (!$post) {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>Post not found</h1>';
        View::footer();
        return;
    }
    if ($user && !empty($user['blacklist'])) {
        $blacklisted = Post::canonicalizeTags(preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY));
        $postTagNames = array_map(fn($t) => strtolower($t['name']), $post['tags']);
        if (array_intersect($blacklisted, $postTagNames)) {
            http_response_code(404);
            View::header('Not Found', $user);
            echo '<h1>Post not found</h1>';
            View::footer();
            return;
        }
    }
    Activity::recordPostBrowsing();
    $comments = Post::commentsFor($id);
    $tags     = $post['tags'];



    $fileUrl = Image::fileUrl($post['filename']);
    $thumbUrl = Image::thumbUrl($post['filename']);
    $tagNames = array_column($tags, 'name');
    $readableTags = array_map(fn($tag) => str_replace('_', ' ', $tag), array_slice($tagNames, 0, 8));
    $postTitle = trim((string)($post['title'] ?? ''));
    $postHeading = $postTitle !== '' ? $postTitle : 'Post #' . $id;
    if ($postTitle === '') {
        $postTitle = $readableTags
            ? implode(', ', array_slice($readableTags, 0, 4)) . ' - Post #' . $id
            : 'Post #' . $id;
    }
    $description = 'View post #' . $id;
    if ($readableTags) $description .= ' tagged ' . implode(', ', $readableTags);
    $description .= ' on ' . View::siteSetting('site_name', SITE_NAME) . '.';
    $posterName = $post['user_id']
        ? (DB::scalar('SELECT name FROM users WHERE id = ?', [$post['user_id']]) ?: 'unknown')
        : 'Anonymous';
    $ratingLabel = match($post['rating']) {
        's' => 'Safe',
        'q' => 'Questionable',
        'e' => 'Explicit',
        default => (string)$post['rating'],
    };

    View::header($postTitle, $user, $tags, [
        'description' => $description,
        'canonical' => View::url('/post/' . $id),
        'type' => 'article',
        'image' => $thumbUrl,
        'image_alt' => $readableTags ? implode(', ', $readableTags) : 'Post #' . $id,
        'sidebar' => [
            'group_tags' => true,
            'source' => (string)($post['source'] ?? ''),
            'details' => [
                'Rating' => $ratingLabel,
                'Size' => $post['width'] . '×' . $post['height'] . ' — ' . round($post['filesize'] / 1024, 1) . ' KB',
                'MD5' => (string)$post['md5'],
                'Uploaded by' => (string)$posterName,
                'Date' => date('Y-m-d H:i', (int)$post['created_at']),
            ],
        ],
        'json_ld' => [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'name' => $postTitle,
            'description' => $description,
            'contentUrl' => View::absoluteUrl($fileUrl),
            'thumbnailUrl' => View::absoluteUrl($thumbUrl),
            'width' => (int)$post['width'],
            'height' => (int)$post['height'],
            'uploadDate' => date(DATE_ATOM, (int)$post['created_at']),
        ],
    ]);
    View::flash();

    $isOwner  = $user && (int)$user['id'] === (int)$post['user_id'];
    $canModeratePosts = $user && Auth::can('moderate_posts', $user);
    $canModerateComments = $user && Auth::can('moderate_comments', $user);
    $hasPendingReport = $user && Post::hasPendingReport($id, (int)$user['id']);

    echo '<article class="post-view">';
    echo '<h1>' . View::e($postHeading) . '</h1>';

    echo '<div class="post-image">';
    $ext = pathinfo($post['filename'], PATHINFO_EXTENSION);
    if (in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true)) {
        echo '<video src="' . View::e($fileUrl) . '" controls loop playsinline preload="metadata"></video>';
    } else {
        echo '<a href="' . View::e($fileUrl) . '">';
        echo '<img src="' . View::e($fileUrl) . '" alt="' . View::e($readableTags ? implode(', ', $readableTags) : 'Post #' . $id) . '">';
        echo '</a>';
    }
    echo '</div>';


    echo '<div class="post-actions">';
    $upChevron = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>';
    $downChevron = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    if ($user) {
        echo '<form class="post-score-control" method="post" action="' . View::url('/post/' . $id) . '">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="vote">';
        echo '<button type="submit" name="value" value="1" aria-label="Upvote">' . $upChevron . '</button>';
        echo '<span class="post-score-value" aria-label="Score ' . View::e($post['score']) . '">' . View::e($post['score']) . '</span>';
        echo '<button type="submit" name="value" value="-1" aria-label="Downvote">' . $downChevron . '</button>';
        echo '</form>';
    } else {
        echo '<div class="post-score-control" aria-label="Score ' . View::e($post['score']) . '">';
        echo '<span class="post-score-button is-disabled">' . $upChevron . '</span>';
        echo '<span class="post-score-value">' . View::e($post['score']) . '</span>';
        echo '<span class="post-score-button is-disabled">' . $downChevron . '</span>';
        echo '</div>';
    }
    if ($user) {
        $isFav = Post::isFavorite($id, (int)$user['id']);
        echo '<form method="post" action="' . View::url('/post/' . $id) . '" style="display:inline">';
        View::csrfField();
        if ($isFav) {
            echo '<input type="hidden" name="action" value="unfavorite">';
            echo '<button type="submit">★ Remove from Favorites</button>';
        } else {
            echo '<input type="hidden" name="action" value="favorite">';
            echo '<button type="submit">☆ Add to Favorites</button>';
        }
        echo '</form> ';
        if ($hasPendingReport) {
            echo '<span>Report pending review.</span> ';
        } else {
            $reportDialogId = 'report-post-dialog-' . $id;
            echo '<button type="button" class="report-dialog-open" data-report-dialog="' . View::e($reportDialogId) . '">Report post</button>';
            echo '<dialog class="report-dialog" id="' . View::e($reportDialogId) . '">';
            echo '<div class="report-dialog-titlebar">';
            echo '<strong>Report post #' . View::e($id) . '</strong>';
            echo '<button type="button" class="report-dialog-close" aria-label="Close report window">×</button>';
            echo '</div>';
            echo '<form class="report-dialog-form" method="post" action="' . View::url('/post/' . $id) . '">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="report">';
            echo '<label><span>Reason</span><textarea name="reason" rows="3" minlength="3" maxlength="' . MAX_POST_REPORT_LENGTH . '" required></textarea></label>';
            echo '<div class="report-dialog-actions"><button type="submit">Submit report</button><button type="button" class="report-dialog-cancel">Cancel</button></div>';
            echo '</form></dialog>';
            echo '<script>(()=>{';
            echo 'const dialog=document.getElementById(' . json_encode($reportDialogId) . ');';
            echo 'const opener=document.querySelector(`[data-report-dialog="${dialog.id}"]`);';
            echo 'const titlebar=dialog.querySelector(".report-dialog-titlebar");';
            echo 'const close=()=>dialog.close();';
            echo 'opener.addEventListener("click",()=>{if(!dialog.open)dialog.showModal();});';
            echo 'dialog.querySelector(".report-dialog-close").addEventListener("click",close);';
            echo 'dialog.querySelector(".report-dialog-cancel").addEventListener("click",close);';
            echo 'let drag=null;';
            echo 'titlebar.addEventListener("pointerdown",event=>{';
            echo 'if(event.button!==0||event.target.closest("button"))return;';
            echo 'const rect=dialog.getBoundingClientRect();';
            echo 'dialog.style.transform="none";dialog.style.left=rect.left+"px";dialog.style.top=rect.top+"px";';
            echo 'drag={id:event.pointerId,x:event.clientX-rect.left,y:event.clientY-rect.top};';
            echo 'titlebar.setPointerCapture(event.pointerId);event.preventDefault();});';
            echo 'titlebar.addEventListener("pointermove",event=>{if(!drag||event.pointerId!==drag.id)return;';
            echo 'const maxLeft=Math.max(0,window.innerWidth-dialog.offsetWidth);';
            echo 'const maxTop=Math.max(0,window.innerHeight-dialog.offsetHeight);';
            echo 'dialog.style.left=Math.min(maxLeft,Math.max(0,event.clientX-drag.x))+"px";';
            echo 'dialog.style.top=Math.min(maxTop,Math.max(0,event.clientY-drag.y))+"px";});';
            echo 'const stop=event=>{if(drag&&event.pointerId===drag.id)drag=null;};';
            echo 'titlebar.addEventListener("pointerup",stop);titlebar.addEventListener("pointercancel",stop);';
            echo '})();</script>';
        }
    }
    if ($isOwner || $canModeratePosts) {
        echo '<a href="' . View::url('/post/' . $id . '/edit') . '"><button type="button">Edit</button></a> ';
        echo '<form method="post" action="' . View::url('/post/' . $id . '/delete') . '" style="display:inline" onsubmit="return confirm(\'Delete post #' . $id . '?\');">';
        View::csrfField();
        echo '<button>Delete</button>';
        echo '</form>';
    }
    echo '</div>';

    echo '</article>';


    echo '<section class="comments">';
    echo '<h2>Comments (' . count($comments) . ')</h2>';
    foreach ($comments as $c) {
        $author = $c['user_name'] ?? $c['guest_name'] ?? 'Anonymous';
        echo '<div class="comment" id="comment-' . (int)$c['id'] . '">';
        echo '<span class="comment-author">' . View::e($author) . '</span> ';
        echo '<span class="comment-date">' . date('Y-m-d H:i', (int)$c['created_at']) . '</span>';
        if ($canModerateComments) {
            echo ' <form method="post" action="' . View::url('/post/' . $id) . '" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="delete_comment">';
            echo '<input type="hidden" name="comment_id" value="' . View::e($c['id']) . '">';
            echo '<button>×</button>';
            echo '</form>';
        }
        echo '<p>' . nl2br(View::e($c['body'])) . '</p>';
        echo '</div>';
    }

    echo '<h3>Add comment</h3>';
    if ($user) {
        echo '<form method="post" action="' . View::url('/post/' . $id) . '">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="comment">';
        echo '<textarea name="body" rows="4" cols="60" maxlength="' . MAX_COMMENT_LENGTH . '" required></textarea><br>';
        echo '<button>Post comment</button>';
        echo '</form>';
    } else {
        echo '<p><a href="' . View::url('/login') . '">Log in</a> to post a comment.</p>';
    }
    echo '</section>';

    View::footer();
}

function post_handle(?array $user, int $id): void
{
    View::verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'comment') {
        Auth::require();
        $body = $_POST['body'] ?? '';
        try {
            if (!DB::consumeRateLimit('comment', Auth::requestSubject(), COMMENT_RATE_LIMIT, COMMENT_RATE_WINDOW)) {
                throw new RuntimeException('Too many comments. Please try again later.', 429);
            }
            Post::addComment($id, $body, Auth::id());
            View::setFlash('Comment posted.', 'ok');
        } catch (RuntimeException $e) {
            View::setFlash($e->getMessage(), 'error');
        }
    } elseif ($action === 'report') {
        Auth::require();
        try {
            if (!DB::consumeRateLimit('post_report', 'user:' . (int)$user['id'], POST_REPORT_RATE_LIMIT, POST_REPORT_RATE_WINDOW)) {
                throw new RuntimeException('Too many reports. Please try again later.');
            }
            Post::report($id, (int)$user['id'], (string)($_POST['reason'] ?? ''));
            View::setFlash('Post reported. Thank you.', 'ok');
        } catch (RuntimeException $e) {
            View::setFlash($e->getMessage(), 'error');
        }
    } elseif ($action === 'favorite' && $user) {
        Post::addFavorite($id, (int)$user['id']);
        View::setFlash('Added to favorites.', 'ok');
    } elseif ($action === 'unfavorite' && $user) {
        Post::removeFavorite($id, (int)$user['id']);
        View::setFlash('Removed from favorites.', 'ok');
    } elseif ($action === 'vote' && $user) {
        $value = (int)($_POST['value'] ?? 0);
        Post::vote($id, (int)$user['id'], $value);
    } elseif ($action === 'delete_comment' && $user && Auth::can('moderate_comments', $user)) {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid) Post::deleteComment($cid);
        View::setFlash('Comment deleted.', 'ok');
    }

    Router::redirect('/post/' . $id);
}

function page_post_edit(?array $user, int $id, string $method): void
{
    Auth::require();
    $post = Post::getById($id);
    if (!$post) {
        http_response_code(404);
        View::header('Not Found', $user);
        echo '<h1>Post not found</h1>';
        View::footer();
        return;
    }
    if ((int)$user['id'] !== (int)$post['user_id'] && !Auth::can('moderate_posts', $user)) {
        Router::redirect('/post/' . $id);
    }

    $error = '';
    if ($method === 'POST') {
        View::verifyCsrf();
        try {
            Post::update($id, $_POST);
            View::setFlash('Post updated.', 'ok');
            Router::redirect('/post/' . $id);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    $tagStr = implode(' ', array_column($post['tags'], 'name'));

    View::header('Edit Post #' . $id, $user);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<h1>Edit Post #' . View::e($id) . '</h1>';
    echo '<form method="post">';
    View::csrfField();
    echo '<label>Tags<br><input name="tags" value="' . View::e($tagStr) . '" size="60"></label><br>';
    echo '<label>Rating<br><select name="rating">';

    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        $sel = $post['rating'] === $v ? ' selected' : '';
        echo "<option value=\"{$v}\"{$sel}>{$l}</option>";
    }
    echo '</select></label><br>';
    echo '<label>Source<br><input name="source" value="' . View::e($post['source'] ?? '') . '" size="60"></label><br>';
    echo '<label>Title<br><input name="title" value="' . View::e($post['title'] ?? '') . '" size="60"></label><br>';
    echo '<button>Save</button> <a href="' . View::url('/post/' . $id) . '">Cancel</a>';
    echo '</form>';
    View::footer();
}

function action_post_delete(?array $user, int $id): void
{
    Auth::require();
    View::verifyCsrf();
    $post = Post::getById($id);
    if (!$post) Router::redirect('/posts');
    if ((int)$user['id'] !== (int)$post['user_id'] && !Auth::can('moderate_posts', $user)) {
        Router::redirect('/post/' . $id);
    }
    Post::delete($id);
    View::setFlash('Post #' . $id . ' deleted.', 'ok');
    Router::redirect('/posts');
}

function page_upload(?array $user, string $method): void
{
    Auth::require();
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        try {
            $id = Post::upload($_FILES['file'] ?? [], $_POST);
            View::setFlash('Post #' . $id . ' uploaded.', 'ok');
            Router::redirect('/post/' . $id);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    View::header('Upload', $user);
    View::flash();
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<form method="post" enctype="multipart/form-data" class="upload-form">';
    View::csrfField();
    $maxMb = MAX_FILE_SIZE / 1024 / 1024;
    echo '<label><span>Image/Video <small>(JPEG, PNG, GIF, WebP, MP4, WebM, MOV — max ' . $maxMb . ' MB)</small></span>';
    echo '<input type="file" name="file" accept="image/*,video/mp4,video/webm,video/quicktime,.mov" required></label>';
    echo '<fieldset class="upload-content-type"><legend>Content type</legend><div>';
    echo '<label><input type="radio" name="content_type" value="artwork" checked><span>Artwork</span></label>';
    echo '<label><input type="radio" name="content_type" value="real_life"><span>Real Life</span></label>';
    echo '</div></fieldset>';
    echo '<label><span>Tags <small>(space-separated)</small></span><input name="tags" placeholder="character:foo artist:bar general_tag"></label>';
    echo '<label class="upload-rating"><span>Rating</span><select name="rating">';
    foreach (['s' => 'Safe', 'q' => 'Questionable', 'e' => 'Explicit'] as $v => $l) {
        echo "<option value=\"{$v}\">{$l}</option>";
    }
    echo '</select></label>';
    echo '<label><span>Source URL</span><input name="source" placeholder="https://…"></label>';
    echo '<label><span>Title</span><input name="title"></label>';
    echo '<button>Upload</button>';
    echo '</form>';
    View::footer();
}
