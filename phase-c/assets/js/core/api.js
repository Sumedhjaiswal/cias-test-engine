/**
 * core/api.js — CIAS Centralized API Client
 *
 * Addresses all 7 frontend API architecture issues:
 *
 *   Issue 1 — Safe JSON response validation (safeJsonResponse)
 *   Issue 2 — Standardized response envelope (normalizeResponse)
 *   Issue 3 — REST nonce strictly separated from AJAX nonce
 *   Issue 4 — Auth expiry / 401 / 403 handling (handleAuthFailure)
 *   Issue 5 — REST client extracted into this dedicated module
 *   Issue 6 — Request timeout + AbortController (DEFAULT_TIMEOUT_MS)
 *   Issue 7 — Structured frontend error logging (logError)
 *
 * Public API — window.CIAS_API:
 *   init(config)                       Bootstrap with D (app data)
 *   restGet(path, cb)                  GET /wp-json/cias/v1/:path
 *   restPost(path, body, cb)           POST /wp-json/cias/v1/:path
 *   ajaxPost(action, data, cb)         POST /wp-admin/admin-ajax.php
 *
 * Architecture rules enforced:
 *   ✅ REST requests use ONLY rest_nonce  (wp_rest nonce)
 *   ✅ AJAX requests use ONLY nonce       (cias_app_nonce)
 *   ✅ No mixed fallback logic
 *   ✅ All requests have timeout + abort
 *   ✅ Auth failures handled centrally
 *   ✅ Safe JSON parsing — never crashes on HTML responses
 *   ✅ Structured error logging with module + context tagging
 *
 * @package CIAS\PhaseC\Core
 * @since   3.20.0
 */

