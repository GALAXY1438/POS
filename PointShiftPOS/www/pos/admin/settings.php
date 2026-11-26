<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();
header('Location: ../settings.php');
exit();

