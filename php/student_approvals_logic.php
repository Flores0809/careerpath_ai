<?php
// CareerPath AI - Student registration approvals + LRN class list (logic).
//
// Included by php/users.php (Manage Accounts > Approvals tab, administrators
// only). Staff upload the school's list of valid LRNs (CSV or Excel). A
// student who registers with an LRN on the list is activated automatically;
// any other registration waits as "pending" until an administrator approves
// or rejects it. While no list is uploaded, registration stays open.
//
// Expects $pdo, $currentUser and $message from the including page.

require_once __DIR__ . '/name_match.php';
require_once __DIR__ . '/grade_levels.php';

/** Normalise one spreadsheet cell to a 12-digit LRN, or null when invalid. */
function normalize_lrn($value): ?string
{
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    // Excel may hand over a long number in scientific notation (1.23E+11).
    if (preg_match('/^[0-9]+(\.[0-9]+)?[eE]\+?[0-9]+$/', $v)) {
        $v = sprintf('%.0f', (float) $v);
    }
    $v = preg_replace('/\D/', '', $v);
    return strlen($v) === 12 ? $v : null;
}

/** Reads the first sheet of an .xlsx file with no external library. */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot read .xlsx files. Save the sheet as CSV and upload that instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('That file is not a valid Excel (.xlsx) workbook.');
    }
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not find the first sheet in that workbook.');
    }
    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Could not read the sheet in that workbook.');
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $letters = preg_replace('/[^A-Z]/', '', strtoupper((string) $c['r']));
            $col = 0;
            for ($i = 0; $i < strlen($letters); $i++) {
                $col = $col * 26 + (ord($letters[$i]) - 64);
            }
            $col--;
            $type = (string) $c['t'];
            if ($type === 's') {
                $val = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) $c->is->t;
            } else {
                $val = (string) $c->v;
            }
            $cells[$col] = $val;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Reads a CSV file into rows of cells. */
