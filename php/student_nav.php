<?php
// CareerPath AI - shared student-facing nav bar.
// Include AFTER require_student_login() has set $currentStudent, e.g.:
//   $currentStudent = require_student_login();
//   require __DIR__ . '/student_nav.php';
require_once __DIR__ . '/notifications_helper.php';
$cpNavUnread = student_unread_count(get_db(), (int) $currentStudent['student_id']);
$cpCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$cpIsActive = fn(array $pages) => in_array($cpCurrentPage, $pages, true) ? 'cp-active' : '';
?>
<div class="cp-nav">
    <div class="cp-nav-top">
    <a href="student_dashboard.php" class="cp-nav-brand">
        <img src="assets/img/logo.png" alt="Meridian Educational Institution Inc. logo" class="cp-nav-logo">
        <img src="assets/img/logo-hex.png" alt="CareerPath AI logo" class="cp-nav-logo cp-nav-logo-hex">
        <span class="cp-nav-brand-text">
            <span class="cp-nav-school">Meridian Educational Institution Inc.</span>
            <span class="cp-nav-title">CareerPath AI</span>
        </span>
    </a>
    <div class="cp-nav-user">
        <a href="student_profile.php" class="cp-nav-user-name"><?= htmlspecialchars($currentStudent['name']) ?></a>
        · <a href="student_logout.php">Logout</a>
    </div>
    </div>
    <button type="button" class="cp-nav-hamburger" id="cp-nav-hamburger" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="cp-nav-links">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
    <div class="cp-nav-links" id="cp-nav-links">
        <a class="cp-nav-item <?= $cpIsActive(['student_dashboard.php']) ?>" href="student_dashboard.php">Dashboard</a>

        <div class="cp-dropdown">
            <button type="button" class="cp-nav-item cp-dropdown-toggle <?= $cpIsActive(['assessment.php', 'student_history.php']) ?>">Assessment <span class="cp-caret">▾</span></button>
            <div class="cp-dropdown-menu">
                <a href="assessment.php">Take Assessment</a>
                <a href="student_history.php">My History</a>
            </div>
        </div>

        <a class="cp-nav-item <?= $cpIsActive(['request_consultation.php']) ?>" href="request_consultation.php">Consultations</a>
        <a class="cp-nav-item <?= $cpIsActive(['student_profile.php']) ?>" href="student_profile.php">My Profile</a>
        <a class="cp-nav-item <?= $cpIsActive(['student_notifications.php']) ?>" href="student_notifications.php">Notifications<?php if ($cpNavUnread > 0): ?> <span class="cp-badge"><?= $cpNavUnread ?></span><?php endif; ?></a>
    </div>
</div>
<style>
    .cp-nav { display: flex; flex-direction: column; gap: 10px; background: linear-gradient(135deg, #6e1423 0%, #4a0c17 100%); color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 15.5px; position: relative; }
    .cp-nav-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px 16px; }
    .cp-nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; margin-right: 10px; flex-shrink: 0; }
    .cp-nav-logo { width: 36px; height: 36px; border-radius: 50%; background: #fff; object-fit: cover; flex-shrink: 0; }
    .cp-nav-logo-hex { border-radius: 0; background: none; width: 34px; height: auto; object-fit: contain; }
    .cp-nav-brand-text { display: flex; flex-direction: column; line-height: 1.2; }
    .cp-nav-school { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #e9c9ce; font-weight: normal; }
    .cp-nav-title { font-size: 17.5px; font-weight: bold; color: #fff; }
    .cp-nav-links { display: flex; align-items: center; flex-wrap: wrap; gap: 2px; border-top: 1px solid rgba(255,255,255,0.15); padding-top: 10px; }
    .cp-nav-item { color: #e9c9ce; text-decoration: none; padding: 8px 12px; border-radius: 6px; background: none; border: none; font: inherit; font-size: 15.5px; font-family: inherit; cursor: pointer; display: inline-block; box-sizing: border-box; line-height: 20px; vertical-align: middle; margin: 0; appearance: none; -webkit-appearance: none; }
    a.cp-nav-item:hover, .cp-dropdown-toggle:hover { color: #fff; background: rgba(255,255,255,0.12); }
    .cp-nav-item.cp-active { color: #fff; background: rgba(255,255,255,0.18); font-weight: bold; }
    .cp-caret { font-size: 11.5px; }

    .cp-dropdown { position: relative; }
    .cp-dropdown-menu { display: none; position: absolute; top: calc(100% + 4px); left: 0; background: #fff; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.18); min-width: 190px; padding: 6px; z-index: 50; }
    .cp-dropdown.cp-open .cp-dropdown-menu { display: block; }
    .cp-dropdown-menu a { display: block; color: #6e1423; text-decoration: none; padding: 9px 12px; border-radius: 6px; font-size: 14.5px; }
    .cp-dropdown-menu a:hover { background: #faf0f1; }

    .cp-nav-user { color: #fff; white-space: nowrap; text-align: right; flex-shrink: 0; }
    .cp-nav-user a { color: #ffd166; }
    a.cp-nav-user-name { color: #fff; text-decoration: none; padding: 4px 8px; border-radius: 6px; transition: background-color 0.15s ease; }
    a.cp-nav-user-name:hover { background: rgba(255,255,255,0.12); text-decoration: none; }
    .cp-badge { background: #e63946; color: #fff; border-radius: 10px; padding: 1px 6px; font-size: 11.5px; font-weight: bold; margin-left: 2px; }

    /* Hamburger toggle: hidden entirely on desktop (the full link row
       already fits and reads fine there). On mobile it replaces the
       always-expanded, fully-stacked link list -- which used to push the
       actual page content (dashboard stats, results, etc.) down below the
       fold before a visitor saw any of it -- with a compact button that
       reveals the same links on tap. */
    .cp-nav-hamburger { display: none; position: absolute; top: 12px; right: 16px; width: 34px; height: 34px; padding: 0; background: none; border: none; color: #fff; cursor: pointer; border-radius: 6px; align-items: center; justify-content: center; }
    .cp-nav-hamburger:hover { background: rgba(255,255,255,0.12); }
    .cp-nav-hamburger svg { width: 22px; height: 22px; }

    @media (max-width: 760px) {
        .cp-nav-top { flex-direction: column; align-items: flex-start; padding-right: 40px; }
        .cp-nav-hamburger { display: flex; }
        .cp-nav-links { flex-direction: column; align-items: stretch; display: none; }
        .cp-nav-links.cp-nav-open { display: flex; }
        .cp-dropdown-menu { position: static; box-shadow: none; padding-left: 12px; }
        .cp-nav-user { text-align: left; }
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

    var hamburger = document.getElementById('cp-nav-hamburger');
    var navLinks = document.getElementById('cp-nav-links');
    hamburger.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = navLinks.classList.toggle('cp-nav-open');
        hamburger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
})();
</script>
