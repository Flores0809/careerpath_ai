<?php
// CareerPath AI - Manage Career Categories (counselors & administrators)
//
// career_categories is the lookup table backing careers.career_category /
// pending_careers.career_category (migration_13_category_management.sql).
// Before this page existed, a category was just whatever string a counselor
// typed into a free-text field on careers.php / careers_manage.php, guided
// only by a hardcoded suggestion list — no way to see the full set, describe
// what a category actually covers, rename one, or remove it. This page is
// that missing management surface.
//
// Not a hard foreign key on purpose: careers.career_category stays plain
// VARCHAR so this migration didn't have to touch every existing row under a
// constraint. Consistency is enforced here instead — renaming a category
// cascades to every career/pending_career using the old name, and deleting
// a category is blocked while any career still references it.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/change_log_helper.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $message = ['type' => 'error', 'text' => 'Category name cannot be empty.'];
        } else {
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM career_categories WHERE name = :name");
            $existsStmt->execute(['name' => $name]);
            if ($existsStmt->fetchColumn() > 0) {
                $message = ['type' => 'error', 'text' => "\"$name\" already exists as a category."];
            } else {
                $stmt = $pdo->prepare("INSERT INTO career_categories (name, description) VALUES (:name, :description)");
                $stmt->execute(['name' => $name, 'description' => $description !== '' ? $description : null]);
                $newCategoryId = (int) $pdo->lastInsertId();
                $newCatStmt = $pdo->prepare("SELECT * FROM career_categories WHERE category_id = :id");
                $newCatStmt->execute(['id' => $newCategoryId]);
                log_change($pdo, 'career_categories', $newCategoryId, $name, 'insert', null, $newCatStmt->fetch(), $currentUser['user_id']);
                $message = ['type' => 'success', 'text' => "Added category \"$name\"."];
            }
        }
    } elseif ($action === 'update') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $newName = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $currentStmt = $pdo->prepare("SELECT * FROM career_categories WHERE category_id = :id");
        $currentStmt->execute(['id' => $categoryId]);
        $oldRow = $currentStmt->fetch();
        $oldName = $oldRow ? $oldRow['name'] : false;

        if ($oldName === false) {
            $message = ['type' => 'error', 'text' => 'Category not found.'];
        } elseif ($newName === '') {
            $message = ['type' => 'error', 'text' => 'Category name cannot be empty.'];
        } else {
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM career_categories WHERE name = :name AND category_id != :id");
            $dupStmt->execute(['name' => $newName, 'id' => $categoryId]);
            if ($newName !== $oldName && $dupStmt->fetchColumn() > 0) {
                $message = ['type' => 'error', 'text' => "\"$newName\" is already used by another category."];
            } else {
                try {
                    $pdo->beginTransaction();

                    if ($newName !== $oldName) {
                        // Rename cascades to every career/pending_career currently
                        // tagged with the old name, so nothing silently becomes
                        // "uncategorized" just because the cluster was renamed.
                        $pdo->prepare("UPDATE careers SET career_category = :new WHERE career_category = :old")
                            ->execute(['new' => $newName, 'old' => $oldName]);
                        $pdo->prepare("UPDATE pending_careers SET career_category = :new WHERE career_category = :old")
                            ->execute(['new' => $newName, 'old' => $oldName]);
                    }

                    $pdo->prepare("UPDATE career_categories SET name = :name, description = :description WHERE category_id = :id")
                        ->execute(['name' => $newName, 'description' => $description !== '' ? $description : null, 'id' => $categoryId]);

                    if ($oldRow) {
                        $newCatStmt = $pdo->prepare("SELECT * FROM career_categories WHERE category_id = :id");
                        $newCatStmt->execute(['id' => $categoryId]);
                        log_change($pdo, 'career_categories', $categoryId, $newName, 'update', $oldRow, $newCatStmt->fetch(), $currentUser['user_id']);
                    }

                    $pdo->commit();
                    $message = ['type' => 'success', 'text' => "Updated \"$newName\"."];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = ['type' => 'error', 'text' => 'Could not save changes. Please try again.'];
                }
            }
        }
    } elseif ($action === 'delete') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $rowStmt = $pdo->prepare("SELECT * FROM career_categories WHERE category_id = :id");
        $rowStmt->execute(['id' => $categoryId]);
        $oldRow = $rowStmt->fetch();
        $name = $oldRow ? $oldRow['name'] : false;

        if ($name === false) {
            $message = ['type' => 'error', 'text' => 'Category not found.'];
        } else {
            $liveStmt = $pdo->prepare("SELECT COUNT(*) FROM careers WHERE career_category = :name");
            $liveStmt->execute(['name' => $name]);
            $liveCount = (int) $liveStmt->fetchColumn();

            $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM pending_careers WHERE career_category = :name");
            $pendingStmt->execute(['name' => $name]);
            $pendingCount = (int) $pendingStmt->fetchColumn();

            if ($liveCount > 0 || $pendingCount > 0) {
                $parts = [];
                if ($liveCount > 0) $parts[] = "$liveCount live career" . ($liveCount === 1 ? '' : 's');
                if ($pendingCount > 0) $parts[] = "$pendingCount pending career" . ($pendingCount === 1 ? '' : 's');
                $message = ['type' => 'error', 'text' => "Can't delete \"$name\" — " . implode(' and ', $parts) . " still use it. Reassign them first in Manage Careers / Career Review."];
            } else {
                $pdo->prepare("DELETE FROM career_categories WHERE category_id = :id")->execute(['id' => $categoryId]);
                log_change($pdo, 'career_categories', $categoryId, $name, 'delete', $oldRow, null, $currentUser['user_id']);
                $message = ['type' => 'success', 'text' => "Deleted category \"$name\"."];
            }
        }
    }
}

