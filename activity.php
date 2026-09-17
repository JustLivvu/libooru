<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

class Activity
{
    public static function requestIp(): string
    {
        // The web server resolves trusted proxies; never trust client-supplied IP headers here.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function country(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
        $cached = DB::row('SELECT country_code FROM ip_country_cache WHERE ip = ? AND expires_at > ?', [$ip, time()]);
        if ($cached) return $cached['country_code'];

        $code = '';
        // Keep below the provider's free quota, including failed requests.
        if (DB::consumeRateLimit('ip_country_lookup', 'server', 900, 86400)) {
            $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $body = @file_get_contents('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country_code', false, $context);
            $result = $body === false ? null : json_decode($body, true);
            if (is_array($result) && ($result['success'] ?? false) === true
                && is_string($result['country_code'] ?? null) && preg_match('/^[A-Z]{2}$/D', $result['country_code'])) {
                $code = $result['country_code'];
            }
        }
        DB::exec('INSERT INTO ip_country_cache (ip, country_code, expires_at) VALUES (?, ?, ?)
            ON CONFLICT(ip) DO UPDATE SET country_code = excluded.country_code, expires_at = excluded.expires_at',
            [$ip, $code, time() + ($code === '' ? 3600 : 30 * 86400)]);
        return $code;
    }

    public static function recordLogin(array $user): void
    {
        $ip = self::requestIp();
        $country = self::country($ip);
        if ($country === '' && $ip !== '' && $ip === ($user['last_ip'] ?? '')) {
            $country = $user['country_code'] ?? '';
        }
        $pdo = DB::get();
        $pdo->beginTransaction();
        try {
            DB::exec('UPDATE users SET last_ip = ?, country_code = ? WHERE id = ?', [$ip, $country, (int)$user['id']]);
            DB::exec('INSERT INTO login_events (user_id) VALUES (?)', [(int)$user['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function flag(string $code): string
    {
        if (!preg_match('/^[A-Z]{2}$/D', $code)) return '';
        return mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8')
            . mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
    }

    public static function statistics(?int $now = null): array
    {
        $now ??= time();
        $timezone = new DateTimeZone(ACTIVITY_TIMEZONE);
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0)->getTimestamp();
        $counts = DB::row('SELECT
            COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS today,
            COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS last_12h,
            COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS last_6h,
            COUNT(DISTINCT CASE WHEN created_at >= ? THEN user_id END) AS last_1h
            FROM login_events WHERE created_at >= ? AND created_at <= ?',
            [$today, $now - 43200, $now - 21600, $now - 3600, min($today, $now - 43200), $now]);
        $start = $now - 86400;
        $rows = DB::rows('SELECT min(23, CAST((created_at - ?) / 3600 AS INTEGER)) AS bucket, COUNT(DISTINCT user_id) AS users
            FROM login_events WHERE created_at >= ? AND created_at <= ? GROUP BY bucket', [$start, $start, $now]);
        $buckets = array_column($rows, 'users', 'bucket');
        $hours = [];
        for ($i = 0; $i < 24; $i++) {
            $from = (new DateTimeImmutable('@' . ($start + $i * 3600)))->setTimezone($timezone);
            $to = (new DateTimeImmutable('@' . ($start + ($i + 1) * 3600)))->setTimezone($timezone);
            $hours[] = ['label' => $from->format('d M H:i') . ' – ' . $to->format('H:i T'), 'users' => (int)($buckets[$i] ?? 0)];
        }
        return ['counts' => array_map('intval', $counts), 'hours' => $hours];
    }
}
