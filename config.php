<?php
declare(strict_types=1);

define('LIBOORU_ROOT', __DIR__);
define('DATA_DIR',     LIBOORU_ROOT . '/data');
define('UPLOAD_DIR',   DATA_DIR . '/uploads');
define('THUMB_DIR',    DATA_DIR . '/thumbs');
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

// Max upload size (bytes) — also set in php.ini
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100 MB

// API key header
define('API_KEY_HEADER', 'X-API-Key');
