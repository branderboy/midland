jQuery(document).ready(function($) {
    'use strict';

    // Persist the session so conversation history survives refreshes and the
    // admin Conversations screen shows one coherent thread per visitor.
    var sessionId;
    try {
        sessionId = window.localStorage.getItem('scai_session_id');
        if (!sessionId) {
            sessionId = 'sc_' + Math.random().toString(36).substr(2, 12) + '_' + Date.now();
            window.localStorage.setItem('scai_session_id', sessionId);
        }
    } catch (e) {
        sessionId = 'sc_' + Math.random().toString(36).substr(2, 12) + '_' + Date.now();
    }
    var $widget = $('#smart-chat-widget');
    var $bubble = $('#smart-chat-bubble');
    var $window = $('#smart-chat-window');
    var $messages = $('#smart-chat-messages');
    var $input = $('#smart-chat-input');
    var $form = $('#smart-chat-form');
    var $actions = $('#smart-chat-actions');

    // Visitor-intent phrases that surface the booking option. Stems are matched
    // as prefixes (no trailing word boundary) so "schedule", "scheduling",
    // "booking", "estimating", "appointments", etc. all trigger, not just the
    // exact base word. The old trailing \b broke this: it required a non-word
    // char right after "schedul", so "schedule"/"scheduling" never matched and
    // the AI promised a booking link that never appeared.
    var intentRegex = /\b(schedul|book|visit|walk[ -]?through|quote|estimat|appointment|on[ -]?site|come (out|by)|get someone (to|out)|set ?up|set it up)/i;

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

    $('#smart-chat-cta-visit').on('click', startBooking);
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

    // Scheduling entry point. If a booking link is set, open it directly in a
    // new tab so "Schedule a Visit" goes straight to Calendly. If no link is
    // set, fall back to the embedded Smart Form.
    function startBooking() {
        if (scaiConfig.bookingUrl) {
            // Drop a confirmation line in the chat, then open the scheduler.
            showBooking();
            window.open(scaiConfig.bookingUrl, '_blank', 'noopener,noreferrer');
        } else {
            showForm();
        }
    }

    function showBooking() {
        var $card = $('<div class="smart-chat-msg smart-chat-msg-ai smart-chat-booking"></div>');
        $card.append(document.createTextNode('Grab any time that works for you.'));
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
                if (triggerBooking) startBooking();
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
    // fires sfco:submitted with its response data — show a confirmation and a
    // booking button using the redirect URL the form returned (which now comes
    // from the Smart Forms Calendly settings), falling back to the chat's own
    // configured booking URL.
    $(document).on('sfco:submitted', function(e, formData) {
        hideForm();
        appendMsg('ai', "Got it. You're all set. Grab a time that works for you.");

        var url = (formData && formData.redirect) ? formData.redirect : scaiConfig.bookingUrl;
        if (url) {
            var $card = $('<div class="smart-chat-msg smart-chat-msg-ai smart-chat-booking"></div>');
            $card.append(document.createTextNode('Pick a time that works for you.'));
            $('<a class="smart-chat-book-btn" target="_blank" rel="noopener noreferrer">Pick a time</a>')
                .attr('href', url)
                .appendTo($card);
            $messages.append($card);
            $messages.scrollTop($messages[0].scrollHeight);
        }
    });
});
