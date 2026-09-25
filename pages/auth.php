<?php
declare(strict_types=1);

function page_login(?array $user, string $method): void
{
    if ($user) Router::redirect('/');
    $error = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $name = trim($_POST['name'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (Auth::login($name, $pass)) {
            Router::redirect('/');
        } elseif (Auth::hasPendingRegistration($name)) {
            $error = 'Your account exists but it has not been approved yet.';
        } else {
            $error = 'Invalid username or password.';
        }
    }

    View::header('Login', null);
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    echo '<form method="post" style="display: flex; flex-direction: column; gap: 16px; max-width: 300px;">';
    View::csrfField();
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Username</span><input name="name" required autofocus></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Password</span><input type="password" name="password" required></label>';
    echo '<div style="display: flex; align-items: center; gap: 16px;">';
    echo '<button type="submit">Login</button>';
    echo '<a href="' . View::url('/register') . '" style="font-size: 13px; color: var(--text-muted);">Register</a>';
    echo '</div>';
    echo '</form>';
    View::footer();
}

function page_register(?array $user, string $method): void
{
    if (View::siteSetting('disable_registrations', '0') === '1') {
        View::header('Register', null);
        echo '<p>Registrations are currently disabled.</p>';
        View::footer();
        return;
    }
    if ($user) Router::redirect('/');
    $error = '';
    $success = '';
    $acceptedTerms = false;
    $requireRegistrationReason = View::siteSetting('require_registration_reason', '0') === '1';
    $requiresRegistrationApproval = View::siteSetting('registration_requires_approval', '0') === '1';
    $registrationCaptchaEnabled = View::siteSetting('enable_registration_captcha', '0') === '1';
    $turnstileSiteKey = View::siteSetting('turnstile_site_key', '');
    $turnstileSecretKey = View::siteSetting('turnstile_secret_key', '');
    $registrationReason = '';

    if ($method === 'POST') {
        View::verifyCsrf();
        $name  = trim($_POST['name'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $registrationReason = trim($_POST['registration_reason'] ?? '');
        $acceptedTerms = isset($_POST['accept_terms']);
        $id = false;
        if (!$acceptedTerms) {
            $error = 'You must accept the Terms of Service to register.';
        } elseif ($requireRegistrationReason && $registrationReason === '') {
            $error = 'Please provide a reason for registration.';
        } elseif ($registrationCaptchaEnabled && ($turnstileSiteKey === '' || $turnstileSecretKey === '')) {
            $error = 'Registration CAPTCHA is not configured correctly. Please contact the administrator.';
        } elseif ($registrationCaptchaEnabled && !Auth::verifyTurnstile(trim((string)($_POST['cf-turnstile-response'] ?? '')), $turnstileSecretKey)) {
            $error = 'CAPTCHA verification failed. Please try again.';
        } elseif ($requiresRegistrationApproval) {
            $requestId = Auth::requestRegistration($name, $pass, $email, $registrationReason);
            if ($requestId) {
                $success = 'Your registration request has been sent for approval.';
            }
        } else {
            $id = Auth::register($name, $pass, $email, $registrationReason);
        }
        if ($id) {
            Auth::login($name, $pass);
            Router::redirect('/');
        } elseif ($error === '' && $success === '') {
            $error = 'Registration failed. Username may be taken or too short (min 2 chars, password min 4 chars).';
        }
    }

    View::header('Register', null);
    if ($registrationCaptchaEnabled && $turnstileSiteKey !== '') {
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
    if ($error) echo '<p class="flash flash-error">' . View::e($error) . '</p>';
    if ($success) echo '<p class="flash flash-ok">' . View::e($success) . '</p>';
    echo '<form method="post" style="display: flex; flex-direction: column; gap: 16px; max-width: 300px;">';
    View::csrfField();
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Username (2–32 chars)</span><input name="name" required autofocus></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Password (min 4 chars)</span><input type="password" name="password" required></label>';
    echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Email (optional)</span><input type="email" name="email"></label>';
    if ($requireRegistrationReason) {
        echo '<label style="display: flex; flex-direction: column; gap: 4px;"><span>Reason for registration</span><textarea name="registration_reason" required rows="4">' . View::e($registrationReason) . '</textarea></label>';
    }
    echo '<label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="accept_terms" value="1" required' . ($acceptedTerms ? ' checked' : '') . '> <span>I accept <a href="' . View::url('/terms') . '" target="_blank" rel="noopener" style="text-decoration:underline;">Terms of Service</a></span></label>';
    if ($registrationCaptchaEnabled && $turnstileSiteKey !== '') {
        echo '<div class="cf-turnstile" data-sitekey="' . View::e($turnstileSiteKey) . '" data-theme="auto" data-action="register"></div>';
    }
    echo '<div style="display: flex; align-items: center; gap: 16px;">';
    echo '<button type="submit">Register</button>';
    echo '<a href="' . View::url('/login') . '" style="font-size: 13px; color: var(--text-muted);">Back to login</a>';
    echo '</div>';
    echo '</form>';
    View::footer();
}
