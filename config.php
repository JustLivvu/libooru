<?php
declare(strict_types=1);

define('LIBOORU_ROOT', __DIR__);
define('DATA_DIR',     LIBOORU_ROOT . '/data');
define('UPLOAD_DIR',   DATA_DIR . '/uploads');
define('THUMB_DIR',    DATA_DIR . '/thumbs');
define('SITE_ASSET_DIR', DATA_DIR . '/site-assets');
define('DB_PATH',      DATA_DIR . '/libooru.db');

// Site settings
define('SITE_NAME',    'Libooru');
define('SITE_BASE',    '');          // e.g. '/libooru' if not at root
define('POSTS_PER_PAGE', 20);
define('THUMB_WIDTH',  450);
define('THUMB_HEIGHT', 450);

// Allowed MIME types  =>  extension
const ALLOWED_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
];

// Resource limits — keep php.ini/client_max_body_size in sync with MAX_FILE_SIZE.
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB
define('MAX_MEDIA_PIXELS', 20_000_000);
define('MEDIA_PROCESS_TIMEOUT', 30);

// Video delivery: allow a small startup/seek burst, then cap one PHP
// response at 2.5 MiB/s (about 21 Mbps). Four viewers stay below a 100 Mbps uplink.
define('VIDEO_RATE_LIMIT_AFTER', 2 * 1024 * 1024);
define('VIDEO_RATE_LIMIT', 2560 * 1024);
define('S3_VIDEO_URL_TTL', 3600);

// Comment abuse protection.
define('MAX_COMMENT_LENGTH', 4_000); // bytes
define('COMMENT_RATE_LIMIT', 10);
define('COMMENT_RATE_WINDOW', 60); // seconds

// API key header
define('API_KEY_HEADER', 'X-API-Key');
