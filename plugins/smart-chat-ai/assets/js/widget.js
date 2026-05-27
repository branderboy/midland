jQuery(document).ready(function($) {
    'use strict';

    var sessionId = 'sc_' + Math.random().toString(36).substr(2, 12) + '_' + Date.now();
    var $widget = $('#smart-chat-widget');
    var $bubble = $('#smart-chat-bubble');
    var $window = $('#smart-chat-window');
    var $messages = $('#smart-chat-messages');
    var $input = $('#smart-chat-input');
    var $form = $('#smart-chat-form');
    var $actions = $('#smart-chat-actions');

    // Visitor-intent phrases that auto-open the embedded Smart Form.
    var intentRegex = /\b(schedul|book|visit|walk[- ]?through|quote|estimate|appointment|on[- ]?site|come (out|by)|get someone (to|out)|set up|set it up)\b/i;

    $widget.show();

    $bubble.on('click', function() {
        $window.toggle();
        $bubble.toggle();
        if ($window.is(':visible')) {
            $input.focus();
            if ($messages.children().length === 0) {
                appendMsg('ai', 'Hey! What can I help you with?');
            }
        }
    });

    $('#smart-chat-close').on('click', function() {
        $window.hide();
        $bubble.show();
    });

    $('#smart-chat-cta-visit').on('click', showForm);
    $('#smart-chat-form-close').on('click', hideForm);

    function showForm() {
        $form.slideDown(180);
        $actions.hide();
        $input.prop('disabled', true);
    }

    function hideForm() {
        $form.slideUp(180);
        $actions.show();
        $input.prop('disabled', false).focus();
    }

    function appendMsg(sender, text) {
        var cls = sender === 'user' ? 'smart-chat-msg-user' : 'smart-chat-msg-ai';
        $messages.append('<div class="smart-chat-msg ' + cls + '">' + escHtml(text) + '</div>');
        $messages.scrollTop($messages[0].scrollHeight);
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function sendMessage() {
        var msg = $input.val().trim();
        if (!msg) return;

        appendMsg('user', msg);
        $input.val('').focus();

        // If the visitor expressed intent to book, slide the form in
        // alongside the AI reply so they can act immediately.
        var triggerForm = intentRegex.test(msg);

        $.post(scaiConfig.ajaxurl, {
            action: 'scai_send_message',
            nonce: scaiConfig.nonce,
            message: msg,
            session_id: sessionId
        }, function(res) {
            if (res.success) {
                appendMsg('ai', res.data.message);
                if (triggerForm) showForm();
            } else {
                appendMsg('ai', 'Sorry, something went wrong. Please try again.');
            }
        }).fail(function() {
            appendMsg('ai', 'Connection error. Please try again.');
        });
    }

    $('#smart-chat-send').on('click', sendMessage);
    $input.on('keypress', function(e) {
        if (e.which === 13) { sendMessage(); }
    });

    // When the embedded Smart Form is submitted successfully, Smart Forms
    // fires its own success state — show a confirmation in chat and close
    // the form panel.
    $(document).on('sfco:submitted', function() {
        hideForm();
        appendMsg('ai', "Got it — I'll have someone reach out today to lock in a walkthrough.");
    });
});
