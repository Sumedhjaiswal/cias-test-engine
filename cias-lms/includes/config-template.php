<?php
/**
 * CIAS LMS — Environment Configuration
 * Add these constants to wp-config.php (NEVER commit actual values)
 *
 * Required for full functionality.
 */

// ── Vimeo ─────────────────────────────────────────────────────────────────────
// define( 'CIAS_VIMEO_ACCESS_TOKEN',  'your_vimeo_personal_access_token' );
// define( 'CIAS_VIMEO_DOMAIN_LOCK',   'yourdomain.com' ); // Domain-lock Vimeo embeds to this domain

// ── Cloudflare R2 (for PDFs) ──────────────────────────────────────────────────
// define( 'CIAS_R2_ACCOUNT_ID',  'your_cloudflare_account_id' );
// define( 'CIAS_R2_BUCKET',      'cias-pdfs' );
// define( 'CIAS_R2_ACCESS_KEY',  'your_r2_access_key' );
// define( 'CIAS_R2_SECRET_KEY',  'your_r2_secret_key' );

// ── AiSensy (WhatsApp — direct REST, NOT via Composio) ───────────────────────
// define( 'CIAS_AISENSY_API_KEY', 'your_aisensy_api_key' );

// ── Composio (Zoom, Google Calendar — peripheral integrations only) ───────────
// define( 'CIAS_COMPOSIO_API_KEY', 'your_composio_api_key' );

// ── Redis (shared with CIAS core) ─────────────────────────────────────────────
// Already defined in CIAS core: CIAS_REDIS_HOST, CIAS_REDIS_PORT
