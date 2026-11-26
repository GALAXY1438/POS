<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();
// Sales Analysis → Reports page (Sales tab)
header('Location: reports.php?tab=sales');
exit();
