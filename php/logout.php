<?php
// CareerPath AI - Logout handler
require __DIR__ . '/auth.php';

logout_user();
header('Location: login.php');
exit;
