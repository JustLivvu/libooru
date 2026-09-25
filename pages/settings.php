<?php
declare(strict_types=1);

function page_settings(?array $user, string $method): void
{
    Auth::require();

    $error = '';
    $biography = $user['biography'] ?? '';
    $displayName = $user['display_name'] ?? '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_profile') {
            $biography = trim(is_string($_POST['biography'] ?? null) ? $_POST['biography'] : '');
            $displayName = trim(is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : ($user['display_name'] ?? ''));
            $newImages = [];
            try {
                if (!mb_check_encoding($displayName, 'UTF-8') || mb_strlen($displayName, 'UTF-8') > 64
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $displayName)) {
                    throw new RuntimeException('Display name must contain no more than 64 characters and no control characters.');
                }
                if (!mb_check_encoding($biography, 'UTF-8') || mb_strlen($biography, 'UTF-8') > 2000) {
                    throw new RuntimeException('Biography must be valid text with no more than 2,000 characters.');
                }
                $profile = DB::row('SELECT avatar, banner FROM users WHERE id = ?', [(int)$user['id']]);
                $updated = $profile;
                foreach (['avatar', 'banner'] as $field) {
                    $image = saveProfileImageUpload($field . '_upload', (int)$user['id']);
                    if ($image !== null) {
                        $newImages[] = $image;
                        $updated[$field] = $image;
                    } elseif (isset($_POST['remove_' . $field])) {
                        $updated[$field] = '';
                    }
                }
                DB::exec('UPDATE users SET avatar = ?, banner = ?, biography = ?, display_name = ? WHERE id = ?',
                    [$updated['avatar'], $updated['banner'], $biography, $displayName, (int)$user['id']]);
            } catch (Throwable $e) {
                foreach ($newImages as $image) deleteLocalSiteImage($image);
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save your profile. Please try again.';
            }
            if ($error === '') {
                foreach (['avatar', 'banner'] as $field) {
                    if ($profile[$field] !== $updated[$field]) deleteLocalSiteImage($profile[$field]);
                }
                View::setFlash('Profile updated.', 'ok');
                Router::redirect('/settings');
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $dbUser = DB::row('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
            if (!password_verify($current, $dbUser['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($new) < 4) {
                $error = 'New password must be at least 4 characters.';
            } elseif ($new !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                DB::exec('UPDATE users SET password = ? WHERE id = ?', [$hash, (int)$user['id']]);
                View::setFlash('Password changed successfully.', 'ok');
                Router::redirect('/settings');
            }

        } elseif ($action === 'regen_api') {
            $key = bin2hex(random_bytes(16));
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', [$key, (int)$user['id']]);
            View::setFlash('API key regenerated.', 'ok');
            Router::redirect('/settings');

        } elseif ($action === 'delete_api') {
            DB::exec('UPDATE users SET api_key = ? WHERE id = ?', ['', (int)$user['id']]);
            View::setFlash('API key deleted.', 'ok');
            Router::redirect('/settings');

        } elseif ($action === 'save_blacklist') {
            $blacklist = trim($_POST['blacklist'] ?? '');
            DB::exec('UPDATE users SET blacklist = ? WHERE id = ?', [$blacklist, (int)$user['id']]);
            View::setFlash('Blacklist updated.', 'ok');
            Router::redirect('/settings');
        }
    }


    $user = DB::row('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
    $hasKey = !empty($user['api_key']);

    View::header('Settings', $user);
    View::flash();
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';

    $themeOptions = [
        'dark' => 'Dark',
        'light' => 'Light',
        'catppuccin' => 'Catppuccin Mocha',
        'blue' => 'Blue',
    ];
    $siteDefaultTheme = View::siteSetting('default_theme', 'dark');
    $customThemeCss = View::siteSetting('custom_theme_css', '');
    if (trim($customThemeCss) !== '') {
        $themeOptions['custom'] = View::siteSetting('custom_theme_name', 'Custom') ?: 'Custom';
    }
    if (!isset($themeOptions[$siteDefaultTheme])) $siteDefaultTheme = 'dark';

    echo '<section class="theme-settings">';
    echo '<h2>Appearance</h2>';
    echo '<label for="theme-select">Theme</label>';
    echo '<select id="theme-select">';
    echo '<option value="site">Site default (' . View::e($themeOptions[$siteDefaultTheme]) . ')</option>';
    foreach ($themeOptions as $themeValue => $themeLabel) {
        echo '<option value="' . View::e($themeValue) . '">' . View::e($themeLabel) . '</option>';
    }
    echo '</select>';
    echo '<p>The selected theme is saved locally in this browser. Site default follows changes made by the administrator.</p>';
    echo '</section>';
    echo '<script>(()=>{const select=document.getElementById("theme-select");if(!select)return;';
    echo 'const theme=window.libooruTheme;select.value=theme?.getPreference?.()||"site";';
    echo 'select.addEventListener("change",()=>theme?.set(select.value));})();</script>';

    echo '<h2>Profile</h2>';
    echo '<p><a href="' . View::url('/user/' . rawurlencode($user['name'])) . '">View your profile</a></p>';
    echo '<form method="post" enctype="multipart/form-data" class="profile-settings">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="save_profile">';
    echo '<label>Display name <small>(up to 64 characters)</small><input type="text" name="display_name" maxlength="64" value="' . View::e($displayName) . '" placeholder="' . View::e($user['name']) . '"></label>';
    echo '<p class="profile-help">Shown on your profile. Leave empty to use your username. Your login and profile URL stay the same.</p>';
    echo '<p class="profile-help">JPEG, PNG, GIF or WebP, up to 5 MB per image. A square profile picture and a wide banner work best. These images and your biography are visible on your profile.</p>';
    foreach (['avatar' => 'Profile picture', 'banner' => 'Banner'] as $field => $label) {
        echo '<label>' . $label . '<input type="file" name="' . $field . '_upload" accept="image/jpeg,image/png,image/gif,image/webp"></label>';
        if ($user[$field] !== '') {
            echo '<img class="' . ($field === 'avatar' ? 'profile-avatar' : 'profile-banner') . '" src="' . View::e($user[$field]) . '" alt="Current ' . strtolower($label) . '">';
            echo '<label><input type="checkbox" name="remove_' . $field . '" value="1"> Remove current ' . strtolower($label) . '</label>';
        }
    }
    echo '<label>Biography <small>(up to 2,000 characters)</small><textarea name="biography" rows="6" maxlength="2000" placeholder="Tell others about yourself">' . View::e($biography) . '</textarea></label>';
    echo '<button type="submit">Save profile</button></form>';


    echo '<h2>Tag Blacklist</h2>';
    echo '<p style="color:var(--muted-text);font-size:12px">Tags listed here will be hidden from top tags in the left sidebar.</p>';
    echo '<form method="post" style="max-width:400px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="save_blacklist">';
    echo '<label>Blacklisted tags <small>(space or line separated)</small><br>';
    echo '<textarea name="blacklist" rows="3" style="width:100%" placeholder="e.g. tag1 tag2">' . View::e($user['blacklist'] ?? '') . '</textarea></label><br><br>';
    echo '<button>Save Blacklist</button>';
    echo '</form>';


    echo '<h2 style="margin-top:30px">Change Password</h2>';
    echo '<form method="post" style="max-width:400px">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="change_password">';
    echo '<label>Current password<br><input type="password" name="current_password" required autocomplete="current-password" style="width:100%"></label><br><br>';
    echo '<label>New password<br><input type="password" name="new_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<label>Confirm new password<br><input type="password" name="confirm_password" required autocomplete="new-password" style="width:100%"></label><br><br>';
    echo '<button>Change Password</button>';
    echo '</form>';


    echo '<div class="settings-section-heading"><h2>API Key</h2><a href="' . View::url('/api-docs') . '">API documentation</a></div>';

    if ($hasKey) {
        echo '<div class="api-key-display">';
        echo '<input id="user-api-key" type="text" readonly value="' . View::e($user['api_key']) . '" autocomplete="off" spellcheck="false" aria-label="Your API key">';
        echo '<button type="button" id="copy-api-key">Copy</button>';
        echo '</div>';
        echo '<p class="profile-help">Keep this key private. Anyone with it can authenticate as your account through the API.</p>';
        echo '<script>(()=>{const input=document.getElementById("user-api-key");const button=document.getElementById("copy-api-key");if(!input||!button)return;button.addEventListener("click",async()=>{let copied=false;try{await navigator.clipboard.writeText(input.value);copied=true;}catch(e){input.select();copied=document.execCommand("copy");}if(copied){button.textContent="Copied";setTimeout(()=>button.textContent="Copy",1600);}});})();</script>';
    } else {
        echo '<p>You do not have an API key.</p>';
    }

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">';


    echo '<form method="post">';
    View::csrfField();
    echo '<input type="hidden" name="action" value="regen_api">';
    echo '<button>' . ($hasKey ? 'Reset API Key' : 'Generate API Key') . '</button>';
    echo '</form>';


    if ($hasKey) {
        echo '<form method="post" onsubmit="return confirm(\'Delete your API key? Importers using it will stop working.\')">';
        View::csrfField();
        echo '<input type="hidden" name="action" value="delete_api">';
        echo '<button>Delete API Key</button>';
        echo '</form>';
    }

    echo '</div>';
    View::footer();
}
