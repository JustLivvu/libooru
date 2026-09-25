<?php
declare(strict_types=1);

function renderActivityStatistics(bool $browsing = false): void
{
    $activity = $browsing ? Activity::browsingStatistics() : Activity::statistics();
    $unit = $browsing ? 'unique IP addresses' : 'unique users';
    $graphId = $browsing ? 'browsing' : 'login';
    $description = $browsing ? 'Unique IP addresses browsing the post list or individual posts' : 'Unique users with successful logins';
    echo '<h3>' . ($browsing ? 'Post browsing activity' : 'Login activity') . '</h3><div class="activity-counts">';
    foreach (['today' => 'Today', 'last_12h' => 'Last 12 hours', 'last_6h' => 'Last 6 hours', 'last_1h' => 'Last hour'] as $key => $label) {
        echo '<div class="activity-count"><span>' . $label . '</span><strong>' . $activity['counts'][$key] . '</strong><small>' . $unit . '</small></div>';
    }
    echo '</div><h3>Activity graph</h3>';
    echo '<p class="activity-note">' . $description . ' in each hour of the last 24 hours. Today starts at midnight in ' . View::e(ACTIVITY_TIMEZONE) . '. Recording starts when this feature is enabled.</p>';
    $max = max(1, max(array_column($activity['hours'], 'users')));
    echo '<div class="activity-chart"><svg viewBox="0 0 960 240" role="img" aria-labelledby="' . $graphId . '-graph-title ' . $graphId . '-graph-description">';
    echo '<title id="' . $graphId . '-graph-title">' . $description . ' during the last 24 hours</title><desc id="' . $graphId . '-graph-description">Exact hourly counts and times are available in the table below.</desc>';
    echo '<line x1="40" y1="200" x2="952" y2="200" class="activity-grid" />';
    echo '<line x1="40" y1="30" x2="952" y2="30" class="activity-grid" />';
    echo '<text x="30" y="204" text-anchor="end">0</text><text x="30" y="34" text-anchor="end">' . $max . '</text>';
    foreach ($activity['hours'] as $i => $hour) {
        $x = 44 + $i * 38;
        $height = round($hour['users'] / $max * 170, 2);
        echo '<rect class="activity-bar" x="' . $x . '" y="' . (200 - $height) . '" width="28" height="' . $height . '" rx="3"><title>' . View::e($hour['label']) . ': ' . $hour['users'] . ' ' . $unit . '</title></rect>';
        if ($hour['users'] > 0) echo '<text x="' . ($x + 14) . '" y="' . (192 - $height) . '" text-anchor="middle">' . $hour['users'] . '</text>';
    }
    echo '<text x="44" y="228">24 hours ago</text><text x="500" y="228" text-anchor="middle">12 hours ago</text><text x="952" y="228" text-anchor="end">Now</text></svg></div>';
    if (array_sum(array_column($activity['hours'], 'users')) === 0) echo '<p class="activity-note">' . ($browsing ? 'No post browsing' : 'No successful logins') . ' in the last 24 hours.</p>';
    echo '<details class="activity-hourly"><summary>Hourly details</summary><div style="overflow-x:auto"><table><thead><tr><th>Time (' . View::e(ACTIVITY_TIMEZONE) . ')</th><th>' . ucfirst($unit) . '</th></tr></thead><tbody>';
    foreach ($activity['hours'] as $hour) echo '<tr><td>' . View::e($hour['label']) . '</td><td>' . $hour['users'] . '</td></tr>';
    echo '</tbody></table></div></details>';
}