window.CIAS_API = (function () {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════════════
     CONFIGURATION
  ══════════════════════════════════════════════════════════════════════════ */

  var DEFAULT_TIMEOUT_MS = 30000; // 30s — safe for AI responses on mobile
  var AJAX_TIMEOUT_MS    = 15000; // 15s — for standard AJAX operations

  var _config = {
    rest_url:   '/wp-json/cias/v1',
    ajax_url:   '/wp-admin/admin-ajax.php',
    rest_nonce: '',   // wp_rest nonce — for REST API ONLY
    nonce:      '',   // cias_app_nonce — for admin-ajax ONLY
  };

  /* ══════════════════════════════════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Bootstrap with app data from PHP.
   * Must be called before any API methods.
   *
   * @param {Object} D  The localized app data object (window.ciasApp)
   */
  function init(D) {
    _config.rest_url   = (D.rest_url   || '/wp-json/cias/v1').replace(/\/$/, '');
    _config.ajax_url   = D.ajax_url    || '/wp-admin/admin-ajax.php';
    _config.rest_nonce = D.rest_nonce  || '';
    _config.nonce      = D.nonce       || '';

    // Warn in dev if nonces are missing — helps catch bootstrap failures early
    if (!_config.rest_nonce) logError('init', 'rest_nonce missing from bootstrap data', 'warn');
    if (!_config.nonce)      logError('init', 'ajax nonce missing from bootstrap data', 'warn');
  }

  /* ══════════════════════════════════════════════════════════════════════════
     ISSUE 7 — STRUCTURED FRONTEND ERROR LOGGING
     logError(module, message, level, context)
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Centralized frontend error logger.
   * Tags every log with module source, level, and optional context.
   * Safe for production — never throws.
   * Ready for future telemetry integration.
   *
   * @param {string} module   e.g. 'restPost', 'ajaxPost', 'auth'
   * @param {string} message  Human-readable description
   * @param {string} level    'error' | 'warn' | 'info' (default: 'error')
   * @param {*}      context  Optional extra data (URL, response, etc.)
   */
  function logError(module, message, level, context) {
    level = level || 'error';
    var tag = '[CIAS:' + module + ']';

    try {
      if (level === 'warn'  && console.warn)  console.warn(tag,  message, context || '');
      if (level === 'info'  && console.info)  console.info(tag,  message, context || '');
      if (level === 'error' && console.error) console.error(tag, message, context || '');
    } catch (e) {
      // Silent — logging must never break the app
    }

    // Future telemetry hook — add your monitoring service here
    // e.g. window._cias_telemetry && window._cias_telemetry.capture(tag, message, context);
  }

  /* ══════════════════════════════════════════════════════════════════════════
     ISSUE 1 — SAFE JSON RESPONSE VALIDATION
     Prevents crashes when server returns HTML, Cloudflare errors, PHP fatals
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Safely parse a fetch Response.
   * Checks r.ok, handles non-JSON bodies, normalizes errors.
   *
   * @param  {Response} response  fetch() Response object
   * @param  {string}   context   For logging ('GET /guru/chat' etc.)
   * @return {Promise<Object>}    Normalized response object
   */
  function safeJsonResponse(response, context) {
    // Issue 4 — Auth failure detection
    if (response.status === 401 || response.status === 403) {
      handleAuthFailure(response.status, context);
      return Promise.resolve(_normalizeError(
        response.status === 401 ? 'auth_expired' : 'forbidden',
        response.status === 401 ? 'Your session has expired. Please log in again.' : 'Access denied.',
        response.status
      ));
    }

    // Issue 1 — Check r.ok before parsing
    if (!response.ok) {
      logError('safeJsonResponse', 'HTTP ' + response.status, 'warn', context);
      return response.text().then(function (body) {
        // Try to parse as JSON anyway — WP sometimes sends JSON with non-200 status
        try {
          var parsed = JSON.parse(body);
          return _normalizeEnvelope(parsed);
        } catch (e) {
          // HTML error page from Cloudflare, PHP fatal, nginx etc.
          logError('safeJsonResponse', 'Non-JSON response body', 'error', context);
          return _normalizeError('server_error', 'Server returned an unexpected response.', response.status);
        }
      });
    }

    return response.text().then(function (body) {
      if (!body || body.trim() === '') {
        return _normalizeError('empty_response', 'Server returned an empty response.', response.status);
      }
      try {
        return _normalizeEnvelope(JSON.parse(body));
      } catch (e) {
        logError('safeJsonResponse', 'JSON parse failed', 'error', { context: context, body: body.substring(0, 200) });
        return _normalizeError('json_parse_error', 'Could not read server response.', response.status);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     ISSUE 2 — STANDARDIZED API RESPONSE ENVELOPE
     Normalizes WP REST responses, WP_Error, raw JSON, HTML errors
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Normalize any server response into the standard CIAS envelope:
   * { success: true|false, data: {}, error: { code, message } }
   *
   * Handles:
   *   WP REST success:  { job_id, session_id, ... }
   *   WP AJAX success:  { success: true, data: {...} }
   *   WP_Error REST:    { code: 'rest_forbidden', message: '...' }
   *   WP AJAX error:    { success: false, data: { message: '...' } }
   *
   * @param  {*} raw  Parsed JSON from server
   * @return {Object} Normalized envelope
   */
  function _normalizeEnvelope(raw) {
    if (!raw || typeof raw !== 'object') {
      return _normalizeError('invalid_response', 'Invalid server response format.');
    }

    // Already a WP AJAX envelope { success: bool, data: {...} }
    if (typeof raw.success === 'boolean') {
      if (raw.success) return raw; // Pass through — callers already handle this format
      return {
        success: false,
        data: raw.data || {},
        error: {
          code:    raw.data && raw.data.code    ? raw.data.code    : 'request_failed',
          message: raw.data && raw.data.message ? raw.data.message : 'Request failed.',
        },
      };
    }

    // WP_Error REST response { code: 'rest_forbidden', message: '...', data: { status: 403 } }
    if (raw.code && raw.message && !raw.success) {
      return {
        success: false,
        data: raw.data || {},
        error: { code: raw.code, message: raw.message },
      };
    }

    // Raw WP REST success response (e.g. { job_id: '...', session_id: '...' })
    // Wrap it in a success envelope for consistent frontend handling
    return { success: true, data: raw, error: null };
  }

  function _normalizeError(code, message, status) {
    return {
      success: false,
      data:    {},
      error:   { code: code, message: message, status: status || 0 },
    };
  }

  /* ══════════════════════════════════════════════════════════════════════════
     ISSUE 4 — AUTH EXPIRY / SESSION FAILURE HANDLING
  ══════════════════════════════════════════════════════════════════════════ */

  var _authFailureShown = false; // Prevent duplicate banners

  /**
   * Centralized auth failure handler.
   * Shows a user-friendly re-login prompt.
   * Does NOT redirect automatically — avoids redirect loops.
   *
   * @param {number} status   HTTP status (401 or 403)
   * @param {string} context  Which endpoint failed
   */
  function handleAuthFailure(status, context) {
    logError('auth', 'Auth failure ' + status, 'warn', context);

    if (_authFailureShown) return;
    _authFailureShown = true;

    // Find or create the auth banner — mobile-safe, no redirect
    var existing = document.getElementById('cias-auth-banner');
    if (existing) return;

    var banner = document.createElement('div');
    banner.id = 'cias-auth-banner';
    banner.style.cssText = [
      'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
      'background:#1a1560', 'color:#fff', 'padding:14px 16px',
      'font-family:-apple-system,sans-serif', 'font-size:14px',
      'display:flex', 'align-items:center', 'justify-content:space-between',
      'gap:12px', 'box-shadow:0 2px 12px rgba(0,0,0,.3)',
    ].join(';');

    var msg = document.createElement('span');
    msg.textContent = status === 401
      ? 'Your session has expired. Please log in again to continue.'
      : 'Access denied. Please log in again.';

    var btn = document.createElement('button');
    btn.textContent = 'Log In →';
    btn.style.cssText = 'background:#6c63ff;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit';
    btn.addEventListener('click', function () {
      // Reload current page — WP will redirect to login and back
      window.location.reload();
    });

    banner.appendChild(msg);
    banner.appendChild(btn);
    document.body.appendChild(banner);
  }

  /* ══════════════════════════════════════════════════════════════════════════
     ISSUE 5 + 6 — REST CLIENT WITH TIMEOUT + ABORT
     Issue 3 — REST nonce strictly separated from AJAX nonce
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Create an AbortController with automatic timeout.
   * @param  {number}  ms  Timeout in milliseconds
   * @return {{ controller: AbortController, clear: Function }}
   */
  function _withTimeout(ms) {
    var controller = new AbortController();
    var timer = setTimeout(function () {
      controller.abort();
    }, ms);
    return {
      signal: controller.signal,
      clear:  function () { clearTimeout(timer); },
    };
  }

  /**
   * GET /wp-json/cias/v1/:path
   * Uses ONLY rest_nonce (wp_rest) — never AJAX nonce
   *
   * @param {string}   path  e.g. '/student/home'
   * @param {Function} cb    callback(normalizedResponse)
   */
  function restGet(path, cb) {
    var url     = _config.rest_url + path;
    var timeout = _withTimeout(DEFAULT_TIMEOUT_MS);

    fetch(url, {
      method:  'GET',
      headers: { 'X-WP-Nonce': _config.rest_nonce }, // REST nonce ONLY
      signal:  timeout.signal,
    })
    .then(function (r) {
      timeout.clear();
      return safeJsonResponse(r, 'GET ' + path);
    })
    .then(function (normalized) {
      if (cb) cb(normalized);
    })
    .catch(function (e) {
      timeout.clear();
      if (e.name === 'AbortError') {
        logError('restGet', 'Request timed out after ' + DEFAULT_TIMEOUT_MS + 'ms', 'warn', path);
        if (cb) cb(_normalizeError('timeout', 'Request timed out. Please check your connection.'));
      } else {
        logError('restGet', e.message, 'error', path);
        if (cb) cb(_normalizeError('network_error', 'Network error. Please check your connection.'));
      }
    });
  }

  /**
   * POST /wp-json/cias/v1/:path
   * Uses ONLY rest_nonce (wp_rest) — never AJAX nonce
   *
   * @param {string}   path  e.g. '/guru/chat'
   * @param {Object}   body  Request body (will be JSON.stringify'd)
   * @param {Function} cb    callback(normalizedResponse)
   */
  function restPost(path, body, cb) {
    var url     = _config.rest_url + path;
    var timeout = _withTimeout(DEFAULT_TIMEOUT_MS);

    fetch(url, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce':   _config.rest_nonce,  // REST nonce ONLY
      },
      body:    JSON.stringify(body),
      signal:  timeout.signal,
    })
    .then(function (r) {
      timeout.clear();
      return safeJsonResponse(r, 'POST ' + path);
    })
    .then(function (normalized) {
      if (cb) cb(normalized);
    })
    .catch(function (e) {
      timeout.clear();
      if (e.name === 'AbortError') {
        logError('restPost', 'Request timed out after ' + DEFAULT_TIMEOUT_MS + 'ms', 'warn', path);
        if (cb) cb(_normalizeError('timeout', 'Request timed out. Please check your connection.'));
      } else {
        logError('restPost', e.message, 'error', path);
        if (cb) cb(_normalizeError('network_error', 'Network error. Please check your connection.'));
      }
    });
  }

  /**
   * POST /wp-admin/admin-ajax.php
   * Uses ONLY nonce (cias_app_nonce) — never REST nonce
   *
   * @param {string}   action  WP AJAX action name
   * @param {Object}   data    Form data fields
   * @param {Function} cb      callback(normalizedResponse)
   */
  function ajaxPost(action, data, cb) {
    var timeout = _withTimeout(AJAX_TIMEOUT_MS);
    var fd      = new FormData();

    fd.append('action', action);
    fd.append('nonce',  _config.nonce);  // AJAX nonce ONLY
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });

    fetch(_config.ajax_url, {
      method: 'POST',
      body:   fd,
      signal: timeout.signal,
    })
    .then(function (r) {
      timeout.clear();
      return safeJsonResponse(r, 'AJAX ' + action);
    })
    .then(function (normalized) {
      if (cb) cb(normalized);
    })
    .catch(function (e) {
      timeout.clear();
      if (e.name === 'AbortError') {
        logError('ajaxPost', 'Request timed out after ' + AJAX_TIMEOUT_MS + 'ms', 'warn', action);
        if (cb) cb(_normalizeError('timeout', 'Request timed out. Please check your connection.'));
      } else {
        logError('ajaxPost', e.message, 'error', action);
        if (cb) cb(_normalizeError('network_error', 'Network error. Please check your connection.'));
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     PUBLIC API
  ══════════════════════════════════════════════════════════════════════════ */

  return {
    init:             init,
    restGet:          restGet,
    restPost:         restPost,
    ajaxPost:         ajaxPost,
    logError:         logError,
    handleAuthFailure:handleAuthFailure,
    safeJsonResponse: safeJsonResponse,
  };

}());
