<?php
/**
 * Root inventory.php — redirects to the unified admin inventory page.
 * Preserves all query parameters (tab, search, stock_filter, etc.)
 */
require_once __DIR__ . '/config.php';
requireLogin();

$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . SITE_URL . '/admin/inventory.php' . $qs);
exit();
