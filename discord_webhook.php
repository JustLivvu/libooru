<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view.php';

class DiscordWebhook
{
    public static function isValidUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true)) return false;
        return (bool)preg_match('#^/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9._-]+/?$#', (string)($parts['path'] ?? ''));
    }

    public static function notifyNewPost(int $postId): bool
    {
        if (View::siteSetting('discord_webhook_enabled', '0') !== '1') return false;
        $url = View::siteSetting('discord_webhook_url', '');
        if (!self::isValidUrl($url)) return false;

        $post = DB::row('SELECT id FROM posts WHERE id = ?', [$postId]);
        if (!$post) return false;
        $tags = array_column(DB::rows(
            'SELECT t.name FROM tags t INNER JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name',
            [$postId]
        ), 'name');

        $baseUrl = rtrim(View::siteSetting('webhook_site_url', ''), '/');
        $postUrl = $baseUrl !== ''
            ? $baseUrl . '/post/' . $postId
            : View::absoluteUrl(View::url('/post/' . $postId));
        $tagText = $tags ? implode(', ', $tags) : 'none';
        if (strlen($tagText) > 1700) $tagText = substr($tagText, 0, 1697) . '...';
        $siteName = View::siteSetting('site_name', SITE_NAME);

        return self::send($url, [
            'username' => $siteName,
            'content' => "**New post #{$postId}**\n{$postUrl}\n**Tags:** {$tagText}",
            'allowed_mentions' => ['parse' => []],
        ]);
    }

    public static function sendTest(): bool
    {
        $url = View::siteSetting('discord_webhook_url', '');
        if (!self::isValidUrl($url)) return false;
        return self::send($url, [
            'username' => View::siteSetting('site_name', SITE_NAME),
            'content' => 'Discord webhook connected successfully.',
            'allowed_mentions' => ['parse' => []],
        ]);
    }

    private static function send(string $url, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) return false;
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 7,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $result = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            return $result !== false && $status >= 200 && $status < 300;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 7,
                'ignore_errors' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match) ? (int)$match[1] : 0;
        return $result !== false && $status >= 200 && $status < 300;
    }
}
