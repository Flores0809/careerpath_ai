<?php
// CareerPath AI - Notification Module helpers
// --------------------------------------------------------------------
// A Gantt-chart item not named anywhere in the capstone paper's ERD or Use
// Case Diagram (see README for the alignment note) — built anyway since
// it's on the group's Software Development list. Deliberately simple:
// in-app only (no email/SMS), one flat table, read/unread per audience.

require_once __DIR__ . '/db.php';

/** Create a notification for a single student. */
function notify_student(PDO $pdo, int $studentId, string $message, ?string $link = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (audience, student_id, message, link) VALUES ('student', :student_id, :message, :link)"
    );
    $stmt->execute(['student_id' => $studentId, 'message' => $message, 'link' => $link]);
}

/** Create a notification for one staff account, or broadcast to all staff (counselor + administrator) if $userId is null. */
function notify_staff(PDO $pdo, ?int $userId, string $message, ?string $link = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (audience, user_id, message, link) VALUES ('staff', :user_id, :message, :link)"
    );
    $stmt->execute(['user_id' => $userId, 'message' => $message, 'link' => $link]);
}

/** Unread count for the logged-in student (used for the nav badge). */
function student_unread_count(PDO $pdo, int $studentId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE audience = 'student' AND student_id = :id AND is_read = 0");
    $stmt->execute(['id' => $studentId]);
    return (int) $stmt->fetchColumn();
}

/** Unread count for the logged-in staff account: their own + any broadcast-to-all-staff ones. */
function staff_unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE audience = 'staff' AND (user_id = :id OR user_id IS NULL) AND is_read = 0"
    );
    $stmt->execute(['id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function student_notifications(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE audience = 'student' AND student_id = :id ORDER BY created_at DESC LIMIT 50");
    $stmt->execute(['id' => $studentId]);
    return $stmt->fetchAll();
}

function staff_notifications(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM notifications WHERE audience = 'staff' AND (user_id = :id OR user_id IS NULL) ORDER BY created_at DESC LIMIT 50"
    );
    $stmt->execute(['id' => $userId]);
    return $stmt->fetchAll();
}

function mark_notification_read(PDO $pdo, int $notificationId): void
{
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = :id");
    $stmt->execute(['id' => $notificationId]);
}
