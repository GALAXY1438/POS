<?php
/**
 * Root transactions.php — redirects to the unified admin transactions page.
 * Preserves all query parameters.
 */
require_once __DIR__ . '/config.php';
requireLogin();

$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . SITE_URL . '/admin/transactions.php' . $qs);
exit();