function read_csv_rows(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Could not open the uploaded file.');
    }
    $first = true;
    while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($first && isset($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]); // strip BOM
            $first = false;
        }
        $rows[] = $data;
    }
    fclose($fh);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_roster') {
        $file = $_FILES['roster_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = ['type' => 'error', 'text' => 'Choose a CSV or Excel file to upload.'];
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = ['type' => 'error', 'text' => 'File is too large (5 MB maximum).'];
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            try {
                if ($ext === 'csv') {
                    $rows = read_csv_rows($file['tmp_name']);
                } elseif ($ext === 'xlsx') {
                    $rows = read_xlsx_rows($file['tmp_name']);
                } else {
                    throw new RuntimeException('Only .csv and .xlsx files are accepted.');
                }

                $valid = [];
                $skipped = 0;
                foreach ($rows as $r) {
                    $lrn = normalize_lrn($r[0] ?? '');
                    if ($lrn === null) {
                        $skipped++;
                        continue;
                    }
                    $name = trim((string) ($r[1] ?? ''));
                    $valid[$lrn] = $name !== '' ? mb_substr($name, 0, 150) : null;
                }

                if (!$valid) {
                    throw new RuntimeException('No valid 12-digit LRNs found in the first column. Format the LRN column as Text before saving.');
                }

                $pdo->beginTransaction();
                if (!empty($_POST['replace_list'])) {
                    $pdo->exec("DELETE FROM lrn_roster");
                }
                $ins = $pdo->prepare(
                    "INSERT INTO lrn_roster (lrn, name) VALUES (:lrn, :name)
                     ON DUPLICATE KEY UPDATE name = VALUES(name)"
                );
                foreach ($valid as $lrn => $name) {
                    $ins->execute(['lrn' => $lrn, 'name' => $name]);
                }
                // Anyone already waiting whose LRN is now on the list (and whose
                // name matches, when the list gives one) goes live.
                $auto = 0;
                $waiting = $pdo->query(
                    "SELECT s.student_id, s.name, r.name AS roster_name
                     FROM students s JOIN lrn_roster r ON r.lrn = s.student_number
                     WHERE s.status = 'pending'"
                )->fetchAll();
                $activate = $pdo->prepare("UPDATE students SET status = 'active' WHERE student_id = :id AND status = 'pending'");
                foreach ($waiting as $w) {
                    if (($w['roster_name'] ?? '') === '' || names_match($w['name'], $w['roster_name'])) {
                        $activate->execute(['id' => $w['student_id']]);
                        $auto++;
                    }
                }
                $pdo->commit();

                $text = count($valid) . ' LRNs loaded.';
                if ($auto) {
                    $text .= " $auto waiting account(s) were approved automatically.";
                }
                if ($skipped) {
                    $text .= " $skipped row(s) skipped (header or not a 12-digit LRN).";
                }
                $message = ['type' => 'success', 'text' => $text];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    } elseif ($action === 'clear_roster') {
        $pdo->exec("DELETE FROM lrn_roster");
        $message = ['type' => 'success', 'text' => 'Class list cleared. New registrations are active immediately again.'];
    } elseif (in_array($action, ['approve', 'reject', 'approve_selected', 'approve_all'], true)) {
        if ($action === 'approve_all') {
            $ids = array_map('intval', $pdo->query("SELECT student_id FROM students WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($action === 'approve_selected') {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        } else {
            $ids = [(int) ($_POST['student_id'] ?? 0)];
        }
        $newStatus = $action === 'reject' ? 'disabled' : 'active';
        $done = 0;
        $sel = $pdo->prepare("SELECT * FROM students WHERE student_id = :id");
        $upd = $pdo->prepare("UPDATE students SET status = :s WHERE student_id = :id AND status = 'pending'");
        foreach ($ids as $id) {
            $sel->execute(['id' => $id]);
            $row = $sel->fetch();
            if (!$row || $row['status'] !== 'pending') {
                continue;
            }
            if ($action === 'reject') {
                // Rejecting moves the pending account into rejected_registrations
                // and removes it from `students`, which frees the email and LRN
                // so the student can register again. A pending account has never
                // logged in, so there is no history to lose.
                $reason = trim((string) ($_POST['reason'] ?? ''));
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        "INSERT INTO rejected_registrations
                            (name, student_number, email, password_hash, grade_level, age, registered_at, rejected_by, reason)
                         VALUES (:name, :lrn, :email, :hash, :grade, :age, :reg, :by, :reason)"
                    )->execute([
                        'name' => $row['name'], 'lrn' => $row['student_number'], 'email' => $row['email'],
                        'hash' => $row['password_hash'], 'grade' => $row['grade_level'], 'age' => $row['age'],
                        'reg' => $row['created_at'], 'by' => $currentUser['user_id'],
                        'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                    ]);
                    $pdo->prepare("DELETE FROM students WHERE student_id = :id AND status = 'pending'")->execute(['id' => $id]);
                    $pdo->commit();
                    $done++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('student_approvals_logic.php reject failed — ' . $e->getMessage());
                    $message = ['type' => 'error', 'text' => 'Could not reject that account. Make sure migration_29_rejected_registrations.sql has been run.'];
                    break;
                }
                continue;
            }
            $upd->execute(['s' => $newStatus, 'id' => $id]);
            log_change($pdo, 'students', $id, $row['name'], 'update', ['status' => 'pending'], ['status' => $newStatus], $currentUser['user_id']);
            $done++;
        }
        if (!$message) {
            $message = $done
                ? ['type' => 'success', 'text' => $done . ($newStatus === 'active' ? ' account(s) approved.' : ' account(s) rejected and moved to the Rejected list. The student can register again.')]
                : ['type' => 'error', 'text' => 'No pending accounts were selected.'];
        }
    } elseif ($action === 'restore_rejected' || $action === 'purge_rejected') {
        $rid = (int) ($_POST['rejection_id'] ?? 0);
        $rs = $pdo->prepare("SELECT * FROM rejected_registrations WHERE rejection_id = :id");
        $rs->execute(['id' => $rid]);
        $rej = $rs->fetch();
        if (!$rej) {
            $message = ['type' => 'error', 'text' => 'That rejected registration no longer exists.'];
        } elseif ($action === 'purge_rejected') {
            $pdo->prepare("DELETE FROM rejected_registrations WHERE rejection_id = :id")->execute(['id' => $rid]);
            $message = ['type' => 'success', 'text' => 'Rejected record permanently removed.'];
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO students (name, student_number, email, password_hash, grade_level, age, status)
                     VALUES (:name, :lrn, :email, :hash, :grade, :age, 'pending')"
                )->execute([
                    'name' => $rej['name'], 'lrn' => $rej['student_number'], 'email' => $rej['email'],
                    'hash' => $rej['password_hash'], 'grade' => $rej['grade_level'], 'age' => $rej['age'],
                ]);
                $pdo->prepare("DELETE FROM rejected_registrations WHERE rejection_id = :id")->execute(['id' => $rid]);
                $pdo->commit();
                $message = ['type' => 'success', 'text' => 'Registration restored to the waiting list.'];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'error', 'text' => (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Cannot restore: that email or LRN has since been used by another account.'
                    : 'Could not restore that registration.'];
            }
        }
    } elseif ($action === 'edit_pending') {
        // Admin corrects a waiting account's details after confirming with
        // the student (e.g. a mistyped LRN or name). Stays pending; approve afterwards.
        $id = (int) ($_POST['student_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $lrn = trim($_POST['student_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $grade = trim($_POST['grade_level'] ?? '');
        $ageRaw = trim($_POST['age'] ?? '');

        $sel = $pdo->prepare("SELECT name, student_number, email, grade_level, age, status FROM students WHERE student_id = :id");
        $sel->execute(['id' => $id]);
        $old = $sel->fetch();

        if (!$old || $old['status'] !== 'pending') {
            $message = ['type' => 'error', 'text' => 'That account is no longer waiting for approval.'];
        } elseif ($name === '' || $lrn === '' || $email === '' || $grade === '' || $ageRaw === '') {
            $message = ['type' => 'error', 'text' => 'Name, LRN, email, grade level and age are all required.'];
        } elseif (!ctype_digit($lrn) || strlen($lrn) !== 12) {
            $message = ['type' => 'error', 'text' => 'LRN must be exactly 12 digits.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ['type' => 'error', 'text' => 'Enter a valid email address.'];
        } elseif (!ctype_digit($ageRaw) || (int) $ageRaw < 10 || (int) $ageRaw > 25) {
            $message = ['type' => 'error', 'text' => 'Enter a valid age (10-25).'];
        } elseif (!in_array($grade, $gradeLevelOptions, true) && $grade !== $old['grade_level']) {
            $message = ['type' => 'error', 'text' => 'Select a valid grade level from the list.'];
        } else {
            try {
                $upd = $pdo->prepare(
                    "UPDATE students SET name = :name, student_number = :lrn, email = :email, grade_level = :grade, age = :age
                     WHERE student_id = :id AND status = 'pending'"
                );
                $upd->execute(['name' => $name, 'lrn' => $lrn, 'email' => $email, 'grade' => $grade, 'age' => (int) $ageRaw, 'id' => $id]);
                log_change(
                    $pdo, 'students', $id, $name, 'update',
                    ['name' => $old['name'], 'student_number' => $old['student_number'], 'email' => $old['email'], 'grade_level' => $old['grade_level'], 'age' => $old['age']],
                    ['name' => $name, 'student_number' => $lrn, 'email' => $email, 'grade_level' => $grade, 'age' => (int) $ageRaw],
                    $currentUser['user_id']
                );
                $message = ['type' => 'success', 'text' => 'Account details updated. Check the class-list columns, then approve.'];
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $message = ['type' => 'error', 'text' => str_contains($e->getMessage(), 'email')
                        ? 'That email is already used by another account.'
                        : 'That LRN is already registered to another account.'];
                } else {
                    error_log('student_approvals_logic.php edit_pending failed — ' . $e->getMessage());
                    $message = ['type' => 'error', 'text' => 'Could not save the changes due to a system error.'];
                }
            }
        }
    }
}

try {
    $rosterCount = (int) $pdo->query("SELECT COUNT(*) FROM lrn_roster")->fetchColumn();
    $tablesReady = true;
} catch (PDOException $e) {
    $rosterCount = 0;
    $tablesReady = false;
}

$pending = $pdo->query(
    "SELECT s.student_id, s.name, s.student_number, s.email, s.grade_level, s.age, s.created_at,
            r.name AS roster_name, (r.lrn IS NOT NULL) AS on_list
     FROM students s
     LEFT JOIN lrn_roster r ON r.lrn = s.student_number
     WHERE s.status = 'pending' ORDER BY s.created_at ASC"
)->fetchAll();

try {
    $rejected = $pdo->query(
        "SELECT r.*, u.name AS rejected_by_name
         FROM rejected_registrations r
         LEFT JOIN users u ON u.user_id = r.rejected_by
         ORDER BY r.rejected_at DESC"
    )->fetchAll();
} catch (PDOException $e) {
    $rejected = []; // migration_29 not run yet
}
