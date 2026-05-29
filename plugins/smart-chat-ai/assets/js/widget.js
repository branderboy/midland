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

    // After the form is submitted (lead captured), optionally offer a booking
    // link so the visitor can grab a time. The URL comes from the embedded
    // form's per-form Booking link setting (scaiConfig.bookingUrl).
    function appendBookingButton() {
        if (!scaiConfig.bookingUrl) return;
        var $card = $('<div class="smart-chat-msg smart-chat-msg-ai smart-chat-booking"></div>');
        $card.append(document.createTextNode('Want to lock in a time now?'));
        $('<a class="smart-chat-book-btn" target="_blank" rel="noopener noreferrer">Pick a time</a>')
            .attr('href', scaiConfig.bookingUrl)
            .appendTo($card);
        $messages.append($card);
        $messages.scrollTop($messages[0].scrollHeight);
    }

    // Enlarge / shrink the chat window.
    $('#smart-chat-expand').on('click', function() {
        $window.toggleClass('expanded');
    });

    var $inputArea = $('#smart-chat-input-area');
    var autoExpanded = false;

    // When the form opens it takes over the body so there's a single scroll
    // area (no more dual messages + form scrollbars), and the window jumps to
    // its larger size so the fields aren't cramped. The form's own close
    // button brings the conversation back and restores the prior size.
    function showForm() {
        if ( ! $window.hasClass('expanded') ) {
            $window.addClass('expanded');
            autoExpanded = true;
        }
        $messages.hide();
        $actions.hide();
        $inputArea.hide();
        $form.stop(true, true).slideDown(180);
    }

    function hideForm() {
        if ( autoExpanded ) {
            $window.removeClass('expanded');
            autoExpanded = false;
        }
        $form.stop(true, true).slideUp(180, function() {
            $messages.show();
            $inputArea.show();
            $actions.show();
            $input.prop('disabled', false).focus();
            $messages.scrollTop($messages[0].scrollHeight);
        });
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

        // If the visitor expressed booking intent, surface the booking option
        // alongside the AI reply so they can act immediately.
        var triggerBooking = intentRegex.test(msg);

        $.post(scaiConfig.ajaxurl, {
            action: 'scai_send_message',
            nonce: scaiConfig.nonce,
            message: msg,
            session_id: sessionId
        }, function(res) {
            if (res.success) {
                appendMsg('ai', res.data.message);
                if (triggerBooking) showForm();
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
        appendMsg('ai', "Got it. You're all set. We'll be in touch shortly to confirm your time.");
        appendBookingButton();
    });
});
