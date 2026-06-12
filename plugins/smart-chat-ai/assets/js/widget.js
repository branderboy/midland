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
    var $actions = $('#smart-chat-actions');

    // Booking is gated on the backend capturing a lead first. bookingReady
    // flips true only when the server returns a decorated booking_url (i.e. a
    // name + email have been captured for this session); bookingShown keeps the
    // "Pick a time" card from repeating once it's been offered. This is what
    // enforces capture-first: no booking card can appear before the lead exists.
    var bookingReady = false;
    var bookingShown = false;

    $widget.show();

    $bubble.on('click', function() {
        $window.toggle();
        $bubble.toggle();
        if ($window.is(':visible')) {
            $input.focus();
            if ($messages.children().length === 0) {
                appendMsg('ai', 'Hey! What can I help you with?');
                showSuggestions();
            }
        }
    });

    // Tappable starter questions shown only on first open. Clicking one sends
    // it as the visitor's message and clears the chips.
    function showSuggestions() {
        var list = (scaiConfig.suggestions || []);
        if (!list.length) return;
        var $wrap = $('<div class="smart-chat-suggestions"></div>');
        list.forEach(function(q) {
            $('<button type="button" class="smart-chat-suggestion"></button>')
                .text(q)
                .on('click', function() {
                    $wrap.remove();
                    $input.val(q);
                    sendMessage();
                })
                .appendTo($wrap);
        });
        $messages.append($wrap);
        $messages.scrollTop($messages[0].scrollHeight);
    }

    $('#smart-chat-close').on('click', function() {
        $window.hide();
        $bubble.show();
    });

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

        // Remove starter chips once the conversation is underway.
        $messages.find('.smart-chat-suggestions').remove();

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

                // The server returns a decorated booking_url ONLY once it has
                // captured a lead for this session (name + email). That is our
                // capture-first gate: until it arrives, no booking card shows.
                // The link carries utm_content=LEAD_<id> so the booking is
                // attributed to the lead and tagged by the Calendly webhook.
                if (res.data.booking_url) {
                    scaiConfig.bookingUrl = res.data.booking_url;
                    bookingReady = true;
                }

                // Offer the "Pick a time" card when the lead is captured AND the
                // AI's own reply points the visitor to booking, and only once.
                var aiSaid = (res.data.message || '').toLowerCase();
                var bookingCue = /(grab (a |any )?time|pick a time|book a time|right here|grab a slot|schedule (it|that|your)|booking link|link (right )?here)/i;
                if (bookingReady && !bookingShown && bookingCue.test(aiSaid)) {
                    showBooking();
                    bookingShown = true;
                }
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
