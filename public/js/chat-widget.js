/**
 * AK Deep Knowledge AI Chatbot - front-end chat widget.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const chatWindow   = document.getElementById('wp-ai-chatbot-window');
    const closeBtn     = document.getElementById('wp-ai-close-chat');
    const chatInput    = document.getElementById('wp-ai-chat-input');
    const sendBtn      = document.getElementById('wp-ai-send-btn');
    const chatBody     = document.getElementById('wp-ai-chat-body');
    const chatToggle   = document.getElementById('wp-ai-chatbot-toggle');
    const fabContainer = document.getElementById('wp-ai-fab-container');
    const aiChatBtn    = document.getElementById('wp-ai-open-chat-btn');

    if (!chatWindow || !chatBody || !chatInput || !sendBtn || typeof window.wpAiChatbotData === 'undefined') return;

    const DATA          = window.wpAiChatbotData;
    // [akdk_chatbot]: the chat sits inside the page (always open, no launcher, no close button).
    const EMBEDDED      = chatWindow.classList.contains('wp-ai-embedded');
    // Each bot (Sales Tools > Bots) keeps its own conversation.
    const BOT_ID        = String(DATA.bot || '').replace(/[^a-z0-9_\-]/gi, '');
    const STORAGE_KEY   = 'wp_ai_chat_history_permanent' + (BOT_ID ? '_' + BOT_ID : '');
    const LIVE_KEY      = STORAGE_KEY + '_live';
    const STR           = DATA.strings || {};
    const PRE_CHAT_KEY  = 'wp_ai_pre_chat_submitted';
    const VISITOR_NAME_KEY = 'wp_ai_pre_chat_visitor_name';
    const MAX_STORED    = 100;
    const HISTORY_SIZE  = Math.max(0, parseInt(DATA.history_size, 10) || 0);
    const MAX_MSG_LEN   = parseInt(DATA.max_message_len, 10) || 1500;
    const DEFAULT_PLACEHOLDER = String(DATA.input_placeholder || 'Type your message...');
    let isSending = false;

    /* ------------------------------------------------------------------
     * Safe storage (localStorage can be blocked in private mode / iframes)
     * ------------------------------------------------------------------ */
    const memoryStore = {};
    const store = {
        get: function (key) {
            try { return window.localStorage.getItem(key); } catch (e) { return Object.prototype.hasOwnProperty.call(memoryStore, key) ? memoryStore[key] : null; }
        },
        set: function (key, value) {
            try { window.localStorage.setItem(key, value); } catch (e) { memoryStore[key] = String(value); }
        },
        remove: function (key) {
            try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
            delete memoryStore[key];
        }
    };

    /* ------------------------------------------------------------------
     * Visitor chat history lifetime (AI Settings > Visitor chat history).
     * Only this browser's copy is cleared; the admin's chat logs are kept.
     * ------------------------------------------------------------------ */
    let historyKeep  = parseInt(DATA.history_keep, 10) || 0;   // Minutes of no activity; 0 = keep; -1 = until the tab closes.
    let historyEpoch = parseInt(DATA.history_epoch, 10) || 0;  // Last "Clear all visitors' chat history" (server time, ms).
    const AT_KEY    = STORAGE_KEY + '_at';     // Last activity (this browser's clock).
    const SEEN_KEY  = 'wp_ai_history_epoch_seen';
    // "Until the tab closes": the chat lives in sessionStorage, which the browser empties with the tab.
    const tabStore = {
        get: function (key) { try { return window.sessionStorage.getItem(key); } catch (e) { return store.get(key); } },
        set: function (key, value) { try { window.sessionStorage.setItem(key, value); } catch (e) { store.set(key, value); } },
        remove: function (key) { try { window.sessionStorage.removeItem(key); } catch (e) { /* ignore */ } store.remove(key); }
    };
    const histStore = historyKeep === -1 ? tabStore : store;
    if (historyKeep === -1) { store.remove(STORAGE_KEY); store.remove(AT_KEY); } // A chat kept from an earlier setting.

    function clearVisitorHistory() {
        histStore.remove(STORAGE_KEY);
        store.remove(LIVE_KEY);
        histStore.remove(AT_KEY);
        store.remove(PRE_CHAT_KEY);
        histStore.remove(VISITOR_NAME_KEY);
        store.remove(VISITOR_NAME_KEY);
    }

    /** Clears the chat when it is too old or the admin cleared all chats. Returns true when it cleared. */
    function expireHistory() {
        if (isSending) return false;
        const hasChat = !!histStore.get(STORAGE_KEY);
        let at = parseInt(histStore.get(AT_KEY), 10) || 0;
        if (hasChat && !at) { at = Date.now(); histStore.set(AT_KEY, String(at)); } // Chat saved before this setting existed.
        let clear = false;
        const seenRaw = store.get(SEEN_KEY);
        const seen = parseInt(seenRaw, 10) || 0;
        if (historyEpoch > seen) {
            // New "clear all". Compared once per clear (not on every check), so a visitor whose
            // clock is wrong can never get their new chat cleared again and again.
            if (hasChat && (seenRaw !== null || at < historyEpoch)) clear = true;
            store.set(SEEN_KEY, String(historyEpoch));
        }
        if (hasChat && historyKeep > 0 && Date.now() - at > historyKeep * 60000) clear = true;
        if (clear) clearVisitorHistory();
        return clear;
    }

    let stateBusy = false;
    function checkHistoryState() {
        if (expireHistory() && typeof loadFromStorage === 'function' && chatBody) loadFromStorage();
        if (!DATA.history_state_url || stateBusy || !window.fetch) return;
        stateBusy = true;
        fetch(DATA.history_state_url, { cache: 'no-store', credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                historyEpoch = parseInt(d.epoch, 10) || 0;
                if (historyKeep !== -1) historyKeep = parseInt(d.keep, 10) || 0; // Page may be cached with an older setting.
                if (expireHistory() && chatBody) loadFromStorage();
            })
            .catch(function () { /* offline: try again later */ })
            .then(function () { stateBusy = false; });
    }

    function getHistory() {
        try {
            const parsed = JSON.parse(histStore.get(STORAGE_KEY));
            return Array.isArray(parsed) ? parsed.filter(function (m) { return m && typeof m.text === 'string' && typeof m.sender === 'string'; }) : [];
        } catch (e) {
            return [];
        }
    }

    function setHistory(history) {
        histStore.set(STORAGE_KEY, JSON.stringify(history.slice(-MAX_STORED)));
        histStore.set(AT_KEY, String(Date.now()));
    }

    function saveToStorage(text, sender, extra) {
        const history = getHistory();
        const item = { text: text, sender: sender };
        if (extra && Array.isArray(extra.products) && extra.products.length) item.products = extra.products.slice(0, 4);
        if (extra && extra.agent) item.agent = String(extra.agent).slice(0, 60);
        history.push(item);
        setHistory(history);
    }

    function setInputEnabled(enabled, placeholder) {
        chatInput.disabled = !enabled;
        sendBtn.disabled = !enabled;
        chatInput.placeholder = placeholder || DEFAULT_PLACEHOLDER;
    }

    /* ------------------------------------------------------------------
     * Open / close
     * ------------------------------------------------------------------ */
    function scrollToBottom() { chatBody.scrollTop = chatBody.scrollHeight; }

    // Greeting follows Appearance timing on every page visit, even with saved history.
    // No sessionStorage suppression: opening chat never permanently disables it.
    if (fabContainer && fabContainer.querySelector('.wp-ai-greet')) {
        const delay = Math.max(0, Math.min(120, parseInt(DATA.launcher_greet_delay, 10) || 0)) * 1000;
        const duration = Math.max(1, Math.min(120, parseInt(DATA.launcher_greet_duration, 10) || 8)) * 1000;
        window.setTimeout(function () {
            fabContainer.classList.add('wp-ai-greeting-visible');
            window.setTimeout(function () {
                fabContainer.classList.remove('wp-ai-greeting-visible');
            }, duration);
        }, delay);
    }

    function toggleChat() {
        if (EMBEDDED) return; // Part of the page: it is always open.
        chatWindow.classList.toggle('wp-ai-hidden');
        document.body.classList.toggle('wp-ai-chat-open');

        if (!chatWindow.classList.contains('wp-ai-hidden')) {
            unread = 0;
            if (fabContainer) {
                fabContainer.removeAttribute('data-unread');
                const pb = fabContainer.querySelector('.wp-ai-pro-bubble');
                if (pb) pb.remove();
            }
            checkHistoryState(); // Opening the chat: is it still current?
            if (!chatInput.disabled && window.innerWidth > 480) chatInput.focus();
            scrollToBottom();
            quickActionsResize();
        }
    }

    function onToggleActivate(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!chatWindow.classList.contains('wp-ai-hidden')) {
            toggleChat();
            if (fabContainer) fabContainer.classList.remove('active');
            return;
        }
        if (fabContainer && fabContainer.classList.contains('has-wa-menu')) {
            // Speed-dial: first click shows WhatsApp + AI chat, second click hides them.
            fabContainer.classList.toggle('active');
        } else {
            toggleChat();
        }
    }

    if (chatToggle) {
        chatToggle.addEventListener('click', onToggleActivate);
        chatToggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') onToggleActivate(e);
        });
    }

    if (aiChatBtn) {
        aiChatBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleChat();
            if (fabContainer) fabContainer.classList.remove('active');
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            toggleChat();
        });
    }

    document.addEventListener('click', function (e) {
        if (fabContainer && fabContainer.classList.contains('active') && !fabContainer.contains(e.target)) {
            fabContainer.classList.remove('active');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !chatWindow.classList.contains('wp-ai-hidden')) toggleChat();
    });

    /* ------------------------------------------------------------------
     * Clear history
     * ------------------------------------------------------------------ */
    const deleteBtn  = document.getElementById('wp-ai-delete-chat');
    const modal      = document.getElementById('wp-ai-confirm-modal');
    const cancelDel  = document.getElementById('wp-ai-cancel-del');
    const confirmDel = document.getElementById('wp-ai-confirm-del');

    if (deleteBtn && modal && cancelDel && confirmDel) {
        deleteBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            modal.classList.remove('wp-ai-hidden');
        });
        cancelDel.addEventListener('click', function () { modal.classList.add('wp-ai-hidden'); });
        confirmDel.addEventListener('click', function () {
            clearVisitorHistory();
            modal.classList.add('wp-ai-hidden');
            setInputEnabled(true);
            loadFromStorage();
        });
    }

    /* ------------------------------------------------------------------
     * Quick actions & guided queries
     * ------------------------------------------------------------------ */
    function isPreChatPending() {
        return !!DATA.pre_chat_form_id && !store.get(PRE_CHAT_KEY);
    }

    function checkQuickActionsVisibility() {
        const qaWrapper = document.getElementById('wp-ai-quick-actions-wrapper');
        if (!qaWrapper) return;

        const actions = DATA.quick_actions;
        if (!Array.isArray(actions) || actions.length === 0) {
            qaWrapper.style.display = 'none';
            return;
        }
        qaWrapper.style.display = 'flex';

        const disabled = isPreChatPending() || document.getElementById('wp-ai-guided-bubbles') !== null;
        qaWrapper.classList.toggle('wp-ai-disabled', disabled);
        qaWrapper.querySelectorAll('button').forEach(function (btn) { btn.disabled = disabled; });
    }

    function renderGuidedQueries() {
        if (String(DATA.is_guided_enabled) !== '1') return;

        const queries = Array.isArray(DATA.guided_queries) ? DATA.guided_queries.filter(function (q) { return typeof q === 'string' && q.trim() !== ''; }) : [];
        if (queries.length === 0) return;

        setInputEnabled(false, 'Please select an option above...');

        const old = document.getElementById('wp-ai-guided-bubbles');
        if (old) old.remove();

        const container = document.createElement('div');
        container.id = 'wp-ai-guided-bubbles';
        container.className = 'wp-ai-guided-options-container';

        queries.forEach(function (query) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wp-ai-guided-btn';
            btn.textContent = query;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                container.remove();
                setInputEnabled(true);
                checkQuickActionsVisibility();
                chatInput.value = query;
                handleSendMessage();
            });
            container.appendChild(btn);
        });

        chatBody.appendChild(container);
        scrollToBottom();
    }

    /* ------------------------------------------------------------------
     * Lead forms
     * ------------------------------------------------------------------ */

    // Must match the PHP column naming: sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) ).
    function fieldKey(label) {
        return String(label).toLowerCase().replace(/ /g, '_').replace(/[^a-z0-9_\-]/g, '');
    }

    /* ---------- External forms (links, embeds, WordPress forms, embed code) ---------- */
    function externalFormHTML(form) {
        const url = String(form.url || '');
        const safeUrl = /^https?:\/\//i.test(url) ? wpAiEscapeHtml(url) : '';
        const arrow = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"></path></svg>';
        let html = '<div class="wp-ai-form-container wp-ai-ext" data-form-id="' + wpAiEscapeHtml(form.id) + '" data-source="' + wpAiEscapeHtml(form.source) + '">';
        html += '<p class="wp-ai-form-title">' + wpAiEscapeHtml(form.title || '') + '</p>';
        if (form.note) html += '<p class="wp-ai-ext-note">' + wpAiEscapeHtml(form.note) + '</p>';
        if (!safeUrl) return html + '<p class="wp-ai-ext-note">This form is not available right now.</p></div>';

        if (form.source === 'directlink') {
            // Direct Link intentionally navigates in the current tab: no form, iframe or extra confirmation.
            return '<div class="wp-ai-ext wp-ai-direct-link" data-form-id="' + wpAiEscapeHtml(form.id) + '" data-source="directlink"><a class="wp-ai-ext-link wp-ai-direct-link-bubble" href="' + safeUrl + '" rel="noopener noreferrer">' + wpAiEscapeHtml(form.button || form.title || 'Open link') + arrow + '</a></div>';
        }
        if (form.source === 'link') {
            html += '<a class="wp-ai-submit-btn wp-ai-ext-link" href="' + safeUrl + '" target="_blank" rel="noopener">' + wpAiEscapeHtml(form.button || 'Open') + arrow + '</a>';
        } else {
            const auto = (form.source === 'shortcode' || form.source === 'html') ? ' data-auto-height="1"' : '';
            html += '<iframe class="wp-ai-ext-frame" src="' + safeUrl + '" title="' + wpAiEscapeHtml(form.title || 'Form') + '" style="height:' + (parseInt(form.height, 10) || 520) + 'px" loading="lazy" allow="payment; clipboard-write; fullscreen" referrerpolicy="strict-origin-when-cross-origin"' + auto + '></iframe>';
            if (form.source === 'embed') html += '<a class="wp-ai-ext-open" href="' + safeUrl + '" target="_blank" rel="noopener">' + wpAiEscapeHtml(form.button || 'Open in new tab') + arrow + '</a>';
        }
        html += '<p class="wp-ai-form-success" hidden>✓ Thank you! We have received it.</p>';
        return html + '</div>';
    }

    const trackedOnce = {};
    function track(formId, event) {
        const key = formId + ':' + event;
        if (trackedOnce[key]) return;
        trackedOnce[key] = true;
        if (event === 'view') {
            try { if (window.sessionStorage.getItem('wp_ai_ev_' + key)) return; window.sessionStorage.setItem('wp_ai_ev_' + key, '1'); } catch (e) { /* ignore */ }
        }
        if (!DATA.track_url) return;
        try {
            fetch(DATA.track_url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ form_id: formId, event: event }) }).catch(function () {});
        } catch (e) { /* ignore */ }
    }

    function initExternal(scope) {
        (scope || chatBody).querySelectorAll('.wp-ai-ext:not([data-ready])').forEach(function (box) {
            box.setAttribute('data-ready', '1');
            track(box.getAttribute('data-form-id'), 'view');
        });
    }

    function completePreChat() {
        store.set(PRE_CHAT_KEY, 'true');
        setInputEnabled(true);
        renderGuidedQueries();
        checkQuickActionsVisibility();
    }

    function markFormDone(box, label) {
        const history = getHistory();
        for (let i = history.length - 1; i >= 0; i--) {
            if (history[i].sender === 'bot' && history[i].text.indexOf('[SHOW_FORM_' + box.getAttribute('data-form-id') + ']') !== -1) {
                history[i].text = '*' + label + '*';
                break;
            }
        }
        setHistory(history);
    }

    function externalSubmitted(box, kind) {
        if (!box || box.getAttribute('data-done') === '1') return;
        box.setAttribute('data-done', '1');
        const formId = box.getAttribute('data-form-id');
        track(formId, kind === 'booked' ? 'booked' : 'submitted');
        const ok = box.querySelector('.wp-ai-form-success'); if (ok) ok.hidden = false;
        const title = (box.querySelector('.wp-ai-form-title') || {}).textContent || 'Form';
        if (box.closest('.is-pre-chat')) {
            completePreChat();
        } else {
            markFormDone(box, title + ' submitted successfully');
        }
        setTimeout(function () { renderMessage('Thank you! We have received your details. Anything else I can help with?', 'bot', true); }, 600);
    }

    // Visitor opened a link / "open in new tab".
    chatBody.addEventListener('click', function (e) {
        const link = e.target.closest ? e.target.closest('.wp-ai-ext-link, .wp-ai-ext-open') : null;
        if (link) {
            const box = link.closest('.wp-ai-ext');
            track(box.getAttribute('data-form-id'), 'click');
            if (box.closest('.is-pre-chat')) completePreChat();
            return;
        }
        const cont = e.target.closest ? e.target.closest('.wp-ai-ext-continue') : null;
        if (cont) { e.preventDefault(); cont.remove(); completePreChat(); }
    });

    // Messages from embedded forms: auto height and "submitted".
    window.addEventListener('message', function (e) {
        let frame = null;
        chatBody.querySelectorAll('.wp-ai-ext-frame').forEach(function (f) { if (f.contentWindow === e.source) frame = f; });
        if (!frame) return;
        const box = frame.closest('.wp-ai-ext');
        let d = e.data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (err) { d = { raw: d }; } }
        d = d || {};
        if (e.origin === window.location.origin && d.akdkForm) {
            if (d.type === 'height' && frame.hasAttribute('data-auto-height')) frame.style.height = Math.max(160, Math.min(900, parseInt(d.height, 10) + 8)) + 'px';
            if (d.type === 'submitted') externalSubmitted(box, 'submitted');
            if (d.type === 'booked') externalSubmitted(box, 'booked');
            if (d.type === 'cart') track(box.getAttribute('data-form-id'), 'cart'); // Add to cart is not a lead.
            return;
        }
        // Known third-party embeds (reported by the browser, confirmed later by webhook if set up).
        if (d.event === 'calendly.event_scheduled') externalSubmitted(box, 'booked');
        else if (d.type === 'form-submit' || (d.type === 'hsFormCallback' && d.eventName === 'onFormSubmitted') || d.raw === 'form-submit') externalSubmitted(box, 'submitted');
    });

    /** The admin's hint, or a sensible one from the field type and label. */
    function fieldPlaceholder(field) {
        const own = String(field.placeholder || '').trim();
        if (own) return own;
        const label = String(field.label || '').trim();
        if (field.type === 'email') return 'you@example.com';
        if (field.type === 'tel') return 'Your phone number';
        if (field.type === 'textarea') return 'Type your message...';
        if (/\bname\b|naam/i.test(label)) return 'Your name';
        return label ? 'Enter ' + label.toLowerCase() : '';
    }

    function generateFormHTML(formId) {
        const forms = Array.isArray(DATA.forms) ? DATA.forms : [];
        const form = forms.find(function (f) { return f && f.id === formId; });
        if (!form) return '<p>This form is not available right now.</p>';
        // Direct Links are Quick Action-only: never render a card in the chat.
        // Navigation must happen only from the visitor's explicit button click.
        if (form.source === 'directlink') return '';
        if (form.source && form.source !== 'builtin') return externalFormHTML(form);

        const title = wpAiEscapeHtml(form.title || 'Form');
        const allowedTypes = ['text', 'email', 'tel', 'number', 'date', 'url'];
        const chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>';

        let html = '<div class="wp-ai-form-container">';
        html += '<p class="wp-ai-form-title">' + title + '</p>';
        html += '<form class="wp-ai-lead-form" novalidate>';
        html += '<input type="hidden" name="wp_ai_form_title" value="' + title + '">';
        html += '<input type="hidden" name="wp_ai_form_id" value="' + wpAiEscapeHtml(form.id) + '">';

        (Array.isArray(form.fields) ? form.fields : []).forEach(function (field) {
            if (!field || !field.label) return;
            const name = fieldKey(field.label);
            if (!name) return;
            const isRequired = field.required === 'yes';
            const req = isRequired ? ' required' : '';
            const opts = field.options ? String(field.options).split(',').map(function (o) { return o.trim(); }).filter(Boolean) : [];

            const inputId = 'wp-ai-f-' + name + '-' + Math.floor(Math.random() * 100000);
            const ph = ' placeholder="' + wpAiEscapeHtml(fieldPlaceholder(field)) + '"';
            html += '<div class="wp-ai-form-field">';
            html += '<label for="' + inputId + '">' + wpAiEscapeHtml(field.label) + (isRequired ? ' <span class="wp-ai-req">*</span>' : '') + '</label>';

            if (field.type === 'textarea') {
                html += '<textarea id="' + inputId + '" name="' + name + '"' + req + ph + ' maxlength="16000"></textarea>';
            } else if (field.type === 'select') {
                html += '<select id="' + inputId + '" name="' + name + '"' + req + '><option value="">Select an option...</option>';
                opts.forEach(function (opt) { html += '<option value="' + wpAiEscapeHtml(opt) + '">' + wpAiEscapeHtml(opt) + '</option>'; });
                html += '</select>';
            } else if (field.type === 'multiselect') {
                const dropId = 'wp-ai-ms-' + name + '-' + Math.floor(Math.random() * 100000);
                html += '<div class="wp-ai-ms-wrapper" id="' + dropId + '-wrapper" data-required="' + (isRequired ? '1' : '0') + '">' +
                    '<div class="wp-ai-ms-trigger" id="' + dropId + '-trigger" data-target="' + dropId + '" tabindex="0" role="button"><span class="wp-ai-ms-label">Select options...</span>' + chevron + '</div>' +
                    '<div class="wp-ai-ms-dropdown" id="' + dropId + '-dropdown">' +
                        '<label class="wp-ai-ms-all"><input type="checkbox" class="wp-ai-ms-selectall" data-target="' + dropId + '"> Select all</label>' +
                        opts.map(function (opt) {
                            return '<label class="wp-ai-ms-item"><input type="checkbox" name="' + name + '[]" value="' + wpAiEscapeHtml(opt) + '" class="wp-ai-ms-option" data-group="' + dropId + '"> ' + wpAiEscapeHtml(opt) + '</label>';
                        }).join('') +
                    '</div>' +
                '</div>';
            } else {
                const type = allowedTypes.indexOf(field.type) !== -1 ? field.type : 'text';
                const auto = type === 'email' ? ' autocomplete="email" inputmode="email"' : (type === 'tel' ? ' autocomplete="tel" inputmode="tel"' : (/name|naam/i.test(field.label) ? ' autocomplete="name"' : ''));
                html += '<input id="' + inputId + '" type="' + type + '" name="' + name + '"' + req + ph + auto + ' maxlength="490" />';
            }
            html += '</div>';
        });

        html += '<div class="wp-ai-form-error" role="alert"></div>';
        html += '<button type="submit" class="wp-ai-submit-btn">Submit</button>';
        html += '</form></div>';
        return html;
    }

    function showFormError(form, message) {
        const box = form.querySelector('.wp-ai-form-error');
        if (!box) return;
        box.textContent = message || '';
        box.style.display = message ? 'block' : 'none';
    }

    function validateForm(form) {
        let missing = 0;
        form.querySelectorAll('.wp-ai-invalid').forEach(function (el) { el.classList.remove('wp-ai-invalid'); });
        form.querySelectorAll('[required]').forEach(function (el) {
            if (String(el.value || '').trim() === '') { missing++; el.classList.add('wp-ai-invalid'); }
        });
        form.querySelectorAll('.wp-ai-ms-wrapper[data-required="1"]').forEach(function (wrap) {
            if (!wrap.querySelector('.wp-ai-ms-option:checked')) {
                missing++;
                const trig = wrap.querySelector('.wp-ai-ms-trigger');
                if (trig) trig.classList.add('wp-ai-invalid');
            }
        });
        if (missing) return 'Please fill in all required fields.';

        const email = form.querySelector('input[type="email"]');
        if (email && email.value.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
            email.classList.add('wp-ai-invalid');
            return 'Please enter a valid email address.';
        }
        return '';
    }

    document.addEventListener('submit', async function (e) {
        const form = e.target;
        if (!form || !form.classList || !form.classList.contains('wp-ai-lead-form')) return;
        e.preventDefault();

        const validationError = validateForm(form);
        if (validationError) {
            showFormError(form, validationError);
            return;
        }
        showFormError(form, '');

        const dataObj = {};
        new FormData(form).forEach(function (value, key) {
            if (key.slice(-2) === '[]') {
                const cleanKey = key.slice(0, -2);
                if (!dataObj[cleanKey]) dataObj[cleanKey] = [];
                dataObj[cleanKey].push(value);
            } else {
                dataObj[key] = value;
            }
        });

        const submitBtn = form.querySelector('.wp-ai-submit-btn');
        const originalText = submitBtn ? submitBtn.textContent : 'Submit';
        if (submitBtn) { submitBtn.textContent = 'Submitting...'; submitBtn.disabled = true; }

        try {
            const response = await postJson(DATA.form_submit_url, { form_data: dataObj });
            const data = await readJson(response);

            if (response.ok && data && data.success) {
                const formTitle = dataObj.wp_ai_form_title ? dataObj.wp_ai_form_title : 'Form';
                const successText = formTitle + ' submitted successfully';
                const successP = document.createElement('p');
                successP.className = 'wp-ai-form-success';
                successP.textContent = '✓ ' + successText;
                form.innerHTML = '';
                form.appendChild(successP);

                setTimeout(function () { renderMessage(data.message || 'Thank you! Your submission has been received.', 'bot', true); }, 400);

                if (form.classList.contains('is-pre-chat-form')) {
                    // The submitted pre-chat name is conversation context, separate from
                    // the sliding chat-message limit. Save it only after a successful submission.
                    const preForm = (Array.isArray(DATA.forms) ? DATA.forms : []).find(function (f) {
                        return f && String(f.id) === String(DATA.pre_chat_form_id);
                    });
                    if (preForm && Array.isArray(preForm.fields)) {
                        let first = '', last = '', full = '';
                        preForm.fields.forEach(function (field) {
                            const label = String(field.label || '').trim();
                            if (!/\bname\b|naam/i.test(label) || /company|business|product|shop|store|brand|username|user name|file|project|website|domain|pet|baby|father|mother|guardian/i.test(label)) return;
                            const key = fieldKey(label);
                            const value = typeof dataObj[key] === 'string' ? dataObj[key].trim() : '';
                            if (!value || String(field.type || 'text') !== 'text') return;
                            if (/\bfirst\b/i.test(label)) first = value;
                            else if (/\blast\b|surname/i.test(label)) last = value;
                            else if (!full) full = value;
                        });
                        const name = (full || (first + ' ' + last).trim()).replace(/[\r\n<>]/g, ' ').trim().slice(0, 120);
                        if (name) histStore.set(VISITOR_NAME_KEY, name);
                    }
                    completePreChat();
                } else {
                    // Replace the stored form marker so the form isn't shown again after a reload.
                    const history = getHistory();
                    for (let i = history.length - 1; i >= 0; i--) {
                        if (history[i].sender === 'bot' && history[i].text.indexOf('[SHOW_FORM_') !== -1) {
                            history[i].text = '*' + successText + '*';
                            break;
                        }
                    }
                    setHistory(history);
                }
            } else {
                if (submitBtn) { submitBtn.textContent = originalText; submitBtn.disabled = false; }
                showFormError(form, (data && data.message) ? data.message : 'Could not save your details. Please try again.');
            }
        } catch (err) {
            if (submitBtn) { submitBtn.textContent = originalText; submitBtn.disabled = false; }
            showFormError(form, 'Network error. Please check your connection and try again.');
        }
    });

    /* ------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    /* Allowlist HTML sanitizer for untrusted (AI/Markdown) output. No dependencies. */
    function wpAiSanitizeHtml(html) {
        var ALLOWED = {P:1,BR:1,B:1,STRONG:1,I:1,EM:1,U:1,S:1,DEL:1,UL:1,OL:1,LI:1,H1:1,H2:1,H3:1,H4:1,H5:1,H6:1,A:1,IMG:1,TABLE:1,THEAD:1,TBODY:1,TR:1,TH:1,TD:1,BLOCKQUOTE:1,CODE:1,PRE:1,SPAN:1,DIV:1,HR:1};
        var DROP = {SCRIPT:1,STYLE:1,IFRAME:1,OBJECT:1,EMBED:1,FORM:1,INPUT:1,BUTTON:1,TEXTAREA:1,SELECT:1,LINK:1,META:1,SVG:1,MATH:1,BASE:1,TEMPLATE:1,NOSCRIPT:1};
        var ATTRS = {A:['href','title'],IMG:['src','alt','title'],TD:['colspan','rowspan'],TH:['colspan','rowspan']};
        function safeUrl(v, isImg) {
            v = String(v).replace(/[\u0000- \u007f-\u009f]/g, '');
            if (/^(https?:|mailto:|tel:)/i.test(v)) return !isImg || /^https?:/i.test(v);
            if (/^[a-z][a-z0-9+.\-]*:/i.test(v)) return false;
            return true;
        }
        var doc = new DOMParser().parseFromString('<body>' + html, 'text/html');
        (function clean(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (ch) {
                if (ch.nodeType === 3) return;
                if (ch.nodeType !== 1) { node.removeChild(ch); return; }
                var tag = ch.tagName;
                if (DROP[tag]) { node.removeChild(ch); return; }
                if (!ALLOWED[tag]) { clean(ch); while (ch.firstChild) node.insertBefore(ch.firstChild, ch); node.removeChild(ch); return; }
                var keep = ATTRS[tag] || [];
                Array.prototype.slice.call(ch.attributes).forEach(function (a) {
                    var n = a.name.toLowerCase();
                    if (keep.indexOf(n) === -1) { ch.removeAttribute(a.name); return; }
                    if ((n === 'href' || n === 'src') && !safeUrl(a.value, n === 'src')) ch.removeAttribute(a.name);
                });
                if (tag === 'A') { ch.setAttribute('rel', 'noopener noreferrer nofollow'); ch.setAttribute('target', '_blank'); }
                if (tag === 'IMG') { ch.setAttribute('referrerpolicy', 'no-referrer'); ch.setAttribute('loading', 'lazy'); }
                clean(ch);
            });
        })(doc.body);
        return doc.body.innerHTML;
    }

    function wpAiEscapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
    }

    /*
     * Small built-in Markdown renderer (bold, italic, links, images, lists,
     * headings, code, quotes, tables). Output still goes through the sanitizer.
     * Kept in-house so the widget needs no external library.
     */
    function markdownToHtml(src) {
        const codeBlocks = [];
        let text = String(src).replace(/\r\n?/g, '\n').replace(/```[^\n]*\n([\s\S]*?)```/g, function (m, code) {
            codeBlocks.push('<pre><code>' + wpAiEscapeHtml(code.replace(/\n$/, '')) + '</code></pre>');
            return '\u0000CB' + (codeBlocks.length - 1) + '\u0000';
        });
        text = wpAiEscapeHtml(text);

        function inline(t) {
            const codes = [];
            t = t.replace(/`([^`\n]+)`/g, function (m, c) { codes.push('<code>' + c + '</code>'); return '\u0001' + (codes.length - 1) + '\u0001'; });
            t = t.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, '<img src="$2" alt="$1">');
            t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2">$1</a>');
            t = t.replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,!?;:])/g, '$1<a href="$2">$2</a>');
            t = t.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
            t = t.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>').replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>');
            t = t.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
            return t.replace(/\u0001(\d+)\u0001/g, function (m, i) { return codes[i]; });
        }

        const lines = text.split('\n');
        const out = [];
        let para = [];
        let list = null; // { type: 'ul'|'ol', items: [] }

        function flushPara() { if (para.length) { out.push('<p>' + para.map(inline).join('<br>') + '</p>'); para = []; } }
        function flushList() { if (list) { out.push('<' + list.type + '>' + list.items.map(function (i) { return '<li>' + inline(i) + '</li>'; }).join('') + '</' + list.type + '>'); list = null; } }
        function isTableRow(l) { return /^\s*\|.*\|\s*$/.test(l); }
        function cells(l) { return l.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return inline(c.trim()); }); }

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            let m;
            if (/^\s*$/.test(line)) { flushPara(); flushList(); continue; }
            if ((m = line.match(/^\u0000CB(\d+)\u0000$/))) { flushPara(); flushList(); out.push(codeBlocks[m[1]]); continue; }
            if ((m = line.match(/^(#{1,4})\s+(.*)$/))) { flushPara(); flushList(); out.push('<h' + (m[1].length + 2 > 6 ? 6 : m[1].length + 2) + '>' + inline(m[2]) + '</h' + (m[1].length + 2 > 6 ? 6 : m[1].length + 2) + '>'); continue; }
            if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) { flushPara(); flushList(); out.push('<hr>'); continue; }
            if ((m = line.match(/^\s*&gt;\s?(.*)$/))) { flushPara(); flushList(); out.push('<blockquote>' + inline(m[1]) + '</blockquote>'); continue; }
            if (isTableRow(line) && i + 1 < lines.length && /^\s*\|?[\s:\-|]+\|?\s*$/.test(lines[i + 1]) && lines[i + 1].indexOf('-') !== -1) {
                flushPara(); flushList();
                let html = '<table><thead><tr>' + cells(line).map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
                i += 2;
                while (i < lines.length && isTableRow(lines[i])) { html += '<tr>' + cells(lines[i]).map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; i++; }
                i--;
                out.push(html + '</tbody></table>');
                continue;
            }
            if ((m = line.match(/^\s*[-*+]\s+(.*)$/))) { flushPara(); if (!list || list.type !== 'ul') { flushList(); list = { type: 'ul', items: [] }; } list.items.push(m[1]); continue; }
            if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushPara(); if (!list || list.type !== 'ol') { flushList(); list = { type: 'ol', items: [] }; } list.items.push(m[1]); continue; }
            if (list && /^\s{2,}\S/.test(line)) { list.items[list.items.length - 1] += ' ' + line.trim(); continue; }
            flushList();
            para.push(line);
        }
        flushPara(); flushList();
        return out.join('');
    }

    function wpAiRenderBotHtml(rawText, formHtmlFn, imgMax) {
        var forms = [];
        rawText = String(rawText).replace(/\[SHOW_FORM_([a-zA-Z0-9_\-]+)\]/g, function (m, id) { forms.push(id); return 'WPAIFORMPH' + (forms.length - 1) + 'X'; });
        var box = document.createElement('div');
        box.innerHTML = wpAiSanitizeHtml(markdownToHtml(rawText));
        Array.prototype.forEach.call(box.querySelectorAll('a'), function (a) {
            var h = a.getAttribute('href') || '';
            if (/^https?:\/\/\S+\.(jpg|jpeg|png|gif|webp)$/i.test(h) && a.textContent.trim() === h) {
                var img = document.createElement('img'); img.setAttribute('src', h); img.setAttribute('alt', ''); a.parentNode.replaceChild(img, a);
            }
        });
        Array.prototype.forEach.call(box.querySelectorAll('img'), function (i) {
            i.style.maxWidth = imgMax || '250px'; i.style.height = 'auto'; i.style.borderRadius = '6px'; i.style.display = 'block'; i.style.margin = '5px 0';
        });
        return box.innerHTML.replace(/WPAIFORMPH(\d+)X/g, function (m, i) { return (formHtmlFn && forms[i] !== undefined) ? formHtmlFn(forms[i]) : ''; });
    }

    const COPY_ICON  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    const CHECK_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#4ade80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('copy failed'));
            } catch (err) { reject(err); }
        });
    }

    function logShownForms(text) {
        if (!DATA.form_view_url || !text) return;
        const matched = String(text).matchAll(/\[SHOW_FORM_([a-zA-Z0-9_\-]+)\]/g);
        const ids = new Set();
        for (const item of matched) ids.add(item[1]);
        ids.forEach(function (id) {
            const headers = { 'Content-Type': 'application/json' };
            if (DATA.nonce) headers['X-WP-Nonce'] = DATA.nonce;
            fetch(DATA.form_view_url, {
                method: 'POST', credentials: 'same-origin', keepalive: true, headers: headers,
                body: JSON.stringify({form_id: id})
            }).catch(function () {});
        });
    }

    function renderMessage(text, sender, save, extra) {
        text = String(text == null ? '' : text);
        if (save === undefined) save = true;
        extra = extra || {};
        if (sender === 'note') { renderNote(text, save); return; }
        const isAgent = sender === 'agent';

        const rowDiv = document.createElement('div');
        rowDiv.className = 'wp-ai-message-row wp-ai-message-row-' + sender;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'wp-ai-message wp-ai-' + sender + '-message';

        if (isAgent) {
            rowDiv.className = 'wp-ai-message-row wp-ai-message-row-bot wp-ai-message-row-agent';
            msgDiv.className = 'wp-ai-message wp-ai-bot-message wp-ai-agent-message';
        }
        const onlyForm = sender === 'bot' ? text.trim().match(/^\[SHOW_FORM_([a-zA-Z0-9_\-]+)\]$/) : null;
        if (onlyForm) {
            const formHtml = generateFormHTML(onlyForm[1]);
            // Nothing to show (a Direct Link, which only works as a Quick Action button): no empty bubble.
            if (formHtml === '') return;
            msgDiv.classList.add('has-form');
            msgDiv.innerHTML = formHtml;
        } else if (sender === 'bot' || isAgent) {
            // Model output is untrusted: it is sanitized before it touches the DOM.
            // Form shortcodes are swapped for the real form after sanitizing.
            msgDiv.innerHTML = wpAiRenderBotHtml(text, generateFormHTML, '220px');
            if (msgDiv.querySelector('form')) msgDiv.classList.add('has-form');
            if (isAgent) {
                const who = document.createElement('span');
                who.className = 'wp-ai-agent-name';
                who.textContent = String(extra.agent || STR.agent || 'Team');
                msgDiv.insertBefore(who, msgDiv.firstChild);
            }
        } else {
            msgDiv.textContent = text;
        }

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'wp-ai-copy-btn';
        copyBtn.title = 'Copy message';
        copyBtn.setAttribute('aria-label', 'Copy message');
        copyBtn.innerHTML = COPY_ICON;
        copyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const plain = text.replace(/\[SHOW_FORM_[a-zA-Z0-9_\-]+\]/g, '').trim();
            copyText(plain).then(function () {
                copyBtn.innerHTML = CHECK_ICON;
                setTimeout(function () { copyBtn.innerHTML = COPY_ICON; }, 2000);
            }).catch(function () { /* copying not allowed: ignore */ });
        });

        if (sender === 'user') {
            rowDiv.appendChild(copyBtn);
            rowDiv.appendChild(msgDiv);
        } else {
            rowDiv.appendChild(msgDiv);
            rowDiv.appendChild(copyBtn);
        }

        chatBody.appendChild(rowDiv);
        if (sender === 'bot' && Array.isArray(extra.products) && extra.products.length) renderProducts(extra.products);
        scrollToBottom();
        if (sender === 'bot') initExternal(rowDiv);
        if (save) saveToStorage(text, sender, extra);
        if (save && sender === 'bot' && msgDiv.querySelector('form, .wp-ai-ext')) logShownForms(text);
    }

    function loadFromStorage() {
        const history = getHistory();
        chatBody.innerHTML = '';

        if (isPreChatPending()) {
            setInputEnabled(false, 'Please fill the form first...');
            renderMessage('Welcome! Please fill out this quick form before we start chatting:', 'bot', false);

            const msgDiv = document.createElement('div');
            msgDiv.className = 'wp-ai-message wp-ai-bot-message has-form';
            msgDiv.innerHTML = generateFormHTML(DATA.pre_chat_form_id);
            const formEl = msgDiv.querySelector('form');
            const extEl = msgDiv.querySelector('.wp-ai-ext');
            if (formEl) {
                formEl.classList.add('is-pre-chat-form');
            } else if (extEl) {
                msgDiv.classList.add('is-pre-chat');
                const cont = document.createElement('button');
                cont.type = 'button';
                cont.className = 'wp-ai-btn-secondary wp-ai-ext-continue';
                cont.textContent = 'Done? Continue to chat';
                extEl.appendChild(cont);
            } else {
                // Form missing: don't lock the visitor out of the chat.
                store.set(PRE_CHAT_KEY, 'true');
                setInputEnabled(true);
            }
            chatBody.appendChild(msgDiv);
            logShownForms("[SHOW_FORM_" + DATA.pre_chat_form_id + "]");
            initExternal(msgDiv);
            scrollToBottom();
        } else if (history.length > 0) {
            setInputEnabled(true);
            history.forEach(function (msg) { renderMessage(msg.text, msg.sender, false, msg); });
        } else {
            setInputEnabled(true);
            renderMessage(String(DATA.welcome || 'Hi there! How can I help you today?'), 'bot', false);
        }

        const userHasChatted = history.some(function (msg) { return msg.sender === 'user'; });
        if (!userHasChatted && !isPreChatPending()) {
            renderGuidedQueries();
        }
        checkQuickActionsVisibility();
    }

    /* ------------------------------------------------------------------
     * Sending messages
     * ------------------------------------------------------------------ */
    function showTypingIndicator() {
        removeTypingIndicator();
        const typingDiv = document.createElement('div');
        typingDiv.className = 'wp-ai-typing-indicator';
        typingDiv.id = 'wp-ai-typing';
        typingDiv.innerHTML = '<div class="wp-ai-dot"></div><div class="wp-ai-dot"></div><div class="wp-ai-dot"></div>';
        chatBody.appendChild(typingDiv);
        scrollToBottom();
    }

    function removeTypingIndicator() {
        const typingDiv = document.getElementById('wp-ai-typing');
        if (typingDiv) typingDiv.remove();
    }

    /**
     * POST JSON to one of the plugin's REST routes.
     *
     * Visitors who are not logged in get no X-WP-Nonce header: WordPress rejects
     * an expired nonce with 403 "Cookie check failed" before the plugin even
     * runs, and pages served from a cache (cache plugins, Cloudflare, hosting
     * cache) carry nonces that expire after 12-24 hours. Logged-in users still
     * send it; if theirs has expired too, the request is retried once without
     * it (the plugin accepts it as a visitor message).
     */
    function isNonceError(response, data) {
        if (!response || response.status !== 403) return false;
        const code = data && typeof data.code === 'string' ? data.code : '';
        return code === 'rest_cookie_invalid_nonce' || code === 'rest_invalid_nonce' || code === '';
    }

    async function postJson(url, body) {
        const send = function (withNonce) {
            const headers = { 'Content-Type': 'application/json' };
            if (withNonce && DATA.nonce) headers['X-WP-Nonce'] = DATA.nonce;
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body) });
        };
        const useNonce = DATA.logged_in === '1' || DATA.logged_in === true;
        let response = await send(useNonce);
        if (useNonce && response.status === 403) {
            const data = await readJson(response.clone());
            if (isNonceError(response, data)) {
                response = await send(false);
            }
        }
        return response;
    }

    async function readJson(response) {
        try { return await response.json(); } catch (e) { return null; }
    }

    function errorMessageFor(response, data) {
        if (data && typeof data.message === 'string' && data.message !== '' && response && response.status !== 500) {
            return data.message;
        }
        if (response && response.status === 429) return 'You are sending messages too quickly. Please wait a moment and try again.';
        if (response && response.status === 403) return 'Your session has expired. Please refresh the page and try again.';
        return 'Sorry, something went wrong. Please try again in a moment.';
    }

    async function handleSendMessage() {
        if (isSending) return;
        let message = chatInput.value.trim();
        if (message === '') return;
        if (message.length > MAX_MSG_LEN) message = message.slice(0, MAX_MSG_LEN);

        // History BEFORE this message (the server adds the current message itself).
        const recentHistory = HISTORY_SIZE > 0 ? getHistory().filter(function (m) { return m.sender !== 'note'; }).slice(-HISTORY_SIZE).map(function (m) { return { text: m.text, sender: m.sender }; }) : [];

        isSending = true;
        pageChatted = true;
        renderMessage(message, 'user', true);
        chatInput.value = '';
        setInputEnabled(false, DEFAULT_PLACEHOLDER);
        showTypingIndicator();

        try {
            const response = await postJson(DATA.rest_url, {
                message: message,
                history: recentHistory,
                visitor_name: (histStore.get(VISITOR_NAME_KEY) || '').slice(0, 120),
                bot: BOT_ID,
                lang: String(DATA.lang || ''),
                page: String(window.location.href).slice(0, 250)
            });
            const data = await readJson(response);
            removeTypingIndicator();

            if (response.ok && data && data.success && typeof data.reply === 'string') {
                // Empty reply: a team member handles this chat, their answer comes through Live Chat.
                if (data.reply !== '') renderMessage(data.reply, 'bot', true, { products: data.products });
                if (data.mode) setLiveMode(data.mode);
                if (data.offer_human) offerHuman();
            } else {
                renderMessage(errorMessageFor(response, data), 'bot', false);
            }
        } catch (error) {
            removeTypingIndicator();
            renderMessage('Network error. Please check your connection and try again.', 'bot', false);
        } finally {
            isSending = false;
            if (!document.getElementById('wp-ai-guided-bubbles') && !isPreChatPending()) {
                setInputEnabled(true);
                chatInput.focus();
            }
        }
    }

    /* ------------------------------------------------------------------
     * 2.0.0: notes, product cards, live chat, proactive messages
     * ------------------------------------------------------------------ */
    function renderNote(text, save) {
        const note = document.createElement('div');
        note.className = 'wp-ai-chat-note';
        note.textContent = text;
        chatBody.appendChild(note);
        scrollToBottom();
        if (save) saveToStorage(text, 'note');
    }

    function safeUrl(u) {
        u = String(u || '');
        return /^https?:\/\//i.test(u) ? u : '';
    }

    function renderProducts(products) {
        const wrap = document.createElement('div');
        wrap.className = 'wp-ai-products';
        products.slice(0, 4).forEach(function (p) {
            if (!p || !p.name) return;
            const card = document.createElement('div');
            card.className = 'wp-ai-product';
            const url = safeUrl(p.url);
            const img = safeUrl(p.image);
            if (img) {
                const a = document.createElement('a');
                a.className = 'wp-ai-product-img';
                if (url) a.href = url;
                const i = document.createElement('img');
                i.src = img; i.alt = ''; i.loading = 'lazy';
                a.appendChild(i);
                card.appendChild(a);
            }
            const info = document.createElement('div');
            info.className = 'wp-ai-product-info';
            const name = document.createElement(url ? 'a' : 'span');
            name.className = 'wp-ai-product-name';
            if (url) name.href = url;
            name.textContent = String(p.name);
            info.appendChild(name);
            if (p.price) {
                const price = document.createElement('span');
                price.className = 'wp-ai-product-price';
                price.textContent = String(p.price);
                info.appendChild(price);
            }
            card.appendChild(info);
            const actions = document.createElement('div');
            actions.className = 'wp-ai-product-actions';
            if (p.stock === 'out') {
                const out = document.createElement('span');
                out.className = 'wp-ai-product-out';
                out.textContent = STR.out_stock || 'Out of stock';
                actions.appendChild(out);
            } else if (p.can_add && DATA.wc_ajax_add) {
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'wp-ai-product-add';
                add.textContent = STR.add_to_cart || 'Add to cart';
                add.setAttribute('data-product-id', String(parseInt(p.id, 10) || 0));
                actions.appendChild(add);
                const buy = safeUrl(p.buy_url);
                if (buy) {
                    const b = document.createElement('a');
                    b.className = 'wp-ai-product-buy';
                    b.href = buy;
                    b.textContent = STR.buy_now || 'Buy now';
                    actions.appendChild(b);
                }
            } else if (url) {
                const o = document.createElement('a');
                o.className = 'wp-ai-product-buy';
                o.href = url;
                o.textContent = STR.options || 'Choose options';
                actions.appendChild(o);
            }
            card.appendChild(actions);
            wrap.appendChild(card);
        });
        if (wrap.children.length) chatBody.appendChild(wrap);
    }

    chatBody.addEventListener('click', function (e) {
        const btn = e.target.closest ? e.target.closest('.wp-ai-product-add') : null;
        if (!btn || btn.disabled) return;
        e.preventDefault();
        const id = parseInt(btn.getAttribute('data-product-id'), 10) || 0;
        if (!id || !DATA.wc_ajax_add) return;
        btn.disabled = true;
        btn.classList.add('is-loading');
        const body = new URLSearchParams();
        body.set('product_id', String(id));
        body.set('quantity', '1');
        fetch(DATA.wc_ajax_add, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.classList.remove('is-loading');
                if (!d || d.error) {
                    btn.disabled = false;
                    if (d && d.product_url) window.location.href = d.product_url;
                    return;
                }
                btn.textContent = STR.added || 'Added ✓';
                btn.classList.add('is-added');
                // The theme's mini cart / cart count.
                if (d.fragments) {
                    Object.keys(d.fragments).forEach(function (sel) {
                        try {
                            document.querySelectorAll(sel).forEach(function (el) {
                                const tmp = document.createElement('div');
                                tmp.innerHTML = d.fragments[sel];
                                if (tmp.firstElementChild) el.replaceWith(tmp.firstElementChild);
                            });
                        } catch (err) { /* selector not valid here */ }
                    });
                }
                if (window.jQuery) {
                    // No button passed: WooCommerce would add its own "View cart" link next to ours.
                    try { window.jQuery(document.body).trigger('added_to_cart', [d.fragments || {}, d.cart_hash || '', null]); } catch (err) { /* ignore */ }
                }
                const actions = btn.parentNode;
                if (DATA.cart_url && actions && !actions.querySelector('.wp-ai-product-cart')) {
                    const c = document.createElement('a');
                    c.className = 'wp-ai-product-cart';
                    c.href = safeUrl(DATA.cart_url) || '#';
                    c.textContent = STR.view_cart || 'View cart';
                    actions.appendChild(c);
                    if (chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight < 120) scrollToBottom();
                }
                setTimeout(function () { btn.disabled = false; btn.classList.remove('is-added'); btn.textContent = STR.add_to_cart || 'Add to cart'; }, 2500);
            })
            .catch(function () { btn.disabled = false; btn.classList.remove('is-loading'); });
    });

    /* Live chat: replies of a team member appear here within seconds. */
    let liveMode = 'bot';
    let liveTimer = null;
    let liveBusy = false;
    let unread = 0;
    let pageChatted = false;

    function liveLastId() { return parseInt(store.get(LIVE_KEY), 10) || 0; }

    function setLiveMode(mode) {
        if (mode !== 'bot' && mode !== 'waiting' && mode !== 'human') return;
        const changed = mode !== liveMode;
        liveMode = mode;
        chatWindow.classList.toggle('wp-ai-live-human', mode === 'human');
        if (changed || !liveTimer) scheduleLive(mode === 'bot' ? 25000 : 3000);
    }

    function liveDelay() {
        const open = EMBEDDED || !chatWindow.classList.contains('wp-ai-hidden');
        if (liveMode !== 'bot') return open ? 4000 : 8000;
        return open ? 20000 : 60000; // A team member may still take over the chat.
    }

    function scheduleLive(delay) {
        if (!DATA.live_url || !window.fetch) return;
        if (liveTimer) clearTimeout(liveTimer);
        liveTimer = setTimeout(pollLive, delay);
    }

    function pollLive() {
        liveTimer = null;
        // Nothing to wait for: no chat yet on this page and none before.
        const hasChat = pageChatted || getHistory().some(function (m) { return m.sender === 'user'; });
        if (!hasChat || document.visibilityState === 'hidden' || liveBusy) { scheduleLive(liveDelay()); return; }
        liveBusy = true;
        const after = liveLastId();
        fetch(DATA.live_url + (DATA.live_url.indexOf('?') === -1 ? '?' : '&') + 'after=' + after + '&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                if (d.last_id) store.set(LIVE_KEY, String(d.last_id));
                (d.messages || []).forEach(function (m) {
                    if (m.type === 'note') { renderMessage(m.text, 'note', true); return; }
                    renderMessage(m.text, 'agent', true, { agent: m.agent });
                    if (!EMBEDDED && chatWindow.classList.contains('wp-ai-hidden')) {
                        unread++;
                        showBubble(String(m.text).slice(0, 140), null);
                    }
                });
                if (d.mode) { liveMode = d.mode; chatWindow.classList.toggle('wp-ai-live-human', d.mode === 'human'); }
            })
            .catch(function () { /* offline: try again later */ })
            .then(function () { liveBusy = false; scheduleLive(liveDelay()); });
    }

    function offerHuman() {
        if (!DATA.live_url || !DATA.live_button || liveMode !== 'bot') return;
        const old = chatBody.querySelector('.wp-ai-human-offer');
        if (old) old.remove();
        const box = document.createElement('div');
        box.className = 'wp-ai-human-offer';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = String(DATA.live_button);
        btn.addEventListener('click', function () {
            btn.disabled = true;
            postJson(DATA.live_url, {}).then(readJson).then(function (d) {
                box.remove();
                if (d && d.reply) renderMessage(d.reply, 'bot', true);
                if (d && d.mode) setLiveMode(d.mode);
            }).catch(function () { btn.disabled = false; });
        });
        box.appendChild(btn);
        chatBody.appendChild(box);
        scrollToBottom();
    }

    /* Proactive messages and new replies while the chat is closed: a bubble next to the chat button. */
    function showBubble(text, onOpen) {
        if (EMBEDDED || !fabContainer) return;
        let b = fabContainer.querySelector('.wp-ai-pro-bubble');
        if (!b) {
            b = document.createElement('div');
            b.className = 'wp-ai-pro-bubble';
            b.setAttribute('role', 'status');
            const t = document.createElement('button');
            t.type = 'button';
            t.className = 'wp-ai-pro-text';
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'wp-ai-pro-close';
            x.setAttribute('aria-label', 'Close');
            x.textContent = '×';
            b.appendChild(x);
            b.appendChild(t);
            x.addEventListener('click', function (e) { e.stopPropagation(); b.remove(); });
            fabContainer.appendChild(b);
        }
        fabContainer.classList.remove('wp-ai-greeting-visible');
        b.querySelector('.wp-ai-pro-text').textContent = text;
        b.querySelector('.wp-ai-pro-text').onclick = function (e) {
            e.stopPropagation();
            b.remove();
            if (chatWindow.classList.contains('wp-ai-hidden')) toggleChat();
            if (onOpen) onOpen();
        };
        if (unread > 0) fabContainer.setAttribute('data-unread', String(unread));
    }

    function proactiveSeen(rule) {
        const key = 'wp_ai_pro_' + rule.id;
        if (rule.freq === 'visitor') return !!store.get(key);
        if (rule.freq === 'day') return (Date.now() - (parseInt(store.get(key), 10) || 0)) < 86400000;
        return !!tabStore.get(key);
    }

    function markProactive(rule) {
        const key = 'wp_ai_pro_' + rule.id;
        if (rule.freq === 'session') tabStore.set(key, '1');
        else store.set(key, String(Date.now()));
    }

    let proactiveDone = false;
    function fireProactive(rule) {
        if (proactiveDone || proactiveSeen(rule) || isSending) return;
        if (!chatWindow.classList.contains('wp-ai-hidden')) return; // The visitor is already chatting.
        proactiveDone = true;
        markProactive(rule);
        const say = function () {
            if (!isPreChatPending()) renderMessage(rule.message, 'bot', true);
        };
        if (rule.open) {
            toggleChat();
            say();
        } else {
            showBubble(rule.message, say);
        }
    }

    function startProactive() {
        if (EMBEDDED || !Array.isArray(DATA.proactive) || !DATA.proactive.length) return;
        const href = String(window.location.href).toLowerCase();
        const cartItems = /(?:^|;\s*)woocommerce_items_in_cart=1/.test(document.cookie);
        const touch = window.matchMedia && window.matchMedia('(hover: none)').matches;
        DATA.proactive.forEach(function (rule) {
            if (!rule || !rule.id || !rule.message || proactiveSeen(rule)) return;
            if (rule.where === 'url' && (!rule.value || href.indexOf(String(rule.value).toLowerCase()) === -1)) return;
            if (rule.where === 'cart_items' && !cartItems) return;
            const secs = Math.max(0, parseInt(rule.seconds, 10) || 0) * 1000;
            if (rule.trigger === 'scroll') {
                const want = Math.max(5, Math.min(100, parseInt(rule.scroll, 10) || 50));
                const onScroll = function () {
                    const doc = document.documentElement;
                    const max = Math.max(1, doc.scrollHeight - window.innerHeight);
                    if ((window.scrollY / max) * 100 >= want - 0.5) { window.removeEventListener('scroll', onScroll); fireProactive(rule); }
                };
                window.addEventListener('scroll', onScroll, { passive: true });
            } else if (rule.trigger === 'exit' && !touch) {
                const onLeave = function (e) {
                    if (!e.relatedTarget && e.clientY <= 0) { document.removeEventListener('mouseout', onLeave); fireProactive(rule); }
                };
                // A short wait first, so moving the mouse at once does not count as leaving.
                setTimeout(function () { document.addEventListener('mouseout', onLeave); }, 3000);
            } else {
                setTimeout(function () { fireProactive(rule); }, rule.trigger === 'exit' ? Math.max(secs, 15000) : secs);
            }
        });
    }

    /* ------------------------------------------------------------------
     * Quick action bar
     * ------------------------------------------------------------------ */
    function enableDragToScroll(elementId) {
        const container = document.getElementById(elementId);
        if (!container) return;
        let isDown = false, startX, scrollLeft;

        container.addEventListener('mousedown', function (e) {
            isDown = true; container.classList.add('wp-ai-dragging');
            startX = e.pageX - container.offsetLeft;
            scrollLeft = container.scrollLeft;
        });
        container.addEventListener('mouseleave', function () { isDown = false; container.classList.remove('wp-ai-dragging'); });
        container.addEventListener('mouseup', function () { isDown = false; container.classList.remove('wp-ai-dragging'); });
        container.addEventListener('mousemove', function (e) {
            if (!isDown) return;
            e.preventDefault();
            if (container.scrollWidth > container.clientWidth) container.scrollLeft = scrollLeft - ((e.pageX - container.offsetLeft) - startX) * 2;
        });
    }

    let quickActionsResize = function () {};

    function loadQuickActions() {
        const wrapper = document.getElementById('wp-ai-quick-actions-wrapper');
        const container = document.getElementById('wp-ai-quick-actions');
        const actions = Array.isArray(DATA.quick_actions) ? DATA.quick_actions : [];
        const scrollLeftBtn = document.getElementById('wp-ai-scroll-left');
        const scrollRightBtn = document.getElementById('wp-ai-scroll-right');

        if (!wrapper || !container) return;
        if (actions.length === 0) { wrapper.style.display = 'none'; return; }
        wrapper.style.display = 'flex';

        container.innerHTML = '';
        actions.forEach(function (action) {
            if (!action || !action.label) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wp-ai-quick-action-btn';
            btn.textContent = action.label;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (btn.disabled || wrapper.classList.contains('wp-ai-disabled')) return;

                if (!action.form_id) {
                    chatInput.value = action.label;
                    handleSendMessage();
                    return;
                }
                // Direct Link is a navigation-only Quick Action. Never create chat
                // bubbles, append history, or ask the AI to show a card.
                const directForm = (Array.isArray(DATA.forms) ? DATA.forms : []).find(function (f) {
                    return f && String(f.id) === String(action.form_id) && f.source === 'directlink';
                });
                if (directForm) {
                    const url = String(directForm.url || '').trim();
                    if (/^https?:\/\/[^\s]+$/i.test(url)) {
                        window.location.assign(url);
                    } else {
                        console.warn('[AK Chatbot] Direct Link has no valid HTTPS/HTTP URL.');
                    }
                    return;
                }
                renderMessage(action.label, 'user', true);
                renderMessage('[SHOW_FORM_' + action.form_id + ']', 'bot', true);
            });
            container.appendChild(btn);
        });

        function updateScrollButtons() {
            wrapper.classList.toggle('is-overflowing', container.scrollWidth > container.clientWidth + 1);
        }
        quickActionsResize = updateScrollButtons;
        setTimeout(updateScrollButtons, 100);
        window.addEventListener('resize', updateScrollButtons);

        if (scrollLeftBtn) scrollLeftBtn.addEventListener('click', function () { if (!scrollLeftBtn.disabled) container.scrollBy({ left: -150, behavior: 'smooth' }); });
        if (scrollRightBtn) scrollRightBtn.addEventListener('click', function () { if (!scrollRightBtn.disabled) container.scrollBy({ left: 150, behavior: 'smooth' }); });
    }

    /* ------------------------------------------------------------------
     * Multiselect dropdowns inside lead forms
     * ------------------------------------------------------------------ */
    function closeAllDropdowns() {
        document.querySelectorAll('.wp-ai-ms-dropdown').forEach(function (d) { d.style.display = 'none'; });
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest ? e.target.closest('.wp-ai-ms-trigger') : null;
        if (trigger) {
            e.stopPropagation();
            const dropdown = document.getElementById(trigger.getAttribute('data-target') + '-dropdown');
            if (!dropdown) return;
            const isOpen = dropdown.style.display === 'block';
            closeAllDropdowns();
            if (!isOpen) dropdown.style.display = 'block';
            return;
        }
        if (!(e.target.closest && e.target.closest('.wp-ai-ms-wrapper'))) closeAllDropdowns();
    });

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('wp-ai-ms-trigger')) {
            e.preventDefault();
            e.target.click();
        }
    });

    document.addEventListener('change', function (e) {
        if (!e.target.classList) return;
        if (e.target.classList.contains('wp-ai-ms-selectall')) {
            const dropId = e.target.getAttribute('data-target');
            document.querySelectorAll('.wp-ai-ms-option[data-group="' + dropId + '"]').forEach(function (cb) { cb.checked = e.target.checked; });
            updateMultiselectLabel(dropId);
        }
        if (e.target.classList.contains('wp-ai-ms-option')) {
            const dropId = e.target.getAttribute('data-group');
            updateMultiselectLabel(dropId);
            const allOpts = document.querySelectorAll('.wp-ai-ms-option[data-group="' + dropId + '"]');
            const selectAll = document.querySelector('.wp-ai-ms-selectall[data-target="' + dropId + '"]');
            if (selectAll) selectAll.checked = Array.prototype.every.call(allOpts, function (cb) { return cb.checked; });
        }
    });

    function updateMultiselectLabel(dropId) {
        const checked = document.querySelectorAll('.wp-ai-ms-option[data-group="' + dropId + '"]:checked');
        const trigger = document.getElementById(dropId + '-trigger');
        const label = trigger ? trigger.querySelector('.wp-ai-ms-label') : null;
        if (!label) return;
        label.textContent = checked.length === 0 ? 'Select options...' : Array.prototype.map.call(checked, function (c) { return c.value; }).join(', ');
        label.style.opacity = checked.length === 0 ? '' : '1';
        trigger.classList.remove('wp-ai-invalid');
    }

    /* ------------------------------------------------------------------
     * Init
     * ------------------------------------------------------------------ */
    loadQuickActions();
    expireHistory();
    loadFromStorage();
    checkHistoryState();
    if (EMBEDDED) {
        scrollToBottom();
        setTimeout(function () { quickActionsResize(); }, 150);
    }
    // While the chat is open, check every 20 seconds (so "Clear all" reaches open chats within seconds).
    setInterval(function () {
        if (!chatWindow.classList.contains('wp-ai-hidden') && document.visibilityState !== 'hidden') checkHistoryState();
    }, 20000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !chatWindow.classList.contains('wp-ai-hidden')) checkHistoryState();
    });
    enableDragToScroll('wp-ai-quick-actions');

    // Keep wheel scrolling inside the chat instead of scrolling the page behind it.
    chatBody.addEventListener('wheel', function (e) { e.stopPropagation(); }, { passive: true });

    /* ------------------------------------------------------------------
     * Voice input (optional)
     * - All languages (default): records a short clip, the site turns it into
     *   text with Gemini. English stays English, Urdu/Hindi come back in Roman Urdu.
     * - One language (strict): the browser's own speech recognition in that language.
     * ------------------------------------------------------------------ */
    const voiceBtn = document.getElementById('wp-ai-voice-btn');
    const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    const voiceOn = voiceBtn && String(DATA.voice_enabled) === '1' && window.isSecureContext;
    const strictVoice = DATA.voice_mode === 'strict';
    const canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && AudioCtx && window.OfflineAudioContext);
    const useServer = voiceOn && !strictVoice && !!DATA.transcribe_url && canRecord;
    const useBrowser = voiceOn && !useServer && !!SpeechRec;

    let voiceState = 'idle'; // idle | listening | writing
    const setVoiceState = function (state, placeholder) {
        voiceState = state;
        voiceBtn.classList.toggle('is-listening', state === 'listening');
        voiceBtn.classList.toggle('is-writing', state === 'writing');
        voiceBtn.setAttribute('aria-pressed', state === 'listening' ? 'true' : 'false');
        if (!chatInput.disabled) chatInput.placeholder = placeholder || DEFAULT_PLACEHOLDER;
    };
    const voiceNotice = function (text) {
        setVoiceState('idle', text);
        setTimeout(function () { if (voiceState === 'idle' && !chatInput.disabled) chatInput.placeholder = DEFAULT_PLACEHOLDER; }, 3500);
    };
    // Spoken words go into the message box (after anything already typed), so the
    // visitor can read them, fix a word if needed and press send.
    let voiceBase = '';
    const showSpoken = function (text) {
        text = String(text || '').replace(/\s+/g, ' ').trim();
        const joined = voiceBase && text ? voiceBase + ' ' + text : (voiceBase || text);
        chatInput.value = joined.slice(0, MAX_MSG_LEN);
    };
    const finishSpoken = function (text) {
        text = String(text || '').trim();
        if (text === '') { chatInput.value = voiceBase; voiceNotice("Didn't catch that. Please try again."); return; }
        showSpoken(text);
        setVoiceState('idle');
        if (!chatInput.disabled) {
            try {
                chatInput.focus({ preventScroll: true });
                const end = chatInput.value.length;
                chatInput.setSelectionRange(end, end);
            } catch (e) { /* ignore */ }
        }
    };
    const startVoice = function () {
        voiceBase = chatInput.value.trim();
    };

    if (useServer) {
        voiceBtn.hidden = false;
        const maxSec = Math.max(5, Math.min(60, Number(DATA.voice_max_sec) || 30));
        const SILENCE_END_MS = 900;   // Stop this long after the visitor stops talking.
        const NO_SPEECH_MS   = 7000;  // Give up when nothing is said at all.
        let rec = null, stream = null, meter = null, meterTimer = null, stopTimer = null, chunks = [];

        const releaseMic = function () {
            if (meterTimer) { clearInterval(meterTimer); meterTimer = null; }
            if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; }
            if (meter) { try { meter.close(); } catch (e) {} meter = null; }
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        };
        const stopRecording = function () { if (rec && rec.state !== 'inactive') { try { rec.stop(); } catch (e) {} } };

        // Any recording (webm, ogg, mp4) -> 16 kHz mono 16-bit WAV, which every model reads.
        const toWav = function (blob) {
            return blob.arrayBuffer().then(function (buf) {
                const ctx = new AudioCtx();
                return new Promise(function (resolve, reject) {
                    const p = ctx.decodeAudioData(buf, resolve, reject);
                    if (p && p.then) p.then(resolve, reject);
                }).then(function (audio) {
                    try { ctx.close(); } catch (e) {}
                    const rate = 16000;
                    const off = new OfflineAudioContext(1, Math.max(1, Math.ceil(audio.duration * rate)), rate);
                    const src = off.createBufferSource();
                    src.buffer = audio; src.connect(off.destination); src.start(0);
                    return off.startRendering();
                });
            }).then(function (rendered) {
                // Cut the silence before and after the speech (less to upload, and silence makes
                // speech models invent text), and lift quiet recordings to a normal level.
                const all = rendered.getChannelData(0);
                let peak = 0;
                for (let i = 0; i < all.length; i++) { const a = Math.abs(all[i]); if (a > peak) peak = a; }
                const floor = Math.max(0.006, peak * 0.06);
                let first = -1, last = -1;
                for (let i = 0; i < all.length; i++) { if (Math.abs(all[i]) > floor) { if (first < 0) first = i; last = i; } }
                if (peak < 0.006 || first < 0 || last - first < 16000 * 0.2) return '';
                const pad = 16000 * 0.25;
                const pcm = all.slice(Math.max(0, first - pad), Math.min(all.length, last + pad));
                const gain = peak < 0.5 ? Math.min(8, 0.8 / peak) : 1;
                const out = new DataView(new ArrayBuffer(44 + pcm.length * 2));
                const str = function (o, t) { for (let i = 0; i < t.length; i++) out.setUint8(o + i, t.charCodeAt(i)); };
                str(0, 'RIFF'); out.setUint32(4, 36 + pcm.length * 2, true); str(8, 'WAVE'); str(12, 'fmt ');
                out.setUint32(16, 16, true); out.setUint16(20, 1, true); out.setUint16(22, 1, true);
                out.setUint32(24, 16000, true); out.setUint32(28, 32000, true); out.setUint16(32, 2, true); out.setUint16(34, 16, true);
                str(36, 'data'); out.setUint32(40, pcm.length * 2, true);
                for (let i = 0; i < pcm.length; i++) {
                    const v = Math.max(-1, Math.min(1, pcm[i] * gain));
                    out.setInt16(44 + i * 2, v < 0 ? v * 0x8000 : v * 0x7fff, true);
                }
                const bytes = new Uint8Array(out.buffer);
                let bin = '';
                for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
                return btoa(bin);
            });
        };

        const transcribe = function (blob, heardSpeech) {
            if (!heardSpeech || blob.size < 1000) { voiceNotice("Didn't catch that. Please try again."); return; }
            setVoiceState('writing', 'Writing what you said...');
            toWav(blob).then(function (b64) {
                if (!b64) return { text: '' };
                return postJson(DATA.transcribe_url, { audio: b64 }).then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        if (!res.ok) throw new Error((data && data.message) || 'Voice failed');
                        return data;
                    });
                });
            }).then(function (data) {
                finishSpoken(data && data.text);
            }).catch(function (err) {
                if (window.console) console.warn('[AK Chatbot] voice:', err);
                chatInput.value = voiceBase;
                voiceNotice(err && err.message && err.message.length < 120 ? err.message : 'Voice is not working right now. Please type your message.');
            });
        };

        voiceBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (voiceState === 'listening') { stopRecording(); return; }
            if (voiceState !== 'idle' || chatInput.disabled || isSending) return;
            startVoice();

            // The level meter must be created inside the tap itself: browsers (Safari, and Chrome
            // after a permission prompt) keep audio that starts later paused, and a paused meter
            // never "hears" anything.
            try { meter = new AudioCtx(); if (meter.state === 'suspended' && meter.resume) meter.resume(); } catch (err) { meter = null; }

            setVoiceState('listening', 'Starting microphone...');
            navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } }).then(function (s) {
                stream = s;
                chunks = [];
                const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
                const type = types.find(function (t) { return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t); });
                rec = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);

                // Stop by itself after a short pause in speech, like phone voice typing.
                // The speech level is measured against the room's own background noise.
                let heard = false, quietSince = 0, noise = 0, noiseFrames = 0, liveFrames = 0, meterFrames = 0;
                const startedAt = Date.now();
                try {
                    if (!meter) meter = new AudioCtx();
                    if (meter.state === 'suspended' && meter.resume) meter.resume();
                    const analyser = meter.createAnalyser();
                    analyser.fftSize = 1024;
                    meter.createMediaStreamSource(stream).connect(analyser);
                    const data = new Float32Array(analyser.fftSize);
                    meterTimer = setInterval(function () {
                        analyser.getFloatTimeDomainData(data);
                        let sum = 0;
                        for (let i = 0; i < data.length; i++) sum += data[i] * data[i];
                        const rms = Math.sqrt(sum / data.length);
                        const now = Date.now();
                        meterFrames++;
                        if (rms > 0) liveFrames++;
                        // A meter that only ever reads zero is paused or blocked: fall back to the
                        // stop tap and the time limit instead of throwing the recording away.
                        if (meterFrames === 15 && liveFrames === 0) {
                            clearInterval(meterTimer); meterTimer = null; heard = true;
                            setVoiceState('listening', 'Listening... tap the mic when done');
                            return;
                        }
                        if (noiseFrames < 3) { noise += rms; noiseFrames++; if (noiseFrames === 3) noise /= 3; return; }
                        const loud = rms > Math.max(0.008, Math.min(0.05, noise * 2.5));
                        if (loud) { heard = true; quietSince = 0; }
                        else if (heard && !quietSince) quietSince = now;
                        if ((heard && quietSince && now - quietSince > SILENCE_END_MS) || (!heard && now - startedAt > NO_SPEECH_MS)) {
                            stopRecording();
                        }
                    }, 60);
                } catch (err) { heard = true; } // No level meter: rely on the stop tap and the time limit.

                rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
                rec.onstop = function () {
                    const blob = new Blob(chunks, { type: rec.mimeType || type || 'audio/webm' });
                    releaseMic();
                    transcribe(blob, heard);
                };
                rec.start();
                stopTimer = setTimeout(stopRecording, maxSec * 1000);
                if (voiceState === 'listening' && meterTimer) setVoiceState('listening', 'Listening... speak now');
            }).catch(function (err) {
                releaseMic();
                if (window.console) console.warn('[AK Chatbot] microphone:', err);
                voiceNotice(err && err.name === 'NotFoundError' ? 'No microphone found on this device.' : 'Please allow microphone access to use voice.');
            });
        });
    } else if (useBrowser) {
        voiceBtn.hidden = false;
        let recognizer = null;

        voiceBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (voiceState === 'listening' && recognizer) { recognizer.stop(); return; }
            if (voiceState !== 'idle' || chatInput.disabled || isSending) return;
            startVoice();

            recognizer = new SpeechRec();
            // Strict: the admin's language. Otherwise (no Gemini key): the visitor's own browser language.
            recognizer.lang = strictVoice
                ? (DATA.voice_lang || 'en-US')
                : (navigator.language || document.documentElement.lang || 'en-US');
            recognizer.interimResults = true;   // Words appear in the box while the visitor speaks.
            recognizer.continuous = false;      // Stops by itself after a pause.
            recognizer.maxAlternatives = 1;

            let finalText = '', liveText = '', failed = false;
            recognizer.onresult = function (event) {
                let interim = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) finalText += ' ' + event.results[i][0].transcript;
                    else interim += ' ' + event.results[i][0].transcript;
                }
                liveText = (finalText + interim).trim();
                showSpoken(liveText);
            };
            recognizer.onerror = function (ev) {
                failed = true;
                const code = ev && ev.error;
                if (code === 'not-allowed' || code === 'service-not-allowed') voiceNotice('Please allow microphone access to use voice.');
                else if (code === 'no-speech') voiceNotice("Didn't catch that. Please try again.");
                else if (code === 'network') voiceNotice('Voice needs an internet connection.');
                else if (code !== 'aborted') voiceNotice('Voice is not working right now. Please type your message.');
            };
            recognizer.onend = function () {
                recognizer = null;
                // Some browsers (Safari) end without marking the last words final: keep what was shown.
                const text = finalText.trim() || liveText;
                if (text) { finishSpoken(text); return; }
                if (!failed) voiceNotice("Didn't catch that. Please try again.");
            };

            setVoiceState('listening', 'Listening... speak now');
            try { recognizer.start(); } catch (err) { recognizer = null; voiceNotice('Voice is not working right now. Please type your message.'); }
        });
    }

    // 2.0.0: live chat replies and proactive messages.
    if (DATA.live_url) scheduleLive(1500);
    startProactive();

    /* Small public API for themes and future add-ons (e.g. other voice providers). */
    window.wpAiChatbot = {
        open: function () { if (chatWindow.classList.contains('wp-ai-hidden')) toggleChat(); },
        close: function () { if (!chatWindow.classList.contains('wp-ai-hidden')) toggleChat(); },
        send: function (text) {
            if (typeof text !== 'string' || text.trim() === '' || chatInput.disabled) return false;
            chatInput.value = text;
            handleSendMessage();
            return true;
        }
    };

    sendBtn.addEventListener('click', function (e) { e.preventDefault(); handleSendMessage(); });
    chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            handleSendMessage();
        }
    });
});