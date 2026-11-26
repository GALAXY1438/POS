<?php
require_once 'config.php';
User::requireLogin();
User::redirectToDashboard();
