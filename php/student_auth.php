<?php
// CareerPath AI - Student session helpers
//
// Deliberately separate from auth.php (staff administrator/counselor
// sessions). Students are a different login domain entirely, so this uses
// its own $_SESSION keys (student_id/student_name/...) to avoid any chance
// of colliding with, or being confused with, staff sessions on the same
// browser/site.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/** Returns the logged-in student's session info, or null if not logged in. */
function current_student(): ?array
{
    if (!isset($_SESSION['student_id'])) {
        return null;
    }
    return [
        'student_id' => $_SESSION['student_id'],
        'name' => $_SESSION['student_name'],
        'email' => $_SESSION['student_email'],
        'grade_level' => $_SESSION['student_grade_level'] ?? null,
    ];
}

/** Redirects to student_login.php (preserving the current URL) if not logged in. */
function require_student_login(): array
{
    $student = current_student();
    if (!$student) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header("Location: student_login.php?next=$next");
        exit;
    }
    return $student;
}

/** Logs a student in: sets session vars and rotates the session ID (prevents session fixation). */
function login_student(array $studentRow): void
{
    session_regenerate_id(true);
    $_SESSION['student_id'] = (int) $studentRow['student_id'];
    $_SESSION['student_name'] = $studentRow['name'];
    $_SESSION['student_email'] = $studentRow['email'];
    $_SESSION['student_grade_level'] = $studentRow['grade_level'] ?? null;
}

/** Logs the current student out and destroys their session data. */
function logout_student(): void
{
    unset(
        $_SESSION['student_id'],
        $_SESSION['student_name'],
        $_SESSION['student_email'],
        $_SESSION['student_grade_level']
    );
}
