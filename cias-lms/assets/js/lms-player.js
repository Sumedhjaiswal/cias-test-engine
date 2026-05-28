/**
 * lms-player.js
 * CIAS LMS — Video player module
 *
 * Flow:
 *  1. Request a signed token from server (POST /video-token)
 *  2. Server validates enrollment, creates session, returns token + watermark info
 *  3. Client uses token to request the Vimeo embed URL (POST /video-redeem)
 *  4. Embed iframe loads — Vimeo domain restriction + private video ensures no direct access
 *  5. Security layer is activated (watermark + event monitoring)
 *  6. Progress reported to server every 30s
 */

( function () {
    'use strict';

    const api = window.CIAS_LMS;
    if ( ! api ) return;

    let progressTimer   = null;
    let watchSeconds    = 0;
    let lessonId        = null;
    let player          = null;

    // ── Load a video lesson ───────────────────────────────────────────────────

    async function loadLesson( lid, initialWatchSecs ) {
        lessonId    = lid;
        watchSeconds = initialWatchSecs || 0;

        const container = document.getElementById( 'cias-lms-player' );
        if ( ! container ) return;

        container.innerHTML = '<div class="cias-lms-loading">Loading video…</div>';

        try {
            const tokenRes = await fetch( `${ api.apiBase }/video-token`, {
                method  : 'POST',
                headers : {
                    'Content-Type' : 'application/json',
                    'X-WP-Nonce'  : api.nonce,
                },
                body : JSON.stringify( { lesson_id: lid } ),
            } );

            const tokenData = await tokenRes.json();
            if ( ! tokenData.success ) throw new Error( tokenData.message || 'Token error' );

            const { token, session_id, watermark_name, watermark_phone } = tokenData.data;

            // Request the embed URL (redeems token server-side)
            const embedRes = await fetch( `${ api.apiBase }/video-embed`, {
                method  : 'POST',
                headers : {
                    'Content-Type' : 'application/json',
                    'X-WP-Nonce'  : api.nonce,
                },
                body : JSON.stringify( { token } ),
            } );

            const embedData = await embedRes.json();
            if ( ! embedData.success ) throw new Error( embedData.message || 'Embed error' );

            renderPlayer( container, embedData.data.embed_url, session_id );

            // Activate security layer
            if ( window.CIASLMSSecurity ) {
                window.CIASLMSSecurity.init( lid, session_id, watermark_name, watermark_phone );
            }

            startProgressTracking();

        } catch ( err ) {
            container.innerHTML = `<div class="cias-lms-error">Failed to load video: ${ err.message }</div>`;
        }
    }

    // ── Render Vimeo iframe ───────────────────────────────────────────────────

    function renderPlayer( container, embedUrl, sessionId ) {
        container.innerHTML = '';

        // Wrapper enforces 16:9 ratio
        const wrapper = document.createElement( 'div' );
        Object.assign( wrapper.style, {
            position      : 'relative',
            paddingBottom : '56.25%',
            height        : '0',
            overflow      : 'hidden',
            background    : '#000',
            borderRadius  : '8px',
        } );

        const iframe = document.createElement( 'iframe' );
        iframe.id              = 'cias-vimeo-player';
        iframe.src             = embedUrl;
        iframe.allow           = 'autoplay; fullscreen';
        iframe.allowFullscreen = true;

        // Block all dangerous permissions
        iframe.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-fullscreen allow-presentation' );
        iframe.setAttribute( 'referrerpolicy', 'no-referrer' );

        Object.assign( iframe.style, {
            position : 'absolute',
            inset    : '0',
            width    : '100%',
            height   : '100%',
            border   : 'none',
        } );

        // Transparent overlay to block right-click / drag on iframe
        const blocker = document.createElement( 'div' );
        Object.assign( blocker.style, {
            position      : 'absolute',
            inset         : '0',
            zIndex        : '1',
            background    : 'transparent',
            pointerEvents : 'none', // allow clicks through to controls but block context menu via parent handler
        } );
        blocker.addEventListener( 'contextmenu', e => e.preventDefault() );

        wrapper.append( iframe, blocker );
        container.appendChild( wrapper );

        // Listen for Vimeo timeupdate via postMessage
        window.addEventListener( 'message', ( event ) => {
            if ( event.origin !== 'https://player.vimeo.com' ) return;
            try {
                const data = JSON.parse( event.data );
                if ( data.event === 'playProgress' || data.event === 'timeupdate' ) {
                    watchSeconds = Math.floor( data.data?.seconds ?? data.data?.currentTime ?? 0 );
                }
                if ( data.event === 'finish' ) {
                    saveProgress( true );
                }
            } catch ( _ ) {}
        } );

        // Enable Vimeo JS API
        iframe.contentWindow?.postMessage( '{"method":"addEventListener","value":"playProgress"}', 'https://player.vimeo.com' );
        iframe.contentWindow?.postMessage( '{"method":"addEventListener","value":"finish"}', 'https://player.vimeo.com' );
    }

    // ── Progress tracking ─────────────────────────────────────────────────────

    function startProgressTracking() {
        if ( progressTimer ) clearInterval( progressTimer );
        progressTimer = setInterval( () => saveProgress( false ), 30_000 );
    }

    async function saveProgress( completed ) {
        if ( ! lessonId ) return;
        try {
            await fetch( `${ api.apiBase }/progress`, {
                method  : 'POST',
                headers : {
                    'Content-Type' : 'application/json',
                    'X-WP-Nonce'  : api.nonce,
                },
                body : JSON.stringify( {
                    lesson_id   : lessonId,
                    watch_secs  : watchSeconds,
                    completed   : completed,
                } ),
            } );
        } catch ( _ ) {}
    }

    // Save on page unload
    document.addEventListener( 'visibilitychange', () => {
        if ( document.visibilityState === 'hidden' ) saveProgress( false );
    } );
    window.addEventListener( 'beforeunload', () => saveProgress( false ) );

    // ── Public API ────────────────────────────────────────────────────────────

    window.CIASLMSPlayer = { loadLesson };

} )();
