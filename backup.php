<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/s3.php';
require_once __DIR__ . '/view.php';

class DatabaseBackup
{
    private const PREFIX = 'database-backups/';

    public static function isConfigured(): bool
    {
        return View::siteSetting('backup_s3_endpoint', '') !== ''
            && View::siteSetting('backup_s3_bucket', '') !== ''
            && View::siteSetting('backup_s3_access_key', '') !== ''
            && View::siteSetting('backup_s3_secret_key', '') !== '';
    }

    private static function client(): S3Client
    {
        $endpoint = View::siteSetting('backup_s3_endpoint', '');
        $region = View::siteSetting('backup_s3_region', 'us-east-1');
        $bucket = View::siteSetting('backup_s3_bucket', '');
        $accessKey = View::siteSetting('backup_s3_access_key', '');
        $secretKey = View::siteSetting('backup_s3_secret_key', '');

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('Configure the backup S3 bucket first.');
        }
        return new S3Client($endpoint, $region, $bucket, $accessKey, $secretKey);
    }

    public static function list(): array
    {
        if (!self::isConfigured()) return [];

        return array_values(array_filter(
            self::client()->listObjects(self::PREFIX),
            fn(array $object): bool => self::isBackupKey($object['key'])
        ));
    }

    public static function create(string $label = 'manual'): array
    {
        $label = preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)) ?: 'manual';
        $tempPath = tempnam(DATA_DIR, '.db-backup-');
        if ($tempPath === false) {
            throw new RuntimeException('Could not create a temporary backup file.');
        }
        @unlink($tempPath);

        try {
            $quotedPath = str_replace("'", "''", $tempPath);
            DB::get()->exec("VACUUM INTO '{$quotedPath}'");
            self::validateSnapshot($tempPath);

            $key = self::PREFIX
                . 'libooru-' . $label . '-'
                . gmdate('Ymd-His') . '-'
                . bin2hex(random_bytes(4))
                . '.sqlite';
            self::client()->putObject($key, $tempPath, 'application/vnd.sqlite3', true);

            return [
                'key' => $key,
                'size' => (int)filesize($tempPath),
            ];
        } finally {
            if (is_file($tempPath)) @unlink($tempPath);
        }
    }

    public static function restore(string $key): array
    {
        if (!self::isBackupKey($key)) {
            throw new RuntimeException('Invalid database backup key.');
        }

        $restorePath = tempnam(DATA_DIR, '.db-restore-');
        if ($restorePath === false) {
            throw new RuntimeException('Could not create a temporary restore file.');
        }

        try {
            self::client()->getObjectToFile($key, $restorePath);
            self::validateSnapshot($restorePath);

            // Do not replace the live DB until a fresh remote safety copy exists.
            $preRollback = self::create('pre-rollback');
            DB::get()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            DB::close();

            $emergencyPath = DATA_DIR
                . '/libooru.pre-rollback-'
                . gmdate('Ymd-His') . '-'
                . bin2hex(random_bytes(4))
                . '.sqlite';

            if (!rename(DB_PATH, $emergencyPath)) {
                throw new RuntimeException('Could not preserve the current database before rollback.');
            }

            @unlink(DB_PATH . '-wal');
            @unlink(DB_PATH . '-shm');

            if (!rename($restorePath, DB_PATH)) {
                rename($emergencyPath, DB_PATH);
                throw new RuntimeException('Could not activate the selected backup. The current database was restored.');
            }
            chmod(DB_PATH, 0640);
            $restorePath = '';

            return [
                'restored_key' => $key,
                'pre_rollback_key' => $preRollback['key'],
                'local_emergency_path' => $emergencyPath,
            ];
        } finally {
            if ($restorePath !== '' && is_file($restorePath)) @unlink($restorePath);
        }
    }

    private static function validateSnapshot(string $path): void
    {
        if (!is_file($path) || filesize($path) < 100) {
            throw new RuntimeException('The downloaded database backup is empty or invalid.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $check = $pdo->query('PRAGMA quick_check')->fetchColumn();
        if ($check !== 'ok') {
            throw new RuntimeException('SQLite integrity check failed for the selected backup.');
        }

        $requiredTables = ['users', 'posts', 'site_settings'];
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        foreach ($requiredTables as $table) {
            $statement->execute([$table]);
            if (!$statement->fetchColumn()) {
                throw new RuntimeException('The selected file is not a valid Libooru database backup.');
            }
        }
        $pdo = null;
    }

    private static function isBackupKey(string $key): bool
    {
        return (bool)preg_match(
            '#^' . preg_quote(self::PREFIX, '#') . 'libooru-[a-z0-9-]+-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sqlite$#',
            $key
        );
    }
}
