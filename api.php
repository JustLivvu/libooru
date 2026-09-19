<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/post.php';
require_once __DIR__ . '/view.php';























class Api
{
    private ?array $authUser = null;

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }


        $key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
        if ($key !== '') {
            $this->authUser = Auth::fromApiKey($key);
            if ($this->authUser) {

                Auth::setUser($this->authUser);
            }
        } elseif (Auth::current()) {

            $this->authUser = Auth::current();
        }

        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $prefix = SITE_BASE . '/api/v1';
        $path   = substr($uri, strlen($prefix));
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            $this->route($method, $path);
        } catch (Throwable $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function route(string $method, string $path): void
    {

        if ($method === 'GET' && preg_match('#^/posts$#', $path)) {
            $this->getPosts();
        }

        elseif ($method === 'GET' && preg_match('#^/posts/(\d+)$#', $path, $m)) {
            $this->getPost((int)$m[1]);
        }

        elseif ($method === 'POST' && preg_match('#^/posts$#', $path)) {
            $this->createPost();
        }

        elseif ($method === 'PUT' && preg_match('#^/posts/(\d+)$#', $path, $m)) {
            $this->updatePost((int)$m[1]);
        }

        elseif ($method === 'DELETE' && preg_match('#^/posts/(\d+)$#', $path, $m)) {
            $this->deletePost((int)$m[1]);
        }

        elseif ($method === 'GET' && preg_match('#^/tags/autocomplete$#', $path)) {
            $this->tagsAutocomplete();
        }

        elseif ($method === 'GET' && preg_match('#^/tags$#', $path)) {
            $this->getTags();
        }

        elseif ($method === 'GET' && preg_match('#^/comments/(\d+)$#', $path, $m)) {
            $this->getComments((int)$m[1]);
        }

        elseif ($method === 'POST' && preg_match('#^/comments/(\d+)$#', $path, $m)) {
            $this->addComment((int)$m[1]);
        }

        elseif ($method === 'DELETE' && preg_match('#^/comments/(\d+)$#', $path, $m)) {
            $this->deleteComment((int)$m[1]);
        }

        elseif ($method === 'POST' && preg_match('#^/votes/(\d+)$#', $path, $m)) {
            $this->vote((int)$m[1]);
        }

        elseif ($method === 'GET' && preg_match('#^/users/me$#', $path)) {
            $this->me();
        }
        else {
            throw new RuntimeException('Not found', 404);
        }
    }



    private function getPosts(): void
    {
        $this->requirePostReadAccess();
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = min(100, max(1, (int)($_GET['limit'] ?? POSTS_PER_PAGE)));
        $tags    = array_filter(preg_split('/[\s,]+/', trim($_GET['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
        $rating  = $_GET['rating'] ?? '';
        $quality = $_GET['quality'] ?? '';

        $result = Post::list($page, $limit, $tags, $rating, 'id DESC', $quality);
        echo json_encode([
            'posts'   => $result['posts'],
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    private function getPost(int $id): void
    {
        $this->requirePostReadAccess();
        $post = Post::getById($id);
        if (!$post) throw new RuntimeException('Post not found', 404);

        $user = Auth::current();
        if ($user && !empty($user['blacklist'])) {
            $blacklisted = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
            $postTagNames = array_map(fn($t) => strtolower($t['name']), $post['tags']);
            if (array_intersect($blacklisted, $postTagNames)) {
                throw new RuntimeException('Post not found', 404);
            }
        }

        $post['comments'] = Post::commentsFor($id);
        echo json_encode($post);
    }

    private function createPost(): void
    {
        $this->requireAuth();
        if (empty($_FILES['file'])) {
            throw new RuntimeException('No file uploaded', 400);
        }
        $meta = [
            'tags'         => $_POST['tags']         ?? '',
            'rating'       => $_POST['rating']       ?? 'q',
            'source'       => $_POST['source']       ?? '',
            'title'        => $_POST['title']        ?? '',
            'content_type' => $_POST['content_type'] ?? null,
        ];
        $id = Post::upload($_FILES['file'], $meta);
        http_response_code(201);
        echo json_encode(['id' => $id]);
    }

    private function updatePost(int $id): void
    {
        $this->requireAuth();
        $post = DB::row('SELECT * FROM posts WHERE id = ?', [$id]);
        if (!$post) throw new RuntimeException('Post not found', 404);
        if ($post['user_id'] !== $this->authUser['id'] && !Auth::can('moderate_posts', $this->authUser)) {
            throw new RuntimeException('Forbidden', 403);
        }
        $body = $this->jsonBody();
        Post::update($id, $body);
        echo json_encode(['ok' => true]);
    }

    private function deletePost(int $id): void
    {
        $this->requireAuth();
        $post = DB::row('SELECT * FROM posts WHERE id = ?', [$id]);
        if (!$post) throw new RuntimeException('Post not found', 404);
        if ($post['user_id'] !== $this->authUser['id'] && !Auth::can('moderate_posts', $this->authUser)) {
            throw new RuntimeException('Forbidden', 403);
        }
        Post::delete($id);
        echo json_encode(['ok' => true]);
    }



    private function getTags(): void
    {
        $this->requirePostReadAccess();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
        $q      = trim($_GET['q'] ?? '');
        $offset = ($page - 1) * $limit;

        $user = Auth::current();
        $blacklisted = [];
        if ($user && !empty($user['blacklist'])) {
            $blacklisted = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
        }
        $notInSql = '';
        $notInParams = [];
        if ($blacklisted) {
            $notInPlaceholders = implode(',', array_fill(0, count($blacklisted), '?'));
            $notInSql = " AND name NOT IN ($notInPlaceholders) COLLATE NOCASE";
            $notInParams = $blacklisted;
        }

        if ($q !== '') {
            $tags  = DB::rows('SELECT name, count FROM tags WHERE name LIKE ?' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge(['%' . $q . '%'], $notInParams, [$limit, $offset]));
            $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE name LIKE ?' . $notInSql, array_merge(['%' . $q . '%'], $notInParams));
        } else {
            $tags  = DB::rows('SELECT name, count FROM tags WHERE 1=1' . $notInSql . ' ORDER BY count DESC LIMIT ? OFFSET ?', array_merge($notInParams, [$limit, $offset]));
            $total = (int)DB::scalar('SELECT COUNT(*) FROM tags WHERE 1=1' . $notInSql, $notInParams);
        }
        echo json_encode(['tags' => $tags, 'total' => $total]);
    }

    private function tagsAutocomplete(): void
    {
        $this->requirePostReadAccess();

        $q     = trim($_GET['q'] ?? '');
        $limit = min(10, max(1, (int)($_GET['limit'] ?? 8)));

        if ($q === '') {
            echo json_encode([]);
            return;
        }

        $user = Auth::current();
        $blacklisted = [];
        if ($user && !empty($user['blacklist'])) {
            $blacklisted = preg_split('/[\s,]+/', strtolower(trim($user['blacklist'])), -1, PREG_SPLIT_NO_EMPTY);
        }

        $notInSql = '';
        $notInParams = [];
        if ($blacklisted) {
            $notInPlaceholders = implode(',', array_fill(0, count($blacklisted), '?'));
            $notInSql = " AND name NOT IN ($notInPlaceholders) COLLATE NOCASE";
            $notInParams = $blacklisted;
        }


        $sql1 = 'SELECT name, count FROM tags
                 WHERE name LIKE ?' . $notInSql . '
                 ORDER BY (CASE WHEN name LIKE ? THEN 0 ELSE 1 END), count DESC
                 LIMIT ?';
        $params1 = array_merge([$q . '%'], $notInParams, [$q . '%', $limit]);
        $tags = DB::rows($sql1, $params1);


        if (count($tags) < $limit) {
            $found = array_column($tags, 'name');
            $sql2 = 'SELECT name, count FROM tags
                     WHERE name LIKE ? AND name NOT LIKE ?' . $notInSql . '
                     ORDER BY count DESC
                     LIMIT ?';
            $params2 = array_merge(['%' . $q . '%', $q . '%'], $notInParams, [$limit - count($tags)]);
            $extra = DB::rows($sql2, $params2);
            $tags = array_merge($tags, $extra);
        }

        echo json_encode($tags);
    }



    private function getComments(int $postId): void
    {
        $this->requirePostReadAccess();
        $comments = Post::commentsFor($postId);
        echo json_encode(['comments' => $comments]);
    }

    private function addComment(int $postId): void
    {
        $this->requireAuth();
        if (!DB::consumeRateLimit('comment', Auth::requestSubject(), COMMENT_RATE_LIMIT, COMMENT_RATE_WINDOW)) {
            throw new RuntimeException('Too many comments. Please try again later.', 429);
        }
        $body = $this->jsonBody();
        $text = trim($body['body'] ?? '');
        if ($text === '') throw new RuntimeException('Body is required', 400);
        $userId    = $this->authUser ? (int)$this->authUser['id'] : null;
        $id = Post::addComment($postId, $text, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id]);
    }

    private function deleteComment(int $id): void
    {
        $this->requirePermission('moderate_comments');
        Post::deleteComment($id);
        echo json_encode(['ok' => true]);
    }



    private function vote(int $postId): void
    {
        $this->requireAuth();
        $body  = $this->jsonBody();
        $value = (int)($body['value'] ?? 1);
        Post::vote($postId, (int)$this->authUser['id'], $value);
        $score = (int)DB::scalar('SELECT score FROM posts WHERE id = ?', [$postId]);
        echo json_encode(['score' => $score]);
    }



    private function me(): void
    {
        $this->requireAuth();
        $u = $this->authUser;
        echo json_encode([
            'id'         => (int)$u['id'],
            'name'       => $u['name'],
            'email'      => $u['email'],
            'role'       => $u['role'],
            'api_key'    => $u['api_key'],
            'created_at' => (int)$u['created_at'],
        ]);
    }



    private function requirePostReadAccess(): void
    {
        if (View::siteSetting('require_login_posts', '0') === '1' && !$this->authUser) {
            throw new RuntimeException('Unauthorized', 401);
        }
    }

    private function requireAuth(): void
    {
        if (!$this->authUser) throw new RuntimeException('Unauthorized', 401);
    }

    private function requirePermission(string $permission): void
    {
        $this->requireAuth();
        if (!Auth::can($permission, $this->authUser)) throw new RuntimeException('Forbidden', 403);
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
