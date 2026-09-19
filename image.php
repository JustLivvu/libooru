<?php
declare(strict_types=1);




class Image
{
    public static function thumbPath(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (in_array($ext, ['mp4', 'webm', 'mov'], true)) {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        }
        return THUMB_DIR . '/' . $filename;
    }

    public static function uploadPath(string $filename): string
    {
        return UPLOAD_DIR . '/' . $filename;
    }

    public static function thumbUrl(string $filename): string
    {
        return Storage::getThumbUrl($filename);
    }

    public static function fileUrl(string $filename): string
    {
        return Storage::getFileUrl($filename);
    }





    public static function makeThumbnail(string $srcPath, string $destPath, string $mime): bool
    {
        $tw = THUMB_WIDTH;
        $th = THUMB_HEIGHT;

        if (str_starts_with($mime, 'video/')) {

            $cmd = sprintf(
                'timeout %ds ffmpeg -i %s -ss 00:00:00.000 -vframes 1 -vf "scale=\'max(%d,a*%d)\':\'max(%d,%d/a)\',crop=%d:%d" -q:v 2 -y %s 2>/dev/null',
                MEDIA_PROCESS_TIMEOUT, escapeshellarg($srcPath), $tw, $tw, $th, $th, $tw, $th, escapeshellarg($destPath)
            );
            exec($cmd, $output, $ret);
            return $ret === 0 && file_exists($destPath);
        }

        [$origW, $origH, $type] = getimagesize($srcPath);
        if (!$origW || !$origH) return false;

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($srcPath),
            'image/png'  => imagecreatefrompng($srcPath),
            'image/gif'  => imagecreatefromgif($srcPath),
            'image/webp' => imagecreatefromwebp($srcPath),
            default      => false,
        };
        if (!$src) return false;


        if ($origW > $origH) {
            $cropX = (int)(($origW - $origH) / 2);
            $cropY = 0;
            $cropS = $origH;
        } else {
            $cropX = 0;
            $cropY = (int)(($origH - $origW) / 2);
            $cropS = $origW;
        }

        $thumb = imagecreatetruecolor($tw, $th);
        if (!$thumb) { imagedestroy($src); return false; }


        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $tw, $th, $transparent);
        }

        imagecopyresampled($thumb, $src, 0, 0, $cropX, $cropY, $tw, $th, $cropS, $cropS);

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $destPath, 95),
            'image/png'  => imagepng($thumb, $destPath, 4),
            'image/gif'  => imagegif($thumb, $destPath),
            'image/webp' => imagewebp($thumb, $destPath, 92),
            default      => false,
        };

        imagedestroy($src);
        imagedestroy($thumb);
        return (bool)$ok;
    }

    public static function getDimensions(string $path, string $mime): array
    {
        if (str_starts_with($mime, 'video/')) {
            $cmd = sprintf(
                'timeout %ds ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 %s 2>/dev/null',
                MEDIA_PROCESS_TIMEOUT, escapeshellarg($path)
            );
            $out = trim(shell_exec($cmd) ?: '');
            if ($out && str_contains($out, 'x')) {
                [$w, $h] = explode('x', $out, 2);
                return [(int)$w, (int)$h];
            }
            return [0, 0];
        }

        [$w, $h] = getimagesize($path) ?: [0, 0];
        return [(int)$w, (int)$h];
    }
}
