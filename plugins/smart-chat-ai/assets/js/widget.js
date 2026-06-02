jQuery(document).ready(function($) {
    'use strict';

    // Browser-local session token (per tab). Persisted in sessionStorage and
    // sent on every request; when empty the server mints one and returns it,
    // which we then store and reuse.
    var sessionToken;
    try {
        sessionToken = window.sessionStorage.getItem('scai_session_id') || '';
    } catch (e) {
        sessionToken = '';
    }
    var $widget = $('#smart-chat-widget');
    var $bubble = $('#smart-chat-bubble');
    var $window = $('#smart-chat-window');
    var $messages = $('#smart-chat-messages');
    var $input = $('#smart-chat-input');
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

    $('#smart-chat-cta-visit').on('click', startBooking);

    // Scheduling entry point. Opens the Calendly booking link in a new tab and
    // also drops a "Pick a time" card in the chat as a fallback (in case a
    // popup blocker stops the auto-open). The embedded form was removed.
    function startBooking() {
        if (scaiConfig.bookingUrl) {
            showBooking();
            window.open(scaiConfig.bookingUrl, '_blank', 'noopener,noreferrer');
        } else {
            appendMsg('ai', "Give us a call and we'll get you on the schedule.");
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
    $('#smart-chat-expand-lg, #smart-chat-expand-sm').on('click', function() {
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
            session_token: sessionToken
        }, function(res) {
            if (res.success) {
                // Store/refresh the server-issued session token.
                if (res.data && res.data.session_id) {
                    sessionToken = res.data.session_id;
                    try { window.sessionStorage.setItem('scai_session_id', sessionToken); } catch (e) {}
                }
                appendMsg('ai', res.data.message);

                // Show the booking link only when the AI's own reply points the
                // visitor to it (the prompt does this AFTER collecting name and
                // email). Detecting it from the AI reply, not the visitor's
                // words, prevents the link firing before contact info is captured.
                var aiSaid = (res.data.message || '').toLowerCase();
                var bookingCue = /(grab (a |any )?time|pick a time|book a time|right here|grab a slot|schedule (it|that|your)|booking link|link (right )?here)/i;
                if (scaiConfig.bookingUrl && bookingCue.test(aiSaid)) {
                    showBooking();
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
