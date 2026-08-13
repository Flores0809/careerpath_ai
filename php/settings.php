<?php
// CareerPath AI - Administrator Module: System Settings
// --------------------------------------------------------------------
// The paper's Use Case Diagram (Figure 8) names 5 System Administrator use
// cases and doesn't include "System Settings" — see README for the
// alignment note. Built anyway since it's on the group's Gantt chart.
// Deliberately small: a couple of real, load-bearing settings rather than a
// generic settings dumping ground.

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator']);

$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recommendationCount = (int) ($_POST['recommendation_count'] ?? 5);
    $recommendationCount = max(1, min(10, $recommendationCount));
    $siteName = trim($_POST['site_name'] ?? '') ?: 'CareerPath AI';

    $update = $pdo->prepare("UPDATE system_settings SET setting_value = :value WHERE setting_key = :key");
    $update->execute(['value' => (string) $recommendationCount, 'key' => 'recommendation_count']);
    $update->execute(['value' => $siteName, 'key' => 'site_name']);

    $message = ['type' => 'success', 'text' => 'Settings saved.'];
}

$settings = $pdo->query("SELECT setting_key, setting_value, description FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
$settingsByKey = [];
foreach ($settings as $s) {
    $settingsByKey[$s['setting_key']] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — System Settings</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .panel, .flash-success { max-width: 640px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 14px 0 4px; }
    .hint { font-size: 12px; color: #888; margin-top: 2px; }
    input[type=text], input[type=number] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    button { margin-top: 18px; padding: 9px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; background: #6e1423; color: #fff; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>System Settings</h1>

    <?php if ($message): ?>
        <div class="flash-success"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <form method="POST">
            <label>Recommendations per assessment (Top-N)</label>
            <input type="number" name="recommendation_count" min="1" max="10" value="<?= htmlspecialchars($settingsByKey['recommendation_count']['setting_value'] ?? '5') ?>">
            <p class="hint"><?= htmlspecialchars($settingsByKey['recommendation_count']['description'] ?? '') ?></p>

            <label>Site name</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars($settingsByKey['site_name']['setting_value'] ?? 'CareerPath AI') ?>">
            <p class="hint"><?= htmlspecialchars($settingsByKey['site_name']['description'] ?? '') ?></p>

            <button type="submit">Save settings</button>
        </form>
    </div>
</body>
</html>
