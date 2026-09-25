<?php
declare(strict_types=1);

function saveSiteImageUpload(string $field): ?string
{
    $upload = $_FILES[$field] ?? null;
    if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Could not upload the selected image.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Site images must be JPEG, PNG, GIF, or WebP files.');
    }
    if (!is_dir(SITE_ASSET_DIR) && !mkdir(SITE_ASSET_DIR, 0755, true) && !is_dir(SITE_ASSET_DIR)) {
        throw new RuntimeException('Could not create local site-assets directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], SITE_ASSET_DIR . '/' . $filename)) {
        throw new RuntimeException('Could not save the selected image locally.');
    }
    chmod(SITE_ASSET_DIR . '/' . $filename, 0644);
    return SITE_BASE . '/site-assets/' . $filename;
}

function deleteLocalSiteImage(string $url): void
{
    $prefix = SITE_BASE . '/site-assets/';
    if (str_starts_with($url, $prefix)) {
        @unlink(SITE_ASSET_DIR . '/' . basename($url));
    }
}

function saveProfileImageUpload(string $field, int $userId): ?string
{
    $upload = $_FILES[$field] ?? null;
    if ($upload === null || ($upload['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Could not upload the selected profile image.');
    }
    if (filesize($upload['tmp_name']) > 5 * 1024 * 1024) {
        throw new RuntimeException('Each profile image must be no larger than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($upload['tmp_name']);
    if (!isset($extensions[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Profile images must be valid JPEG, PNG, GIF, or WebP files.');
    }
    if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > MAX_MEDIA_PIXELS) {
        throw new RuntimeException('Profile images must contain no more than 20 million pixels.');
    }
    if (!is_dir(SITE_ASSET_DIR) && !mkdir(SITE_ASSET_DIR, 0755, true) && !is_dir(SITE_ASSET_DIR)) {
        throw new RuntimeException('Could not create the profile images directory.');
    }
    $filename = 'profile-' . $userId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], SITE_ASSET_DIR . '/' . $filename)) {
        throw new RuntimeException('Could not save the selected profile image.');
    }
    chmod(SITE_ASSET_DIR . '/' . $filename, 0644);
    return SITE_BASE . '/site-assets/' . $filename;
}