function page_admin(?array $user, string $method): void
{
    if (!Auth::can('access_admin_panel', $user) && !Auth::can('manage_post_reports', $user)
        && !Auth::can('manage_database_backups', $user) && !Auth::can('manage_scraper', $user)) {
        Router::redirect('/');
    }
    $permissionCatalog = Auth::permissionCatalog();

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';
        $permissionByAction = [
            'delete_user' => 'manage_users',
            'regen_api' => 'manage_users',
            'reset_password' => 'manage_users',
            'set_role' => 'manage_roles',
            'create_role' => 'manage_roles',
            'update_role' => 'manage_roles',
            'delete_role' => 'manage_roles',
            'site_settings' => 'manage_site_settings',
            'global_tag_blacklist' => 'manage_site_settings',
            'webhook_settings' => 'manage_site_settings',
            'test_discord_webhook' => 'manage_site_settings',
            'storage_settings' => 'manage_storage_settings',
            'registrations_settings' => 'manage_registration_settings',
            'approve_registration_request' => 'manage_registration_requests',
            'decline_registration_request' => 'manage_registration_requests',
            'resolve_post_report' => 'manage_post_reports',
            'dismiss_post_report' => 'manage_post_reports',
            'backup_settings' => 'manage_database_backups',
            'create_database_backup' => 'manage_database_backups',
            'restore_database_backup' => 'manage_database_backups',
        ];
        if (isset($permissionByAction[$action]) && !Auth::can($permissionByAction[$action], $user)) {
            View::setFlash('You do not have permission to perform this action.', 'error');
            Router::redirect('/admin');
        }

        if ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['id']) {
                DB::exec('DELETE FROM users WHERE id = ?', [$uid]);
                View::setFlash('User deleted.', 'ok');
            }
        } elseif ($action === 'set_role') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = trim((string)($_POST['role'] ?? ''));
            $roleExists = (bool)DB::scalar('SELECT id FROM roles WHERE slug = ?', [$role]);
            if ($uid === (int)$user['id'] && $role !== $user['role']) {
                View::setFlash('You cannot change your own role.', 'error');
            } elseif (!$uid || !$roleExists) {
                View::setFlash('Invalid user or role.', 'error');
            } else {
                DB::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $uid]);
                View::setFlash('Role updated.', 'ok');
            }
        } elseif ($action === 'create_role') {
            $name = trim((string)($_POST['role_name'] ?? ''));
            $permissions = array_values(array_intersect(array_keys($permissionCatalog), array_map('strval', (array)($_POST['permissions'] ?? []))));
            $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
            if (strlen($name) < 2 || strlen($name) > 40) {
                View::setFlash('Role name must contain between 2 and 40 characters.', 'error');
            } else {
                if ($slug === '') $slug = 'role-' . bin2hex(random_bytes(4));
                try {
                    DB::exec(
                        'INSERT INTO roles (name, slug, permissions) VALUES (?, ?, ?)',
                        [$name, $slug, json_encode($permissions, JSON_THROW_ON_ERROR)]
                    );
                    View::setFlash('Role created.', 'ok');
                } catch (PDOException $e) {
                    View::setFlash('A role with this name already exists.', 'error');
                }
            }
        } elseif ($action === 'update_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $name = trim((string)($_POST['role_name'] ?? ''));
            $permissions = array_values(array_intersect(array_keys($permissionCatalog), array_map('strval', (array)($_POST['permissions'] ?? []))));
            $role = DB::row('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role || (int)$role['is_system'] === 1) {
                View::setFlash('System roles cannot be changed.', 'error');
            } elseif ($role['slug'] === $user['role']) {
                View::setFlash('You cannot change the role currently assigned to your account.', 'error');
            } elseif (strlen($name) < 2 || strlen($name) > 40) {
                View::setFlash('Role name must contain between 2 and 40 characters.', 'error');
            } else {
                try {
                    DB::exec('UPDATE roles SET name = ?, permissions = ? WHERE id = ?', [
                        $name,
                        json_encode($permissions, JSON_THROW_ON_ERROR),
                        $roleId,
                    ]);
                    View::setFlash('Role updated.', 'ok');
                } catch (PDOException $e) {
                    View::setFlash('A role with this name already exists.', 'error');
                }
            }
        } elseif ($action === 'delete_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $role = DB::row('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role || (int)$role['is_system'] === 1) {
                View::setFlash('System roles cannot be deleted.', 'error');
            } elseif ($role['slug'] === $user['role']) {
                View::setFlash('You cannot delete the role currently assigned to your account.', 'error');
            } else {
                $pdo = DB::get();
                $pdo->beginTransaction();
                try {
                    DB::exec("UPDATE users SET role = 'user' WHERE role = ?", [$role['slug']]);
                    DB::exec('DELETE FROM roles WHERE id = ?', [$roleId]);
                    $pdo->commit();
                    View::setFlash('Role deleted. Its users were moved to the User role.', 'ok');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    View::setFlash('Could not delete the role.', 'error');
                }
            }
        } elseif ($action === 'regen_api') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $key = bin2hex(random_bytes(16));
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', [$key, $uid]);
            View::setFlash('API key regenerated.', 'ok');
        } elseif ($action === 'reset_password') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $target = $uid > 0 ? DB::row('SELECT id, name FROM users WHERE id = ?', [$uid]) : null;
            $temporaryPassword = $target ? Auth::resetPassword($uid) : null;
            if ($target && $temporaryPassword !== null) {
                $_SESSION['admin_password_reset'] = [
                    'username' => (string)$target['name'],
                    'password' => $temporaryPassword,
                ];
                View::setFlash('Password reset. Copy the temporary password shown below.', 'ok');
            } else {
                View::setFlash('User not found. Password was not changed.', 'error');
            }
        } elseif (in_array($action, ['webhook_settings', 'test_discord_webhook'], true)) {
            $submittedUrl = trim((string)($_POST['discord_webhook_url'] ?? ''));
            $clearWebhook = isset($_POST['clear_discord_webhook']);
            $enabled = isset($_POST['discord_webhook_enabled']) ? '1' : '0';
            $savedUrl = View::siteSetting('discord_webhook_url', '');

            if ($clearWebhook) {
                View::setSiteSetting('discord_webhook_url', '');
                View::setSiteSetting('discord_webhook_enabled', '0');
                View::setFlash('Discord webhook removed.', 'ok');
            } elseif ($submittedUrl !== '' && !DiscordWebhook::isValidUrl($submittedUrl)) {
                View::setFlash('Enter a valid Discord webhook URL.', 'error');
            } else {
                if ($submittedUrl !== '') {
                    View::setSiteSetting('discord_webhook_url', $submittedUrl);
                    $savedUrl = $submittedUrl;
                }
                if ($enabled === '1' && !DiscordWebhook::isValidUrl($savedUrl)) {
                    View::setFlash('Add a valid Discord webhook URL before enabling notifications.', 'error');
                } else {
                    View::setSiteSetting('discord_webhook_enabled', $enabled);
                    View::setSiteSetting('webhook_site_url', rtrim(View::absoluteUrl(View::url('/')), '/'));
                    if ($action === 'test_discord_webhook') {
                        $testSent = DiscordWebhook::sendTest();
                        View::setFlash(
                            $testSent ? 'Test message sent to Discord.' : 'Discord rejected the test message.',
                            $testSent ? 'ok' : 'error'
                        );
                    } else {
                        View::setFlash('Webhook settings saved.', 'ok');
                    }
                }
            }
        } elseif ($action === 'global_tag_blacklist') {
            try {
                Post::saveGlobalBlockedRules((string)($_POST['global_tag_blacklist'] ?? ''));
                $removed = isset($_POST['purge_matching_posts']) ? Post::purgeGloballyBlockedPosts() : 0;
                View::setFlash(
                    'Global tag rules saved.' . ($removed > 0 ? " Removed {$removed} matching post(s)." : ''),
                    'ok'
                );
            } catch (Throwable $e) {
                View::setFlash('Could not save global tag rules: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'site_settings') {
            $discordUrl = trim((string)($_POST['discord_url'] ?? ''));
            if ($discordUrl !== '' && (strlen($discordUrl) > 2048 || !filter_var($discordUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($discordUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                View::setFlash('Discord URL must be a valid HTTP or HTTPS address.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            $customThemeCss = (string)($_POST['custom_theme_css'] ?? '');
            if (strlen($customThemeCss) > 100000) {
                View::setFlash('Custom theme CSS cannot exceed 100 KB.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            $customThemeName = trim((string)($_POST['custom_theme_name'] ?? ''));
            if ($customThemeName === '') $customThemeName = 'Custom';
            if (strlen($customThemeName) > 40) {
                View::setFlash('Custom theme name cannot exceed 40 characters.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            $apiRateLimitInput = trim((string)($_POST['api_rate_limit'] ?? ''));
            if (!ctype_digit($apiRateLimitInput) || (int)$apiRateLimitInput < 1 || (int)$apiRateLimitInput > 100000) {
                View::setFlash('API rate limit must be between 1 and 100,000 requests per minute.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            $apiEnabled = isset($_POST['api_enabled']) ? '1' : '0';
            $apiRateLimit = (string)(int)$apiRateLimitInput;
            $footerText = trim((string)($_POST['footer_text'] ?? ''));
            $footerLinks = trim((string)($_POST['footer_links'] ?? ''));
            if (strlen($footerText) > 1000 || strlen($footerLinks) > 5000) {
                View::setFlash('Footer text or link list is too long.', 'error');
                Router::redirect('/admin', ['open' => 'site-settings']);
            }
            foreach (preg_split('/\R/', $footerLinks) ?: [] as $footerLinkLine) {
                if (trim($footerLinkLine) === '') continue;
                $footerLinkParts = array_map('trim', explode('|', $footerLinkLine, 2));
                $footerLinkLabel = $footerLinkParts[0] ?? '';
                $footerLinkUrl = $footerLinkParts[1] ?? '';
                $validFooterUrl = str_starts_with($footerLinkUrl, '/')
                    || (filter_var($footerLinkUrl, FILTER_VALIDATE_URL)
                        && in_array(strtolower((string)parse_url($footerLinkUrl, PHP_URL_SCHEME)), ['http', 'https'], true));
                if (count($footerLinkParts) !== 2 || $footerLinkLabel === '' || strlen($footerLinkLabel) > 80
                    || $footerLinkUrl === '' || strlen($footerLinkUrl) > 2048 || !$validFooterUrl) {
                    View::setFlash('Each footer link must use the format Label | /path or Label | https://example.com.', 'error');
                    Router::redirect('/admin', ['open' => 'site-settings']);
                }
            }
            $availableThemes = ['dark', 'light', 'catppuccin', 'blue'];
            if (trim($customThemeCss) !== '') $availableThemes[] = 'custom';
            $defaultTheme = (string)($_POST['default_theme'] ?? 'dark');
            if (!in_array($defaultTheme, $availableThemes, true)) $defaultTheme = 'dark';
            $name    = trim($_POST['site_name'] ?? '');
            $description = trim($_POST['site_description'] ?? '');
            $default = trim($_POST['default_blacklist'] ?? '');
            $terms   = trim($_POST['terms_of_service'] ?? '');
            if ($name !== '') View::setSiteSetting('site_name', $name);
            View::setSiteSetting('site_description', $description);
            View::setSiteSetting('discord_url', $discordUrl);
            View::setSiteSetting('default_theme', $defaultTheme);
            View::setSiteSetting('custom_theme_name', $customThemeName);
            View::setSiteSetting('custom_theme_css', $customThemeCss);
            View::setSiteSetting('api_enabled', $apiEnabled);
            View::setSiteSetting('api_rate_limit', $apiRateLimit);
            View::setSiteSetting('footer_text', $footerText);
            View::setSiteSetting('footer_links', $footerLinks);
            foreach (['site_logo_upload' => 'site_logo', 'site_banner_upload' => 'site_banner', 'home_header_upload' => 'home_header_image'] as $field => $setting) {
                $oldImage = View::siteSetting($setting);
                if (isset($_POST['clear_' . $setting])) {
                    View::setSiteSetting($setting, '');
                    deleteLocalSiteImage($oldImage);
                } else {
                    $newImage = saveSiteImageUpload($field);
                    if ($newImage !== null) {
                        View::setSiteSetting($setting, $newImage);
                        deleteLocalSiteImage($oldImage);
                    }
                }
            }
            View::setSiteSetting('default_blacklist', $default);
            View::setSiteSetting('terms_of_service', $terms);
            View::setFlash('Site settings saved.', 'ok');
        } elseif ($action === 'storage_settings') {
            $driver    = in_array($_POST['storage_driver'] ?? '', ['local', 's3'], true) ? $_POST['storage_driver'] : 'local';
            $endpoint  = trim($_POST['s3_endpoint'] ?? '');
            $region    = trim($_POST['s3_region'] ?? 'us-east-1');
            $bucket    = trim($_POST['s3_bucket'] ?? '');
            $accessKey = trim($_POST['s3_access_key'] ?? '');
            $secretKey = trim($_POST['s3_secret_key'] ?? '');

            View::setSiteSetting('storage_driver', $driver);
            View::setSiteSetting('s3_endpoint',   $endpoint);
            View::setSiteSetting('s3_region',     $region);
            View::setSiteSetting('s3_bucket',     $bucket);
            View::setSiteSetting('s3_access_key', $accessKey);
            View::setSiteSetting('s3_secret_key', $secretKey);
            View::setFlash('Storage settings saved.', 'ok');
        } elseif ($action === 'backup_settings') {
            $endpoint = trim((string)($_POST['backup_s3_endpoint'] ?? ''));
            $region = trim((string)($_POST['backup_s3_region'] ?? 'us-east-1'));
            $bucket = trim((string)($_POST['backup_s3_bucket'] ?? ''));
            $accessKey = trim((string)($_POST['backup_s3_access_key'] ?? ''));
            $secretKey = trim((string)($_POST['backup_s3_secret_key'] ?? ''));
            View::setSiteSetting('backup_s3_endpoint', $endpoint);
            View::setSiteSetting('backup_s3_region', $region ?: 'us-east-1');
            View::setSiteSetting('backup_s3_bucket', $bucket);
            View::setSiteSetting('backup_s3_access_key', $accessKey);
            if ($secretKey !== '') View::setSiteSetting('backup_s3_secret_key', $secretKey);
            View::setFlash('Database backup settings saved.', 'ok');
        } elseif ($action === 'create_database_backup') {
            try {
                $backup = DatabaseBackup::create();
                View::setFlash('Database backup created: ' . $backup['key'], 'ok');
            } catch (Throwable $e) {
                View::setFlash('Could not create database backup: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'restore_database_backup') {
            try {
                $result = DatabaseBackup::restore((string)($_POST['backup_key'] ?? ''));
                View::setFlash(
                    'Database restored. A pre-rollback S3 backup and local emergency copy were created at '
                    . $result['local_emergency_path'] . '.',
                    'ok'
                );
            } catch (Throwable $e) {
                View::setFlash('Could not restore database backup: ' . $e->getMessage(), 'error');
            }
        } elseif ($action === 'registrations_settings') {
            $disableReg = isset($_POST['disable_registrations']) ? '1' : '0';
            $requireRegistrationReason = isset($_POST['require_registration_reason']) ? '1' : '0';
            $registrationRequiresApproval = isset($_POST['registration_requires_approval']) ? '1' : '0';
            $requireLogin = isset($_POST['require_login_posts']) ? '1' : '0';
            $enableAdultWarning = isset($_POST['enable_adult_warning']) ? '1' : '0';
            $showUserFavorites = isset($_POST['show_user_favorites']) ? '1' : '0';
            $enableRegistrationCaptcha = isset($_POST['enable_registration_captcha']) ? '1' : '0';
            $turnstileSiteKey = trim($_POST['turnstile_site_key'] ?? '');
            $turnstileSecretKey = trim($_POST['turnstile_secret_key'] ?? '');
            $savedTurnstileSecretKey = $turnstileSecretKey !== ''
                ? $turnstileSecretKey
                : View::siteSetting('turnstile_secret_key', '');
            View::setSiteSetting('disable_registrations', $disableReg);
            View::setSiteSetting('require_registration_reason', $requireRegistrationReason);
            View::setSiteSetting('registration_requires_approval', $registrationRequiresApproval);
            View::setSiteSetting('require_login_posts', $requireLogin);
            View::setSiteSetting('enable_adult_warning', $enableAdultWarning);
            View::setSiteSetting('show_user_favorites', $showUserFavorites);
            View::setSiteSetting('turnstile_site_key', $turnstileSiteKey);
            if ($turnstileSecretKey !== '') View::setSiteSetting('turnstile_secret_key', $turnstileSecretKey);
            if ($enableRegistrationCaptcha === '1' && ($turnstileSiteKey === '' || $savedTurnstileSecretKey === '')) {
                View::setSiteSetting('enable_registration_captcha', '0');
                View::setFlash('Registration CAPTCHA was not enabled. Enter both Turnstile keys.', 'error');
            } else {
                View::setSiteSetting('enable_registration_captcha', $enableRegistrationCaptcha);
                View::setFlash('Registrations & Content settings saved.', 'ok');
            }
        } elseif (in_array($action, ['resolve_post_report', 'dismiss_post_report'], true)) {
            $reportId = (int)($_POST['report_id'] ?? 0);
            $status = $action === 'resolve_post_report' ? 'resolved' : 'dismissed';
            $updated = $reportId > 0 ? DB::exec(
                "UPDATE post_reports SET status = ?, resolved_by = ?, resolved_at = unixepoch()
                 WHERE id = ? AND status = 'pending'",
                [$status, (int)$user['id'], $reportId]
            ) : 0;
            View::setFlash($updated ? 'Post report ' . $status . '.' : 'Report was not found or was already reviewed.', $updated ? 'ok' : 'error');
        } elseif ($action === 'approve_registration_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $approved = Auth::approveRegistrationRequest($requestId);
            View::setFlash($approved ? 'Registration request approved.' : 'Could not approve this registration request.', $approved ? 'ok' : 'error');
        } elseif ($action === 'decline_registration_request') {
            $requestId = (int)($_POST['request_id'] ?? 0);
            View::setFlash(Auth::declineRegistrationRequest($requestId) ? 'Registration request declined.' : 'Registration request was not found.', 'ok');
        }

        $sectionByAction = [
            'site_settings' => 'site-settings',
            'global_tag_blacklist' => 'banned-tags',
            'webhook_settings' => 'webhooks',
            'test_discord_webhook' => 'webhooks',
            'storage_settings' => 'media-storage',
            'registrations_settings' => 'registrations-content',
            'resolve_post_report' => 'post-reports',
            'dismiss_post_report' => 'post-reports',
            'backup_settings' => 'database-backups',
            'create_database_backup' => 'database-backups',
            'restore_database_backup' => 'database-backups',
            'approve_registration_request' => 'registration-requests',
            'decline_registration_request' => 'registration-requests',
            'delete_user' => 'users',
            'set_role' => 'users',
            'regen_api' => 'users',
            'reset_password' => 'users',
            'create_role' => 'roles',
            'update_role' => 'roles',
            'delete_role' => 'roles',
        ];
        $redirectParams = isset($sectionByAction[$action]) ? ['open' => $sectionByAction[$action]] : [];
        Router::redirect('/admin', $redirectParams);
    }

    $openSection = $_GET['open'] ?? '';
    $roles = DB::rows(
        'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) AS user_count
         FROM roles r ORDER BY r.is_system DESC, r.name COLLATE NOCASE'
    );
    $roleNames = array_column($roles, 'name', 'slug');
    $users        = DB::rows('SELECT id, name, email, registration_reason, role, api_key, created_at, country_code FROM users ORDER BY id DESC');
    $passwordResetResult = is_array($_SESSION['admin_password_reset'] ?? null)
        ? $_SESSION['admin_password_reset']
        : null;
    unset($_SESSION['admin_password_reset']);
    $registrationRequests = DB::rows('SELECT id, name, email, registration_reason, created_at FROM registration_requests ORDER BY created_at ASC');
    $postReports = [];
    $pendingPostReportCount = 0;
    if (Auth::can('manage_post_reports', $user)) {
        $postReports = DB::rows(
            "SELECT pr.*, reporter.name AS reporter_name, resolver.name AS resolver_name
             FROM post_reports pr
             LEFT JOIN users reporter ON reporter.id = pr.reporter_user_id
             LEFT JOIN users resolver ON resolver.id = pr.resolved_by
             ORDER BY CASE pr.status WHEN 'pending' THEN 0 ELSE 1 END, pr.created_at DESC"
        );
        $pendingPostReportCount = (int)DB::scalar("SELECT COUNT(*) FROM post_reports WHERE status = 'pending'");
    }
    $postCount    = (int)DB::scalar('SELECT COUNT(*) FROM posts');
    $tagCount     = (int)DB::scalar('SELECT COUNT(*) FROM tags');
    $commentCount = (int)DB::scalar('SELECT COUNT(*) FROM comments');

    $curName             = View::siteSetting('site_name', SITE_NAME);
    $curDescription      = View::siteSetting('site_description', '');
    $curDiscordUrl       = View::siteSetting('discord_url', '');
    $curLogo             = View::siteSetting('site_logo');
    $curBanner           = View::siteSetting('site_banner');
    $curHomeHeaderImage  = View::siteSetting('home_header_image');
    $curDefaultBlacklist = View::siteSetting('default_blacklist', '');
    $curDefaultTheme     = View::siteSetting('default_theme', 'dark');
    $curCustomThemeName  = View::siteSetting('custom_theme_name', 'Custom');
    $curCustomThemeCss   = View::siteSetting('custom_theme_css', '');
    $curApiEnabled       = View::siteSetting('api_enabled', '1') === '1';
    $curApiRateLimit     = min(100000, max(1, (int)View::siteSetting('api_rate_limit', '120')));
    $curFooterText       = View::siteSetting('footer_text', '© {year} {site_name}.');
    $curFooterLinks      = View::siteSetting('footer_links', "Posts | /posts\nTags | /tags\nWiki | /wiki\nAPI | /api-docs\nTerms | /terms");
    $customThemeConfigured = trim($curCustomThemeCss) !== '';
    $adminThemeOptions   = [
        'dark' => 'Dark',
        'light' => 'Light',
        'catppuccin' => 'Catppuccin Mocha',
        'blue' => 'Blue',
        'custom' => $curCustomThemeName ?: 'Custom',
    ];
    if (!isset($adminThemeOptions[$curDefaultTheme]) || ($curDefaultTheme === 'custom' && !$customThemeConfigured)) {
        $curDefaultTheme = 'dark';
    }
    $curGlobalTagBlacklist = View::siteSetting('global_tag_blacklist', '');
    $globallyBlockedPostCount = count(Post::globallyBlockedPostIds());
    $curTermsOfService   = View::siteSetting('terms_of_service', '');
    $discordWebhookConfigured = DiscordWebhook::isValidUrl(View::siteSetting('discord_webhook_url', ''));
    $discordWebhookEnabled = View::siteSetting('discord_webhook_enabled', '0') === '1';

    $curStorageDriver = View::siteSetting('storage_driver', 'local');
    $curS3Endpoint    = View::siteSetting('s3_endpoint', '');
    $curS3Region      = View::siteSetting('s3_region', 'us-east-1');
    $curS3Bucket      = View::siteSetting('s3_bucket', '');
    $curS3AccessKey   = View::siteSetting('s3_access_key', '');
    $curS3SecretKey   = View::siteSetting('s3_secret_key', '');
    $curBackupS3Endpoint = View::siteSetting('backup_s3_endpoint', '');
    $curBackupS3Region = View::siteSetting('backup_s3_region', 'us-east-1');
    $curBackupS3Bucket = View::siteSetting('backup_s3_bucket', '');
    $curBackupS3AccessKey = View::siteSetting('backup_s3_access_key', '');
    $backupS3SecretConfigured = View::siteSetting('backup_s3_secret_key', '') !== '';
    $databaseBackups = [];
    $databaseBackupListError = '';
    if (Auth::can('manage_database_backups', $user) && DatabaseBackup::isConfigured()) {
        try {
            $databaseBackups = DatabaseBackup::list();
        } catch (Throwable $e) {
            $databaseBackupListError = $e->getMessage();
        }
    }


    View::header('Panel', $user);
    View::flash();
    renderAdminTabs($user, 'panel');


    if (Auth::can('access_admin_panel', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'statistics' ? ' open' : '') . '>';
    echo '<summary>Statistics</summary>';
    echo '<div class="admin-section-content">';
    echo '<table>';
    echo '<tbody>';
    echo '<tr><th style="text-align:left; padding:8px;">Posts</th><td style="padding:8px;">' . $postCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Tags</th><td style="padding:8px;">' . $tagCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Comments</th><td style="padding:8px;">' . $commentCount . '</td></tr>';
    echo '<tr><th style="text-align:left; padding:8px;">Users</th><td style="padding:8px;">' . count($users) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    renderActivityStatistics();
    renderActivityStatistics(true);
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'banned-tags' ? ' open' : '') . '>';
    echo '<summary>Banned Tags <span class="admin-section-count">' . count(Post::globalBlockedRules()) . ' rules</span></summary>';
    echo '<div class="admin-section-content">';
    echo '<p>These rules reject posts across every uploader and scraper. Put one rule per line. Multiple tags on one line mean that all of them must occur together.</p>';
    echo '<form method="post" style="max-width:600px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="global_tag_blacklist">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Global banned tag rules</span><textarea name="global_tag_blacklist" rows="8" style="width:100%" placeholder="child&#10;young_anthro&#10;animal human&#10;anthro human">' . View::e($curGlobalTagBlacklist) . '</textarea></label>';
    echo '<label style="display:flex; align-items:flex-start; gap:8px;"><input type="checkbox" name="purge_matching_posts" value="1" checked> <span>Delete posts already matching these rules, including their media files (' . $globallyBlockedPostCount . ' currently match)</span></label>';
    echo '<button style="align-self:flex-start;">Save Banned Tags</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'site-settings' ? ' open' : '') . '>';
    echo '<summary>Site Settings</summary>';
    echo '<div class="admin-section-content">';
    echo '<p style="color:var(--muted-text);font-size:12px">All site images are saved in local storage.</p>';
    echo '<form method="post" enctype="multipart/form-data" style="max-width:500px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="site_settings">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Site name</span><input name="site_name" value="' . View::e($curName) . '" style="width:100%"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Discord URL <small>(leave empty to hide the homepage link)</small></span><input type="url" name="discord_url" value="' . View::e($curDiscordUrl) . '" maxlength="2048" placeholder="https://discord.gg/your-invite" style="width:100%"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>SEO description <small>(used by search engines and Discord)</small></span><textarea name="site_description" rows="3" maxlength="200" style="width:100%" placeholder="Describe the site in one concise sentence…">' . View::e($curDescription) . '</textarea></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Default theme <small>(used when a visitor has no local preference)</small></span><select id="default-theme-select" name="default_theme" style="width:100%">';
    foreach ($adminThemeOptions as $themeValue => $themeLabel) {
        $disabled = $themeValue === 'custom' && !$customThemeConfigured ? ' disabled' : '';
        echo '<option value="' . View::e($themeValue) . '"' . ($curDefaultTheme === $themeValue ? ' selected' : '') . $disabled . '>' . View::e($themeLabel) . '</option>';
    }
    echo '</select></label>';
    echo '<fieldset class="custom-theme-editor">';
    echo '<legend>Custom theme</legend>';
    echo '<label><span>Theme name</span><input name="custom_theme_name" maxlength="40" value="' . View::e($curCustomThemeName) . '" placeholder="Custom"></label>';
    echo '<label><span>Custom CSS <small>(clear this field to disable the custom theme)</small></span><textarea id="custom-theme-css-input" name="custom_theme_css" rows="18" maxlength="100000" spellcheck="false" placeholder=":root[data-theme=&quot;custom&quot;] {&#10;  --bg-main: #10131a;&#10;  --accent: #8ab4f8;&#10;}">' . View::e($curCustomThemeCss) . '</textarea></label>';
    $customThemeExample = <<<'CSS'
:root[data-theme="custom"] {
  color-scheme: dark;
  --bg-main: #10131a;
  --bg-card: #181d27;
  --bg-sidebar: #141821;
  --bg-elevated: #242b38;
  --bg-input: #181d27;
  --text-main: #eef2ff;
  --text-muted: #b3bdd1;
  --text-dim: #7d899f;
  --border-color: #2d3545;
  --border-hover: #46526a;
  --accent: #8ab4f8;
  --accent-hover: #b7d1ff;
  --accent-bg: #263752;
  --nav-current-bg: #263752;
  --nav-current-text: #ffffff;
  --tag-general: #66c2ff;
  --tag-artist: #ffd166;
  --tag-copyright: #c69cff;
  --tag-character: #ff8fa3;
  --tag-species: #74d99f;
  --tag-lore: #67d5ca;
  --tag-meta: #8ab4f8;
}
CSS;
    echo '<details class="custom-theme-example"><summary>Example custom theme CSS</summary><pre><code>' . View::e($customThemeExample) . '</code></pre></details>';
    echo '<script>(()=>{const css=document.getElementById("custom-theme-css-input");const option=document.querySelector("#default-theme-select option[value=custom]");if(!css||!option)return;const sync=()=>option.disabled=!css.value.trim();css.addEventListener("input",sync);sync();})();</script>';
    echo '</fieldset>';
    echo '<fieldset class="api-settings-editor">';
    echo '<legend>API</legend>';
    echo '<label class="api-enabled-setting"><input type="checkbox" name="api_enabled" value="1"' . ($curApiEnabled ? ' checked' : '') . '> <span>Enable API</span></label>';
    echo '<label><span>Rate limit <small>(requests per minute for each user or IP address)</small></span><input type="number" name="api_rate_limit" min="1" max="100000" step="1" required value="' . $curApiRateLimit . '"></label>';
    echo '<a href="' . View::url('/api-docs') . '">Open API documentation</a>';
    echo '</fieldset>';
    echo '<fieldset class="footer-settings-editor">';
    echo '<legend>Footer</legend>';
    echo '<label><span>Footer text <small>(supports {year} and {site_name})</small></span><textarea name="footer_text" rows="3" maxlength="1000" placeholder="© {year} {site_name}.">' . View::e($curFooterText) . '</textarea></label>';
    echo '<label><span>Footer links <small>(one per line: Label | URL)</small></span><textarea name="footer_links" rows="6" maxlength="5000" spellcheck="false" placeholder="Terms | /terms&#10;GitHub | https://github.com/example/repository">' . View::e($curFooterLinks) . '</textarea></label>';
    echo '<small>Internal links must begin with <code>/</code>. The footer is hidden on the homepage.</small>';
    echo '</fieldset>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Navbar logo <small>(saved locally)</small></span><input type="file" name="site_logo_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curLogo) echo '<label><input type="checkbox" name="clear_site_logo"> Remove current navbar logo</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Navbar banner <small>(saved locally; used when no logo is set)</small></span><input type="file" name="site_banner_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curBanner) echo '<label><input type="checkbox" name="clear_site_banner"> Remove current navbar banner</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Homepage header <small>(shown on / instead of the site-name text; saved locally)</small></span><input type="file" name="home_header_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
    if ($curHomeHeaderImage) echo '<label><input type="checkbox" name="clear_home_header_image"> Remove current homepage header</label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Default Blacklist Tags for New Users <small>(space or line separated)</small></span><textarea name="default_blacklist" rows="2" style="width:100%" placeholder="e.g. nsfw gore">' . View::e($curDefaultBlacklist) . '</textarea></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Terms of Service <small>(shown at /terms)</small></span><textarea name="terms_of_service" rows="12" style="width:100%" placeholder="Write your Terms of Service…">' . View::e($curTermsOfService) . '</textarea></label>';
    echo '<button style="align-self:flex-start;">Save Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_site_settings', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'webhooks' ? ' open' : '') . '>';
    echo '<summary>Webhooks</summary>';
    echo '<div class="admin-section-content">';
    echo '<form method="post" style="max-width:600px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Discord webhook URL</span><input type="password" name="discord_webhook_url" value="" autocomplete="new-password" placeholder="' . ($discordWebhookConfigured ? 'Configured — leave blank to keep it' : 'https://discord.com/api/webhooks/...') . '"></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;"><input type="checkbox" name="discord_webhook_enabled" value="1"' . ($discordWebhookEnabled ? ' checked' : '') . '> <span>Send a notification when a new post is created</span></label>';
    if ($discordWebhookConfigured) {
        echo '<label style="display:flex; align-items:center; gap:5px;"><input type="checkbox" name="clear_discord_webhook" value="1"> <span>Remove the saved webhook</span></label>';
    }
    echo '<small style="color:var(--text-muted)">Messages contain the post link and its tags. Discord mentions are disabled.</small>';
    echo '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
    echo '<button type="submit" name="action" value="webhook_settings">Save webhook</button>';
    echo '<button type="submit" name="action" value="test_discord_webhook">Send test</button>';
    echo '</div>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_storage_settings', $user)) {
    $isLocal = $curStorageDriver === 'local';
    $isS3    = $curStorageDriver === 's3';
    echo '<details class="admin-section"' . ($openSection === 'media-storage' ? ' open' : '') . '>';
    echo '<summary>Media Storage Settings</summary>';
    echo '<div class="admin-section-content">';

    $totalBytes = 0;
    if (file_exists(LIBOORU_ROOT . '/data/uploads')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(LIBOORU_ROOT . '/data/uploads', FilesystemIterator::SKIP_DOTS)) as $file) { $totalBytes += $file->getSize(); }
    }
    if (file_exists(LIBOORU_ROOT . '/data/thumbs')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(LIBOORU_ROOT . '/data/thumbs', FilesystemIterator::SKIP_DOTS)) as $file) { $totalBytes += $file->getSize(); }
    }
    $gbTaken = number_format($totalBytes / (1024 * 1024 * 1024), 2);


    $s3BytesEstimate = (int)DB::scalar('SELECT SUM(filesize) FROM posts') + ($postCount * 51200);
    $s3GbTaken = number_format($s3BytesEstimate / (1024 * 1024 * 1024), 2);

    echo '<div style="display:flex; gap:20px; margin-bottom:15px; flex-wrap:wrap;">';
    echo '<p style="color:var(--muted-text);font-size:14px;margin:0;">Local Storage taken: <strong>' . $gbTaken . ' GB</strong></p>';
    echo '<p style="color:var(--muted-text);font-size:14px;margin:0;">S3 Storage taken (est.): <strong>' . $s3GbTaken . ' GB</strong></p>';
    echo '</div>';

    echo '<form method="post" style="max-width:500px; display:flex; flex-direction:column; gap:15px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="storage_settings">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Storage Method</span>';
    echo '<select name="storage_driver" style="width:100%" onchange="document.getElementById(\'s3-fields\').style.display = this.value === \'s3\' ? \'flex\' : \'none\';">';
    echo '<option value="local"' . ($isLocal ? ' selected' : '') . '>Local (data/uploads, data/thumbs)</option>';
    echo '<option value="s3"' . ($isS3 ? ' selected' : '') . '>S3 (Amazon S3, MinIO, R2, Wasabi, etc.)</option>';
    echo '</select></label>';

    echo '<div id="s3-fields" style="display:' . ($isS3 ? 'flex' : 'none') . '; flex-direction:column; gap:15px; border: 1px solid var(--border); padding: 15px; border-radius: 4px;">';
    echo '<h3 style="margin:0;font-size:15px">S3 Configuration</h3>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Endpoint <small>(e.g. https://s3.amazonaws.com)</small></span><input name="s3_endpoint" value="' . View::e($curS3Endpoint) . '" style="width:100%" placeholder="https://s3.us-east-1.amazonaws.com"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Bucket Name</span><input name="s3_bucket" value="' . View::e($curS3Bucket) . '" style="width:100%" placeholder="my-libooru-bucket"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Region <small>(default: us-east-1)</small></span><input name="s3_region" value="' . View::e($curS3Region) . '" style="width:100%" placeholder="us-east-1"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Access Key</span><input name="s3_access_key" value="' . View::e($curS3AccessKey) . '" style="width:100%" placeholder="AKIA..."></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Secret Key</span><input type="password" name="s3_secret_key" value="' . View::e($curS3SecretKey) . '" style="width:100%"></label>';
    echo '</div>';

    echo '<button style="align-self:flex-start;">Save Storage Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_database_backups', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'database-backups' ? ' open' : '') . '>';
        echo '<summary>Database backups <span class="admin-section-count">' . count($databaseBackups) . '</span></summary>';
        echo '<div class="admin-section-content">';
        echo '<p style="color:var(--text-muted)">Backups contain the SQLite database only. Media files are not included.</p>';
        echo '<h3>Backup S3 settings</h3>';
        echo '<form method="post" style="max-width:500px;display:flex;flex-direction:column;gap:15px">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="backup_settings">';
        echo '<label><span>Endpoint</span><input name="backup_s3_endpoint" value="' . View::e($curBackupS3Endpoint) . '" placeholder="https://s3.amazonaws.com"></label>';
        echo '<label><span>Region</span><input name="backup_s3_region" value="' . View::e($curBackupS3Region) . '" placeholder="us-east-1"></label>';
        echo '<label><span>Backup bucket</span><input name="backup_s3_bucket" value="' . View::e($curBackupS3Bucket) . '"></label>';
        echo '<label><span>Access key</span><input name="backup_s3_access_key" value="' . View::e($curBackupS3AccessKey) . '" autocomplete="off"></label>';
        echo '<label><span>Secret key</span><input type="password" name="backup_s3_secret_key" value="" autocomplete="new-password" placeholder="' . ($backupS3SecretConfigured ? 'Configured — leave blank to keep it' : 'Enter secret key') . '"></label>';
        echo '<button style="align-self:flex-start">Save backup settings</button>';
        echo '</form>';

        if (DatabaseBackup::isConfigured()) {
            echo '<form method="post" style="margin:24px 0">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="create_database_backup">';
            echo '<button>Create backup now</button>';
            echo '</form>';

            if ($databaseBackupListError !== '') {
                echo '<p class="flash error">Could not list backups: ' . View::e($databaseBackupListError) . '</p>';
            } elseif (!$databaseBackups) {
                echo '<p>No backups found in the configured bucket.</p>';
            } else {
                echo '<div style="overflow-x:auto"><table style="width:100%">';
                echo '<thead><tr><th>Backup</th><th>Created</th><th>Size</th><th>Action</th></tr></thead><tbody>';
                foreach ($databaseBackups as $backupObject) {
                    $modified = strtotime($backupObject['last_modified']);
                    echo '<tr>';
                    echo '<td><code>' . View::e($backupObject['key']) . '</code></td>';
                    echo '<td>' . View::e($modified ? date('Y-m-d H:i:s', $modified) : $backupObject['last_modified']) . '</td>';
                    echo '<td>' . View::e(number_format($backupObject['size'] / 1048576, 2)) . ' MB</td>';
                    echo '<td><form method="post" onsubmit="return confirm(\'Rollback the live database to this backup? A pre-rollback backup will be created first.\')">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="restore_database_backup">';
                    echo '<input type="hidden" name="backup_key" value="' . View::e($backupObject['key']) . '">';
                    echo '<button>Rollback to this backup</button>';
                    echo '</form></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        } else {
            echo '<p>Save a complete backup S3 configuration to create and restore backups.</p>';
        }

        echo '</div></details>';
    }


    if (Auth::can('manage_registration_settings', $user)) {
    $curDisableReg = View::siteSetting('disable_registrations', '0');
    $curRequireRegistrationReason = View::siteSetting('require_registration_reason', '0');
    $curRegistrationRequiresApproval = View::siteSetting('registration_requires_approval', '0');
    $curRequireLogin = View::siteSetting('require_login_posts', '0');
    $curEnableAdultWarning = View::siteSetting('enable_adult_warning', '0');
    $curShowUserFavorites = View::siteSetting('show_user_favorites', '0');
    $curEnableRegistrationCaptcha = View::siteSetting('enable_registration_captcha', '0');
    $curTurnstileSiteKey = View::siteSetting('turnstile_site_key', '');
    $hasTurnstileSecretKey = View::siteSetting('turnstile_secret_key', '') !== '';
    echo '<details class="admin-section"' . ($openSection === 'registrations-content' ? ' open' : '') . '>';
    echo '<summary>Registrations &amp; Content</summary>';
    echo '<div class="admin-section-content">';
    echo '<form method="post" style="max-width:500px; display:flex; flex-direction:column; gap:15px; margin-bottom: 20px;">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="registrations_settings">';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="disable_registrations" value="1"' . ($curDisableReg === '1' ? ' checked' : '') . '> ';
    echo '<span>Turn off registrations</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="require_registration_reason" value="1"' . ($curRequireRegistrationReason === '1' ? ' checked' : '') . '> ';
    echo '<span>Require a reason for registration</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="registration_requires_approval" value="1"' . ($curRegistrationRequiresApproval === '1' ? ' checked' : '') . '> ';
    echo '<span>Require administrator approval for registrations</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="require_login_posts" value="1"' . ($curRequireLogin === '1' ? ' checked' : '') . '> ';
    echo '<span>Forbid viewing posts for logged out users</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="enable_adult_warning" value="1"' . ($curEnableAdultWarning === '1' ? ' checked' : '') . '> ';
    echo '<span>Enable 18+ warning</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="show_user_favorites" value="1"' . ($curShowUserFavorites === '1' ? ' checked' : '') . '> ';
    echo '<span>Let users see other users’ favorites</span></label>';
    echo '<label style="display:flex; align-items:center; gap:5px;">';
    echo '<input type="checkbox" name="enable_registration_captcha" value="1" aria-controls="turnstile-fields" onchange="document.getElementById(\'turnstile-fields\').style.display = this.checked ? \'flex\' : \'none\';"' . ($curEnableRegistrationCaptcha === '1' ? ' checked' : '') . '> ';
    echo '<span>Enable registration captcha</span></label>';
    echo '<div id="turnstile-fields" style="display:' . ($curEnableRegistrationCaptcha === '1' ? 'flex' : 'none') . '; flex-direction:column; gap:15px; border:1px solid var(--border-color); padding:15px;">';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Cloudflare Turnstile Site Key</span><input name="turnstile_site_key" value="' . View::e($curTurnstileSiteKey) . '" autocomplete="off"></label>';
    echo '<label style="display:flex; flex-direction:column; gap:5px;"><span>Cloudflare Turnstile Secret Key</span><input type="password" name="turnstile_secret_key" value="" autocomplete="new-password" placeholder="' . ($hasTurnstileSecretKey ? 'Configured — leave blank to keep it' : 'Enter secret key') . '"></label>';
    echo '<small style="color:var(--text-muted)">Create a Turnstile widget in Cloudflare and enter its site key and secret key here.</small>';
    echo '</div>';
    echo '<button style="align-self:flex-start;">Save Settings</button>';
    echo '</form>';
    echo '</div></details>';
    }


    if (Auth::can('manage_post_reports', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'post-reports' ? ' open' : '') . '>';
        echo '<summary>Post reports <span class="admin-section-count">' . $pendingPostReportCount . ' pending</span></summary>';
        echo '<div class="admin-section-content">';
        if (!$postReports) {
            echo '<p>No post reports.</p>';
        } else {
            echo '<div style="overflow-x:auto"><table>';
            echo '<thead><tr><th>Post</th><th>Reporter</th><th>Reason</th><th>Reported</th><th>Status</th><th>Reviewed by</th><th>Actions</th></tr></thead><tbody>';
            foreach ($postReports as $report) {
                $resolvedBy = $report['resolver_name'] ?: '—';
                if ($report['resolved_at']) {
                    $resolvedBy .= ' (' . date('Y-m-d H:i', (int)$report['resolved_at']) . ')';
                }
                echo '<tr>';
                echo '<td><a href="' . View::url('/post/' . (int)$report['post_id']) . '">#' . (int)$report['post_id'] . '</a></td>';
                echo '<td>' . View::e($report['reporter_name'] ?: 'Deleted user') . '</td>';
                echo '<td style="min-width:240px">' . nl2br(View::e($report['reason'])) . '</td>';
                echo '<td>' . View::e(date('Y-m-d H:i', (int)$report['created_at'])) . '</td>';
                echo '<td>' . View::e(ucfirst($report['status'])) . '</td>';
                echo '<td>' . View::e($resolvedBy) . '</td>';
                echo '<td style="white-space:nowrap">';
                if ($report['status'] === 'pending') {
                    echo '<form method="post" style="display:inline">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="resolve_post_report"><input type="hidden" name="report_id" value="' . (int)$report['id'] . '"><button>Resolve</button></form> ';
                    echo '<form method="post" style="display:inline">';
                    View::csrfField();
                    echo '<input type="hidden" name="action" value="dismiss_post_report"><input type="hidden" name="report_id" value="' . (int)$report['id'] . '"><button>Dismiss</button></form>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></details>';
    }


    if (Auth::can('manage_registration_requests', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'registration-requests' ? ' open' : '') . '>';
    echo '<summary>Registration requests <span class="admin-section-count">' . count($registrationRequests) . '</span></summary>';
    echo '<div class="admin-section-content">';
    if (!$registrationRequests) {
        echo '<p>No pending registration requests.</p>';
    } else {
        echo '<div style="overflow-x:auto"><table>';
        echo '<thead><tr><th>Username</th><th>Email</th><th>Registration reason</th><th>Requested</th><th>Actions</th></tr></thead><tbody>';
        foreach ($registrationRequests as $request) {
            echo '<tr><td>' . View::e($request['name']) . '</td>';
            echo '<td>' . View::e($request['email'] ?: '—') . '</td>';
            echo '<td>' . View::e($request['registration_reason'] ?: '—') . '</td>';
            echo '<td>' . View::e(date('Y-m-d H:i', (int)$request['created_at'])) . '</td><td>';
            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="approve_registration_request"><input type="hidden" name="request_id" value="' . View::e($request['id']) . '"><button>Approve</button></form> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Decline this registration request?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="decline_registration_request"><input type="hidden" name="request_id" value="' . View::e($request['id']) . '"><button>Decline</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></details>';
    }


    if (Auth::can('manage_roles', $user)) {
        echo '<details class="admin-section"' . ($openSection === 'roles' ? ' open' : '') . '>';
        echo '<summary>Roles <span class="admin-section-count">' . count($roles) . '</span></summary>';
        echo '<div class="admin-section-content">';
        echo '<h3>Create role</h3>';
        echo '<form method="post" class="admin-role-editor">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="create_role">';
        echo '<label><span>Role name</span><input name="role_name" maxlength="40" required placeholder="e.g. Moderator"></label>';
        echo '<fieldset><legend>Permissions</legend><div class="admin-permissions">';
        foreach ($permissionCatalog as $permission => $label) {
            echo '<label><input type="checkbox" name="permissions[]" value="' . View::e($permission) . '"> <span>' . View::e($label) . '</span></label>';
        }
        echo '</div></fieldset>';
        echo '<button style="align-self:flex-start">Create Role</button>';
        echo '</form>';

        echo '<h3 style="margin-top:28px">Existing roles</h3>';
        foreach ($roles as $role) {
            $rolePermissions = json_decode((string)$role['permissions'], true);
            if (!is_array($rolePermissions)) $rolePermissions = [];
            echo '<div class="admin-role-card">';
            echo '<div class="admin-role-heading"><strong>' . View::e($role['name']) . '</strong> ';
            echo '<code>' . View::e($role['slug']) . '</code> ';
            echo '<span class="admin-section-count">' . (int)$role['user_count'] . ' users</span></div>';
            if ((int)$role['is_system'] === 1) {
                echo '<p class="admin-role-note">' . ($role['slug'] === 'admin' ? 'System role with all permissions.' : 'Default system role for regular users.') . '</p>';
            } elseif ($role['slug'] === $user['role']) {
                echo '<p class="admin-role-note">This role is assigned to your account, so you cannot edit or delete it yourself.</p>';
            } else {
                echo '<form method="post" class="admin-role-editor">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="update_role">';
                echo '<input type="hidden" name="role_id" value="' . (int)$role['id'] . '">';
                echo '<label><span>Role name</span><input name="role_name" maxlength="40" required value="' . View::e($role['name']) . '"></label>';
                echo '<fieldset><legend>Permissions</legend><div class="admin-permissions">';
                foreach ($permissionCatalog as $permission => $label) {
                    $checked = in_array($permission, $rolePermissions, true) ? ' checked' : '';
                    echo '<label><input type="checkbox" name="permissions[]" value="' . View::e($permission) . '"' . $checked . '> <span>' . View::e($label) . '</span></label>';
                }
                echo '</div></fieldset>';
                echo '<button style="align-self:flex-start">Save Role</button>';
                echo '</form>';
                echo '<form method="post" onsubmit="return confirm(\'Delete this role? Assigned users will become regular users.\')" style="margin-top:10px">';
                View::csrfField();
                echo '<input type="hidden" name="action" value="delete_role">';
                echo '<input type="hidden" name="role_id" value="' . (int)$role['id'] . '">';
                echo '<button>Delete Role</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        echo '</div></details>';
    }


    if (Auth::can('manage_users', $user) || Auth::can('manage_roles', $user)) {
    echo '<details class="admin-section"' . ($openSection === 'users' ? ' open' : '') . '>';
    echo '<summary>Users <span class="admin-section-count">' . count($users) . '</span></summary>';
    echo '<div class="admin-section-content">';
    if ($passwordResetResult !== null) {
        echo '<div class="admin-password-reset-result">';
        echo '<strong>Temporary password for ' . View::e((string)$passwordResetResult['username']) . '</strong>';
        echo '<p>Copy it now. It will not be shown again after leaving or refreshing this page.</p>';
        echo '<div><input id="admin-generated-password" type="text" readonly value="' . View::e((string)$passwordResetResult['password']) . '" autocomplete="off" spellcheck="false">';
        echo '<button type="button" id="admin-copy-password">Copy</button></div>';
        echo '</div>';
        echo '<script>(()=>{const input=document.getElementById("admin-generated-password");const button=document.getElementById("admin-copy-password");if(!input||!button)return;button.addEventListener("click",async()=>{let copied=false;try{await navigator.clipboard.writeText(input.value);copied=true;}catch(e){input.select();copied=document.execCommand("copy");}if(copied){button.textContent="Copied";setTimeout(()=>button.textContent="Copy",1600);}});})();</script>';
    }
    echo '<div style="overflow-x:auto"><table>';
    echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Reason</th><th>Role</th>';
    if (Auth::can('manage_users', $user)) echo '<th>API Key</th>';
    echo '<th>Actions</th></tr></thead>';
    echo '<tbody>';
    foreach ($users as $u) {
        echo '<tr>';
        echo '<td>' . View::e($u['id']) . '</td>';
        $countryFlag = Activity::flag($u['country_code']);
        $countryLabel = $countryFlag !== ''
            ? '<span class="user-country" title="IP country: ' . View::e($u['country_code']) . '" aria-label="IP country: ' . View::e($u['country_code']) . '">' . $countryFlag . '</span>'
            : '<span class="user-country-unknown" title="Country is not available yet">Unknown</span>';
        echo '<td><a href="' . View::url('/user/' . rawurlencode($u['name'])) . '">' . View::e($u['name']) . '</a> ' . $countryLabel . '</td>';
        echo '<td>' . View::e($u['email'] ?: '—') . '</td>';
        echo '<td>' . View::e($u['registration_reason'] ?: '—') . '</td>';
        echo '<td>' . View::e($roleNames[$u['role']] ?? $u['role']) . '</td>';
        if (Auth::can('manage_users', $user)) echo '<td><code style="font-size:11px">' . View::e($u['api_key']) . '</code></td>';
        echo '<td style="white-space:nowrap">';

        if (Auth::can('manage_roles', $user) && (int)$u['id'] !== (int)$user['id']) {
            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="set_role">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<select name="role">';
            foreach ($roles as $availableRole) {
                $sel = $u['role'] === $availableRole['slug'] ? ' selected' : '';
                echo '<option value="' . View::e($availableRole['slug']) . '"' . $sel . '>' . View::e($availableRole['name']) . '</option>';
            }
            echo '</select> <button>Set</button>';
            echo '</form> ';
        }

        if (Auth::can('manage_users', $user)) {
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Reset this user password?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="reset_password">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Reset password</button>';
            echo '</form> ';

            echo '<form method="post" style="display:inline">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="regen_api">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Regen API</button>';
            echo '</form> ';
        }

        if (Auth::can('manage_users', $user) && (int)$u['id'] !== (int)$user['id']) {
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete user ' . View::e($u['name']) . '?\')">';
            View::csrfField();
            echo '<input type="hidden" name="action" value="delete_user">';
            echo '<input type="hidden" name="user_id" value="' . View::e($u['id']) . '">';
            echo '<button>Delete</button>';
            echo '</form>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div></details>';
    }
    View::footer();
}
