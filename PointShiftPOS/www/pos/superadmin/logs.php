<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireSuperAdmin();
header('Location: /pos/activity_logs.php');
exit();
