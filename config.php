<?php
declare(strict_types=1);

define('LIBOORU_ROOT', __DIR__);
define('DATA_DIR',     LIBOORU_ROOT . '/data');
define('UPLOAD_DIR',   DATA_DIR . '/uploads');
define('THUMB_DIR',    DATA_DIR . '/thumbs');
define('SITE_ASSET_DIR', DATA_DIR . '/site-assets');
define('DB_PATH',      DATA_DIR . '/libooru.db');


define('SITE_NAME',    'Libooru');
define('SITE_BASE',    '');


define('SITE_URL',     rtrim((string)(getenv('LIBOORU_SITE_URL') ?: ''), '/'));
define('POSTS_PER_PAGE', 20);
define('THUMB_WIDTH',  450);
define('THUMB_HEIGHT', 450);


const ALLOWED_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'video/mp4'  => 'mp4',
    'video/x-m4v' => 'mp4',
    'video/webm' => 'webm',
];


define('MAX_FILE_SIZE', 100 * 1024 * 1024);
define('MAX_MEDIA_PIXELS', 20_000_000);
define('MEDIA_PROCESS_TIMEOUT', 30);



define('VIDEO_RATE_LIMIT_AFTER', 2 * 1024 * 1024);
define('VIDEO_RATE_LIMIT', 2560 * 1024);
define('S3_VIDEO_URL_TTL', 3600);
define('S3_THUMB_URL_TTL', 3600);


define('MAX_COMMENT_LENGTH', 4_000);
define('COMMENT_RATE_LIMIT', 10);
define('COMMENT_RATE_WINDOW', 60);


define('MAX_POST_REPORT_LENGTH', 1_000);
define('POST_REPORT_RATE_LIMIT', 5);
define('POST_REPORT_RATE_WINDOW', 3600);


define('API_KEY_HEADER', 'X-API-Key');
define('ACTIVITY_TIMEZONE', 'Europe/Prague');
