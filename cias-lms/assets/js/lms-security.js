/**
 * lms-security.js
 * CIAS LMS — Maximum content protection layer
 *
 * Protections:
 *  1. Dynamic floating watermark (student name + phone, randomized position)
 *  2. Screen recording detection via visibilitychange + blur
 *  3. Right-click disabled on content
 *  4. Keyboard shortcuts blocked (PrtSc, Cmd+Shift+3/4, Ctrl+P, F12, Ctrl+Shift+I)
 *  5. DevTools open detection (size + debugger timing)
 *  6. Focus loss tracking (window.onblur)
 *  7. All events reported to server for session logging
 *  8. On too many events — video pauses and overlay shown
 */

( function () {
    'use strict';

    const api = window.CIAS_LMS;
    if ( ! api ) return;

    let currentLessonId = null;
    let sessionId       = null;
    let videoIframe     = null;
    let warningCount    = 0;
    const MAX_WARNINGS  = 3;

    // ── Init ──────────────────────────────────────────────────────────────────

    function init( lessonId, sid, studentName, studentPhone ) {
        currentLessonId = lessonId;
        sessionId       = sid;

        injectWatermark( studentName, studentPhone );
        bindVisibilityChange();
        bindKeyboardBlock();
        bindRightClickBlock();
        bindFocusTracking();
        startDevToolsDetection();
        disableTextSelection();
    }

    // ── Watermark ─────────────────────────────────────────────────────────────

    function injectWatermark( name, phone ) {
        const el = document.createElement( 'div' );
        el.id    = 'cias-lms-watermark';

        const display = phone
            ? `${ name } | ${ phone.slice( 0, 5 ) }XXXXX`
            : name;

        el.textContent = display;

        Object.assign( el.style, {
            position         : 'fixed',
            zIndex           : '2147483647',
            pointerEvents    : 'none',
            userSelect       : 'none',
            color            : 'rgba(255,255,255,0.18)',
            fontSize         : '14px',
            fontFamily       : 'monospace',
            fontWeight       : '600',
            letterSpacing    : '0.05em',
            whiteSpace       : 'nowrap',
            textShadow       : '0 0 4px rgba(0,0,0,0.5)',
            transform        : 'rotate(-25deg)',
            willChange       : 'top, left',
            transition       : 'top 8s ease-in-out, left 8s ease-in-out',
        } );

        document.body.appendChild( el );
        positionWatermark( el );
        setInterval( () => positionWatermark( el ), 8000 );

        // Add a second subtle instance at opposite corner
        const el2    = el.cloneNode( true );
        el2.id       = 'cias-lms-watermark-2';
        el2.style.color = 'rgba(0,0,0,0.08)';
        document.body.appendChild( el2 );
        positionWatermark( el2 );
        setInterval( () => positionWatermark( el2 ), 11000 );
    }

    function positionWatermark( el ) {
        const maxX = Math.max( 0, window.innerWidth  - 300 );
        const maxY = Math.max( 0, window.innerHeight - 60  );
        el.style.left = Math.floor( Math.random() * maxX ) + 'px';
        el.style.top  = Math.floor( Math.random() * maxY ) + 'px';
    }

    // ── Visibility change (screen recorder detection) ─────────────────────────

    function bindVisibilityChange() {
        document.addEventListener( 'visibilitychange', () => {
            if ( document.visibilityState === 'hidden' ) {
                pauseContent();
                reportEvent( 'visibility_hidden', { at: Date.now() } );
                triggerWarning( 'Content paused. Recording or tab switching detected.' );
            }
        } );
    }

    // ── Keyboard shortcuts ─────────────────────────────────────────────────────

    const BLOCKED_KEYS = new Set( [
        'PrintScreen', 'F12',
    ] );

    const BLOCKED_COMBOS = [
        e => e.ctrlKey  && e.shiftKey && e.key === 'I',  // DevTools
        e => e.ctrlKey  && e.shiftKey && e.key === 'J',  // Console
        e => e.ctrlKey  && e.shiftKey && e.key === 'C',  // Inspector
        e => e.ctrlKey  && e.key      === 'p',           // Print
        e => e.ctrlKey  && e.key      === 'u',           // View source
        e => e.ctrlKey  && e.key      === 's',           // Save
        e => e.metaKey  && e.shiftKey && e.key === '3',  // Mac screenshot
        e => e.metaKey  && e.shiftKey && e.key === '4',  // Mac screenshot
        e => e.metaKey  && e.shiftKey && e.key === '5',  // Mac screen record
        e => e.metaKey  && e.key      === 'p',           // Mac print
    ];

    function bindKeyboardBlock() {
        document.addEventListener( 'keydown', ( e ) => {
            const blocked = BLOCKED_KEYS.has( e.key ) || BLOCKED_COMBOS.some( fn => fn( e ) );
            if ( blocked ) {
                e.preventDefault();
                e.stopPropagation();
                reportEvent( 'keyboard_shortcut', { key: e.key, ctrl: e.ctrlKey, meta: e.metaKey } );
                triggerWarning( 'Screenshot / DevTools shortcut blocked.' );
            }
        }, true );
    }

    // ── Right click ───────────────────────────────────────────────────────────

    function bindRightClickBlock() {
        document.addEventListener( 'contextmenu', ( e ) => {
            const target = e.target.closest( '#cias-lms-player, #cias-lms-pdf-viewer, .cias-lms-content' );
            if ( target ) {
                e.preventDefault();
                reportEvent( 'right_click', { x: e.clientX, y: e.clientY } );
            }
        } );
    }

    // ── Focus loss ────────────────────────────────────────────────────────────

    let focusLossTimer = null;

    function bindFocusTracking() {
        window.addEventListener( 'blur', () => {
            focusLossTimer = setTimeout( () => {
                reportEvent( 'focus_lost', { at: Date.now() } );
            }, 2000 ); // only log if unfocused for >2s
        } );
        window.addEventListener( 'focus', () => {
            if ( focusLossTimer ) clearTimeout( focusLossTimer );
        } );
    }

    // ── DevTools detection ────────────────────────────────────────────────────

    function startDevToolsDetection() {
        // Method 1: Window size delta (DevTools panel reduces inner dimensions)
        const threshold = 160;
        setInterval( () => {
            if (
                window.outerWidth  - window.innerWidth  > threshold ||
                window.outerHeight - window.innerHeight > threshold
            ) {
                onDevToolsDetected();
            }
        }, 2000 );

        // Method 2: debugger timing — DevTools pauses execution when open
        const interval = 500;
        let lastTime   = Date.now();
        setInterval( () => {
            const now   = Date.now();
            const delta = now - lastTime;
            lastTime    = now;
            if ( delta > interval * 4 ) {
                onDevToolsDetected();
            }
        }, interval );
    }

    let devToolsAlerted = false;
    function onDevToolsDetected() {
        if ( devToolsAlerted ) return;
        devToolsAlerted = true;
        pauseContent();
        reportEvent( 'devtools_open', { ua: navigator.userAgent } );
        triggerWarning( 'Developer tools detected. Content paused.' );
        setTimeout( () => { devToolsAlerted = false; }, 30000 ); // re-arm after 30s
    }

    // ── Text selection ────────────────────────────────────────────────────────

    function disableTextSelection() {
        const contentEl = document.querySelector( '.cias-lms-content' );
        if ( ! contentEl ) return;
        Object.assign( contentEl.style, {
            userSelect       : 'none',
            webkitUserSelect : 'none',
        } );
    }

    // ── Content pause ─────────────────────────────────────────────────────────

    function pauseContent() {
        // Pause Vimeo player via postMessage
        const iframe = document.getElementById( 'cias-vimeo-player' );
        if ( iframe ) {
            iframe.contentWindow.postMessage( '{"method":"pause"}', 'https://player.vimeo.com' );
        }
    }

    // ── Warning overlay ───────────────────────────────────────────────────────

    function triggerWarning( message ) {
        warningCount++;

        let overlay = document.getElementById( 'cias-lms-warning' );
        if ( ! overlay ) {
            overlay = document.createElement( 'div' );
            overlay.id = 'cias-lms-warning';
            Object.assign( overlay.style, {
                position        : 'fixed',
                inset           : '0',
                zIndex          : '2147483646',
                background      : 'rgba(10,10,10,0.92)',
                display         : 'flex',
                flexDirection   : 'column',
                alignItems      : 'center',
                justifyContent  : 'center',
                color           : '#fff',
                fontFamily      : 'sans-serif',
                textAlign       : 'center',
                padding         : '2rem',
            } );
            document.body.appendChild( overlay );
        }

        overlay.innerHTML = '';

        const icon = document.createElement( 'div' );
        icon.textContent = '⚠️';
        icon.style.fontSize = '3rem';

        const title = document.createElement( 'h2' );
        title.textContent = 'Security Alert';
        title.style.cssText = 'margin:1rem 0 0.5rem;font-size:1.4rem;color:#f87171';

        const msg = document.createElement( 'p' );
        msg.textContent = message;
        msg.style.cssText = 'color:#ccc;margin-bottom:1.5rem;max-width:360px';

        const count = document.createElement( 'p' );
        count.textContent = `Warning ${ warningCount } of ${ MAX_WARNINGS }`;
        count.style.cssText = 'font-size:0.85rem;color:#888;margin-bottom:1.5rem';

        overlay.append( icon, title, msg, count );

        if ( warningCount >= MAX_WARNINGS ) {
            const lockMsg = document.createElement( 'p' );
            lockMsg.textContent = 'This session has been locked. Contact support.';
            lockMsg.style.cssText = 'color:#f87171;font-weight:600';
            overlay.appendChild( lockMsg );
        } else {
            const btn = document.createElement( 'button' );
            btn.textContent = 'I understand — continue';
            Object.assign( btn.style, {
                padding         : '0.6rem 1.4rem',
                background      : '#1d4ed8',
                color           : '#fff',
                border          : 'none',
                borderRadius    : '6px',
                cursor          : 'pointer',
                fontSize        : '0.95rem',
            } );
            btn.onclick = () => { overlay.remove(); };
            overlay.appendChild( btn );
        }
    }

    // ── Event reporting ───────────────────────────────────────────────────────

    async function reportEvent( eventType, metadata ) {
        if ( ! currentLessonId || ! api ) return;
        try {
            await fetch( `${ api.apiBase }/security-event`, {
                method  : 'POST',
                headers : {
                    'Content-Type'     : 'application/json',
                    'X-WP-Nonce'       : api.nonce,
                },
                body    : JSON.stringify( {
                    lesson_id  : currentLessonId,
                    event_type : eventType,
                    metadata,
                } ),
            } );
        } catch ( _ ) {
            // Silently fail — don't break the UI on network error
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    window.CIASLMSSecurity = { init };

} )();
