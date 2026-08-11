(function () {
    var widget = document.getElementById('wp-ai-agent-widget');
    if (!widget) {
        return;
    }

    var toggle = widget.querySelector('.wp-ai-agent-toggle');
    var panel = widget.querySelector('.wp-ai-agent-panel');
    var form = widget.querySelector('#wp-ai-agent-form');
    var input = widget.querySelector('#wp-ai-agent-message');
    var messages = widget.querySelector('#wp-ai-agent-messages');
    var imageInput = widget.querySelector('#wp-ai-agent-image');
    var imageBtn = widget.querySelector('#wp-ai-agent-image-btn');
    var contactBtn = widget.querySelector('#wp-ai-agent-contact-btn');
    var views = widget.querySelectorAll('.wp-ai-agent-view');
    var navBtns = widget.querySelectorAll('.wp-ai-agent-nav-btn');
    var homeCardsEl = widget.querySelector('#wp-ai-agent-home-cards');
    var homeIntroEl = widget.querySelector('#wp-ai-agent-home-intro');
    var careBtn = widget.querySelector('#wp-ai-agent-care');
    var closeBtn = widget.querySelector('.wp-ai-agent-close');
    var menuBtn = widget.querySelector('#wp-ai-agent-menu-btn');
    var menuDropdown = widget.querySelector('#wp-ai-agent-menu-dropdown');
    var submitBtn = widget.querySelector('.wp-ai-agent-submit');
    var voiceBtn = widget.querySelector('#wp-ai-agent-voice');
    // Only one AI request may be in flight at a time (chat queue).
    var busy = false;
    // Voice assistant state manager. Only one state at a time.
    var recognition = null;
    var listening = false;
    var voiceState = 'idle'; // idle | listening | processing | speaking
    var voiceActive = false; // true when the current turn was composed by voice.
    var pendingVoiceSend = false; // a voice transcript is in the input, awaiting Send.

    // Persist the conversation so it survives page reloads — scoped to THIS
    // visitor (session id) and THIS page (URL path), so history never leaks
    // across pages or other visitors.
    var sessionId = getSessionId();
    var pageKey = getPageKey();
    var STORAGE_KEY = 'wpAiAgentHistory_' + sessionId + '_' + pageKey;
    var history = loadHistory();
    var quickActionsEl = null;
    var pendingImage = null; // { dataUrl } — image selected but not yet sent.
    var previewBar = null;
    var defaultPlaceholder = input ? (input.getAttribute('placeholder') || 'Ask a question...') : 'Ask a question...';

    // ---- Google Material icons (inline SVG, fully self-contained — no CDN) ----
    var MI_PATHS = {
        home:     'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
        chat:     'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z',
        orders:   'M18 17H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V7h12v2zM3 22l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20z',
        policy:   'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z',
        products: 'M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm0 10c-2.21 0-4-1.79-4-4h2c0 1.1.9 2 2 2s2-.9 2-2h2c0 2.21-1.79 4-4 4z',
        category: 'M12 2l-5.5 9h11L12 2zm0 3.84L13.93 9h-3.87L12 5.84zM17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5zM3 21.5h8v-8H3v8zm2-6h4v4H5v-4z',
        offer:    'M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58s1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41s-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z',
        shipping: 'M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zM18 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z',
        login:    'M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z',
        register: 'M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-1V8H4v3H1v2h3v3h2v-3h3v-2H6zm9 3c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z',
        support:  'M21 12.22C21 6.73 16.74 3 12 3c-4.69 0-9 3.65-9 9.28-.6.34-1 .98-1 1.72v2c0 1.1.9 2 2 2h1v-6.1c0-3.87 3.13-7 7-7s7 3.13 7 7V19h-8v2h8c1.1 0 2-.9 2-2v-1.22c.59-.31 1-.92 1-1.64v-2.3c0-.7-.41-1.31-1-1.62zM9 14c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z',
        call:     'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
        more:     'M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z',
        send:     'M2.01 21L23 12 2.01 3 2 10l15 2-15 2z',
        trending: 'M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z',
        chevron:  'M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z',
        open:     'M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z',
        help:     'M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z'
    };

    // Return inline SVG markup for a Material icon (trusted, static).
    function miSvg(name) {
        var d = MI_PATHS[name] || MI_PATHS.help;
        return '<svg class="wp-ai-agent-mi" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="' + d + '"/></svg>';
    }

    // An element wrapping the icon SVG (kept separate so labels stay XSS-safe).
    function makeIcon(name) {
        var wrap = document.createElement('span');
        wrap.className = 'wp-ai-agent-ic';
        wrap.innerHTML = miSvg(name);
        return wrap;
    }

    // Choose the best Material icon for an action from its label / query text.
    function iconNameFor(label, query) {
        var s = (String(query || '') + ' ' + String(label || '')).toLowerCase();
        if (/\border|track|invoice\b/.test(s)) { return 'orders'; }
        if (/polic|refund|return|terms|privacy|shipping policy/.test(s)) { return 'policy'; }
        if (/categor/.test(s)) { return 'category'; }
        if (/best ?sell|popular|trend/.test(s)) { return 'trending'; }
        if (/sale|deal|discount|offer|cheap|coupon|freebie|free/.test(s)) { return 'offer'; }
        if (/ship|deliver/.test(s)) { return 'shipping'; }
        if (/register|sign ?up|create account/.test(s)) { return 'register'; }
        if (/login|log ?in|sign ?in|account|my account/.test(s)) { return 'login'; }
        if (/contact|support|care|whatsapp|human|reach|team|call/.test(s)) { return 'support'; }
        if (/product|shop|browse|buy|item/.test(s)) { return 'products'; }
        return 'chat';
    }

    // Remove a leading emoji / symbol from a label so only clean text remains.
    function stripLeadingEmoji(str) {
        var s = String(str == null ? '' : str);
        try {
            var cleaned = s.replace(/^[^\p{L}\p{N}]+/u, '').trim();
            return cleaned !== '' ? cleaned : s;
        } catch (e) {
            return s.replace(/^[^A-Za-z0-9]+/, '').trim() || s;
        }
    }

    // Fill a button/link with a Material icon + clean label (XSS-safe).
    function setActionLabel(el, action) {
        el.textContent = '';
        el.appendChild( makeIcon( iconNameFor( action.label, action.query ) ) );
        var t = document.createElement('span');
        t.className = 'wp-ai-agent-btn-label';
        t.textContent = stripLeadingEmoji( action.label );
        el.appendChild( t );
    }

    // A stable per-visitor id kept in localStorage (survives page navigation,
    // refresh, and browser restarts for this browser profile).

    function newGuestId() {
        return 'guest_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function getSessionId() {
        var key = 'wpAiAgentSessionId';
        var tkey = 'wpAiAgentSessionTs';
        var maxAge = 24 * 60 * 60 * 1000; // 24 hours
        var id = null, ts = 0;
        try {
            id = window.localStorage.getItem(key);
            ts = parseInt(window.localStorage.getItem(tkey) || '0', 10);
        } catch (e) {
            id = null;
        }
        // Expire a stale guest session after 24h of inactivity, then start fresh.
        if (id && ts && (Date.now() - ts) > maxAge) {
            id = null;
        }
        if (!id) {
            id = newGuestId();
        }
        // Refresh the activity timestamp (sliding 24h window).
        try {
            window.localStorage.setItem(key, id);
            window.localStorage.setItem(tkey, String(Date.now()));
        } catch (e) {
            // localStorage unavailable; fall back to a per-page-load id.
        }
        return id;
    }

    // Extend the 24h window on activity (a sent message).
    function touchSession() {
        try { window.localStorage.setItem('wpAiAgentSessionTs', String(Date.now())); } catch (e) { /* ignore */ }
    }

    // A safe slug for the current page, e.g. "/about/" -> "about", "/" -> "home".

    function getPageKey() {
        var path = (window.location.pathname || '/').replace(/\/+$/, '');
        var slug = path.replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
        return slug || 'home';
    }

    // History is intentionally EPHEMERAL: it lives only in memory for the
    // current page view and is cleared on reload or when the visitor leaves the
    // page. Nothing is persisted to localStorage, so one user's chat never
    // carries over to another (or across reloads). The server still logs
    // conversations for admin analytics.
    function loadHistory() {
        return [];
    }

    function saveHistory() {
        // no-op — history is not persisted.
    }

    function rememberMessage(role, text) {
        history.push({ role: role, text: text });
    }

    // Always greet the visitor when the widget loads: show the welcome message
    // at the top, restore any previous conversation below it, then show the
    // quick-action buttons. The greeting itself is never stored in history, so
    // it appears every time the chat is opened without piling up.
    var dataCfg = window.wpAiAgentData || {};

    // Render Home cards + intro, then RESTORE any recent guest conversation
    // (session-wide, last 24h) so a refresh or navigating to another page keeps
    // the chat going. Falls back to the welcome greeting when nothing to resume.
    renderHomeCards(dataCfg.homeCards || dataCfg.quickActions);
    if (homeIntroEl) {
        homeIntroEl.textContent = dataCfg.homeIntro || 'Welcome! Ask us anything.';
    }
    restoreOrWelcome();
    setView('home');

    // Keep the chat OPEN across page navigation / internal-link clicks when the
    // visitor had it open (the conversation itself is restored above). The panel
    // only stays closed if the visitor explicitly closed it.
    try {
        if (window.sessionStorage.getItem('wpAiAgentPanelOpen') === '1') {
            openPanel();
        }
    } catch (e) { /* ignore */ }

    // Switch between the Home and Chat views.
    function setView(name) {
        views.forEach(function (v) {
            v.classList.toggle('is-hidden', v.getAttribute('data-view') !== name);
        });
        navBtns.forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-nav') === name);
        });
        if ('chat' === name) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    navBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            setView(b.getAttribute('data-nav'));
        });
    });

    // Home "question" cards — built from the quick actions. Tapping one jumps to
    // the Chat view and asks that question.

    function renderHomeCards(actions) {
        if (!homeCardsEl) {
            return;
        }
        homeCardsEl.innerHTML = '';
        (actions || []).forEach(function (action) {
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'wp-ai-agent-home-card';

            var icon = makeIcon(iconNameFor(action.label, action.query));
            icon.className = 'wp-ai-agent-card-icon';

            var label = document.createElement('span');
            label.className = 'wp-ai-agent-card-label';
            label.textContent = stripLeadingEmoji(action.label);

            var chev = makeIcon('chevron');
            chev.className = 'wp-ai-agent-ic chev';

            card.appendChild(icon);
            card.appendChild(label);
            card.appendChild(chev);
            card.addEventListener('click', function () {
                setView('chat');
                submitQuery(action.query || action.label);
            });
            homeCardsEl.appendChild(card);
        });
    }

    // "Connect with customer care" — open WhatsApp directly when configured,
    // otherwise jump into the chat and request a human.
    if (careBtn) {
        careBtn.addEventListener('click', function () {
            var url = dataCfg.whatsappUrl || '';
            if (url) {
                if (dataCfg.handoffUrl) {
                    try {
                        fetch(dataCfg.handoffUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ session_id: sessionId, page_url: window.location.href, query: 'customer care' }),
                            keepalive: true
                        });
                    } catch (e) { /* ignore */ }
                }
                window.open(url, '_blank', 'noopener');
            } else {
                setView('chat');
                submitQuery('talk to human');
            }
        });
    }

    // Open / close the panel. The floating "Need Help?" toggle is hidden while
    // the panel is open (so it doesn't sit on top of it) and shown again on close.
    function openPanel() {
        panel.classList.remove('is-hidden');
        if (toggle) {
            toggle.style.display = 'none';
        }
        // Remember the open state so the chat stays open across page navigation.
        try { window.sessionStorage.setItem('wpAiAgentPanelOpen', '1'); } catch (e) { /* ignore */ }
    }
    function closePanel() {
        panel.classList.add('is-hidden');
        if (toggle) {
            toggle.style.display = '';
        }
        // Closing the widget must silence any speech and stop recording at once.
        stopSpeaking();
        stopListening();
        try { window.sessionStorage.setItem('wpAiAgentPanelOpen', '0'); } catch (e) { /* ignore */ }
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closePanel);
    }

    // IMPORTANT: the widget NEVER closes on an outside/page click. Clicking a
    // suggestion chip, category/product button, "View" / "Select options", an
    // internal link or an AI-suggested URL must keep the chat OPEN (like
    // Intercom / Drift). Several of those buttons remove themselves on click,
    // and a former "click-outside-to-close" listener then saw the detached
    // element as "outside" and wrongly closed the panel — that behaviour is
    // removed. The chat now closes ONLY via the Close (×) button, the menu
    // "Exit", or the Esc key.
    document.addEventListener('keydown', function (event) {
        if ('Escape' === event.key && !panel.classList.contains('is-hidden')) {
            closePanel();
        }
    });

    // ---- Header three-dot menu: New Chat / Clear Chat ----
    function closeMenu() {
        if (menuDropdown) { menuDropdown.classList.add('is-hidden'); }
        if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); }
    }
    function openMenu() {
        if (menuDropdown) { menuDropdown.classList.remove('is-hidden'); }
        if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'true'); }
    }

    if (menuBtn && menuDropdown) {
        menuBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            if (menuDropdown.classList.contains('is-hidden')) {
                openMenu();
            } else {
                closeMenu();
            }
        });
        menuDropdown.addEventListener('click', function (event) {
            var item = event.target && event.target.closest ? event.target.closest('[data-action]') : null;
            if (!item) { return; }
            var action = item.getAttribute('data-action');
            if ('new-chat' === action) {
                startNewChat();
            } else if ('clear-chat' === action) {
                clearChat();
            } else if ('exit' === action) {
                closeMenu();
                closePanel();
                return;
            }
            closeMenu();
        });
        // Close the menu when clicking anywhere else.
        document.addEventListener('click', function (event) {
            if (menuDropdown.classList.contains('is-hidden')) { return; }
            if (menuDropdown.contains(event.target) || event.target === menuBtn) { return; }
            closeMenu();
        });
    }

    // New Chat / Clear History links (Home) — the redesigned minimal header has
    // no three-dot menu, so these expose the same actions.
    var newChatLink = widget.querySelector('#wp-ai-agent-newchat');
    var clearChatLink = widget.querySelector('#wp-ai-agent-clearchat');
    if (newChatLink) {
        newChatLink.addEventListener('click', function () { startNewChat(); });
    }
    if (clearChatLink) {
        clearChatLink.addEventListener('click', function () {
            if (window.confirm('Clear this conversation? This cannot be undone.')) {
                clearChat();
            }
        });
    }

    // ---- Voice Assistant (ChatGPT-style) — browser speech-to-text feeds the SAME
    // chat engine. Click mic → speak → after ~2.5s of silence (or a second click)
    // recording auto-stops, the transcript is sent automatically, and the reply is
    // read aloud when Voice Reply is on. (If the admin "manual send" toggle is on,
    // the transcript instead lands in the input for review.) No backend/AI change. ----

    // Stop ALL speech immediately (ChatGPT-style): cancel current + queued audio.
    function stopSpeaking() {
        try {
            if (window.speechSynthesis) { window.speechSynthesis.cancel(); }
        } catch (e) { /* ignore */ }
        if (voiceState === 'speaking') { setVoiceState('idle'); }
    }

    // Floating status badge (shown ABOVE the input — the input placeholder is
    // NEVER changed to "Listening…"). Auto-hides.
    var voiceStatusEl = null;
    var voiceStatusTimer = null;
    var silenceTimer = null; // auto-stops recording after a few seconds of silence

    function ensureVoiceStatus() {
        if (voiceStatusEl) { return voiceStatusEl; }
        voiceStatusEl = document.createElement('div');
        voiceStatusEl.className = 'wp-ai-agent-voice-status is-hidden';
        voiceStatusEl.setAttribute('aria-live', 'polite');
        if (form && form.parentNode) { form.parentNode.insertBefore(voiceStatusEl, form); }
        return voiceStatusEl;
    }
    function showVoiceStatus(text, kind, duration) {
        var el = ensureVoiceStatus();
        el.textContent = text;
        el.className = 'wp-ai-agent-voice-status wpaia-vs-' + ( kind || 'listening' );
        if (voiceStatusTimer) { clearTimeout(voiceStatusTimer); voiceStatusTimer = null; }
        if (duration) { voiceStatusTimer = setTimeout(hideVoiceStatus, duration); }
    }
    function hideVoiceStatus() {
        if (voiceStatusTimer) { clearTimeout(voiceStatusTimer); voiceStatusTimer = null; }
        if (voiceStatusEl) { voiceStatusEl.className = 'wp-ai-agent-voice-status is-hidden'; }
    }

    // Focus the input and put the caret at the END of the recognised text.
    function focusInputEnd() {
        if (!input) { return; }
        input.focus();
        try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) { /* ignore */ }
    }

    function voiceErrorText(err) {
        if ('not-allowed' === err || 'service-not-allowed' === err) {
            return 'Microphone access is blocked. Please allow it in your browser and try again.';
        }
        if ('audio-capture' === err) {
            return 'No microphone was found. Please check your device.';
        }
        return 'I couldn’t understand that. Please try again.';
    }

    // Single-active voice state. The input placeholder stays clean; state is shown
    // via a floating status badge (above the input) + the mic-button animation.
    function setVoiceState(state) {
        voiceState = state;
        if (voiceBtn) {
            voiceBtn.classList.toggle('is-listening', state === 'listening');
            voiceBtn.classList.toggle('is-speaking', state === 'speaking');
        }
        if ('listening' === state) {
            showVoiceStatus('🎤 Listening…', 'listening');
        } else if ('processing' === state) {
            showVoiceStatus('🧠 Processing…', 'processing');
        } else if ('speaking' === state) {
            showVoiceStatus('🔊 Speaking…', 'speaking');
        } else if ('ready' === state) {
            showVoiceStatus('✓ Ready', 'ready', 1200); // brief confirmation, then auto-hide
        } else {
            hideVoiceStatus();
        }
    }

    function stopListening() {
        listening = false;
        if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
        if (recognition) {
            try { recognition.abort(); } catch (e) { /* ignore */ }
        }
        setVoiceState('idle');
    }

    function startVoice() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        openPanel();
        setView('chat');
        stopSpeaking(); // stop any playback first (no two voices at once)

        if (!SR) {
            appendMessage('bot', 'Voice input isn’t supported in this browser — please type your question instead. 😊');
            return;
        }

        // Toggle: a second click while listening = "Stop" → finalise & send the
        // captured speech (recognition.stop() lets onend deliver the transcript).
        if (listening && recognition) {
            try { recognition.stop(); } catch (e) { /* ignore */ }
            return;
        }
        // Any other stale session is discarded before a fresh start.
        if (recognition) {
            try { recognition.abort(); } catch (e) { /* ignore */ }
        }

        var cfg = window.wpAiAgentData || {};
        var manualSend = !!cfg.manualSend; // admin can force review-before-send
        var rec = new SR();
        recognition = rec;
        rec.lang = cfg.lang || 'en-US';
        rec.interimResults = true;   // live transcript so silence detection is snappy
        rec.continuous = true;       // keep listening across brief pauses in speech
        rec.maxAlternatives = 1;
        var finalText = '';      // accumulated final segments
        var capturedText = '';   // full transcript (final + interim), used to send
        var gotResult = false;
        var recError = '';       // '' | 'no-speech' | other error code
        listening = true;
        setVoiceState('listening');
        // The input starts empty and — in AUTO mode — STAYS empty; the recognised
        // speech goes straight to a chat bubble, never into the input box.
        if (input) { input.value = ''; autoGrowInput(); }

        // Auto-stop after a short silence — mimics ChatGPT's "wait until you're
        // done speaking" behaviour. Re-armed on every recognised chunk.
        function armSilence() {
            if (silenceTimer) { clearTimeout(silenceTimer); }
            silenceTimer = setTimeout(function () {
                if (recognition === rec) {
                    try { rec.stop(); } catch (e) { /* ignore */ }
                }
            }, 2500);
        }

        rec.onresult = function (event) {
            if (recognition !== rec) { return; } // ignore a superseded session
            var interim = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var r = event.results[i];
                if (r.isFinal) { finalText += r[0].transcript; }
                else { interim += r[0].transcript; }
            }
            capturedText = (finalText + ' ' + interim).replace(/\s{2,}/g, ' ').trim();
            if (capturedText) { gotResult = true; }
            // MANUAL mode only: mirror the live transcript into the input for review.
            // AUTO mode: the input is left untouched (empty).
            if (manualSend && input) { input.value = capturedText; autoGrowInput(); }
            armSilence();
        };
        rec.onerror = function (e) {
            if (recognition !== rec) { return; }
            var err = e && e.error ? e.error : '';
            if ('no-speech' === err) {
                recError = 'no-speech'; // onend surfaces the "couldn't hear" note
            } else if ('aborted' !== err) {
                recError = err || 'error';
                listening = false;
                if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
                setVoiceState('idle');
                if (!gotResult) {
                    showVoiceStatus('I couldn’t understand your request. Please try again.', 'error', 2800);
                }
            }
        };
        rec.onend = function () {
            if (recognition !== rec) { return; }
            listening = false;
            if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
            setVoiceState('idle');
            var toSend = capturedText.trim();
            if (!gotResult || !toSend) {
                // Distinguish "heard nothing" from "recognition failed".
                if ('' !== recError && 'no-speech' !== recError) {
                    showVoiceStatus('I couldn’t understand your request. Please try again.', 'error', 2800);
                } else {
                    showVoiceStatus('I couldn’t hear anything. Please try again.', 'error', 2800);
                }
                return;
            }
            if (manualSend) {
                focusInputEnd(); // admin opted into review-before-send (input holds the text)
                return;
            }
            // ChatGPT-style: send automatically. The input is NEVER touched — the
            // recognised speech is submitted as a normal user chat bubble and saved
            // in history exactly like a typed message.
            pendingVoiceSend = true;
            submitQuery(toSend);
        };
        try {
            rec.start();
            armSilence();
        } catch (e) {
            listening = false;
            if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
            setVoiceState('idle');
        }
    }

    if (voiceBtn) {
        voiceBtn.addEventListener('click', startVoice);
    }

    // Human-friendly speech: never read raw URLs aloud.
    function speechText(text) {
        var t = String(text == null ? '' : text);
        var hadUrl = /(https?:\/\/|www\.)/i.test(t);
        t = t.replace(/https?:\/\/[^\s]+/gi, ' ').replace(/\bwww\.[^\s]+/gi, ' ');
        t = t.replace(/\s+([.,!?;:])/g, '$1').replace(/\s{2,}/g, ' ').trim();
        if (hadUrl && t.length < 8) {
            return 'You can open the link I\'ve shared in the chat.';
        }
        if (hadUrl && t.length) {
            t += ' You can tap the link in the chat to open it.';
        }
        return t;
    }

    // Speak a bot reply — only when Voice Reply is enabled AND the turn was
    // composed by voice. Applies admin speech rate/pitch/volume/language.
    function speakReply(text) {
        var cfg = window.wpAiAgentData || {};
        if (!cfg.voiceReply || !voiceActive || !window.speechSynthesis) { return; }
        var say = speechText(text);
        if (!say) { return; }
        try {
            stopSpeaking(); // never two voices at once
            var u = new SpeechSynthesisUtterance(say.slice(0, 500));
            u.lang = cfg.lang || 'en-US';
            u.rate = cfg.speechRate ? parseFloat(cfg.speechRate) : 1;
            u.pitch = cfg.speechPitch ? parseFloat(cfg.speechPitch) : 1;
            u.volume = (cfg.speechVolume !== undefined && cfg.speechVolume !== '') ? parseFloat(cfg.speechVolume) : 1;
            u.onstart = function () { setVoiceState('speaking'); };
            u.onend = function () {
                if (voiceState === 'speaking') { setVoiceState('ready'); }
                maybeResumeVoice(); // continuous conversation: listen for the next turn
            };
            window.speechSynthesis.speak(u);
        } catch (e) { /* ignore */ }
    }

    // Continuous Voice Conversation (ChatGPT-style): after the assistant finishes
    // speaking a voice reply, automatically re-open the mic for the next turn so the
    // visitor can keep talking hands-free. Guarded so it only runs when it should:
    //  • Voice Reply is on and manual-send is off,
    //  • the Voice button is actually available (mobile/tablet — hidden on desktop),
    //  • the widget is open and nothing else is in flight.
    // If the visitor stays silent, the silence timer ends the turn with a gentle
    // "I couldn't hear anything" and the loop naturally stops.
    function maybeResumeVoice() {
        var cfg = window.wpAiAgentData || {};
        if (!cfg.voiceReply || cfg.manualSend) { return; }
        if (!voiceBtn || voiceBtn.offsetParent === null) { return; } // hidden on desktop
        if (!panel || panel.classList.contains('is-hidden')) { return; }
        if (busy || listening) { return; }
        setTimeout(function () {
            if (!busy && !listening && voiceBtn && voiceBtn.offsetParent !== null
                && panel && !panel.classList.contains('is-hidden')) {
                startVoice();
            }
        }, 700);
    }

    // Wipe the visible conversation and show the welcome message again. Shared by
    // New Chat and Clear Chat.
    function resetConversationArea() {
        // New Chat / Clear Chat must stop any speech + recording and reset voice.
        stopSpeaking();
        stopListening();
        pendingVoiceSend = false;
        voiceActive = false;
        messages.innerHTML = '';
        quickActionsEl = null;
        clearPendingImage();
        history = [];
        renderWelcomeMessage();
    }

    // New Chat: start a brand-new session (server-side flow/context is keyed by
    // session id, so a new id resets memory for guests AND logged-in users), then
    // show the welcome screen.
    function startNewChat() {
        // New Chat: fresh session id. The PREVIOUS conversation is NOT deleted —
        // it stays archived on the server until its 24h expiry.
        sessionId = newGuestId();
        try {
            window.localStorage.setItem('wpAiAgentSessionId', sessionId);
            window.localStorage.setItem('wpAiAgentSessionTs', String(Date.now()));
        } catch (e) { /* ignore */ }
        STORAGE_KEY = 'wpAiAgentHistory_' + sessionId + '_' + pageKey;
        resetConversationArea();
        // Refresh the home cards too, so returning Home shows a clean slate.
        renderHomeCards(dataCfg.homeCards || dataCfg.quickActions);
        // Open a fresh CHAT view (visible confirmation the new chat started) with
        // the welcome greeting + quick actions ready to go.
        renderQuickActions(dataCfg.quickActions);
        setView('chat');
    }

    // Clear Chat: permanently delete this guest's conversation, flow state and
    // remembered context on the SERVER, then start a brand-new session id.
    function clearChat() {
        var cfg = window.wpAiAgentData || {};
        if (cfg.clearUrl) {
            try {
                fetch(cfg.clearUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: sessionId }),
                    keepalive: true
                });
            } catch (e) { /* ignore */ }
        }
        sessionId = newGuestId();
        try {
            window.localStorage.setItem('wpAiAgentSessionId', sessionId);
            window.localStorage.setItem('wpAiAgentSessionTs', String(Date.now()));
        } catch (e) { /* ignore */ }
        STORAGE_KEY = 'wpAiAgentHistory_' + sessionId + '_' + pageKey;
        resetConversationArea();
        setView('chat');
    }

    // Load this visitor's previous conversation for THIS page from the server
    // (session-specific + page-specific, so history never leaks across pages).
    // Falls back to the localStorage cache when offline or unconfigured.
    function loadConversationHistory() {
        var cfg = window.wpAiAgentData || {};

        var done = function (items) {
            (items || []).forEach(function (item) {
                appendMessage(item.role, item.text);
            });
        };

        if (!cfg.historyUrl) {
            done(history);
            return;
        }

        var url = cfg.historyUrl
            + (cfg.historyUrl.indexOf('?') === -1 ? '?' : '&')
            + 'session_id=' + encodeURIComponent(sessionId)
            + '&page_url=' + encodeURIComponent(window.location.href);

        fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                var msgs = (body && body.messages && body.messages.length) ? body.messages : null;
                if (msgs) {
                    // Server is authoritative; sync the local cache to it.
                    history = msgs.slice();
                    saveHistory();
                    done(msgs);
                } else {
                    done(history);
                }
            })
            .catch(function () {
                done(history);
            });
    }

    if (toggle) {
        toggle.addEventListener('click', openPanel);
    }

    // Header "Contact" button: one tap starts the lead-capture flow, so a
    // visitor can leave their details without knowing any keywords.
    if (contactBtn) {
        contactBtn.addEventListener('click', function () {
            openPanel();
            setView('chat');
            submitQuery('I want to get in touch with your team');
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            handleSend();
        });
    }

    // Enter sends the message; Shift+Enter inserts a new line (like ChatGPT).
    if (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                handleSend();
            }
        });
        // Grow the textarea with its content, up to the CSS max-height.
        input.addEventListener('input', autoGrowInput);
    }

    // Lock/unlock the chat while a request is being processed (chat queue): only
    // one request at a time — disable Send, image upload, and the Enter key.
    function setBusy(state) {
        busy = state;
        if (submitBtn) {
            submitBtn.disabled = state;
            submitBtn.style.opacity = state ? '0.5' : '';
            submitBtn.style.cursor = state ? 'not-allowed' : '';
        }
        if (imageBtn) {
            imageBtn.disabled = state;
            imageBtn.style.opacity = state ? '0.5' : '';
            imageBtn.style.pointerEvents = state ? 'none' : '';
        }
    }

    // A varied "thinking" line so the loading state never feels canned.
    function loadingPhrase() {
        var lines = ['AI is typing…', 'Searching the website…', 'Finding the best results…', 'Just a moment…'];
        return lines[Math.floor(Math.random() * lines.length)];
    }

    // Send handler shared by the form button and the Enter key. When an image is
    // staged, it is sent TOGETHER with any typed note; otherwise a normal message.
    function handleSend() {
        if (busy) { return; } // a request is already being processed
        var text = input ? input.value.trim() : '';

        if (pendingImage) {
            var dataUrl = pendingImage.dataUrl;
            clearPendingImage();
            setView('chat');
            clearQuickActions();
            if (text) {
                appendMessage('human', text);
                rememberMessage('human', text);
            }
            appendImageMessage(dataUrl);
            if (input) { input.value = ''; }
            resetInputHeight();
            setBusy(true);
            var loadingEl = appendMessage('bot', text ? 'Searching…' : 'Searching for similar products…', true);
            sendImage(dataUrl, loadingEl, text);
            return;
        }

        if (!text) {
            return;
        }
        submitQuery(text);
        if (input) { input.value = ''; }
        resetInputHeight();
    }

    function autoGrowInput() {
        if (!input) { return; }
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    function resetInputHeight() {
        if (input) { input.style.height = ''; }
    }

    // Visual search: upload a product image to find similar products.
    if (imageBtn && imageInput) {
        var cfg = window.wpAiAgentData || {};
        if (!cfg.imageSearch) {
            imageBtn.style.display = 'none';
        }
        imageBtn.addEventListener('click', function () {
            imageInput.click();
        });
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            imageInput.value = '';
            if (!file) {
                return;
            }
            setView('chat');
            downscaleImage(file, function (dataUrl) {
                // Stage the image for preview — do NOT send until the user hits
                // Send. This lets them add a note ("in red", "similar products").
                pendingImage = { dataUrl: dataUrl };
                showPendingPreview(dataUrl);
                if (input) { input.focus(); }
            });
        });
    }

    // Show the staged image above the input with a remove (×) control.
    function showPendingPreview(dataUrl) {
        if (!form) { return; }
        if (!previewBar) {
            previewBar = document.createElement('div');
            previewBar.className = 'wp-ai-agent-preview';
            form.parentNode.insertBefore(previewBar, form);
        }
        previewBar.innerHTML = '';

        var thumb = document.createElement('img');
        thumb.className = 'wp-ai-agent-preview-img';
        thumb.src = dataUrl;
        thumb.alt = '';

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'wp-ai-agent-preview-remove';
        remove.setAttribute('aria-label', 'Remove image');
        remove.textContent = '×';
        remove.addEventListener('click', clearPendingImage);

        previewBar.appendChild(thumb);
        previewBar.appendChild(remove);
        previewBar.classList.remove('is-hidden');

        if (input) { input.placeholder = 'Add a note (optional), e.g. "in red"'; }
    }

    function clearPendingImage() {
        pendingImage = null;
        if (previewBar) {
            previewBar.innerHTML = '';
            previewBar.classList.add('is-hidden');
        }
        if (input) { input.placeholder = defaultPlaceholder; }
    }

    // Read an image file and shrink it (max 768px, JPEG) to keep uploads light.
    function downscaleImage(file, cb) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = new Image();
            img.onload = function () {
                var max = 768;
                var w = img.width;
                var h = img.height;
                if (w > max || h > max) {
                    if (w >= h) {
                        h = Math.round(h * max / w);
                        w = max;
                    } else {
                        w = Math.round(w * max / h);
                        h = max;
                    }
                }
                try {
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    cb(canvas.toDataURL('image/jpeg', 0.8));
                } catch (err) {
                    cb(e.target.result); // fallback: original data URL
                }
            };
            img.onerror = function () { cb(e.target.result); };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    // Render clickable reply buttons under a bot message. An action is either a
    // link button (action.url — e.g. "Continue on WhatsApp", opens a new tab and
    // tracks the click) or a query button (action.query — sends that message).
    function renderReplyActions(actions) {
        var cfg = window.wpAiAgentData || {};
        var wrap = document.createElement('div');
        wrap.className = 'wp-ai-agent-quick-actions wp-ai-agent-reply-actions';

        actions.forEach(function (action) {
            var el;
            if (action.url) {
                el = document.createElement('a');
                el.className = 'wp-ai-agent-quick-btn' + (action.same_tab ? '' : ' wp-ai-agent-wa-btn');
                el.href = action.url;
                // Login/Register links navigate the same tab (so the redirect
                // brings the user back logged-in); others open in a new tab.
                if (!action.same_tab) {
                    el.target = '_blank';
                    el.rel = 'noopener noreferrer';
                }
                setActionLabel(el, action);
                el.addEventListener('click', function (event) {
                    // Contact form on the CURRENT page → scroll to it instead of
                    // reloading, so the chat stays open. Prefer a form with an
                    // email field; skip the widget's own message form.
                    if (action.scroll) {
                        var target = null;
                        var allForms = document.querySelectorAll('form');
                        for (var i = 0; i < allForms.length; i++) {
                            var fm = allForms[i];
                            if (fm.id === 'wp-ai-agent-form' || fm.closest('#wp-ai-agent-widget')) { continue; }
                            if (!target) { target = fm; }
                            if (fm.querySelector('input[type="email"]')) { target = fm; break; }
                        }
                        if (target) {
                            event.preventDefault();
                            try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                            catch (e) { target.scrollIntoView(); }
                            var field = target.querySelector('input, textarea, select');
                            if (field) { try { field.focus(); } catch (e) { /* ignore */ } }
                        }
                    }
                    // Track the handoff click (best-effort, non-blocking).
                    if ('handoff' === action.track && cfg.handoffUrl) {
                        try {
                            fetch(cfg.handoffUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    session_id: sessionId,
                                    page_url: window.location.href,
                                    query: action.query || ''
                                }),
                                keepalive: true
                            });
                        } catch (e) { /* ignore */ }
                    }
                    if (wrap.parentNode) {
                        wrap.parentNode.removeChild(wrap);
                    }
                });
            } else {
                el = document.createElement('button');
                el.type = 'button';
                el.className = 'wp-ai-agent-quick-btn';
                setActionLabel(el, action);
                el.addEventListener('click', function () {
                    if (wrap.parentNode) {
                        wrap.parentNode.removeChild(wrap);
                    }
                    submitQuery(action.query || action.label);
                });
            }
            wrap.appendChild(el);
        });

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    // Render guided options as a premium LIST card — full-width white rows with a
    // leading icon, the label, and a chevron (used for category / step choosers).
    // Each row continues the same conversation (no reload, no reset).
    function renderReplyList(items) {
        var wrap = document.createElement('div');
        wrap.className = 'wp-ai-agent-list';

        items.forEach(function (item) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'wp-ai-agent-list-item';

            var icon = makeIcon(iconNameFor(item.label, item.query));
            icon.className = 'wp-ai-agent-list-icon';
            row.appendChild(icon);

            var label = document.createElement('span');
            label.className = 'wp-ai-agent-list-label';
            label.textContent = stripLeadingEmoji(item.label);
            row.appendChild(label);

            var chev = makeIcon('chevron');
            chev.className = 'wp-ai-agent-ic wp-ai-agent-list-chev';
            row.appendChild(chev);

            row.addEventListener('click', function () {
                if (item.url) {
                    window.open(item.url, '_blank', 'noopener');
                    return;
                }
                submitQuery(item.query || item.label);
            });
            wrap.appendChild(row);
        });

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    // Render a row of rich product cards (image, name, price, Add to Cart, View).
    function renderProductCards(products) {
        var wrap = document.createElement('div');
        wrap.className = 'wp-ai-agent-products';

        products.forEach(function (p) {
            var card = document.createElement('div');
            card.className = 'wp-ai-agent-product-card';

            if (p.image) {
                var img = document.createElement('img');
                img.className = 'wp-ai-agent-product-img';
                img.src = p.image;
                img.alt = p.name || '';
                img.loading = 'lazy';
                card.appendChild(img);
            }

            var info = document.createElement('div');
            info.className = 'wp-ai-agent-product-info';

            var name = document.createElement('a');
            name.className = 'wp-ai-agent-product-name';
            name.href = p.url;
            name.target = '_blank';
            name.rel = 'noopener noreferrer';
            name.textContent = p.name || '';
            info.appendChild(name);

            if (p.category) {
                var cat = document.createElement('div');
                cat.className = 'wp-ai-agent-product-cat';
                cat.textContent = p.category;
                info.appendChild(cat);
            }

            if (p.price) {
                var price = document.createElement('div');
                price.className = 'wp-ai-agent-product-price';
                price.textContent = p.price;
                info.appendChild(price);
            }

            if (p.short) {
                var desc = document.createElement('div');
                desc.className = 'wp-ai-agent-product-desc';
                desc.textContent = p.short;
                info.appendChild(desc);
            }

            // Out-of-stock badge (Rule 5): don't encourage buying.
            if (p.in_stock === false) {
                var oos = document.createElement('div');
                oos.className = 'wp-ai-agent-product-oos';
                oos.textContent = 'Currently Out of Stock';
                info.appendChild(oos);
            }

            var actions = document.createElement('div');
            actions.className = 'wp-ai-agent-product-actions';

            // Only the "View" button is shown on product cards. The Add to Cart /
            // Select options action was intentionally removed so the card simply
            // links through to the product page.
            var view = document.createElement('a');
            view.className = 'wp-ai-agent-view-btn';
            view.href = p.url;
            view.target = '_blank';
            view.rel = 'noopener noreferrer';
            view.textContent = 'View';
            actions.appendChild(view);

            info.appendChild(actions);
            card.appendChild(info);
            wrap.appendChild(card);
        });

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    // Show the uploaded image as a user-side bubble (not persisted to history).
    function appendImageMessage(dataUrl) {
        var item = document.createElement('div');
        item.className = 'wp-ai-agent-message wp-ai-agent-message-human';
        var img = document.createElement('img');
        img.src = dataUrl;
        img.className = 'wp-ai-agent-uploaded-img';
        item.appendChild(img);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    function sendImage(dataUrl, loadingEl, text) {
        var cfg = window.wpAiAgentData || {};
        if (!cfg.imageUrl) {
            resolveMessage(loadingEl, 'Image search is not available right now.');
            return;
        }
        fetch(cfg.imageUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                image: dataUrl,
                message: text || '',
                session_id: sessionId,
                page_url: window.location.href
            }),
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var body = {};
                    try {
                        body = JSON.parse(text.replace(/^﻿/, '').trim());
                    } catch (e) {
                        body = { error: 'Unexpected response from server.' };
                    }
                    return { body: body, status: response.status };
                });
            })
            .then(function (result) {
                var body = result.body;
                var answer;
                if (body && body.code && !body.message) {
                    answer = 'Sorry, image search could not be reached. Please try again.';
                } else if (body && body.message) {
                    answer = body.message;
                } else if (body && body.error) {
                    answer = body.error;
                } else {
                    answer = 'No similar products found.';
                }
                resolveMessage(loadingEl, answer);
                // Render the matched product cards returned by the visual search.
                if (body && body.data && body.data.products && body.data.products.length) {
                    renderProductCards(body.data.products);
                }
            })
            .catch(function (error) {
                resolveMessage(loadingEl, 'Unable to search by image right now. ' + (error ? error.message : ''));
            });
    }

    // Send a user query to the AI (shared by the input form and quick actions).
    function submitQuery(text) {
        if (!text) {
            return;
        }
        if (busy) { return; } // one request at a time (quick actions included)
        stopSpeaking(); // a new message stops any ongoing reply playback
        // Was this turn composed by voice? (transcript sat in the input.)
        voiceActive = pendingVoiceSend;
        pendingVoiceSend = false;
        if (voiceActive) { setVoiceState('processing'); }
        touchSession(); // keep the 24h guest window alive on activity
        setView('chat');
        clearQuickActions();
        appendMessage('human', text);

        rememberMessage('human', text);
        setBusy(true);
        // Keep a reference to the loading bubble so we can replace it with
        // the answer instead of leaving it on screen.
        var loadingEl = appendMessage('bot', loadingPhrase(), true);
        sendMessage(text, loadingEl);
    }

    // True when the text is just a greeting / generic opener.
    function isGreeting(text) {
        var t = String(text).toLowerCase().replace(/[^a-z\s]/g, '').trim();
        if (!t) {
            return false;
        }
        var greetings = [
            'hi', 'hii', 'hiii', 'hey', 'heyy', 'hello', 'helo', 'hlo', 'hy',
            'yo', 'hola', 'namaste', 'namaskar', 'salaam', 'salam', 'start',
            'menu', 'help', 'good morning', 'good afternoon', 'good evening',
            'hi there', 'hello there'
        ];
        return greetings.indexOf(t) !== -1;
    }

    // Show the welcome greeting bubble (used on load and for greetings).
    function showWelcome() {
        var data = window.wpAiAgentData || {};
        renderWelcomeMessage();
        renderQuickActions(data.quickActions);
    }

    // Restore a returning guest's recent conversation (session-wide, last 24h)
    // from the server. If there's a thread to resume, replay it with a "Welcome
    // back" note (and the saved filters, if any) and open the Chat view. If not,
    // just show the normal welcome greeting.
    function restoreOrWelcome() {
        var cfg = window.wpAiAgentData || {};
        if (!cfg.historyUrl) {
            renderWelcomeMessage();
            return;
        }
        var url = cfg.historyUrl
            + (cfg.historyUrl.indexOf('?') === -1 ? '?' : '&')
            + 'session_id=' + encodeURIComponent(sessionId)
            + '&hours=24';

        fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (body) {
                var msgs = (body && body.messages && body.messages.length) ? body.messages : [];
                if (msgs.length) {
                    var note = 'Welcome back! 👋';
                    if (body.resume) {
                        note += ' You were previously discussing:\n' + body.resume
                            + '\n\nWould you like to continue where you left off?';
                    }
                    var bubble = appendMessage('bot', note);
                    bubble.className += ' wp-ai-agent-welcome';
                    msgs.forEach(function (item) { appendMessage(item.role, item.text); });
                    history = msgs.slice();
                    setView('chat');
                } else {
                    renderWelcomeMessage();
                }
            })
            .catch(function () { renderWelcomeMessage(); });
    }

    // Render just the welcome greeting bubble.
    function renderWelcomeMessage() {
        var data = window.wpAiAgentData || {};
        if (!data.welcome) {
            return;
        }
        var bubble = appendMessage('bot', data.welcome);
        bubble.className += ' wp-ai-agent-welcome';
    }

    function renderQuickActions(actions) {
        if (!actions || !actions.length) {
            return;
        }
        quickActionsEl = document.createElement('div');
        quickActionsEl.className = 'wp-ai-agent-quick-actions';
        actions.forEach(function (action) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wp-ai-agent-quick-btn';
            setActionLabel(btn, action);
            btn.addEventListener('click', function () {
                submitQuery(action.query || action.label);
            });
            quickActionsEl.appendChild(btn);
        });
        messages.appendChild(quickActionsEl);
        messages.scrollTop = messages.scrollHeight;
    }

    function clearQuickActions() {
        if (quickActionsEl && quickActionsEl.parentNode) {
            quickActionsEl.parentNode.removeChild(quickActionsEl);
        }
        quickActionsEl = null;
    }

    function appendMessage(role, text, isLoading) {
        var item = document.createElement('div');
        item.className = 'wp-ai-agent-message wp-ai-agent-message-' + role;
        if (isLoading) {
            item.className += ' wp-ai-agent-loading';
        }
        setBubbleText(item, text, role);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
        return item;
    }

    // Put text in a bubble. For bot messages, turn any URLs into clickable
    // link buttons (built as DOM nodes, so it stays XSS-safe).
    function setBubbleText(el, text, role) {
        el.innerHTML = '';
        text = String(text == null ? '' : text);

        if ('bot' !== role) {
            el.appendChild(document.createTextNode(text));
            return;
        }

        var urlRe = /(https?:\/\/[^\s<>"']+)/g;
        var last = 0;
        var m;
        while ((m = urlRe.exec(text)) !== null) {
            if (m.index > last) {
                el.appendChild(document.createTextNode(text.slice(last, m.index)));
            }
            var raw = m[0];
            var url = raw.replace(/[.,;:!?)]+$/, ''); // drop trailing punctuation
            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = 'wp-ai-agent-link-btn';
            a.innerHTML = miSvg('open') + '<span>' + 'View' + '</span>';
            el.appendChild(a);
            if (raw.length > url.length) {
                el.appendChild(document.createTextNode(raw.slice(url.length)));
            }
            last = m.index + raw.length;
        }
        if (last < text.length) {
            el.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    function resolveMessage(targetEl, text) {
        if (targetEl) {
            targetEl.classList.remove('wp-ai-agent-loading');
            setBubbleText(targetEl, text, 'bot');
            messages.scrollTop = messages.scrollHeight;
        } else {
            appendMessage('bot', text);
        }
        // The loading bubble is never stored; persist only the final answer.
        rememberMessage('bot', text);
        // Voice status transitions: Processing → (Speaking → Ready) or → Ready.
        var wasVoice = voiceActive;
        var cfg = window.wpAiAgentData || {};
        var willSpeak = wasVoice && cfg.voiceReply && window.speechSynthesis && !!speechText(text);
        if (voiceState === 'processing') { setVoiceState('idle'); }
        // Read the answer aloud when the turn began by voice (if enabled), then
        // reset the voice flag for the next turn.
        speakReply(text);
        if (wasVoice && !willSpeak) { setVoiceState('ready'); } // brief "✓ Ready"
        voiceActive = false;
        // Request finished — unlock the chat for the next message.
        setBusy(false);
    }

    function sendMessage(message, loadingEl) {
        // NOTE: We intentionally do NOT send the X-WP-Nonce header.
        // The /chat endpoint is public (permission_callback => __return_true),
        // and sending a stale/expired nonce (e.g. from a cached page) makes
        // WordPress reject the request with a 403 "rest_cookie_invalid_nonce"
        // BEFORE the permission callback runs. Omitting the nonce makes WP treat
        // the call as an anonymous request, which is allowed.
        fetch(wpAiAgentData.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                message: message,
                session_id: sessionId,
                page_url: window.location.href
            }),
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var body = {};
                    // Strip a leading UTF-8 BOM / stray whitespace that a theme or
                    // another plugin may emit before the JSON, which would
                    // otherwise break JSON.parse and hide a valid response.
                    var cleaned = text.replace(/^﻿/, '').trim();
                    try {
                        body = JSON.parse(cleaned);
                    } catch (e) {
                        body = { error: 'Unexpected response from server: ' + text };
                    }
                    return { body: body, status: response.status };
                });
            })
            .then(function (result) {
                var body = result.body;
                var answer;
                if (body && body.code && body.data) {
                    // A WordPress REST error (e.g. rest_no_route, rest_forbidden).
                    // Don't show the raw technical message as if it were an answer.
                    answer = 'Sorry, I could not reach the assistant right now. Please refresh the page and try again.';
                } else if (body && body.message) {
                    answer = body.message;
                } else if (body && body.error) {
                    answer = body.error;
                } else {
                    answer = 'AI service returned an unexpected response (HTTP ' + result.status + ').';
                }
                resolveMessage(loadingEl, answer);
                // Render rich product cards (image, price, Add to Cart, View)
                // when the agent returns structured products.
                if (body && body.data && body.data.products && body.data.products.length) {
                    renderProductCards(body.data.products);
                }
                // Render premium list cards (category / step options) when the
                // agent returns a guided list.
                if (body && body.data && body.data.list && body.data.list.length) {
                    renderReplyList(body.data.list);
                }
                // Render clickable reply chips (e.g. Login / Register, colour /
                // budget refine) when the agent returns suggested actions.
                if (body && body.data && body.data.actions && body.data.actions.length) {
                    renderReplyActions(body.data.actions);
                }
                // Note: the server logs every conversation, so no separate
                // client-side logging call is needed here.
            })
            .catch(function (error) {
                resolveMessage(loadingEl, 'Unable to reach the AI service. Please try again later. ' + (error ? error.message : ''));
            });
    }
})();




