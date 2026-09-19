<?php
// CareerPath AI - shared site footer.
// Include right before </body> on any page with:
//   require __DIR__ . '/footer.php';
// (wrapped in PHP tags — not written literally here, since a closing PHP
// tag inside a // comment ends the comment AND the surrounding PHP block
// early, which is exactly the bug this comment used to have.)
?>
<footer class="cp-footer">
    &copy; <?= date('Y') ?> Meridian Educational Institution Inc. All rights reserved.
</footer>
<style>
    .cp-footer { margin-top: 40px; padding: 16px 20px; text-align: center; font-size: 12px; color: #888; border-top: 1px solid #eee; }
</style>
<?php require __DIR__ . '/chatbot_widget.php'; ?>