$categories = $pdo->query(
    "SELECT cc.*,
        (SELECT COUNT(*) FROM careers WHERE career_category = cc.name) AS live_count,
        (SELECT COUNT(*) FROM pending_careers WHERE career_category = cc.name) AS pending_count
     FROM career_categories cc
     ORDER BY cc.name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Manage Categories</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1100px; margin: 0 auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 24px; }

    .panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 22px 26px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 14px; color: #6e1423; font-size: 16px; }

    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    label { display: block; font-size: 13px; font-weight: bold; margin: 12px 0 4px; }
    label:first-child { margin-top: 0; }
    input[type=text], textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    textarea { min-height: 50px; }
    button.btn { padding: 8px 18px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; margin-right: 8px; }
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-outline { background: #fff; color: #6e1423; border: 1px solid #6e1423; }
    .btn-danger { background: #fff; color: #b02a37; border: 1px solid #b02a37; }
    .actions { margin-top: 14px; }

    .category-row { border: 1px solid #eee; border-radius: 8px; padding: 14px 18px; margin-bottom: 12px; }
    .category-row summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; font-size: 14px; gap: 10px; }
    .category-row summary::-webkit-details-marker { display: none; }
    .category-row summary .title { font-weight: bold; color: #6e1423; font-size: 15px; }
    .category-row summary .desc { color: #666; font-size: 12.5px; font-weight: normal; margin-top: 2px; }
    .category-row summary .no-desc { color: #aaa; font-style: italic; font-weight: normal; }
    .count-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #f0dde1; color: #6e1423; white-space: nowrap; }
    .count-tag.zero { background: #eee; color: #888; }
    .empty { color: #888; font-style: italic; font-size: 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <div class="wrap">
        <?php require __DIR__ . '/nav.php'; ?>

        <h1>Manage Categories</h1>
        <p class="subtitle">Add, rename, describe, or remove the industry/job clusters careers are grouped under. These power the "Field / Industry" picker students see before taking the assessment.</p>

        <?php if ($message): ?>
            <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
        <?php endif; ?>

        <div class="panel">
            <h2>Add a New Category</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <label>Name</label>
                <input type="text" name="name" placeholder="e.g. Maritime & Logistics" required>
                <label>Description <span style="font-weight:normal;color:#888;">(what kinds of careers belong here — shown to staff, helps keep categorization consistent)</span></label>
                <textarea name="description" placeholder="e.g. Careers in shipping, ports, freight, and supply chain logistics."></textarea>
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>

        <?php if (!$categories): ?>
            <p class="empty">No categories yet — add one above.</p>
        <?php endif; ?>

        <?php foreach ($categories as $cat): ?>
            <?php $totalUses = (int) $cat['live_count'] + (int) $cat['pending_count']; ?>
            <details class="category-row">
                <summary>
                    <span>
                        <div class="title"><?= htmlspecialchars($cat['name']) ?></div>
                        <?php if ($cat['description']): ?>
                            <div class="desc"><?= htmlspecialchars($cat['description']) ?></div>
                        <?php else: ?>
                            <div class="desc no-desc">No description yet</div>
                        <?php endif; ?>
                    </span>
                    <span class="count-tag <?= $totalUses === 0 ? 'zero' : '' ?>">
                        <?= (int) $cat['live_count'] ?> live<?= $cat['pending_count'] > 0 ? ', ' . (int) $cat['pending_count'] . ' pending' : '' ?>
                    </span>
                </summary>

                <form method="POST" style="margin-top:14px;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="category_id" value="<?= (int) $cat['category_id'] ?>">
                    <label>Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($cat['name']) ?>" required>
                    <label>Description</label>
                    <textarea name="description" placeholder="e.g. Careers in shipping, ports, freight, and supply chain logistics."><?= htmlspecialchars($cat['description'] ?? '') ?></textarea>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>

                <form method="POST" class="actions" onsubmit="return confirm(<?= $totalUses > 0 ? "'This category is still used by $totalUses career(s) — it can\\'t be deleted until they\\'re reassigned. OK to check anyway?'" : "'Delete this category? This can\\'t be undone.'" ?>);">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="category_id" value="<?= (int) $cat['category_id'] ?>">
                    <button type="submit" class="btn btn-danger">Delete Category</button>
                </form>
            </details>
        <?php endforeach; ?>
    </div>
</body>
</html>
