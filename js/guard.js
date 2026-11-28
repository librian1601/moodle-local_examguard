/**
 * ExamGuard client side guard for Moodle quiz attempt pages.
 *
 * This file is intentionally plain ES5 so it can be delivered without any
 * Node/Grunt build step and still runs on older Android and iOS browsers.
 *
 * It never decides when a penalty ends: the server sends the authoritative
 * deadline in the JSON configuration and confirms it on every heartbeat.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';

    if (window.__examguardBooted) {
        return;
    }
    window.__examguardBooted = true;

    var configEl = document.getElementById('examguard-config');
    if (!configEl) {
        return;
    }

    var cfg;
    try {
        cfg = JSON.parse(configEl.textContent || configEl.innerHTML || '{}');
    } catch (e) {
        return;
    }
    if (!cfg || !cfg.attemptid || !cfg.ajaxurl) {
        return;
    }

    var settings = cfg.settings || {};
    var strings = cfg.strings || {};

    var OVERLAY_ID = 'examguard-penalty-overlay';
    var DUPLICATE_OVERLAY_ID = 'examguard-duplicatetab-overlay';
    var WATERMARK_ID = 'examguard-watermark';
    var STORAGE_PREFIX = 'examguard_penalty_';
    var ZWSP = '\u200B';

    // Single-Tab Guard constants.
    var TAB_ID = String(new Date().getTime()) + '_' + Math.random().toString(36).slice(2);
    var TAB_LEASE_KEY = 'examguard_tab_lease_' + cfg.attemptid;
    var TAB_SIGNAL_KEY = 'examguard_tab_signal_' + cfg.attemptid;
    var TAB_CHANNEL_NAME = 'examguard_tab_' + cfg.attemptid;
    var TAB_LEASE_REFRESH_MS = 1500;
    var TAB_LEASE_STALE_MS = 4500;
    var HEARTBEAT_INTERVAL_MS = 6000;
    var UNLOCK_GRACE_MS = 1000;

    // Cross page navigation marker. It lives in sessionStorage, so the document that
    // Moodle loads after processattempt.php can still see that the student arrived
    // there through an in-quiz navigation and not by leaving the exam.
    var NAV_MARKER_KEY = 'examguard_navigating_ts';
    var NAV_GRACE_MS = 1000;
    var BOOT_GRACE_MS = 800;
    var QUESTION_BLOCKED_NOTICE = '[Akses Soal Ditutup Sementara oleh Sistem Keamanan Ujian - Kembalikan Fokus ke Layar Ujian]';

    var OVERLAY_STYLE = 'position:fixed;left:0;top:0;width:100vw;height:100vh;background:#ffffff;'
        + 'z-index:999999;display:flex;align-items:center;justify-content:center;padding:24px;'
        + 'box-sizing:border-box;overflow:auto;pointer-events:auto;opacity:1;visibility:visible;';

    var DUPLICATE_OVERLAY_STYLE = 'position:fixed;left:0;top:0;width:100vw;height:100vh;background:#f8f9fa;'
        + 'z-index:1000000;display:flex;align-items:center;justify-content:center;padding:24px;'
        + 'box-sizing:border-box;overflow:auto;pointer-events:auto;opacity:1;visibility:visible;';

    var DEFAULTS = {
        penaltyheading: 'Ujian dikunci sementara',
        penaltybody: 'Sistem mendeteksi Anda meninggalkan halaman ujian.',
        penaltycount: 'Pelanggaran ke-{$a}',
        penaltyremaining: 'Sisa waktu hukuman',
        penaltyidentity: '',
        penaltynotice: 'Jangan menutup atau memuat ulang halaman ini.',
        penaltytamper: 'Layar hukuman dimodifikasi. Hukuman diperpanjang.',
        penaltychecking: 'Memeriksa status...',
        ajaxerror: 'Tidak dapat menghubungi server.',
        printblocked: 'Mencetak soal ujian tidak diizinkan.',
        domswap_placeholder: '[Akses Soal Ditutup Sementara oleh Sistem Keamanan Ujian - Kembalikan Fokus ke Layar Ujian]',
        duplicatetab_title: 'Ujian Sedang Dibuka di Tab Lain',
        duplicatetab_desc: 'Anda sudah membuka ujian ini di tab lain. Silakan kembali ke tab ujian yang sedang berjalan, atau tutup tab tersebut lalu klik tombol di bawah untuk melanjutkan di sini.',
        duplicatetab_takeover: 'Lanjutkan di Tab Ini'
    };

    /**
     * Get the placeholder text for DOM Question Swapping based on configured language strings.
     *
     * @return {string}
     */
    function getQuestionBlockedNotice() {
        return str('domswap_placeholder', QUESTION_BLOCKED_NOTICE);
    }

    var state = {
        locked: false,
        provisional: false,
        isDuplicateTab: false,
        focusViolationFired: false,
        unlockGraceUntil: 0,
        tabTakeoverTs: 0,
        penaltyuntil: cfg.penaltyuntil || 0,
        violationcount: cfg.violationcount || 0,
        frozenseconds: cfg.frozenseconds || 0,
        timeleft: (typeof cfg.timeleft === 'number') ? cfg.timeleft : null,
        serveroffset: (cfg.servernow || Math.floor(new Date().getTime() / 1000))
            - Math.floor(new Date().getTime() / 1000),
        countdowntimer: null,
        heartbeattimer: null,
        watchdogtimer: null,
        watermarktimer: null,
        focuswatchdogtimer: null,
        tabLeaseTimer: null,
        timerfrozen: false,
        internalmutation: false,
        isNavigating: false,
        isUnloading: false,
        navigatingResetTimer: null,
        lasttamper: 0,
        lastviolationreport: 0,
        lastheartbeat: 0,
        heartbeatsinflight: false,
        overlayobserver: null,
        contenttimer: null,
        born: new Date().getTime(),
        bootUntil: new Date().getTime() + BOOT_GRACE_MS,
        trace: []
    };

    var tabChannel = null;
    var questionCache = [];
    var questionsHidden = false;
    var lostFocusConsecutiveCount = 0;

    /**
     * Current client time in milliseconds.
     *
     * @return {number}
     */
    function nowMs() {
        return new Date().getTime();
    }

    /**
     * Current server time in seconds, corrected with the offset from PHP.
     *
     * @return {number}
     */
    function serverNow() {
        return Math.floor(nowMs() / 1000) + state.serveroffset;
    }

    /**
     * Remaining penalty seconds according to the server deadline.
     *
     * @return {number}
     */
    function remaining() {
        return Math.max(0, state.penaltyuntil - serverNow());
    }

    /**
     * Get a translated string with a safe fallback.
     *
     * @param {string} key string key inside cfg.strings.
     * @param {string} fallback optional fallback.
     * @return {string}
     */
    function str(key, fallback) {
        if (strings && typeof strings[key] === 'string' && strings[key] !== '') {
            return strings[key];
        }
        if (typeof fallback === 'string') {
            return fallback;
        }
        return DEFAULTS[key] || '';
    }

    /**
     * Replace the {$a} placeholder in a template.
     *
     * @param {string} template template string.
     * @param {string|number} value replacement value.
     * @return {string}
     */
    function format(template, value) {
        return String(template || '').replace('{$a}', String(value));
    }

    /**
     * Pad a number to two digits.
     *
     * @param {number} number value.
     * @return {string}
     */
    function pad(number) {
        return number < 10 ? '0' + number : String(number);
    }

    /**
     * Format seconds as mm:ss or hh:mm:ss.
     *
     * @param {number} total seconds.
     * @return {string}
     */
    function formatClock(total) {
        var seconds = Math.max(0, Math.floor(total));
        var minutes = Math.floor(seconds / 60) % 60;
        var hours = Math.floor(seconds / 3600);
        var rest = seconds % 60;
        if (hours > 0) {
            return pad(hours) + ':' + pad(minutes) + ':' + pad(rest);
        }
        return pad(minutes) + ':' + pad(rest);
    }

    /**
     * Format a server timestamp for the watermark.
     *
     * @param {number} timestamp unix timestamp in seconds.
     * @return {string}
     */
    function formatTimestamp(timestamp) {
        var date = new Date(timestamp * 1000);
        return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + date.getFullYear()
            + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }

    /**
     * Simple throttle helper.
     *
     * @param {Function} callback function to throttle.
     * @param {number} wait milliseconds.
     * @return {Function}
     */
    function throttle(callback, wait) {
        var last = 0;
        return function() {
            var now = nowMs();
            if (now - last < wait) {
                return;
            }
            last = now;
            callback();
        };
    }

    /**
     * Encode an object as an application/x-www-form-urlencoded body.
     *
     * @param {Object} data key value pairs.
     * @return {string}
     */
    function encodeForm(data) {
        var parts = [];
        for (var key in data) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) {
                continue;
            }
            var value = data[key];
            if (value === null || typeof value === 'undefined') {
                value = '';
            } else if (typeof value === 'object') {
                value = JSON.stringify(value);
            }
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        }
        return parts.join('&');
    }

    /**
     * Send a POST request to ajax.php.
     *
     * @param {string} action action name.
     * @param {Object} extra extra parameters.
     * @param {Function} callback callback(error, data).
     */
    function sendAction(action, extra, callback) {
        // Kill-switch: never let ANY request leave while the page is being swapped
        // (quiz pagination, submit, reload). The callback is still invoked so that
        // in-flight flags (e.g. heartbeatsinflight) are always released.
        if (state.isNavigating || state.isUnloading) {
            if (typeof callback === 'function') {
                callback(new Error('unloading'));
            }
            return;
        }
        // Point 4: for 1 second after a quiz navigation click (on the dying page and
        // on the freshly redirected one) a violation must never reach the server.
        if (action === 'violation' && isNavigationWindow()) {
            if (typeof callback === 'function') {
                callback(new Error('navigating'));
            }
            return;
        }
        // Duplicate tab must never send violation reports.
        if (action === 'violation' && state.isDuplicateTab) {
            if (typeof callback === 'function') {
                callback(new Error('duplicatetab'));
            }
            return;
        }
        // Lock-pause: while screen is locked, only overlayremoved violations may be sent.
        if (action === 'violation' && state.locked && (!extra || extra.eventtype !== 'overlayremoved')) {
            if (typeof callback === 'function') {
                callback(new Error('locked'));
            }
            return;
        }
        var payload = {
            action: action,
            sesskey: cfg.sesskey || '',
            attemptid: cfg.attemptid,
            cmid: cfg.cmid || 0
        };
        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    payload[key] = extra[key];
                }
            }
        }

        var body = encodeForm(payload);

        if (window.fetch) {
            window.fetch(cfg.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body
            }).then(function(response) {
                return response.json();
            }).then(function(data) {
                if (typeof callback === 'function') {
                    callback(null, data);
                }
            }).catch(function(error) {
                if (typeof callback === 'function') {
                    callback(error || new Error('network'));
                }
            });
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) {
                return;
            }
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }
            if (typeof callback !== 'function') {
                return;
            }
            if (xhr.status >= 200 && xhr.status < 300 && data) {
                callback(null, data);
            } else {
                callback(new Error('http' + xhr.status));
            }
        };
        xhr.send(body);
    }

    /**
     * Storage key for this attempt.
     *
     * @return {string}
     */
    function storageKey() {
        return STORAGE_PREFIX + cfg.attemptid;
    }

    /**
     * Cleanly purge all penalty and pending violation state from local and session storage.
     */
    function clearStorage() {
        try {
            window.localStorage.removeItem(storageKey());
            window.localStorage.removeItem(storageKey() + '_queue');
        } catch (e) {
            // Storage can be disabled, ignore.
        }
        try {
            window.sessionStorage.removeItem(storageKey());
            window.sessionStorage.removeItem(storageKey() + '_queue');
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    var pendingViolationTimer = null;
    var pendingViolationType = null;

    /**
     * Cancel any pending scheduled violation.
     */
    function cancelPendingViolation() {
        if (pendingViolationTimer) {
            window.clearTimeout(pendingViolationTimer);
            pendingViolationTimer = null;
        }
        pendingViolationType = null;
    }

    /**
     * Mark that user is intentionally navigating, paginating or submitting within the quiz.
     */
    function markNavigating() {
        state.isNavigating = true;
        state.isUnloading = true;
        cancelPendingViolation();
        setNavMarker();
        trace('nav');
        if (state.navigatingResetTimer) {
            window.clearTimeout(state.navigatingResetTimer);
        }
        state.navigatingResetTimer = window.setTimeout(releaseNavigation, 10000);
    }

    /**
     * Lock down every violation path because the document is going away.
     *
     * pagehide/beforeunload also fire when the unload is cancelled (leave-page
     * dialog, download links), so the flags are released again after a short time.
     * A restored bfcache page is released by the pageshow listener.
     *
     * @param {number} resetms milliseconds until the flags are released.
     */
    function markUnloading(resetms) {
        state.isUnloading = true;
        state.isNavigating = true;
        cancelPendingViolation();
        stopTabLease();
        releaseTabLease();
        setNavMarker();
        trace('unload');
        if (state.navigatingResetTimer) {
            window.clearTimeout(state.navigatingResetTimer);
        }
        state.navigatingResetTimer = window.setTimeout(releaseNavigation, resetms);
    }

    /**
     * Release the navigation kill-switch.
     */
    function releaseNavigation() {
        if (state.navigatingResetTimer) {
            window.clearTimeout(state.navigatingResetTimer);
            state.navigatingResetTimer = null;
        }
        state.isNavigating = false;
        state.isUnloading = false;
    }

    /**
     * Remember in sessionStorage that the student is navigating inside the quiz.
     *
     * The value survives the page swap performed by processattempt.php, which is
     * what allows the next document to ignore the blur/visibilitychange noise
     * fired while Moodle tears the old page down.
     */
    function setNavMarker() {
        try {
            window.sessionStorage.setItem(NAV_MARKER_KEY, String(nowMs()));
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    /**
     * Age in milliseconds of the navigation marker.
     *
     * @return {number} age, or -1 when there is no marker.
     */
    function navMarkerAge() {
        var raw;
        try {
            raw = window.sessionStorage.getItem(NAV_MARKER_KEY);
        } catch (e) {
            return -1;
        }
        if (!raw) {
            return -1;
        }
        var ts = parseInt(raw, 10);
        if (isNaN(ts)) {
            return -1;
        }
        return nowMs() - ts;
    }

    /**
     * True during the 1 second that follows an in-quiz navigation click.
     *
     * @return {boolean}
     */
    function isNavigationWindow() {
        var age = navMarkerAge();
        return age >= 0 && age < NAV_GRACE_MS;
    }

    /**
     * True while the freshly loaded document is still initialising Moodle editors (800ms).
     *
     * @return {boolean}
     */
    function isBootGrace() {
        return nowMs() < state.bootUntil;
    }

    /**
     * True during the brief transition window right after an unlock or tab takeover.
     *
     * @return {boolean}
     */
    function isUnlockGrace() {
        return nowMs() < state.unlockGraceUntil;
    }

    /**
     * Read the current Single-Tab Guard lease object from localStorage.
     *
     * @return {Object|null}
     */
    function readTabLease() {
        var raw = null;
        try {
            raw = window.localStorage.getItem(TAB_LEASE_KEY);
        } catch (e) {
            return null;
        }
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    /**
     * Write or refresh this tab's ownership lease in localStorage.
     */
    function writeTabLease() {
        try {
            window.localStorage.setItem(TAB_LEASE_KEY, JSON.stringify({
                tabId: TAB_ID,
                ts: nowMs()
            }));
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    /**
     * Broadcast a Single-Tab Guard coordination message via BroadcastChannel and localStorage.
     *
     * @param {string} type signal type ('hello', 'alive', 'takeover', 'released').
     */
    function broadcastTabSignal(type) {
        var msg = {
            type: type,
            tabId: TAB_ID,
            ts: nowMs(),
            nonce: Math.random().toString(36).slice(2)
        };
        if (tabChannel) {
            try {
                tabChannel.postMessage(msg);
            } catch (e) {
                // Ignore closed channel errors.
            }
        }
        try {
            window.localStorage.setItem(TAB_SIGNAL_KEY, JSON.stringify(msg));
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    /**
     * Release the tab lease on navigation/unload ONLY if this tab owns it.
     */
    function releaseTabLease() {
        var lease = readTabLease();
        if (lease && lease.tabId === TAB_ID) {
            try {
                window.localStorage.removeItem(TAB_LEASE_KEY);
            } catch (e) {
                // Storage can be disabled, ignore.
            }
            broadcastTabSignal('released');
        }
    }

    /**
     * Start periodic renewal of the active tab lease.
     */
    function startTabLease() {
        writeTabLease();
        if (state.tabLeaseTimer) {
            window.clearInterval(state.tabLeaseTimer);
        }
        state.tabLeaseTimer = window.setInterval(function() {
            if (!state.isDuplicateTab && !state.isUnloading) {
                writeTabLease();
            }
        }, TAB_LEASE_REFRESH_MS);
    }

    /**
     * Stop periodic renewal of the active tab lease.
     */
    function stopTabLease() {
        if (state.tabLeaseTimer) {
            window.clearInterval(state.tabLeaseTimer);
            state.tabLeaseTimer = null;
        }
    }

    /**
     * Build and display the non-penalty Duplicate Tab overlay.
     */
    function createDuplicateTabOverlay() {
        if (document.getElementById(DUPLICATE_OVERLAY_ID)) {
            return;
        }
        state.internalmutation = true;

        var overlay = document.createElement('div');
        overlay.id = DUPLICATE_OVERLAY_ID;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.style.cssText = DUPLICATE_OVERLAY_STYLE;

        var box = document.createElement('div');
        box.className = 'examguard-duplicatetab-box';
        box.style.cssText = 'max-width:560px;width:100%;background:#ffffff;border:1px solid #dee2e6;'
            + 'border-radius:10px;padding:32px 28px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,0.08);'
            + 'font-family:Arial,Helvetica,sans-serif;color:#1d2125;';

        var title = document.createElement('h2');
        title.style.cssText = 'font-size:22px;margin:0 0 14px;color:#0f6cbf;font-weight:700;';
        title.textContent = str('duplicatetab_title');
        box.appendChild(title);

        var desc = document.createElement('p');
        desc.style.cssText = 'font-size:15px;line-height:1.6;margin:0 0 24px;color:#495057;';
        desc.textContent = str('duplicatetab_desc');
        box.appendChild(desc);

        var btn = document.createElement('button');
        btn.id = 'examguard-takeover-btn';
        btn.type = 'button';
        btn.className = 'btn btn-primary examguard-takeover-btn';
        btn.style.cssText = 'background:#0f6cbf;color:#ffffff;border:none;border-radius:6px;'
            + 'padding:10px 22px;font-size:15px;font-weight:600;cursor:pointer;';
        btn.textContent = str('duplicatetab_takeover');
        btn.addEventListener('click', function() {
            takeOverTab();
        });
        box.appendChild(btn);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        state.internalmutation = false;
    }

    /**
     * Remove the Duplicate Tab overlay if present.
     */
    function removeDuplicateTabOverlay() {
        var overlay = document.getElementById(DUPLICATE_OVERLAY_ID);
        if (overlay && overlay.parentNode) {
            state.internalmutation = true;
            overlay.parentNode.removeChild(overlay);
            state.internalmutation = false;
        }
    }

    /**
     * Enter passive Duplicate Tab Mode: stop all violation reporting, hide questions,
     * and show a non-penalty takeover screen.
     */
    function enterDuplicateTabMode() {
        state.isDuplicateTab = true;
        stopTabLease();
        cancelPendingViolation();
        stopHeartbeat();
        lostFocusConsecutiveCount = 0;
        state.focusViolationFired = false;
        hideQuestions();
        createDuplicateTabOverlay();
        trace('dup-enter');
    }

    /**
     * Take ownership of this attempt in the current tab and demote any other open tab.
     */
    function takeOverTab() {
        state.isDuplicateTab = false;
        state.tabTakeoverTs = nowMs();
        state.unlockGraceUntil = nowMs() + UNLOCK_GRACE_MS;
        lostFocusConsecutiveCount = 0;
        state.focusViolationFired = false;
        removeDuplicateTabOverlay();
        startTabLease();
        broadcastTabSignal('takeover');
        trace('dup-takeover');

        if (!state.locked) {
            restoreQuestions(true);
        }
        heartbeat();
    }

    /**
     * Process an incoming Single-Tab Guard signal from another tab.
     *
     * @param {Object} msg signal payload.
     */
    function handleTabSignal(msg) {
        if (!msg || !msg.type || !msg.tabId || msg.tabId === TAB_ID) {
            return;
        }
        if (msg.type === 'hello') {
            if (!state.isDuplicateTab && !state.isUnloading) {
                writeTabLease();
                broadcastTabSignal('alive');
            }
        } else if (msg.type === 'alive') {
            if (!state.tabTakeoverTs || (msg.ts && msg.ts > state.tabTakeoverTs)) {
                enterDuplicateTabMode();
            }
        } else if (msg.type === 'takeover') {
            if (!state.isDuplicateTab) {
                enterDuplicateTabMode();
            }
        }
    }

    /**
     * Initialise Single-Tab Guard using BroadcastChannel + localStorage lease/events.
     *
     * @return {boolean} true if this tab is the active owner, false if duplicate.
     */
    function initSingleTabGuard() {
        if (!tabChannel && typeof window.BroadcastChannel === 'function') {
            try {
                tabChannel = new window.BroadcastChannel(TAB_CHANNEL_NAME);
                tabChannel.onmessage = function(ev) {
                    if (ev && ev.data) {
                        handleTabSignal(ev.data);
                    }
                };
            } catch (e) {
                tabChannel = null;
            }
        }

        var existingLease = readTabLease();
        var otherActive = !!(existingLease && existingLease.tabId && existingLease.tabId !== TAB_ID
            && (nowMs() - (existingLease.ts || 0)) < TAB_LEASE_STALE_MS);

        if (otherActive) {
            broadcastTabSignal('hello');
            enterDuplicateTabMode();
            return false;
        }

        startTabLease();
        broadcastTabSignal('hello');
        return true;
    }

    /**
     * Keep a short breadcrumb of the last DOM events, stored with every violation
     * so a false positive can be diagnosed from the database afterwards.
     *
     * @param {string} name event name.
     */
    function trace(name) {
        if (!state.trace) {
            state.trace = [];
        }
        state.trace.push(Math.round(nowMs() - state.born) + ':' + name);
        while (state.trace.length > 8) {
            state.trace.shift();
        }
    }

    /**
     * True when focus sits inside an Essay editor frame or editor textarea.
     *
     * Internal editor focus requires document.hasFocus() === true. In modern
     * browsers, moving focus into same-origin iframes like TinyMCE keeps top-level
     * document.hasFocus() true. Conversely, clicking browser UI like Chrome's
     * Ask Gemini side panel immediately makes document.hasFocus() false.
     *
     * @return {boolean}
     */
    function isEditorFocusElement() {
        if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
            return false;
        }
        var active = document.activeElement;
        if (!active || !active.tagName) {
            return false;
        }
        var tag = active.tagName.toUpperCase();
        if (tag === 'IFRAME' || tag === 'TEXTAREA') {
            if (typeof active.closest === 'function') {
                return !!active.closest('.tox, .tox-tinymce, .editor_atto, .tox-edit-area');
            }
            var cls = active.className || '';
            return cls.indexOf('tox') !== -1 || cls.indexOf('atto') !== -1;
        }
        if (typeof active.closest === 'function') {
            return !!active.closest('.tox, .tox-tinymce, .editor_atto');
        }
        return false;
    }

    /**
     * Diagnostic payload attached to every violation report.
     *
     * It is stored in local_examguard_logs.extra.client, so the exact cause of a
     * reported event can be inspected without reproducing it in a browser.
     *
     * @param {string} type event type.
     * @return {string} JSON string.
     */
    function navDiagnostics(type) {
        var active = document.activeElement;
        var payload = {
            v: 1,
            type: type,
            vis: document.visibilityState,
            hasfocus: (typeof document.hasFocus === 'function') ? document.hasFocus() : null,
            ready: document.readyState,
            bootgrace: isBootGrace(),
            navwindow: isNavigationWindow(),
            navmark: navMarkerAge(),
            navigating: state.isNavigating,
            unloading: state.isUnloading,
            qhidden: questionsHidden,
            trace: state.trace ? state.trace.slice(0) : []
        };
        if (active && active.tagName) {
            payload.active = active.tagName.toLowerCase()
                + (active.id ? '#' + active.id : '')
                + ((typeof active.className === 'string' && active.className !== '')
                    ? '.' + active.className.split(' ')[0] : '');
        }
        try {
            return JSON.stringify(payload);
        } catch (e) {
            return '';
        }
    }

    /**
     * Schedule a violation with a grace period (120ms for tabswitch, 400ms for windowblur).
     *
     * If the document is navigating between quiz pages or submitting answers, the
     * navigation handlers/beforeunload/pagehide events cancel this timer,
     * preventing false positives on quiz pagination.
     *
     * @param {string} type event type.
     */
    function scheduleViolation(type) {
        if (state.isDuplicateTab || state.isNavigating || state.isUnloading) {
            return;
        }
        // LOCK-PAUSE: while locked, ignore all violations except overlay tampering.
        if (state.locked && type !== 'overlayremoved') {
            cancelPendingViolation();
            return;
        }
        if (type !== 'overlayremoved' && isUnlockGrace()) {
            trace('skip-unlock-grace:' + type);
            cancelPendingViolation();
            return;
        }
        // tabswitch is NEVER subject to bootGrace: editor init never hides visibilityState.
        if (isNavigationWindow() || (type !== 'tabswitch' && isBootGrace())) {
            trace('skip-grace:' + type);
            cancelPendingViolation();
            return;
        }
        // Focus inside an Essay editor frame is not an AFK event (only while document.hasFocus() is true).
        // A visibilitychange to "hidden" is authoritative and must NEVER be filtered by editor focus.
        if (type !== 'tabswitch' && isEditorFocusElement()) {
            trace('skip-editor:' + type);
            cancelPendingViolation();
            return;
        }
        // Do not downgrade or overwrite a pending tabswitch with windowblur.
        if (pendingViolationType === 'tabswitch' && type !== 'tabswitch') {
            return;
        }
        cancelPendingViolation();
        pendingViolationType = type;
        // Tabswitch grace is shortened to 120ms (more than enough for beforeunload/pagehide).
        var delay = (type === 'tabswitch') ? 120 : 400;
        pendingViolationTimer = window.setTimeout(function() {
            pendingViolationTimer = null;
            if (state.isDuplicateTab || state.isNavigating || state.isUnloading) {
                return;
            }
            if (state.locked && type !== 'overlayremoved') {
                return;
            }
            if (type !== 'overlayremoved' && isUnlockGrace()) {
                return;
            }
            if (isNavigationWindow() || (type !== 'tabswitch' && isBootGrace())) {
                trace('skip-grace-late:' + type);
                return;
            }
            // For tabswitch: do NOT abort if document is visible now. If it became hidden,
            // that was a tab switch or minimize and must be reported!
            if (type === 'windowblur') {
                if (isEditorFocusElement()) {
                    trace('skip-editor-late:' + type);
                    return;
                }
                if (typeof document.hasFocus === 'function' && document.hasFocus()) {
                    return;
                }
            }
            reportViolation(type);
        }, delay);
    }

    /**
     * Find if an element or any of its ancestors is a legitimate Moodle quiz navigation target.
     *
     * @param {Element} target clicked element.
     * @return {Element|null}
     */
    function findNavElement(target) {
        if (!target || target === document) {
            return null;
        }
        if (typeof target.closest === 'function') {
            if (target.closest('.tox, .tox-tinymce, .editor_atto')) {
                return null;
            }
            var navBtn = target.closest('.qnbutton, .mod_quiz-next-nav, .mod_quiz-prev-nav, .endtestlink, input[name="next"], input[name="previous"], button[name="next"], button[name="previous"]');
            if (navBtn) {
                return navBtn;
            }
            var link = target.closest('a');
            if (link) {
                var href = link.getAttribute('href') || '';
                href = href.trim();
                if (href && href !== '#' && href.indexOf('#') !== 0 && href.indexOf('javascript:') !== 0) {
                    return link;
                }
            }
            return null;
        }
        var cur = target;
        while (cur && cur !== document) {
            var tag = cur.nodeName ? cur.nodeName.toLowerCase() : '';
            var cls = cur.className || '';
            if (typeof cls === 'string' && (cls.indexOf('tox') !== -1 || cls.indexOf('editor_atto') !== -1)) {
                return null;
            }
            if (typeof cls === 'string' && (cls.indexOf('qnbutton') !== -1 || cls.indexOf('mod_quiz') !== -1 || cls.indexOf('endtestlink') !== -1)) {
                return cur;
            }
            if ((tag === 'input' || tag === 'button') && (cur.name === 'next' || cur.name === 'previous')) {
                return cur;
            }
            if (tag === 'a') {
                var aHref = cur.getAttribute('href') || '';
                aHref = aHref.trim();
                if (aHref && aHref !== '#' && aHref.indexOf('#') !== 0 && aHref.indexOf('javascript:') !== 0) {
                    return cur;
                }
            }
            cur = cur.parentNode;
        }
        return null;
    }

    /**
     * Attach listeners directly to Moodle quiz response form.
     */
    function attachResponseFormListeners() {
        var responseForm = document.getElementById('responseform');
        if (responseForm && !responseForm.__examguardBound) {
            responseForm.__examguardBound = true;
            responseForm.addEventListener('submit', function() {
                markNavigating();
            }, true);
        }
    }

    /**
     * Mirror the server state into local and session storage.
     *
     * The mirror is only used to synchronise tabs, the server stays in charge.
     */
    function mirrorState() {
        if (!state.locked || !state.penaltyuntil || state.penaltyuntil <= serverNow()) {
            clearStorage();
            return;
        }
        var payload = JSON.stringify({
            until: state.penaltyuntil,
            count: state.violationcount,
            locked: state.locked,
            ts: serverNow()
        });
        try {
            window.localStorage.setItem(storageKey(), payload);
        } catch (e) {
            // Storage can be disabled, ignore.
        }
        try {
            window.sessionStorage.setItem(storageKey(), payload);
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    /**
     * React to Single-Tab Guard signals/leases and penalty mirrors from other tabs.
     *
     * @param {StorageEvent} event storage event.
     */
    function onStorage(event) {
        if (!event || !event.key) {
            return;
        }
        if (event.key === TAB_SIGNAL_KEY && event.newValue) {
            var sig;
            try {
                sig = JSON.parse(event.newValue);
            } catch (e) {
                return;
            }
            handleTabSignal(sig);
            return;
        }
        if (event.key === TAB_LEASE_KEY && event.newValue) {
            var lease;
            try {
                lease = JSON.parse(event.newValue);
            } catch (e) {
                return;
            }
            if (lease && lease.tabId && lease.tabId !== TAB_ID && !state.isDuplicateTab) {
                enterDuplicateTabMode();
            }
            return;
        }
        if (event.key !== storageKey() || !event.newValue || state.isDuplicateTab) {
            return;
        }
        var data;
        try {
            data = JSON.parse(event.newValue);
        } catch (e) {
            return;
        }
        // Storage is only a "something changed" signal. The server is the single
        // source of truth, so never lock or unlock from the stored values.
        if (!data || !data.locked) {
            return;
        }
        window.setTimeout(function() {
            if (!state.isNavigating && !state.isUnloading && !state.isDuplicateTab) {
                heartbeat();
            }
        }, 800);
    }

    /**
     * Remember a violation that could not be sent to the server yet.
     *
     * @param {string} type event type.
     */
    function queuePending(type) {
        if (state.isDuplicateTab || state.isNavigating || state.isUnloading) {
            return;
        }
        if (state.locked && type !== 'overlayremoved') {
            return;
        }
        var key = storageKey() + '_queue';
        var queue = [];
        try {
            queue = JSON.parse(window.localStorage.getItem(key) || '[]');
        } catch (e) {
            queue = [];
        }
        if (!queue || queue.length > 20) {
            queue = [];
        }
        queue.push({type: type, ts: nowMs()});
        try {
            window.localStorage.setItem(key, JSON.stringify(queue));
        } catch (e) {
            // Storage can be disabled, ignore.
        }
    }

    /**
     * Re-send the oldest queued violation when the network is back.
     */
    function flushPending() {
        if (state.isDuplicateTab) {
            return;
        }
        var key = storageKey() + '_queue';
        var queue = [];
        try {
            queue = JSON.parse(window.localStorage.getItem(key) || '[]');
        } catch (e) {
            return;
        }
        if (!queue || !queue.length) {
            return;
        }
        // Never replay a queued violation while a quiz page is being swapped or already locked.
        if (state.isNavigating || state.isUnloading || isBootGrace() || isNavigationWindow()) {
            window.setTimeout(flushPending, 1200);
            return;
        }
        try {
            window.localStorage.removeItem(key);
        } catch (e) {
            // Storage can be disabled, ignore.
        }
        var queuedType = queue[0].type || 'tabswitch';
        if (state.locked && queuedType !== 'overlayremoved') {
            return;
        }
        sendAction('violation', {eventtype: queuedType}, function(error, data) {
            if (error || !data || data.status !== 'ok') {
                return;
            }
            state.provisional = false;
            applyServerState(data);
            if (data.remaining > 0) {
                lockScreen(data.penaltyuntil, data.violationcount, false);
            }
            startHeartbeat();
        });
    }

    /**
     * Send a passive event that is logged but never extends the penalty.
     *
     * @param {string} action pageload or devtools.
     */
    function reportPassive(action) {
        sendAction(action, {}, function() {
            // Passive logging only, nothing to do with the response.
        });
    }

    /**
     * Apply the authoritative values returned by the server.
     *
     * @param {Object} data JSON response.
     */
    function applyServerState(data) {
        if (!data) {
            return;
        }
        if (typeof data.servernow === 'number') {
            state.serveroffset = data.servernow - Math.floor(nowMs() / 1000);
        }
        if (typeof data.penaltyuntil === 'number') {
            state.penaltyuntil = data.penaltyuntil;
        }
        if (typeof data.violationcount === 'number') {
            state.violationcount = data.violationcount;
        }
        if (typeof data.frozenseconds === 'number') {
            state.frozenseconds = data.frozenseconds;
        }
        if (typeof data.timeleft === 'number') {
            state.timeleft = data.timeleft;
        }
    }

    /**
     * Block copy, paste, context menu and drag events.
     */
    function setupBlockers() {
        if (settings.blockcopy) {
            var events = ['copy', 'cut', 'paste', 'contextmenu', 'selectstart', 'dragstart'];
            for (var i = 0; i < events.length; i++) {
                document.addEventListener(events[i], handleBlockedEvent, true);
            }
            document.addEventListener('keydown', blockClipboardShortcuts, true);
        }
        if (settings.blockdevtools) {
            document.addEventListener('keydown', blockDevtoolsShortcuts, true);
        }
    }

    /**
     * Cancel a blocked clipboard or drag event.
     *
     * @param {Event} event DOM event.
     */
    function handleBlockedEvent(event) {
        if (event.type === 'dragstart') {
            var tag = event.target && event.target.nodeName ? event.target.nodeName.toLowerCase() : '';
            if (tag !== 'img' && tag !== 'a') {
                return;
            }
        }
        event.preventDefault();
        event.stopPropagation();
    }

    /**
     * Block the copy, paste, select all, print and save shortcuts.
     *
     * @param {KeyboardEvent} event keyboard event.
     */
    function blockClipboardShortcuts(event) {
        var key = (event.key || '').toLowerCase();
        if (!(event.ctrlKey || event.metaKey)) {
            return;
        }
        if (key === 'c' || key === 'x' || key === 'v' || key === 'a' || key === 'p' || key === 's') {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    /**
     * Deter the browser developer tools shortcuts.
     *
     * @param {KeyboardEvent} event keyboard event.
     */
    function blockDevtoolsShortcuts(event) {
        var key = (event.key || '').toLowerCase();
        if (key === 'f12') {
            event.preventDefault();
            event.stopPropagation();
            reportPassive('devtools');
            return;
        }
        if ((event.ctrlKey || event.metaKey) && event.shiftKey) {
            if (key === 'i' || key === 'j' || key === 'c' || key === 'k') {
                event.preventDefault();
                event.stopPropagation();
                reportPassive('devtools');
            }
        }
    }

    /**
     * Create an invisible AI trap span using clip-path, visible to AI/accessibility tree.
     *
     * @return {HTMLSpanElement}
     */
    function createTrapSpan() {
        var trap = document.createElement('span');
        trap.className = 'examguard-aitrap';
        trap.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;'
            + 'clip-path:inset(50%);white-space:nowrap;user-select:none;'
            + '-webkit-user-select:none;pointer-events:none;color:transparent;';
        trap.textContent = settings.traptext;
        return trap;
    }

    /**
     * Inject the hidden AI traps and obfuscate the question text.
     */
    function setupTraps() {
        if (questionsHidden) {
            return;
        }
        if (settings.trapenabled && settings.traptext) {
            var containers = document.querySelectorAll('.qtext');
            for (var i = 0; i < containers.length; i++) {
                addTrap(containers[i]);
            }
        }
        obfuscateQuestionText();
        captureQuestions();
    }

    /**
     * Add trap instructions at the beginning and in the middle of a question container.
     *
     * @param {Element} container .qtext element.
     */
    function addTrap(container) {
        if (!container || container.getAttribute('data-examguard-trap') === '1') {
            return;
        }
        container.setAttribute('data-examguard-trap', '1');

        // 1. Trap at the beginning of .qtext
        var startTrap = createTrapSpan();
        if (container.firstChild) {
            container.insertBefore(startTrap, container.firstChild);
        } else {
            container.appendChild(startTrap);
        }

        // 2. Trap in the middle of .qtext
        var midTrap = createTrapSpan();
        var children = container.childNodes;
        if (children.length > 2) {
            var midIdx = Math.floor(children.length / 2);
            container.insertBefore(midTrap, children[midIdx]);
        } else if (container.children && container.children.length > 0) {
            var firstEl = container.children[0];
            if (firstEl && firstEl.childNodes.length > 1) {
                var elMid = Math.floor(firstEl.childNodes.length / 2);
                firstEl.insertBefore(midTrap, firstEl.childNodes[elMid]);
            } else {
                container.appendChild(midTrap);
            }
        } else {
            container.appendChild(midTrap);
        }
    }

    /**
     * Capture original question HTML from all .qtext elements before any hiding occurs.
     */
    function captureQuestions() {
        if (questionsHidden) {
            return;
        }
        var blockedNotice = getQuestionBlockedNotice();
        var nodes = document.querySelectorAll('.qtext');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.innerHTML && (el.innerHTML.indexOf(blockedNotice) !== -1
                    || el.innerHTML.indexOf('examguard-blocked-notice') !== -1
                    || el.innerHTML.indexOf(QUESTION_BLOCKED_NOTICE) !== -1)) {
                continue;
            }
            var found = false;
            for (var j = 0; j < questionCache.length; j++) {
                if (questionCache[j].el === el) {
                    found = true;
                    questionCache[j].html = el.innerHTML;
                    break;
                }
            }
            if (!found) {
                questionCache.push({
                    el: el,
                    html: el.innerHTML
                });
            }
        }
    }

    /**
     * Temporarily swap all .qtext innerHTML with a placeholder when focus or visibility is lost.
     */
    function hideQuestions() {
        if (questionsHidden) {
            return;
        }
        if (state.isNavigating || state.isUnloading) {
            return;
        }
        captureQuestions();
        questionsHidden = true;
        var blockedNotice = getQuestionBlockedNotice();
        for (var i = 0; i < questionCache.length; i++) {
            var item = questionCache[i];
            if (item.el) {
                item.el.innerHTML = '<span class="examguard-blocked-notice" style="display:inline-block;padding:12px 16px;background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:4px;font-weight:bold;margin:8px 0;">'
                    + blockedNotice + '</span>';
            }
        }
    }

    /**
     * Restore original question innerHTML once focus is returned and page is not locked.
     *
     * @param {boolean} force optional flag to restore immediately on unlock/takeover.
     */
    function restoreQuestions(force) {
        if (!questionsHidden) {
            return;
        }
        if (state.locked || state.isDuplicateTab) {
            return;
        }
        if (!force && typeof document.hasFocus === 'function' && !document.hasFocus()) {
            return;
        }
        for (var i = 0; i < questionCache.length; i++) {
            var item = questionCache[i];
            if (item.el) {
                item.el.innerHTML = item.html;
            }
        }
        questionsHidden = false;
    }

    var SKIP_TAGS = ['script', 'style', 'code', 'pre', 'textarea', 'input', 'select', 'option',
        'math', 'svg', 'noscript', 'iframe'];
    var SKIP_SELECTOR = '.examguard-aitrap, .examguard-blocked-notice, .MathJax, mjx-container, [contenteditable="true"], '
        + '.filter_mathjaxloader, .tex2jax_ignore';

    /**
     * Insert zero width spaces into question text nodes.
     */
    function obfuscateQuestionText() {
        if (!settings.zwspenabled || !document.createTreeWalker) {
            return;
        }
        var containers = document.querySelectorAll('.qtext');
        for (var i = 0; i < containers.length; i++) {
            obfuscateNode(containers[i]);
        }
    }

    /**
     * Obfuscate all suitable text nodes below a root element.
     *
     * @param {Element} root container element.
     */
    function obfuscateNode(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
        var nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (node.__examguardZwsp) {
                continue;
            }
            var parent = node.parentNode;
            if (!parent || !parent.nodeName) {
                continue;
            }
            var tag = parent.nodeName.toLowerCase();
            if (SKIP_TAGS.indexOf(tag) !== -1) {
                continue;
            }
            if (parent.closest && parent.closest(SKIP_SELECTOR)) {
                continue;
            }
            var text = node.nodeValue;
            if (!text || text.length < 2) {
                continue;
            }
            // Drag and drop question types rely on [[1]] placeholders, never touch them.
            if (text.indexOf('[[') !== -1 || text.indexOf(']]') !== -1) {
                continue;
            }
            // Only obfuscate text that actually contains letters.
            if (!/[A-Za-z\u00C0-\u024F]/.test(text)) {
                continue;
            }
            var out = '';
            for (var j = 0; j < text.length; j++) {
                var ch = text.charAt(j);
                out += ch;
                if (j < text.length - 1 && ch !== ' ' && ch !== '\n' && ch !== '\t') {
                    out += ZWSP;
                }
            }
            node.nodeValue = out;
            node.__examguardZwsp = true;
        }
    }

    /**
     * Check if watermark is enabled in configuration.
     *
     * @return {boolean}
     */
    function isWatermarkEnabled() {
        if (typeof settings.watermark !== 'undefined') {
            return !!settings.watermark;
        }
        return !!settings.watermarkenabled;
    }

    /**
     * Create the traceable diagonal watermark layer.
     */
    function setupWatermark() {
        if (!isWatermarkEnabled()) {
            return;
        }
        var layer = document.getElementById(WATERMARK_ID);
        if (!layer) {
            layer = document.createElement('div');
            layer.id = WATERMARK_ID;
            layer.setAttribute('aria-hidden', 'true');
            document.body.appendChild(layer);
        }
        layer.style.cssText = 'position:fixed;left:0;top:0;width:100%;height:100%;'
            + 'pointer-events:none;z-index:9998;overflow:hidden;';
        renderWatermark();
        state.watermarktimer = window.setInterval(renderWatermark, 1000);
        window.addEventListener('resize', throttle(renderWatermark, 400));
    }

    /**
     * Render or refresh the watermark tiles with the current timestamp.
     */
    function renderWatermark() {
        if (!isWatermarkEnabled()) {
            return;
        }
        var layer = document.getElementById(WATERMARK_ID);
        if (!layer) {
            return;
        }
        var width = window.innerWidth || document.documentElement.clientWidth || 1024;
        var height = window.innerHeight || document.documentElement.clientHeight || 768;

        var tilewidth = width < 600 ? 240 : 320;
        var tileheight = width < 600 ? 110 : 130;
        var columns = Math.max(1, Math.ceil(width / tilewidth));
        var rows = Math.max(1, Math.ceil(height / tileheight));
        var desired = columns * rows;
        if (desired > 60) {
            return;
        }

        var user = cfg.user || {};
        var text = (user.fullname || '') + ' | ' + (user.username || '') + ' | IP ' + (user.ip || '')
            + ' | ' + formatTimestamp(serverNow());

        while (layer.childNodes.length > desired) {
            layer.removeChild(layer.lastChild);
        }
        while (layer.childNodes.length < desired) {
            var tile = document.createElement('span');
            tile.className = 'examguard-watermark-tile';
            tile.style.cssText = 'position:absolute;white-space:nowrap;font-size:12px;'
                + 'font-family:Arial,Helvetica,sans-serif;color:#000;'
                + 'transform:rotate(-24deg);transform-origin:center;'
                + 'user-select:none;-webkit-user-select:none;pointer-events:none;';
            layer.appendChild(tile);
        }

        for (var index = 0; index < layer.childNodes.length; index++) {
            var node = layer.childNodes[index];
            var column = index % columns;
            var row = Math.floor(index / columns);
            node.style.left = (column * tilewidth) + 'px';
            node.style.top = (row * tileheight) + 'px';
            node.style.opacity = String(settings.watermarkopacity || 0.12);
            node.textContent = text;
        }
    }

    /**
     * Attach blur listener directly to contentWindow of editor iframes (.tox-edit-area__iframe).
     */
    function attachEditorIframeListeners() {
        var iframes = document.querySelectorAll('iframe.tox-edit-area__iframe, .tox-edit-area iframe, .editor_atto iframe');
        for (var i = 0; i < iframes.length; i++) {
            var iframe = iframes[i];
            if (iframe.__examguardBlurBound) {
                continue;
            }
            try {
                var win = iframe.contentWindow;
                if (win) {
                    iframe.__examguardBlurBound = true;
                    win.addEventListener('blur', function() {
                        trace('iframe-blur');
                        if (state.locked || state.isDuplicateTab || state.isNavigating || state.isUnloading) {
                            return;
                        }
                        hideQuestions();
                        window.setTimeout(function() {
                            if (state.locked || state.isDuplicateTab || state.isNavigating || state.isUnloading) {
                                return;
                            }
                            if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
                                scheduleViolation('windowblur');
                            }
                        }, 50);
                    });
                }
            } catch (e) {
                // Ignore cross-origin frame access errors.
            }
        }
    }

    /**
     * Active focus watchdog: runs every 200ms to detect Ask Gemini side panel or window blur.
     * Edge-triggered: fires reportViolation('windowblur') once after 2 consecutive checks
     * (400ms) with !hasFocus(), and will not fire again until focus returns to the document.
     */
    function startFocusWatchdog() {
        if (state.focuswatchdogtimer) {
            return;
        }
        state.focuswatchdogtimer = window.setInterval(function() {
            attachEditorIframeListeners();
            if (state.locked || state.isDuplicateTab || state.isNavigating
                    || state.isUnloading || isBootGrace() || isUnlockGrace()) {
                lostFocusConsecutiveCount = 0;
                return;
            }
            if (typeof document.hasFocus === 'function') {
                if (!document.hasFocus()) {
                    lostFocusConsecutiveCount++;
                    hideQuestions();
                    if (lostFocusConsecutiveCount >= 2 && !state.focusViolationFired) {
                        lostFocusConsecutiveCount = 0;
                        state.focusViolationFired = true;
                        reportViolation('windowblur');
                    }
                } else {
                    lostFocusConsecutiveCount = 0;
                    state.focusViolationFired = false;
                    if (!state.locked && !state.isDuplicateTab) {
                        restoreQuestions();
                    }
                }
            }
        }, 200);
    }

    /**
     * Reset edge-triggered focus state when active user interaction occurs in an unlocked active tab.
     */
    function onUserActivityResetFocus() {
        if (!state.locked && !state.isDuplicateTab) {
            lostFocusConsecutiveCount = 0;
            state.focusViolationFired = false;
        }
    }

    /**
     * Monitor tab switches, window blurs and page visibility.
     */
    function setupAfkDetector() {
        attachResponseFormListeners();

        // Listen for early user interactions (mousedown, touchstart, pointerdown)
        // on all possible quiz navigation elements (buttons, links, question numbers)
        // to set isNavigating = true before the browser begins page teardown.
        function onEarlyNavInteraction(event) {
            onUserActivityResetFocus();
            if (findNavElement(event.target)) {
                markNavigating();
            }
        }

        document.addEventListener('mousedown', onEarlyNavInteraction, true);
        document.addEventListener('touchstart', onEarlyNavInteraction, true);
        if (window.PointerEvent) {
            document.addEventListener('pointerdown', onEarlyNavInteraction, true);
        }
        document.addEventListener('click', onEarlyNavInteraction, true);
        document.addEventListener('keydown', function(event) {
            onUserActivityResetFocus();
            if (event.key === 'Enter' || event.keyCode === 13 || event.key === ' ' || event.keyCode === 32) {
                onEarlyNavInteraction(event);
            }
        }, true);

        // Catch form submissions at document level as well
        document.addEventListener('submit', function(event) {
            if (!event || !event.target || typeof event.target.closest !== 'function'
                    || !event.target.closest('.tox, .editor_atto')) {
                markNavigating();
            }
        }, true);

        // When page unload starts, cancel any scheduled violation and release tab lease.
        window.addEventListener('beforeunload', function() {
            // Short release: covers a cancelled "leave page?" dialog.
            markUnloading(4000);
        });

        window.addEventListener('pagehide', function() {
            trace('pagehide');
            markUnloading(4000);
        });

        // Page restored from the back/forward cache: detection and lease must be alive again.
        window.addEventListener('pageshow', function(event) {
            if (event && event.persisted) {
                releaseNavigation();
                initSingleTabGuard();
                if (state.isDuplicateTab) {
                    return;
                }
                if (state.locked) {
                    requestHeartbeat();
                } else if (typeof document.hasFocus !== 'function' || document.hasFocus()) {
                    restoreQuestions();
                }
            }
        });

        document.addEventListener('visibilitychange', function() {
            trace('vis:' + document.visibilityState);
            if (state.isDuplicateTab) {
                return;
            }
            if (document.visibilityState === 'hidden') {
                hideQuestions();
                if (state.locked || state.isNavigating || state.isUnloading) {
                    return;
                }
                scheduleViolation('tabswitch');
            } else {
                // Returned to visible: DO NOT cancel pending tabswitch violation!
                // Fast tab switch (< 120ms) or minimized window throttle must not evade penalty.
                if (state.locked) {
                    requestHeartbeat();
                } else if (typeof document.hasFocus !== 'function' || document.hasFocus()) {
                    restoreQuestions();
                }
            }
        });

        window.addEventListener('blur', function() {
            trace('blur');
            if (state.locked || state.isDuplicateTab || state.isNavigating || state.isUnloading) {
                return;
            }
            // Point 2: boot grace holds back false windowblur during initial render.
            if (isBootGrace() || isUnlockGrace()) {
                trace('skip-boot-blur');
                return;
            }
            // Point 3: Moodle moves the focus into the Essay editor (TinyMCE iframe or
            // Atto) on load and when the student clicks the answer box. Browsers still
            // fire a window blur for that internal focus move, so it must not count.
            if (isEditorFocusElement()) {
                trace('skip-editor-blur');
                cancelPendingViolation();
                return;
            }
            hideQuestions();
            if (document.visibilityState === 'visible') {
                scheduleViolation('windowblur');
            }
        });

        window.addEventListener('focus', function() {
            trace('focus');
            onUserActivityResetFocus();
            // Window regained focus: cancel pending blur violation
            if (pendingViolationType === 'windowblur') {
                cancelPendingViolation();
            }
            if (!state.locked && !state.isDuplicateTab
                    && (typeof document.hasFocus !== 'function' || document.hasFocus())) {
                restoreQuestions();
            }
        });

        window.addEventListener('storage', onStorage);
    }

    /**
     * Report a violation and lock the screen immediately.
     *
     * @param {string} type event type.
     */
    function reportViolation(type) {
        if (state.isDuplicateTab || state.isNavigating || state.isUnloading) {
            return;
        }
        // LOCK-PAUSE: while locked, ignore all violations except overlay tampering.
        if (state.locked && type !== 'overlayremoved') {
            return;
        }
        if (type !== 'overlayremoved' && isUnlockGrace()) {
            trace('blocked-unlock-grace:' + type);
            return;
        }

        // Never lock the screen or report during quiz navigation window or non-tabswitch boot grace.
        if (isNavigationWindow() || (type !== 'tabswitch' && isBootGrace())) {
            trace('blocked-report:' + type);
            return;
        }

        // Cooldown/throttle: 0ms if state.locked === false (first violation is always immediate).
        if (state.locked) {
            var ms = nowMs();
            var throttleMs = Math.max(4000, (settings.dedupewindow || 3) * 1000);
            if ((ms - state.lastviolationreport) < throttleMs) {
                return;
            }
            state.lastviolationreport = ms;
        } else {
            state.lastviolationreport = nowMs();
        }

        var wasLocked = state.locked;
        if (!wasLocked) {
            // Send AJAX first before state.locked turns true so sendAction's lock-pause gate
            // does not block this initial violation, then lock immediately.
            sendAction('violation', {eventtype: type, meta: navDiagnostics(type)}, function(error, data) {
                if (error || !data || data.status !== 'ok') {
                    queuePending(type);
                    startHeartbeat();
                    return;
                }
                state.provisional = false;
                applyServerState(data);
                if (data.remaining > 0) {
                    lockScreen(data.penaltyuntil, data.violationcount, false);
                } else if (state.locked) {
                    unlockScreen(data);
                }
                startHeartbeat();
            });
            lockScreen(serverNow() + (settings.penaltyseconds || 120),
                (state.violationcount || 0) + 1, true);
            return;
        }

        sendAction('violation', {eventtype: type, meta: navDiagnostics(type)}, function(error, data) {
            if (error || !data || data.status !== 'ok') {
                queuePending(type);
                return;
            }
            state.provisional = false;
            applyServerState(data);
            if (data.remaining > 0) {
                lockScreen(data.penaltyuntil, data.violationcount, false);
            }
            startHeartbeat();
        });
    }

    /**
     * Create the full viewport white penalty overlay.
     */
    function createOverlay() {
        if (document.getElementById(OVERLAY_ID)) {
            return;
        }
        state.internalmutation = true;

        var overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.style.cssText = OVERLAY_STYLE;

        var box = document.createElement('div');
        box.className = 'examguard-penalty-box';
        box.style.cssText = 'max-width:640px;width:100%;text-align:center;'
            + 'font-family:Arial,Helvetica,sans-serif;color:#1d2125;';

        var title = document.createElement('h2');
        title.style.cssText = 'font-size:24px;margin:0 0 12px;color:#b02a37;';
        title.textContent = str('penaltyheading');
        box.appendChild(title);

        var body = document.createElement('p');
        body.style.cssText = 'font-size:16px;line-height:1.5;margin:0 0 16px;';
        body.textContent = str('penaltybody');
        box.appendChild(body);

        var count = document.createElement('p');
        count.id = 'examguard-penalty-count';
        count.style.cssText = 'font-size:15px;font-weight:bold;margin:0 0 8px;';
        box.appendChild(count);

        var label = document.createElement('div');
        label.style.cssText = 'font-size:13px;text-transform:uppercase;'
            + 'letter-spacing:1px;color:#5c6770;';
        label.textContent = str('penaltyremaining');
        box.appendChild(label);

        var clock = document.createElement('div');
        clock.id = 'examguard-countdown';
        clock.style.cssText = 'font-size:56px;font-weight:bold;'
            + 'font-variant-numeric:tabular-nums;letter-spacing:2px;'
            + 'margin:4px 0 16px;color:#0f6cbf;';
        clock.textContent = formatClock(remaining());
        box.appendChild(clock);

        var identity = document.createElement('p');
        identity.id = 'examguard-penalty-identity';
        identity.style.cssText = 'font-size:13px;color:#5c6770;margin:0 0 12px;';
        box.appendChild(identity);

        var notice = document.createElement('p');
        notice.id = 'examguard-penalty-notice';
        notice.style.cssText = 'font-size:13px;color:#842029;background:#f8d7da;'
            + 'border:1px solid #f5c2c7;border-radius:6px;padding:10px 12px;margin:0;';
        notice.textContent = str('penaltynotice');
        box.appendChild(notice);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        observeOverlayElement();
        state.internalmutation = false;
        updateOverlay();
    }

    /**
     * Refresh the countdown and the violation counter.
     */
    function updateOverlay() {
        var clock = document.getElementById('examguard-countdown');
        var left = remaining();
        if (clock) {
            clock.textContent = formatClock(left);
        }

        var count = document.getElementById('examguard-penalty-count');
        if (count) {
            count.textContent = format(str('penaltycount'), state.violationcount || 1);
        }

        var identity = document.getElementById('examguard-penalty-identity');
        if (identity) {
            identity.textContent = str('penaltyidentity') + ' ' + formatTimestamp(serverNow());
        }

        if (left <= 0 && !state.heartbeatsinflight) {
            requestHeartbeat();
        }
    }

    /**
     * Lock the page with the white overlay.
     *
     * @param {number} until authoritative penalty end timestamp.
     * @param {number} count violation count.
     * @param {boolean} provisional true when the server has not confirmed yet.
     */
    function lockScreen(until, count, provisional) {
        hideQuestions();
        state.locked = true;
        state.provisional = !!provisional;
        if (until) {
            state.penaltyuntil = until;
        }
        if (count) {
            state.violationcount = count;
        }

        var root = document.documentElement;
        if ((' ' + root.className + ' ').indexOf(' examguard-lock ') === -1) {
            root.className = (root.className ? root.className + ' ' : '') + 'examguard-lock';
        }

        createOverlay();
        updateOverlay();
        stopQuizTimer();
        mirrorState();

        if (state.countdowntimer) {
            window.clearInterval(state.countdowntimer);
        }
        state.countdowntimer = window.setInterval(updateOverlay, 250);

        if (!provisional) {
            startHeartbeat();
        }
    }

    /**
     * Remove the overlay once the server confirmed the penalty is over.
     *
     * @param {Object} data heartbeat response.
     */
    function unlockScreen(data) {
        if (!state.locked) {
            return;
        }
        state.locked = false;
        state.provisional = false;
        state.penaltyuntil = 0;
        lostFocusConsecutiveCount = 0;
        state.focusViolationFired = false;
        state.unlockGraceUntil = nowMs() + UNLOCK_GRACE_MS;

        if (state.countdowntimer) {
            window.clearInterval(state.countdowntimer);
            state.countdowntimer = null;
        }
        stopHeartbeat();

        state.internalmutation = true;
        var overlay = document.getElementById(OVERLAY_ID);
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
        var root = document.documentElement;
        root.className = root.className.replace(/(^|\s)examguard-lock(\s|$)/g, ' ');
        state.internalmutation = false;

        clearStorage();
        resumeQuizTimer(data);
        if (!state.isDuplicateTab) {
            restoreQuestions(true);
        }
    }

    /**
     * Ask the server for the authoritative remaining time.
     */
    function heartbeat() {
        if (state.heartbeatsinflight || state.isDuplicateTab) {
            return;
        }
        state.heartbeatsinflight = true;

        sendAction('heartbeat', {}, function(error, data) {
            state.heartbeatsinflight = false;
            if (error || !data || data.status !== 'ok' || state.isDuplicateTab) {
                return;
            }

            applyServerState(data);

            if (data.remaining > 0) {
                if (!state.locked) {
                    lockScreen(data.penaltyuntil, data.violationcount, false);
                } else {
                    updateOverlay();
                }
                startHeartbeat();
            } else if (state.locked) {
                unlockScreen(data);
            } else {
                stopHeartbeat();
            }
        });
    }

    /**
     * Throttled heartbeat used by the countdown and the watchdog.
     */
    function requestHeartbeat() {
        if (state.isDuplicateTab) {
            return;
        }
        var ms = nowMs();
        if ((ms - state.lastheartbeat) < 5000) {
            return;
        }
        state.lastheartbeat = ms;
        heartbeat();
    }

    /**
     * Start the periodic heartbeat (every 6 seconds) used while a penalty is active.
     */
    function startHeartbeat() {
        if (state.heartbeattimer || state.isDuplicateTab) {
            return;
        }
        state.heartbeattimer = window.setInterval(function() {
            if (state.locked && !state.isDuplicateTab) {
                heartbeat();
            } else {
                stopHeartbeat();
            }
        }, HEARTBEAT_INTERVAL_MS);
    }

    /**
     * Stop the periodic heartbeat.
     */
    function stopHeartbeat() {
        if (state.heartbeattimer) {
            window.clearInterval(state.heartbeattimer);
            state.heartbeattimer = null;
        }
    }

    /**
     * Pause the Moodle quiz countdown while the penalty is displayed.
     */
    function stopQuizTimer() {
        if (!settings.freezetimer || !(cfg.timelimit > 0)) {
            return;
        }
        if (!window.M || !M.mod_quiz || !M.mod_quiz.timer) {
            return;
        }
        if (M.mod_quiz.timer.Y === null || typeof M.mod_quiz.timer.stop !== 'function') {
            return;
        }
        if (state.timerfrozen) {
            return;
        }
        M.mod_quiz.timer.stop(null);
        state.timerfrozen = true;
    }

    /**
     * Resume the Moodle quiz countdown using the server side remaining time.
     *
     * @param {Object} data heartbeat response.
     */
    function resumeQuizTimer(data) {
        if (!state.timerfrozen) {
            return;
        }
        if (data && typeof data.timeleft === 'number'
                && window.M && M.mod_quiz && M.mod_quiz.timer && M.mod_quiz.timer.Y !== null) {
            M.mod_quiz.timer.endtime = nowMs() + (data.timeleft * 1000);
            M.mod_quiz.timer.update();
            state.timerfrozen = false;
            return;
        }
        // Without a trustworthy value the page is reloaded so PHP recalculates the timer.
        window.location.reload();
    }

    /**
     * Watch for DOM changes: new question content and overlay tampering.
     */
    function setupMutationObserver() {
        if (!window.MutationObserver || !document.body) {
            return;
        }

        var contentobserver = new MutationObserver(function() {
            if (state.contenttimer) {
                return;
            }
            state.contenttimer = window.setTimeout(function() {
                state.contenttimer = null;
                if (!questionsHidden) {
                    setupTraps();
                }
                attachResponseFormListeners();
                attachEditorIframeListeners();
            }, 600);
        });
        contentobserver.observe(document.body, {childList: true, subtree: true});

        state.overlayobserver = new MutationObserver(function() {
            if (state.internalmutation || !state.locked || state.isDuplicateTab) {
                return;
            }
            var overlay = document.getElementById(OVERLAY_ID);
            if (!overlay) {
                onTamper();
                return;
            }
            enforceOverlayStyle(overlay);
        });
        state.overlayobserver.observe(document.body, {childList: true});
        observeOverlayElement();
    }

    /**
     * Also watch the overlay attributes so it cannot be hidden with DevTools.
     */
    function observeOverlayElement() {
        var overlay = document.getElementById(OVERLAY_ID);
        if (!overlay || !state.overlayobserver) {
            return;
        }
        state.overlayobserver.observe(overlay, {
            attributes: true,
            attributeFilter: ['style', 'class', 'id']
        });
    }

    /**
     * Restore the overlay style when it was altered.
     *
     * @param {Element} overlay overlay element.
     */
    function enforceOverlayStyle(overlay) {
        var style = window.getComputedStyle ? window.getComputedStyle(overlay) : null;
        var broken = overlay.style.display === 'none'
            || overlay.style.visibility === 'hidden'
            || overlay.style.opacity === '0'
            || overlay.style.zIndex !== '999999';

        if (!broken && style) {
            broken = style.display === 'none'
                || style.visibility === 'hidden'
                || parseFloat(style.opacity) < 0.9;
        }

        if (broken) {
            overlay.style.cssText = OVERLAY_STYLE;
        }
    }

    /**
     * Handle a removed or altered overlay: restore it and extend the penalty.
     */
    function onTamper() {
        if (!state.locked || state.isDuplicateTab) {
            return;
        }

        createOverlay();
        updateOverlay();

        var ms = nowMs();
        if (ms - state.lasttamper < 5000) {
            return;
        }
        state.lasttamper = ms;

        sendAction('violation', {eventtype: 'overlayremoved', meta: navDiagnostics('overlayremoved')}, function(error, data) {
            if (error || !data || data.status !== 'ok') {
                return;
            }
            applyServerState(data);
            lockScreen(data.penaltyuntil, data.violationcount, false);
            var notice = document.getElementById('examguard-penalty-notice');
            if (notice) {
                notice.textContent = str('penaltytamper');
            }
            startHeartbeat();
        });
    }

    /**
     * Watchdog: last line of defence against overlay removal.
     */
    function startWatchdog() {
        if (state.watchdogtimer) {
            return;
        }
        state.watchdogtimer = window.setInterval(function() {
            if (state.isDuplicateTab) {
                if (!document.getElementById(DUPLICATE_OVERLAY_ID)) {
                    createDuplicateTabOverlay();
                }
                return;
            }
            if (!state.locked) {
                return;
            }
            var overlay = document.getElementById(OVERLAY_ID);
            if (!overlay) {
                createOverlay();
                updateOverlay();
                return;
            }
            enforceOverlayStyle(overlay);
            if (remaining() <= 0 && !state.heartbeatsinflight) {
                requestHeartbeat();
            }
        }, 1000);
    }

    /**
     * Start everything once the DOM is ready.
     */
    function boot() {
        if (!document.body) {
            window.setTimeout(boot, 50);
            return;
        }

        // Tell the head lock bootstrap that the guard is alive.
        window.__examguardReady = true;
        if (window.__examguardFailsafe) {
            window.clearTimeout(window.__examguardFailsafe);
        }

        // Point 2: boot grace holds back false windowblur during initial render (800ms).
        state.bootUntil = nowMs() + BOOT_GRACE_MS;
        releaseNavigation();
        cancelPendingViolation();

        // Immediately remove navigating marker so immunity doesn't persist.
        try {
            window.sessionStorage.removeItem(NAV_MARKER_KEY);
        } catch (e) {
            // Storage can be disabled, ignore.
        }

        trace('boot');

        document.body.className += (document.body.className ? ' ' : '') + 'examguard-active';

        setupBlockers();
        setupTraps();
        captureQuestions();
        setupWatermark();
        attachResponseFormListeners();
        attachEditorIframeListeners();
        setupAfkDetector();
        startFocusWatchdog();
        setupMutationObserver();
        startWatchdog();

        // Initialise Single-Tab Guard before locking or replaying pending state.
        var isOwnerTab = initSingleTabGuard();
        if (!isOwnerTab) {
            // Remove head anti-flash lock class so the non-penalty duplicate tab overlay is visible.
            var dupRoot = document.documentElement;
            dupRoot.className = dupRoot.className.replace(/(^|\s)examguard-lock(\s|$)/g, ' ');
            return;
        }

        var serverRemaining = (typeof cfg.remaining === 'number')
            ? cfg.remaining
            : Math.max(0, (cfg.penaltyuntil || 0) - serverNow());

        // The backend (cfg comes from PHP on every page load) is the ONLY source of truth.
        if (serverRemaining <= 0 || !cfg.penaltyuntil || cfg.penaltyuntil <= serverNow()) {
            // No penalty on the server: wipe every local trace and never replay the queue.
            state.penaltyuntil = 0;
            state.locked = false;
            clearStorage();
        } else {
            lockScreen(cfg.penaltyuntil, cfg.violationcount || 1, false);
            reportPassive('pageload');

            // The Moodle timer may still be initialising, retry until it is paused.
            var attempts = 0;
            var timerretry = window.setInterval(function() {
                attempts++;
                stopQuizTimer();
                if (state.timerfrozen || attempts > 20) {
                    window.clearInterval(timerretry);
                }
            }, 500);

            flushPending();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
