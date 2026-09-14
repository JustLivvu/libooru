<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

class Auth
{
    private static ?array $user = null;
    private static array $permissionCache = [];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionDir = DATA_DIR . '/sessions';
            if (!is_dir($sessionDir)) {
                @mkdir($sessionDir, 0755, true);
            }
            session_save_path($sessionDir);
            session_name('libooru_session');
            session_set_cookie_params([
                'lifetime' => 86400 * 30,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            self::$user = DB::row('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']]);
        }
    }

    public static function current(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user ? (int)self::$user['id'] : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::$user !== null;
    }

    public static function isAdmin(): bool
    {
        return self::$user && self::$user['role'] === 'admin';
    }

    public static function permissionCatalog(): array
    {
        return [
            'access_admin_panel' => 'Access the admin panel and statistics',
            'manage_site_settings' => 'Manage site settings and branding',
            'manage_storage_settings' => 'Manage media storage settings',
            'manage_registration_settings' => 'Manage registration and content settings',
            'manage_registration_requests' => 'Approve and decline registration requests',
            'manage_users' => 'Delete users and regenerate API keys',
            'manage_roles' => 'Create roles and assign roles to users',
            'manage_scraper' => 'Access and manage scrapers',
            'manage_post_reports' => 'Review and resolve post reports',
            'manage_database_backups' => 'Configure, create, and restore database backups',
            'moderate_posts' => 'Edit and delete any user post',
            'moderate_comments' => 'Delete comments',
        ];
    }

    public static function can(string $permission, ?array $user = null): bool
    {
        $user ??= self::$user;
        if (!$user) return false;

        $role = (string)($user['role'] ?? 'user');
        if ($role === 'admin') return true;

        if (!array_key_exists($role, self::$permissionCache)) {
            $encoded = DB::scalar('SELECT permissions FROM roles WHERE slug = ?', [$role]);
            $decoded = is_string($encoded) ? json_decode($encoded, true) : [];
            self::$permissionCache[$role] = is_array($decoded) ? $decoded : [];
        }

        return in_array('*', self::$permissionCache[$role], true)
            || in_array($permission, self::$permissionCache[$role], true);
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::can($permission)) {
            Router::redirect('/');
        }
    }

    public static function require(): void
    {
        if (!self::isLoggedIn()) {
            Router::redirect('/login');
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            Router::redirect('/');
        }
    }

    public static function login(string $name, string $password): bool
    {
        $user = DB::row('SELECT * FROM users WHERE name = ?', [$name]);
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            self::$user = $user;
            return true;
        }
        return false;
    }

    /** Stable, non-reversible rate-limit key based on the direct peer address. */
    public static function requestSubject(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', $ip);
    }

    public static function verifyTurnstile(string $token, string $secret): bool
    {
        if ($token === '' || $secret === '' || strlen($token) > 2048) return false;

        $data = ['secret' => $secret, 'response' => $token];
        $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteIp !== '') $data['remoteip'] = $remoteIp;

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if ($body === false) return false;

        $result = json_decode($body, true);
        return is_array($result)
            && ($result['success'] ?? false) === true
            && (!isset($result['action']) || $result['action'] === 'register');
    }

    public static function hasPendingRegistration(string $name): bool
    {
        return (bool)DB::scalar('SELECT id FROM registration_requests WHERE name = ?', [$name]);
    }

    public static function logout(): void
    {
        session_destroy();
        self::$user = null;
    }

    public static function register(string $name, string $password, string $email = '', string $registrationReason = ''): int|false
    {
        if (strlen($name) < 2 || strlen($name) > 32) return false;
        if (strlen($password) < 4) return false;
        if (class_exists('View') && View::siteSetting('require_registration_reason', '0') === '1' && trim($registrationReason) === '') return false;
        $existing = DB::scalar('SELECT id FROM users WHERE name = ?', [$name]);
        if ($existing) return false;
        $hash   = password_hash($password, PASSWORD_DEFAULT);
        $apikey = bin2hex(random_bytes(16));
        $defaultBlacklist = class_exists('View') ? View::siteSetting('default_blacklist', '') : (string)(DB::scalar("SELECT value FROM site_settings WHERE key = 'default_blacklist'") ?: '');
        DB::exec(
            'INSERT INTO users (name, password, email, api_key, blacklist, registration_reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $hash, $email, $apikey, $defaultBlacklist, trim($registrationReason)]
        );
        return (int)DB::lastId();
    }

    /** Save a registration for an administrator to approve later. */
    public static function requestRegistration(string $name, string $password, string $email = '', string $registrationReason = ''): int|false
    {
        if (strlen($name) < 2 || strlen($name) > 32 || strlen($password) < 4) return false;
        if (class_exists('View') && View::siteSetting('require_registration_reason', '0') === '1' && trim($registrationReason) === '') return false;
        if (DB::scalar('SELECT id FROM users WHERE name = ?', [$name])) return false;
        if (DB::scalar('SELECT id FROM registration_requests WHERE name = ?', [$name])) return false;

        DB::exec(
            'INSERT INTO registration_requests (name, password, email, registration_reason) VALUES (?, ?, ?, ?)',
            [$name, password_hash($password, PASSWORD_DEFAULT), $email, trim($registrationReason)]
        );
        return (int)DB::lastId();
    }

    public static function approveRegistrationRequest(int $requestId): bool
    {
        $request = DB::row('SELECT * FROM registration_requests WHERE id = ?', [$requestId]);
        if (!$request || DB::scalar('SELECT id FROM users WHERE name = ?', [$request['name']])) return false;

        $apiKey = bin2hex(random_bytes(16));
        $defaultBlacklist = class_exists('View') ? View::siteSetting('default_blacklist', '') : '';
        DB::exec(
            'INSERT INTO users (name, password, email, api_key, blacklist, registration_reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$request['name'], $request['password'], $request['email'], $apiKey, $defaultBlacklist, $request['registration_reason']]
        );
        DB::exec('DELETE FROM registration_requests WHERE id = ?', [$requestId]);
        return true;
    }

    public static function declineRegistrationRequest(int $requestId): bool
    {
        return DB::exec('DELETE FROM registration_requests WHERE id = ?', [$requestId]) > 0;
    }

    /** Authenticate via API key (from header or query param). */
    public static function fromApiKey(string $key): ?array
    {
        return DB::row('SELECT * FROM users WHERE api_key = ?', [$key]) ?: null;
    }

    /** Inject a user directly (used by API after key auth). */
    public static function setUser(array $user): void
    {
        self::$user = $user;
    }
}
