<?php
require_once __DIR__ . '/config.php';
requireLogin();
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . SITE_URL . '/admin/user_management.php' . $qs);
exit();
