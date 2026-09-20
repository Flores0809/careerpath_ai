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

<!--
    Show/hide password toggle -- the small eye icon inside a password field
    that every major login form (Google, GitHub, Facebook, etc.) uses so
    people can check what they typed instead of guessing. Deliberately an
    icon button, not an emoji: emoji render inconsistently across devices
    and don't match the rest of the site's UI, whereas an inline SVG eye
    icon is the actual standard pattern other sites use.

    Included here in footer.php (loaded on every page) so it automatically
    applies to every password field site-wide -- registration, login,
    profile password changes, and the staff "create account"/"reset
    password" forms -- without needing to hand-edit each form's markup.
-->
<style>
    .cp-pw-wrap { position: relative; }
    .cp-pw-wrap input[type="password"],
    .cp-pw-wrap input[type="text"] { padding-right: 38px !important; }
    .cp-pw-toggle {
        position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
        width: 28px; height: 28px; padding: 0; display: flex; align-items: center; justify-content: center;
        background: none; border: none; cursor: pointer; color: #777; border-radius: 4px;
    }
    .cp-pw-toggle:hover { color: #333; background: rgba(0,0,0,0.05); }
    .cp-pw-toggle svg { width: 19px; height: 19px; }
</style>
<script>
(function () {
    // Feather-style eye / eye-off icons -- the same visual language most
    // sites already use for this, so it reads as familiar rather than novel.
    var ICON_EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var ICON_EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.86 21.86 0 0 1 5.06-6.06"></path><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.86 21.86 0 0 1-3.22 4.53"></path><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.closest('.cp-pw-wrap')) return; // already wrapped (safety against double-run)

        var wrap = document.createElement('div');
        wrap.className = 'cp-pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cp-pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.innerHTML = ICON_EYE;
        wrap.appendChild(btn);

        btn.addEventListener('click', function () {
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.innerHTML = isHidden ? ICON_EYE_OFF : ICON_EYE;
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });
})();
</script>
<?php require __DIR__ . '/chatbot_widget.php'; ?>
