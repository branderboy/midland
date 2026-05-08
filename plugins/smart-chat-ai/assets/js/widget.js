jQuery(document).ready(function($) {
    'use strict';

    var sessionId = 'sc_' + Math.random().toString(36).substr(2, 12) + '_' + Date.now();
    var $widget = $('#smart-chat-widget');
    var $bubble = $('#smart-chat-bubble');
    var $window = $('#smart-chat-window');
    var $messages = $('#smart-chat-messages');
    var $input = $('#smart-chat-input');

    $widget.show();

    $bubble.on('click', function() {
        $window.toggle();
        $bubble.toggle();
        if ($window.is(':visible')) {
            $input.focus();
            if ($messages.children().length === 0) {
                appendMsg('ai', 'Hi! How can I help you today?');
            }
        }
    });

    $('#smart-chat-close').on('click', function() {
        $window.hide();
        $bubble.show();
    });

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

        $.post(scaiConfig.ajaxurl, {
            action: 'scai_send_message',
            nonce: scaiConfig.nonce,
            message: msg,
            session_id: sessionId
        }, function(res) {
            if (res.success) {
                appendMsg('ai', res.data.message);
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
});
