<?php
/**
 * Streams database backups to the browser (admin only).
 */
require_once __DIR__ . '/../config.php';
requireLogin();
requireAdmin();

$action = $_GET['action'] ?? '';

try {
    if ($action === 'download') {
        $file         = BackupManager::createBackup('backup');
        $downloadName = 'TechShiftPOS-backup-' . date('Y-m-d-His') . '.sql';
    } elseif ($action === 'download_existing') {
        $file = BackupManager::resolveBackupPath((string)($_GET['file'] ?? ''));
        if ($file === null) {
            http_response_code(404);
            echo 'Backup file not found.';
            exit;
        }
        $downloadName = basename($file);
    } else {
        header('Location: ../settings.php?tab=backup');
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Backup failed: ' . $e->getMessage();
    exit;
}
