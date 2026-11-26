<?php
/**
 * BackupManager — plain-SQL backups via the bundled pg_dump / psql.
 * Used by the Settings → Backup & Restore tab.
 */
class BackupManager
{
    public static function backupDir(): string
    {
        $dir = self::rootDir() . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function listBackups(): array
    {
        $files = glob(self::backupDir() . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $rows  = [];
        foreach ($files as $file) {
            $rows[] = [
                'name' => basename($file),
                'size' => (int)filesize($file),
                'time' => (int)filemtime($file),
            ];
        }
        usort($rows, fn($a, $b) => $b['time'] <=> $a['time']);
        return $rows;
    }

    public static function resolveBackupPath(string $file): ?string
    {
        $file = basename(trim($file));
        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file)) {
            return null;
        }
        $path = self::backupDir() . DIRECTORY_SEPARATOR . $file;
        $real = realpath($path);
        $dir  = realpath(self::backupDir());
        if ($real === false || $dir === false || !str_starts_with($real, $dir)) {
            return null;
        }
        return $real;
    }

    public static function createBackup(string $prefix = 'backup'): string
    {
        $pgDump = self::binDir() . DIRECTORY_SEPARATOR . 'pg_dump.exe';
        if (!is_file($pgDump)) {
            throw new RuntimeException('pg_dump was not found in the runtime folder.');
        }

        $stamp = date('Ymd_His');
        $file  = self::backupDir() . DIRECTORY_SEPARATOR . $prefix . '_' . $stamp . '.sql';
        if (file_exists($file)) {
            $file = self::backupDir() . DIRECTORY_SEPARATOR . $prefix . '_' . $stamp . '_' . substr(uniqid(), -4) . '.sql';
        }

        $cmd = escapeshellarg($pgDump)
             . ' -h ' . escapeshellarg(DB_HOST)
             . ' -p ' . (int)DB_PORT
             . ' -U ' . escapeshellarg(DB_USER)
             . ' -d ' . escapeshellarg(DB_NAME)
             . ' -f ' . escapeshellarg($file)
             . ' 2>&1';

        [$code, $output] = self::runCommand($cmd);
        if ($code !== 0 || !is_file($file) || filesize($file) < 200) {
            @unlink($file);
            throw new RuntimeException('Could not create the backup. ' . self::tail($output));
        }
        return $file;
    }

    /**
     * Replace the live database with the uploaded SQL dump.
     * A safety backup is taken first and used to roll back on failure.
     */
    public static function restore(string $uploadedPath, string $originalName = ''): void
    {
        if (!is_file($uploadedPath)) {
            throw new RuntimeException('The uploaded file was not received.');
        }
        if ($originalName !== '' && !preg_match('/\.sql$/i', $originalName)) {
            throw new RuntimeException('Please upload a .sql backup file.');
        }
        if (filesize($uploadedPath) < 200) {
            throw new RuntimeException('That file is too small to be a valid backup.');
        }

        $head = (string)file_get_contents($uploadedPath, false, null, 0, 4096);
        if (stripos($head, 'PostgreSQL database dump') === false) {
            throw new RuntimeException('That does not look like a TechShift backup file (missing PostgreSQL dump header).');
        }

        $safety = self::createBackup('pre_restore');

        [$code, $out] = self::psql('-c ' . escapeshellarg('DROP SCHEMA public CASCADE; CREATE SCHEMA public;'));
        if ($code !== 0) {
            throw new RuntimeException('Could not clear the old database. ' . self::tail($out));
        }

        [$code, $out] = self::psql('-f ' . escapeshellarg($uploadedPath));
        if ($code !== 0) {
            $rolledBack = self::rollback($safety);
            throw new RuntimeException(
                'The backup could not be loaded. ' . self::tail($out)
                . ($rolledBack ? ' Your previous data was restored.' : ' Automatic rollback failed — safety backup: ' . basename($safety))
            );
        }

        // Bring older backups up to the current schema (e.g. added brand column).
        $migrate = self::rootDir() . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'migrate.sql';
        if (is_file($migrate)) {
            [$code, $out] = self::psql('-f ' . escapeshellarg($migrate));
            if ($code !== 0) {
                throw new RuntimeException('Backup restored, but the schema update failed. ' . self::tail($out));
            }
        }
    }

    private static function rollback(string $safetyFile): bool
    {
        [$code] = self::psql('-c ' . escapeshellarg('DROP SCHEMA public CASCADE; CREATE SCHEMA public;'));
        if ($code !== 0) {
            return false;
        }
        [$code] = self::psql('-f ' . escapeshellarg($safetyFile));
        return $code === 0;
    }

    private static function psql(string $extraArgs): array
    {
        $psql = self::binDir() . DIRECTORY_SEPARATOR . 'psql.exe';
        if (!is_file($psql)) {
            throw new RuntimeException('psql was not found in the runtime folder.');
        }
        $cmd = escapeshellarg($psql)
             . ' -h ' . escapeshellarg(DB_HOST)
             . ' -p ' . (int)DB_PORT
             . ' -U ' . escapeshellarg(DB_USER)
             . ' -d ' . escapeshellarg(DB_NAME)
             . ' -v ON_ERROR_STOP=1 '
             . $extraArgs
             . ' 2>&1';
        return self::runCommand($cmd);
    }

    private static function runCommand(string $cmd): array
    {
        $old = getenv('PGPASSWORD');
        putenv('PGPASSWORD=' . DB_PASS);
        try {
            $output = [];
            $code   = 0;
            exec($cmd, $output, $code);
            return [$code, implode("\n", $output)];
        } finally {
            putenv($old === false ? 'PGPASSWORD' : 'PGPASSWORD=' . $old);
        }
    }

    private static function tail(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $text);
        return implode(' ', array_slice($lines, -3));
    }

    private static function rootDir(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function binDir(): string
    {
        return self::rootDir() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
    }
}
