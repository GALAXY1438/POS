<?php
/**
 * Root reports.php — redirects to unified admin reports page.
 */
require_once __DIR__ . '/config.php';
requireLogin();
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . SITE_URL . '/admin/reports.php' . $qs);
exit();
