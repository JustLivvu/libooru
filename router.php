<?php
declare(strict_types=1);

// Keep every request behind Libooru's front controller. Returning false for
// existing files would let PHP's development server expose data/libooru.db and
// other files that nginx/Apache normally deny. index.php serves the explicitly
// public static, media, and site-asset routes itself.
require __DIR__ . '/index.php';
