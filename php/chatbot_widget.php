<?php
// CareerPath AI - Built-in FAQ chatbot widget (floating button + panel)
// Included from footer.php so it appears on every page. Talks only to
// chatbot_ask.php (same-origin, no API key, no external service).
?>
<div id="cp-chatbot">
    <button type="button" id="cp-chatbot-toggle" aria-label="Open CareerPath AI assistant">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
    </button>
    <div id="cp-chatbot-panel" hidden>
        <div class="cp-chatbot-header">
            <span>CareerPath AI Assistant</span>
            <button type="button" id="cp-chatbot-close" aria-label="Close">✕</button>
        </div>
        <div class="cp-chatbot-messages" id="cp-chatbot-messages">
            <div class="cp-chatbot-msg cp-chatbot-msg-bot">
                Hi! I can answer questions about how CareerPath AI works — RIASEC, the assessment, recommendations, consultations, and more. This runs on a built-in FAQ lookup, not an external AI service, so it only knows about the system itself.
            </div>
            <div class="cp-chatbot-suggestions" id="cp-chatbot-suggestions">
                <button type="button" class="cp-chatbot-chip">What is RIASEC?</button>
                <button type="button" class="cp-chatbot-chip">How do I take the assessment?</button>
                <button type="button" class="cp-chatbot-chip">How are recommendations generated?</button>
                <button type="button" class="cp-chatbot-chip">How do I request a consultation?</button>
            </div>
        </div>
        <form id="cp-chatbot-form">
            <input type="text" id="cp-chatbot-input" placeholder="Ask a question…" autocomplete="off">
            <button type="submit">Send</button>
        </form>
    </div>
</div>
<style>
    #cp-chatbot { position: fixed; bottom: 24px; right: 24px; z-index: 1000; font-family: Arial, sans-serif; }
    /* The old emoji icon (💬) had no reliable center point -- different OSes
       and browsers ship different emoji glyphs with different internal
       bounding boxes, so it never actually sat in the middle of the circle
       (same class of problem as the emoji-based password toggle fixed
       earlier). An inline SVG plus explicit flex centering has one exact,
       predictable center everywhere. */
    #cp-chatbot-toggle { width: 56px; height: 56px; border-radius: 50%; border: none; background: linear-gradient(135deg, #6e1423 0%, #4a0c17 100%); color: #fff; cursor: pointer; box-shadow: 0 6px 18px rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; padding: 0; }
    #cp-chatbot-toggle svg { width: 26px; height: 26px; }
    #cp-chatbot-toggle:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(0,0,0,0.3); }

    #cp-chatbot-panel { position: fixed; bottom: 92px; right: 24px; width: 340px; max-width: calc(100vw - 32px); height: 460px; max-height: calc(100vh - 120px); background: #fff; border-radius: 14px; box-shadow: 0 12px 36px rgba(0,0,0,0.25); flex-direction: column; overflow: hidden; display: flex; }
    #cp-chatbot-panel[hidden] { display: none; }
    .cp-chatbot-header { background: linear-gradient(135deg, #6e1423 0%, #4a0c17 100%); color: #fff; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; font-size: 15px; font-weight: bold; }
    #cp-chatbot-close { background: none; border: none; color: #fff; font-size: 15px; cursor: pointer; padding: 2px 6px; }

    .cp-chatbot-messages { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: #faf7f5; }
    .cp-chatbot-msg { max-width: 88%; padding: 9px 12px; border-radius: 10px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; }
    .cp-chatbot-msg-bot { background: #f0dde1; color: #4a0c17; align-self: flex-start; border-bottom-left-radius: 2px; }
    .cp-chatbot-msg-user { background: #6e1423; color: #fff; align-self: flex-end; border-bottom-right-radius: 2px; }
    .cp-chatbot-msg-question { font-size: 12px; color: #a44553; font-weight: bold; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px; }

    .cp-chatbot-suggestions { display: flex; flex-direction: column; gap: 6px; align-self: stretch; }
    .cp-chatbot-chip { background: #fff; border: 1px solid #e2c9ce; color: #6e1423; border-radius: 8px; padding: 8px 10px; font-size: 13px; text-align: left; cursor: pointer; }
    .cp-chatbot-chip:hover { background: #faf0f1; }

    #cp-chatbot-form { display: flex; border-top: 1px solid #eee; padding: 10px; gap: 8px; }
    #cp-chatbot-input { flex: 1; border: 1px solid #ddd; border-radius: 8px; padding: 9px 10px; font-size: 14px; font-family: inherit; }
    #cp-chatbot-input:focus { outline: none; border-color: #6e1423; }
    #cp-chatbot-form button[type="submit"] { background: #6e1423; color: #fff; border: none; border-radius: 8px; padding: 9px 14px; font-size: 14px; cursor: pointer; }
    #cp-chatbot-form button[type="submit"]:hover { background: #4a0c17; }

    @media (max-width: 480px) {
        #cp-chatbot-panel { right: 16px; left: 16px; width: auto; }
    }
</style>
<script>
(function () {
    var toggle = document.getElementById('cp-chatbot-toggle');
    var panel = document.getElementById('cp-chatbot-panel');
    var closeBtn = document.getElementById('cp-chatbot-close');
    var messages = document.getElementById('cp-chatbot-messages');
    var form = document.getElementById('cp-chatbot-form');
    var input = document.getElementById('cp-chatbot-input');
    var suggestions = document.getElementById('cp-chatbot-suggestions');

    function open() { panel.hidden = false; input.focus(); }
    function close() { panel.hidden = true; }

    toggle.addEventListener('click', function () { panel.hidden ? open() : close(); });
    closeBtn.addEventListener('click', close);

    function addMessage(text, who, questionLabel) {
        var div = document.createElement('div');
        div.className = 'cp-chatbot-msg cp-chatbot-msg-' + who;
        if (questionLabel) {
            var label = document.createElement('div');
            label.className = 'cp-chatbot-msg-question';
            label.textContent = questionLabel;
            div.appendChild(label);
        }
        var body = document.createElement('div');
        body.textContent = text;
        div.appendChild(body);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    function ask(text) {
        if (!text.trim()) return;
        addMessage(text, 'user');
        input.value = '';
        var thinking = document.createElement('div');
        thinking.className = 'cp-chatbot-msg cp-chatbot-msg-bot';
        thinking.textContent = '…';
        messages.appendChild(thinking);
        messages.scrollTop = messages.scrollHeight;

        fetch('chatbot_ask.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'message=' + encodeURIComponent(text)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            thinking.remove();
            addMessage(data.answer, 'bot', data.matched ? data.question : null);
            if (!data.matched && data.suggestions) {
                var chips = document.createElement('div');
                chips.className = 'cp-chatbot-suggestions';
                data.suggestions.forEach(function (s) {
                    var chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'cp-chatbot-chip';
                    chip.textContent = s;
                    chip.addEventListener('click', function () { ask(s); });
                    chips.appendChild(chip);
                });
                messages.appendChild(chips);
                messages.scrollTop = messages.scrollHeight;
            }
        })
        .catch(function () {
            thinking.remove();
            addMessage('Something went wrong reaching the chatbot — please try again.', 'bot');
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        ask(input.value);
    });

    suggestions.querySelectorAll('.cp-chatbot-chip').forEach(function (chip) {
        chip.addEventListener('click', function () { ask(chip.textContent); });
    });
})();
</script>
