<?php
// CareerPath AI - shared top nav bar.
// Include AFTER require_login()/require_role() has set $currentUser, e.g.:
//   $currentUser = require_role(['administrator', 'counselor']);
//   require __DIR__ . '/nav.php';
require_once __DIR__ . '/notifications_helper.php';
$cpNavUnread = staff_unread_count(get_db(), (int) $currentUser['user_id']);
try {
    $cpNavPendingConsultations = (int) get_db()->query("SELECT COUNT(*) FROM consultations WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $cpNavPendingConsultations = 0; // consultations table not present yet (pre-migration_10 install)
}
$cpCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$cpIsActive = fn(array $pages) => in_array($cpCurrentPage, $pages, true) ? 'cp-active' : '';
?>
<div class="cp-nav">
    <a href="index.php" class="cp-nav-brand">CareerPath AI</a>
    <div class="cp-nav-links">
        <a class="cp-nav-item <?= $cpIsActive(['dashboard.php']) ?>" href="dashboard.php">Dashboard</a>

        <div class="cp-dropdown">
            <button type="button" class="cp-nav-item cp-dropdown-toggle <?= $cpIsActive(['careers.php', 'careers_manage.php', 'career_categories.php']) ?>">Careers <span class="cp-caret">▾</span></button>
            <div class="cp-dropdown-menu">
                <a href="careers.php">Career Review</a>
                <a href="careers_manage.php">Manage Careers</a>
                <a href="career_categories.php">Manage Categories</a>
            </div>
        </div>

        <div class="cp-dropdown">
            <button type="button" class="cp-nav-item cp-dropdown-toggle <?= $cpIsActive(['students_lookup.php', 'consultations.php', 'audit_log.php']) ?>">Students <span class="cp-caret">▾</span></button>
            <div class="cp-dropdown-menu">
                <a href="students_lookup.php">Student Lookup</a>
                <a href="consultations.php">Consultations<?php if ($cpNavPendingConsultations > 0): ?> <span class="cp-badge"><?= $cpNavPendingConsultations ?></span><?php endif; ?></a>
                <a href="audit_log.php">Audit Log</a>
            </div>
        </div>

        <a class="cp-nav-item <?= $cpIsActive(['staff_notifications.php']) ?>" href="staff_notifications.php">Notifications<?php if ($cpNavUnread > 0): ?> <span class="cp-badge"><?= $cpNavUnread ?></span><?php endif; ?></a>

        <?php if ($currentUser['role'] === 'administrator'): ?>
            <div class="cp-dropdown">
                <button type="button" class="cp-nav-item cp-dropdown-toggle <?= $cpIsActive(['users.php', 'settings.php', 'backup.php', 'change_history.php']) ?>">Admin <span class="cp-caret">▾</span></button>
                <div class="cp-dropdown-menu">
                    <a href="users.php">Manage Accounts</a>
                    <a href="change_history.php">Change History</a>
                    <a href="settings.php">System Settings</a>
                    <a href="backup.php">Database Backup</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="cp-nav-user">
        <?= htmlspecialchars($currentUser['name']) ?>
        <span class="cp-role-badge"><?= htmlspecialchars($currentUser['role']) ?></span>
        · <a href="logout.php">Logout</a>
    </div>
</div>
<style>
    .cp-nav { display: flex; align-items: center; background: linear-gradient(135deg, #6e1423 0%, #4a0c17 100%); color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; flex-wrap: wrap; gap: 10px 16px; position: relative; }
    .cp-nav-brand { font-weight: bold; font-size: 16px; color: #fff; text-decoration: none; margin-right: 10px; flex-shrink: 0; }
    .cp-nav-links { display: flex; align-items: center; flex-wrap: wrap; gap: 2px; flex: 1 1 auto; }
    .cp-nav-item { color: #e9c9ce; text-decoration: none; padding: 8px 12px; border-radius: 6px; background: none; border: none; font: inherit; font-size: 14px; font-family: inherit; cursor: pointer; display: inline-block; box-sizing: border-box; line-height: 20px; vertical-align: middle; margin: 0; appearance: none; -webkit-appearance: none; }
    a.cp-nav-item:hover, .cp-dropdown-toggle:hover { color: #fff; background: rgba(255,255,255,0.12); }
    .cp-nav-item.cp-active { color: #fff; background: rgba(255,255,255,0.18); font-weight: bold; }
    .cp-caret { font-size: 10px; }

    .cp-dropdown { position: relative; }
    .cp-dropdown-menu { display: none; position: absolute; top: calc(100% + 4px); left: 0; background: #fff; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.18); min-width: 190px; padding: 6px; z-index: 50; }
    .cp-dropdown.cp-open .cp-dropdown-menu { display: block; }
    .cp-dropdown-menu a { display: block; color: #6e1423; text-decoration: none; padding: 9px 12px; border-radius: 6px; font-size: 13px; }
    .cp-dropdown-menu a:hover { background: #faf0f1; }

    .cp-nav-user { color: #fff; white-space: nowrap; margin-left: auto; text-align: right; flex-shrink: 0; }
    .cp-nav-user a { color: #ffd166; }
    .cp-role-badge { background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 10px; font-size: 11px; text-transform: uppercase; margin: 0 4px; }
    .cp-badge { background: #e63946; color: #fff; border-radius: 10px; padding: 1px 6px; font-size: 10px; font-weight: bold; margin-left: 2px; }

    @media (max-width: 760px) {
        .cp-nav { flex-direction: column; align-items: stretch; }
        .cp-nav-links { flex-direction: column; align-items: stretch; }
        .cp-dropdown-menu { position: static; box-shadow: none; padding-left: 12px; }
        .cp-nav-user { text-align: right; }
    }
</style>
<script>
(function () {
    document.querySelectorAll('.cp-dropdown-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var dropdown = btn.closest('.cp-dropdown');
            var wasOpen = dropdown.classList.contains('cp-open');
            document.querySelectorAll('.cp-dropdown.cp-open').forEach(function (d) { d.classList.remove('cp-open'); });
            if (!wasOpen) dropdown.classList.add('cp-open');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.cp-dropdown.cp-open').forEach(function (d) { d.classList.remove('cp-open'); });
    });
})();
</script>
