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
    /* line-height: 0 kills the few extra pixels of "descender" space browsers
       reserve under an inline-block <input> -- without it, this div ends up
       slightly taller than the input's visible box, so a button centered on
       the DIV (top: 50%) lands a few px below center on the INPUT, making it
       look like it's sagging out of the bottom-right corner instead of
       sitting flush inside it. */
    .cp-pw-wrap { position: relative; line-height: 0; }
    .cp-pw-wrap input[type="password"],
    .cp-pw-wrap input[type="text"] { padding-right: 38px !important; }
    .cp-pw-toggle {
        /* top:0; bottom:0; margin:auto 0 auto auto centers a fixed-height
           absolute element vertically no matter what -- unlike top:50% +
           translateY(-50%), it doesn't depend on the browser resolving a
           percentage against the wrapper's (auto, content-derived) height,
           which is what was pushing the icon ~20px too low before. */
        position: absolute; top: 0; bottom: 0; right: 4px; margin: auto 0;
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

        // Some pages style password fields with a selector like
        // `input[type=password]` that doesn't also match `input[type=text]`
        // (login.php does this). Toggling .type to "text" to reveal the
        // password then drops the field out of that rule entirely, so it
        // falls back to the browser's bare default input look -- smaller
        // box, no border-radius, a different font. Snapshotting the page's
        // OWN intended styling now (while it's still type=password, so the
        // real CSS rule is guaranteed to be in effect) and locking it in as
        // inline styles means the field looks identical in both states, no
        // matter how that page's CSS selectors happen to be written.
        var cs = getComputedStyle(input);
        ['width', 'height', 'padding', 'border', 'borderRadius', 'boxSizing',
         'fontFamily', 'fontSize', 'color', 'backgroundColor', 'boxShadow'].forEach(function (prop) {
            input.style[prop] = cs[prop];
        });

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
