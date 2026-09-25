<?php
declare(strict_types=1);

class Router
{
    private static function streamHandle($handle, int $length, bool $rateLimited): void
    {
        $remaining = $length;
        $burstRemaining = $rateLimited ? min(VIDEO_RATE_LIMIT_AFTER, $length) : $length;
        $limitedBytes = 0;
        $limitStartedAt = null;

        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            $chunkLength = strlen($chunk);
            echo $chunk;
            $remaining -= $chunkLength;

            if ($rateLimited) {
                $burstBytes = min($burstRemaining, $chunkLength);
                $burstRemaining -= $burstBytes;
                $limitedInChunk = $chunkLength - $burstBytes;
                if ($limitedInChunk > 0) {
                    $limitStartedAt ??= microtime(true);
                    $limitedBytes += $limitedInChunk;
                    $delay = ($limitedBytes / VIDEO_RATE_LIMIT) - (microtime(true) - $limitStartedAt);
                    if ($delay > 0) usleep((int)($delay * 1000000));
                }
            }
        }
    }

    public static function redirect(string $path, array $params = []): never
    {
        $url = SITE_BASE . $path;
        if ($params) $url .= '?' . http_build_query($params);
        header('Location: ' . $url);
        exit;
    }

    public static function serveFile(string $dir, string $name): void
    {

        $name = basename($name);
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'application/octet-stream';
        $size = filesize($path);
        $isVideo = str_starts_with($mime, 'video/');
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000, immutable');



        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            if ($matches[1] === '') {
                $suffixLength = (int)$matches[2];
                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
            } else {
                $start = (int)$matches[1];
                $end = $matches[2] === '' ? $size - 1 : min((int)$matches[2], $size - 1);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }

            $length = $end - $start + 1;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . $length);
            $handle = fopen($path, 'rb');
            fseek($handle, $start);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                self::streamHandle($handle, $length, $isVideo);
            }
            fclose($handle);
            return;
        }

        header('Content-Length: ' . $size);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        $handle = fopen($path, 'rb');
        self::streamHandle($handle, $size, $isVideo);
        fclose($handle);
    }
}
