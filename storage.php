<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/s3.php';




class Storage
{
    private static ?S3Client $s3Client = null;






    private static function s3MediaKey(string $key): string
    {
        $key = ltrim($key, '/');
        $bucket = trim(View::siteSetting('s3_bucket'), '/');
        $endpointHost = strtolower((string)parse_url(View::siteSetting('s3_endpoint'), PHP_URL_HOST));

        if ($bucket !== '' && str_starts_with($endpointHost, strtolower($bucket) . '.')) {
            return $bucket . '/' . $key;
        }

        return $key;
    }




    public static function getDriver(): string
    {
        $driver = View::siteSetting('storage_driver', 'local');
        return in_array($driver, ['local', 's3'], true) ? $driver : 'local';
    }




    public static function getS3Client(): ?S3Client
    {
        if (self::$s3Client === null) {
            $endpoint  = View::siteSetting('s3_endpoint');
            $region    = View::siteSetting('s3_region', 'us-east-1');
            $bucket    = View::siteSetting('s3_bucket');
            $accessKey = View::siteSetting('s3_access_key');
            $secretKey = View::siteSetting('s3_secret_key');

            if (!$endpoint || !$accessKey || !$secretKey) {
                return null;
            }
            self::$s3Client = new S3Client($endpoint, $region, $bucket, $accessKey, $secretKey);
        }
        return self::$s3Client;
    }




    public static function putMedia(string $filename, string $uploadTmpPath, string $thumbTmpPath, string $uploadMime, string $thumbMime): bool
    {
        $driver = self::getDriver();

        if ($driver === 's3') {
            $s3 = self::getS3Client();
            if (!$s3) {
                throw new RuntimeException('S3 storage is enabled but S3 parameters (endpoint, access key, secret key) are not fully configured.');
            }


            $s3->putObject(self::s3MediaKey('uploads/' . $filename), $uploadTmpPath, $uploadMime, true);


            $thumbExt = pathinfo($filename, PATHINFO_EXTENSION);
            if (in_array(strtolower($thumbExt), ['mp4', 'webm', 'mov'], true)) {
                $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            } else {
                $thumbFilename = $filename;
            }


            $s3->putObject(self::s3MediaKey('thumbs/' . $thumbFilename), $thumbTmpPath, $thumbMime, true);

            return true;
        }


        $destUpload = UPLOAD_DIR . '/' . $filename;
        if (!move_uploaded_file($uploadTmpPath, $destUpload)) {

            if (!copy($uploadTmpPath, $destUpload)) {
                throw new RuntimeException('Failed to save file to local upload directory.');
            }
            @unlink($uploadTmpPath);
        }
        chmod($destUpload, 0644);

        $thumbExt = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array(strtolower($thumbExt), ['mp4', 'webm', 'mov'], true)) {
            $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        } else {
            $thumbFilename = $filename;
        }

        $destThumb = THUMB_DIR . '/' . $thumbFilename;
        if ($thumbTmpPath !== $destThumb) {
            if (!rename($thumbTmpPath, $destThumb)) {
                copy($thumbTmpPath, $destThumb);
                @unlink($thumbTmpPath);
            }
            chmod($destThumb, 0644);
        }

        return true;
    }




    public static function deleteMedia(string $filename): void
    {
        $driver = self::getDriver();

        $thumbExt = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array(strtolower($thumbExt), ['mp4', 'webm', 'mov'], true)) {
            $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        } else {
            $thumbFilename = $filename;
        }

        if ($driver === 's3') {
            $s3 = self::getS3Client();
            if ($s3) {
                try {
                    $s3->deleteObject(self::s3MediaKey('uploads/' . $filename));
                    $s3->deleteObject(self::s3MediaKey('thumbs/' . $thumbFilename));
                } catch (Throwable) {}
            }
        } else {
            @unlink(UPLOAD_DIR . '/' . $filename);
            @unlink(THUMB_DIR . '/' . $thumbFilename);
        }
    }




    public static function getFileUrl(string $filename): string
    {
        return SITE_BASE . '/file/' . rawurlencode($filename);
    }




    public static function getThumbUrl(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['mp4', 'webm', 'mov'], true)) {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        }

        return SITE_BASE . '/thumb/' . rawurlencode($filename);
    }




    public static function serveFile(string $type, string $filename): void
    {
        $usesApiKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '') !== '';
        if (View::siteSetting('require_login_posts', '0') === '1' && !Auth::isLoggedIn()) {
            $apiKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
            $apiUser = $apiKey !== '' ? Auth::fromApiKey($apiKey) : null;
            if (!$apiUser) {
                http_response_code(401);
                header('Cache-Control: no-store');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Authentication required.';
                return;
            }
            Auth::setUser($apiUser);
        }



        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $driver = self::getDriver();
        $filename = basename($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isVideo = $type === 'upload' && in_array($ext, ['mp4', 'webm', 'mov'], true);

        if ($driver === 's3') {
            $s3 = self::getS3Client();
            if ($s3) {
                $key = self::s3MediaKey(($type === 'thumb' ? 'thumbs/' : 'uploads/') . $filename);




                if (($type === 'thumb' || $isVideo) && !$usesApiKey) {
                    $ttl = $type === 'thumb' ? S3_THUMB_URL_TTL : S3_VIDEO_URL_TTL;
                    $cacheControl = $type === 'thumb'
                        ? 'private, max-age=300'
                        : 'private, no-store';
                    header('Cache-Control: ' . $cacheControl);
                    header('Location: ' . $s3->getPresignedUrl($key, $ttl), true, 307);
                    return;
                }

                $mimeTypes = [
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
                    'mov' => 'video/quicktime'
                ];
                $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

                header('Content-Type: ' . $mime);
                header('Cache-Control: public, max-age=31536000, immutable');
                $s3->streamObject($key, $isVideo);
                exit;
            }
        }

        $dir = ($type === 'thumb') ? THUMB_DIR : UPLOAD_DIR;
        Router::serveFile($dir, $filename);
    }
}
