<?php
// CareerPath AI - Student logout handler
require __DIR__ . '/student_auth.php';

logout_student();
header('Location: student_login.php');
exit;
