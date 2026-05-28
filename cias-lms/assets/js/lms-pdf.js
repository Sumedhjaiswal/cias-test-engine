/**
 * lms-pdf.js
 * CIAS LMS — Canvas-based PDF viewer (PDF.js)
 *
 * Security:
 *  - PDF rendered to Canvas — no native PDF viewer, no browser print dialog
 *  - Ctrl+P / Cmd+P blocked by lms-security.js
 *  - Download button absent — no anchor, no blob URL exposed
 *  - Signed R2 URL is fetched and used for PDF.js only — never set as href
 *  - Watermark overlaid on every page canvas
 */

( function () {
    'use strict';

    const api = window.CIAS_LMS;
    if ( ! api ) return;

    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    const WORKER    = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    let pdfDoc      = null;
    let currentPage = 1;
    let totalPages  = 0;
    let lessonId    = null;
    let watermarkText = '';

    // ── Load a PDF lesson ─────────────────────────────────────────────────────

    async function loadLesson( lid, studentName, studentPhone ) {
        lessonId      = lid;
        watermarkText = studentPhone
            ? `${ studentName } | ${ studentPhone.slice( 0, 5 ) }XXXXX`
            : studentName;

        const container = document.getElementById( 'cias-lms-pdf-viewer' );
        if ( ! container ) return;

        container.innerHTML = '<div class="cias-lms-loading">Loading document…</div>';

        try {
            // 1. Get signed URL from server
            const res = await fetch( `${ api.apiBase }/pdf-token`, {
                method  : 'POST',
                headers : {
                    'Content-Type' : 'application/json',
                    'X-WP-Nonce'  : api.nonce,
                },
                body : JSON.stringify( { lesson_id: lid } ),
            } );

            const data = await res.json();
            if ( ! data.success ) throw new Error( data.message || 'PDF token error' );

            const { signed_url } = data.data;

            // Activate security layer
            if ( window.CIASLMSSecurity ) {
                window.CIASLMSSecurity.init( lid, null, data.data.watermark_name, data.data.watermark_phone );
            }

            // 2. Load PDF.js if not already loaded
            await loadPDFJS();

            // 3. Fetch PDF via PDF.js (never creates a download link)
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER;

            const loadingTask = window.pdfjsLib.getDocument( { url: signed_url, disableRange: false } );
            pdfDoc     = await loadingTask.promise;
            totalPages = pdfDoc.numPages;

            container.innerHTML = '';
            renderControls( container );
            renderPage( container, 1 );

        } catch ( err ) {
            container.innerHTML = `<div class="cias-lms-error">Failed to load document: ${ err.message }</div>`;
        }
    }

    // ── Render a single page to Canvas ───────────────────────────────────────

    async function renderPage( container, pageNum ) {
        currentPage = pageNum;

        const canvasWrapper = document.getElementById( 'cias-pdf-canvas-wrapper' );
        if ( ! canvasWrapper ) return;
        canvasWrapper.innerHTML = '';

        const page     = await pdfDoc.getPage( pageNum );
        const viewport = page.getViewport( { scale: 1.5 } );

        const canvas    = document.createElement( 'canvas' );
        const ctx       = canvas.getContext( '2d' );
        canvas.width    = viewport.width;
        canvas.height   = viewport.height;
        canvas.style.width  = '100%';
        canvas.style.display = 'block';

        canvasWrapper.appendChild( canvas );

        await page.render( { canvasContext: ctx, viewport } ).promise;

        // Watermark on every page
        drawWatermark( ctx, canvas.width, canvas.height );

        // Update page counter
        const counter = document.getElementById( 'cias-pdf-page-info' );
        if ( counter ) counter.textContent = `Page ${ pageNum } of ${ totalPages }`;

        const prevBtn = document.getElementById( 'cias-pdf-prev' );
        const nextBtn = document.getElementById( 'cias-pdf-next' );
        if ( prevBtn ) prevBtn.disabled = pageNum <= 1;
        if ( nextBtn ) nextBtn.disabled = pageNum >= totalPages;
    }

    // ── Draw watermark on canvas ──────────────────────────────────────────────

    function drawWatermark( ctx, w, h ) {
        ctx.save();
        ctx.font         = '16px monospace';
        ctx.fillStyle    = 'rgba(100, 100, 100, 0.25)';
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';
        ctx.translate( w / 2, h / 2 );
        ctx.rotate( -Math.PI / 6 );

        // Tile the watermark
        const step = 180;
        for ( let y = -h; y < h; y += step ) {
            for ( let x = -w; x < w; x += step ) {
                ctx.fillText( watermarkText, x, y );
            }
        }
        ctx.restore();
    }

    // ── Controls (prev/next/page) ─────────────────────────────────────────────

    function renderControls( container ) {
        const controls = document.createElement( 'div' );
        controls.className = 'cias-pdf-controls';
        controls.style.cssText = 'display:flex;align-items:center;gap:12px;padding:8px 0;justify-content:center';

        const prev = document.createElement( 'button' );
        prev.id          = 'cias-pdf-prev';
        prev.textContent = '← Prev';
        prev.className   = 'cias-btn';
        prev.disabled    = true;
        prev.onclick     = () => {
            if ( currentPage > 1 ) renderPage( container, currentPage - 1 );
        };

        const info = document.createElement( 'span' );
        info.id            = 'cias-pdf-page-info';
        info.textContent   = `Page 1 of ${ totalPages }`;
        info.style.cssText = 'font-size:0.9rem;color:#888;min-width:120px;text-align:center';

        const next = document.createElement( 'button' );
        next.id          = 'cias-pdf-next';
        next.textContent = 'Next →';
        next.className   = 'cias-btn';
        next.onclick     = () => {
            if ( currentPage < totalPages ) renderPage( container, currentPage + 1 );
        };

        controls.append( prev, info, next );

        const canvasWrapper = document.createElement( 'div' );
        canvasWrapper.id            = 'cias-pdf-canvas-wrapper';
        canvasWrapper.style.cssText = 'background:#525659;padding:8px;border-radius:4px;';

        container.append( controls, canvasWrapper );
    }

    // ── Load PDF.js library dynamically ──────────────────────────────────────

    function loadPDFJS() {
        return new Promise( ( resolve, reject ) => {
            if ( window.pdfjsLib ) return resolve();
            const script    = document.createElement( 'script' );
            script.src      = PDFJS_CDN;
            script.onload   = resolve;
            script.onerror  = () => reject( new Error( 'Failed to load PDF.js' ) );
            document.head.appendChild( script );
        } );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    window.CIASLMSPdf = { loadLesson };

} )();
