<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

destroySecureSession();
session_start();
$_SESSION['flash_success'] = 'You have been securely logged out.';
header('Location: index.php');
exit;