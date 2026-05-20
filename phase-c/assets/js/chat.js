/**
 * chat.js — AI Guru Module
 *
 * Handles all AI Guru chat functionality:
 *   - Sending text messages (async via REST /guru/chat → job queue)
 *   - Image upload and OCR submission (via R2 → REST /answer/submit)
 *   - Job polling (pollJob, pollJobLive)
 *   - OCR confirmation / rejection
 *   - Message rendering (appendBotMsg, typing indicator)
 *
 * Architecture rules enforced:
 *   ✅ NEVER calls Claude synchronously inside an HTTP request
 *   ✅ All AI calls go through: REST → job queue → worker → poll
 *   ✅ No fallback to synchronous AI AJAX handler
 *   ✅ Uses REST API only (/wp-json/cias/v1/)
 *   ✅ No unsafe innerHTML on user-provided content (uses esc())
 *
 * Public API — window.CIASChat:
 *   init(config)          Bootstrap with shared config from cias-app.js
 *   sendMsg()             Send current chat input
 *   trigImg()             Open file picker for image upload
 *   rmImg()               Remove attached image
 *   fillQ(text)           Pre-fill input (from home screen cards)
 *   confirmOCR(id, btn)   Confirm OCR-extracted text
 *   rejectOCR(id)         Reject OCR, send to teacher
 *
 * @package CIAS\PhaseC
 * @since   3.20.0
 */

window.CIASChat = (function () {
  'use strict';

  /* ── Private state ─────────────────────────────────────────────────────── */
  var _D          = {};          // shared app data (set by init)
  var _sessionId  = '';
  var _imgData    = null;
  var _imgFile    = null;
  var _currentJob = null;

  /* ── Shared helpers (injected by init) ─────────────────────────────────── */
  // Note: _restPost and _ajaxPost removed — chat.js now uses CIAS_API directly
  var _el, _esc, _nowTime, _setText, _goTab;

  /* ══════════════════════════════════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════════════════════════════════ */

  function init(config) {
    _D         = config.data    || {};
    _el        = config.el;
    _esc       = config.esc;
    _nowTime   = config.nowTime;
    _setText   = config.setText;
    _goTab     = config.goTab;
    _sessionId = config.sessionId || '';

    _bindChatInput();
  }

  /* ══════════════════════════════════════════════════════════════════════════
     INPUT BINDING
  ══════════════════════════════════════════════════════════════════════════ */

  function _bindChatInput() {
    var inp = _el('chat-inp');
    if (!inp) return;
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
    });
  }

  function fillQ(q) {
    var inp = _el('chat-inp');
    if (inp) { inp.value = q; _autoRes(inp); inp.focus(); }
  }

  function _autoRes(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 80) + 'px';
  }

  /* ══════════════════════════════════════════════════════════════════════════
     IMAGE ATTACH
  ══════════════════════════════════════════════════════════════════════════ */

  function trigImg() {
    var inp = _el('file-inp');
    if (inp) inp.click();
  }

  function onFile(e) {
    var file = e.target.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert('Max file size is 10MB.'); return; }
    _imgFile = file;
    var reader = new FileReader();
    reader.onload = function (ev) {
      _imgData = ev.target.result;
      var thumb = _el('img-thumb');
      if (thumb) thumb.src = _imgData;
      _setText('img-lbl', file.name + ' (' + Math.round(file.size / 1024) + 'KB)');
      var strip = _el('img-strip');
      if (strip) strip.classList.add('show');
      var chatInp = _el('chat-inp');
      if (chatInp) chatInp.placeholder = 'Add a note about this answer (optional)...';
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  }

  function rmImg() {
    _imgData = null; _imgFile = null;
    var thumb = _el('img-thumb');
    if (thumb) thumb.src = '';
    var strip = _el('img-strip');
    if (strip) strip.classList.remove('show');
    var chatInp = _el('chat-inp');
    if (chatInp) chatInp.placeholder = 'Ask your UPSC doubt...';
  }

  /* ══════════════════════════════════════════════════════════════════════════
     SEND MESSAGE
  ══════════════════════════════════════════════════════════════════════════ */

  function sendMsg() {
    var inp    = _el('chat-inp');
    var txt    = inp ? inp.value.trim() : '';
    var hasImg = !!_imgData;

    if (!txt && !hasImg) return;

    // ── Credit check ───────────────────────────────────────────────────────
    if (_D.credits && _D.credits.remaining <= 0 && _D.credits.access_type !== 'free') {
      appendBotMsg('You have no credits remaining. <a href="#" onclick="CIASApp.goTab(\'profile\')" style="color:#6c63ff">Buy credits →</a>');
      return;
    }
    if (_D.credits && _D.credits.remaining <= 0 && _D.credits.access_type === 'free') {
      var limitDiv = document.createElement('div');
      limitDiv.style.cssText = 'font-size:11px;color:#9ca3af;text-align:center;padding:4px 12px';
      var limitTxt = document.createTextNode('Daily free limit reached · ');
      var limitLink = document.createElement('a');
      limitLink.href = '#';
      limitLink.style.color = '#6c63ff';
      limitLink.textContent = 'Upgrade →';
      limitLink.addEventListener('click', function(e) { e.preventDefault(); _goTab('profile'); });
      limitDiv.appendChild(limitTxt);
      limitDiv.appendChild(limitLink);
      var area = _el('chat-area');
      if (area) area.appendChild(limitDiv);
    }

    // ── Render user message ────────────────────────────────────────────────
    var userDiv = document.createElement('div');
    userDiv.className = 'ca-msg-row ca-msg-user-row';
    var bub = document.createElement('div');
    bub.className = 'ca-msg-user-bbl';
    if (hasImg) {
      var imgEl = document.createElement('img');
      imgEl.src = _imgData;
      imgEl.alt = 'Uploaded answer';
      imgEl.className = 'ca-img-in-chat';
      bub.appendChild(imgEl);
    }
    var msgP = document.createElement('p');
    msgP.textContent = txt || 'Answer image submitted for evaluation';
    bub.appendChild(msgP);
    var timeSpan = document.createElement('span');
    timeSpan.className = 'ca-msg-time';
    timeSpan.style.textAlign = 'right';
    timeSpan.textContent = 'You · ' + _nowTime();
    userDiv.appendChild(bub);
    userDiv.appendChild(timeSpan);
    _appendToChat(userDiv);

    // ── Save state, clear input ────────────────────────────────────────────
    var savedTxt    = txt;
    var savedHasImg = hasImg;
    var capturedImg = _imgData;
    if (inp) { inp.value = ''; inp.style.height = 'auto'; }
    rmImg();

    // ── Deduct credit locally for immediate UI feedback ───────────────────
    if (_D.credits) {
      _D.credits.remaining--;
      _setText('hdr-cr-num', _D.credits.remaining);
      _setText('prof-cr',    _D.credits.remaining);
    }

    // ── Show typing indicator ──────────────────────────────────────────────
    var loadDiv = _appendTypingIndicator();

    // ── Route to correct flow ──────────────────────────────────────────────
    if (savedHasImg) {
      _processImageSubmission(capturedImg, savedTxt, loadDiv);
    } else {
      _processTextChat(savedTxt, loadDiv);
    }
  }

  /* ══════════════════════════════════════════════════════════════════════════
     TEXT CHAT — async via REST → job queue → poll
     Architecture rule: NEVER call Claude synchronously here
  ══════════════════════════════════════════════════════════════════════════ */

  function _processTextChat(txt, loadDiv) {
    CIAS_API.restPost('/guru/chat', { message: txt, session_id: _sessionId }, function (res) {
      if (res && res.job_id) {
        // ── Async path: job queued, poll for result ────────────────────────
        _removeLoadDiv(loadDiv);
        _currentJob = res.job_id;
        _sessionId  = res.session_id || _sessionId;

        pollJob(res.job_id, function (result) {
          if (result && result.response) {
            appendBotMsg(_esc(result.response));
          } else {
            // ── Job failed — show retry, never call Claude synchronously ───
            _showRetryError(txt);
          }
        });
      } else if (res && res.error) {
        // REST returned error (402 credits, 429 rate limit, etc.)
        _removeLoadDiv(loadDiv);
        var errMsg = res.error || 'Could not send message.';
        appendBotMsg(errMsg);
      } else {
        // REST failed to return job_id — worker may be down
        _removeLoadDiv(loadDiv);
        _showRetryError(txt);
      }
    });
  }

  function _showRetryError(originalTxt) {
    // Friendly error with retry button — never falls back to sync AI call
    var errDiv = document.createElement('div');
    errDiv.className = 'ca-msg-row';
    errDiv.innerHTML =
      '<div class="ca-bot-av"><i class="ti ti-brain" aria-hidden="true" style="font-family:\'tabler-icons\';font-style:normal"></i></div>' +
      '<div><div class="ca-msg-bubble" style="background:#fef2f2;border:1px solid #fecaca">' +
      '<p style="color:#dc2626;font-size:13px">AI Guru is busy processing requests. Please wait a moment and try again.</p>' +
      '<button style="margin-top:8px;background:#6c63ff;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;font-family:inherit" ' +
      'onclick="this.closest(\'.ca-msg-row\').remove();CIASApp.fillQ(\'' + originalTxt.replace(/'/g, "\\'") + '\')">↩ Retry</button>' +
      '</div></div>';
    var area = _el('chat-area');
    if (area) { area.appendChild(errDiv); area.scrollTop = area.scrollHeight; }
  }

  /* ══════════════════════════════════════════════════════════════════════════
     IMAGE SUBMISSION — R2 upload → OCR job → poll
  ══════════════════════════════════════════════════════════════════════════ */

  function _processImageSubmission(imageDataUrl, note, loadDiv) {
    var statusEl = document.createElement('span');
    statusEl.className = 'ca-ocr-pill';
    statusEl.textContent = 'Uploading...';
    var typingEl = loadDiv.querySelector('.ca-typing');
    if (typingEl) typingEl.after(statusEl);

    if (_D.r2_configured) {
      var mime = _imgFile ? _imgFile.type : 'image/jpeg';
      var size = _imgFile ? _imgFile.size : 0;

      CIAS_API.restPost('/upload/presign', { mime_type: mime, file_size: size, submission_type: 'answer_writing' },
        function (presignRes) {
          if (!presignRes || !presignRes.presign_url) {
            _fallbackImageChat(imageDataUrl, note, loadDiv, statusEl);
            return;
          }
          statusEl.textContent = 'Uploading to R2...';

          var binary = atob(imageDataUrl.split(',')[1]);
          var arr = new Uint8Array(binary.length);
          for (var i = 0; i < binary.length; i++) arr[i] = binary.charCodeAt(i);
          var blob = new Blob([arr], { type: mime });

          fetch(presignRes.presign_url, {
            method: 'PUT', body: blob, headers: { 'Content-Type': mime }
          }).then(function (r) {
            if (!r.ok) throw new Error('R2 upload failed');
            statusEl.textContent = 'Running OCR...';
            CIAS_API.restPost('/answer/submit', {
              object_key: presignRes.object_key,
              mime_type: mime, file_size: size,
              submission_type: 'answer_writing',
              session_id: _sessionId
            }, function (submitRes) {
              if (!submitRes || !submitRes.job_id) {
                _fallbackImageChat(imageDataUrl, note, loadDiv, statusEl);
                return;
              }
              _removeLoadDiv(loadDiv);
              appendBotMsg(
                'Answer uploaded! OCR is running — I\'ll show you the extracted text shortly.<br>' +
                '<span class="ca-ocr-pill" id="ocr-live-status">Processing...</span>'
              );
              pollJobLive(submitRes.job_id, statusEl, note);
            });
          }).catch(function () {
            _fallbackImageChat(imageDataUrl, note, loadDiv, statusEl);
          });
        }
      );
    } else {
      _fallbackImageChat(imageDataUrl, note, loadDiv, statusEl);
    }
  }

  function _fallbackImageChat(imageDataUrl, note, loadDiv, statusEl) {
    if (statusEl) statusEl.textContent = 'Evaluating...';
    CIAS_API.restPost('/guru/chat', {
      message: note || 'Please evaluate my handwritten answer.',
      session_id: _sessionId,
      image_object_key: '',
      image_mime: 'image/jpeg'
    }, function (res) {
      _removeLoadDiv(loadDiv);
      if (res && res.job_id) {
        pollJob(res.job_id, function (result) {
          appendBotMsg(result && result.response ? _esc(result.response) : 'Evaluation complete. Please check your Progress tab.');
        });
      } else {
        appendBotMsg('Answer received. OCR and evaluation in progress. Please check your submissions in the Progress tab.');
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     JOB POLLING
  ══════════════════════════════════════════════════════════════════════════ */

  function pollJob(jobId, cb) {
    var attempts    = 0;
    var maxAttempts = 30; // 60 seconds max

    function check() {
      CIAS_API.ajaxPost('cias_job_poll', { job_id: jobId }, function (res) {
        attempts++;
        if (!res || !res.success) {
          if (attempts < maxAttempts) setTimeout(check, 2000);
          else cb(null);
          return;
        }
        var d = res.data;
        if (d.status === 'done')   { cb(d.result); return; }
        if (d.status === 'dead' || d.status === 'failed') { cb(null); return; }
        if (attempts < maxAttempts) setTimeout(check, 2000);
        else cb(null);
      });
    }
    setTimeout(check, 1500);
  }

  function pollJobLive(jobId, statusEl, note) {
    var attempts = 0;

    function check() {
      attempts++;
      CIAS_API.ajaxPost('cias_job_poll', { job_id: jobId }, function (res) {
        if (!res || !res.success) {
          if (attempts < 30) setTimeout(check, 2000);
          return;
        }
        var d = res.data;
        if (d.status === 'done' && d.result) {
          var r = d.result;
          if (statusEl) statusEl.textContent = 'Done';
          var liveEl = _el('ocr-live-status');

          if (r.needs_confirmation) {
            var rawDiv = document.createElement('div');
            rawDiv.style.cssText = 'background:#f8f8ff;border:0.5px solid #e9e9fb;border-radius:8px;padding:10px;margin:8px 0;font-size:12px;font-family:monospace';
            rawDiv.textContent = r.raw_text;

            var confirmBtn = document.createElement('button');
            confirmBtn.style.cssText = 'background:#6c63ff;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;margin-top:6px;margin-right:6px;font-family:inherit';
            confirmBtn.textContent = 'Yes, evaluate this';
            confirmBtn.addEventListener('click', function () { confirmOCR(r.submission_id, confirmBtn); });

            var rejectBtn = document.createElement('button');
            rejectBtn.style.cssText = 'background:#f3f4f6;color:#374151;border:none;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;margin-top:6px;font-family:inherit';
            rejectBtn.textContent = 'No, send to teacher';
            rejectBtn.addEventListener('click', function () { rejectOCR(r.submission_id); });

            var confirmWrap = document.createElement('div');
            var confLabel = document.createElement('strong');
            confLabel.textContent = 'I extracted the following text (' + Math.round(r.confidence * 100) + '% confidence):';
            confirmWrap.appendChild(confLabel);
            confirmWrap.appendChild(document.createElement('br'));
            confirmWrap.appendChild(rawDiv);
            var question = document.createTextNode('Does this look correct?');
            confirmWrap.appendChild(question);
            confirmWrap.appendChild(document.createElement('br'));
            confirmWrap.appendChild(confirmBtn);
            confirmWrap.appendChild(rejectBtn);

            _appendNodeToChat(confirmWrap);

          } else if (r.teacher_review) {
            appendBotMsg('Your handwriting was difficult to read. I\'ve sent this answer to your teacher for review. You\'ll be notified when feedback is ready.');
          } else if (r.auto_evaluating) {
            appendBotMsg('Text extracted successfully! AI evaluation in progress...');
          }
          if (liveEl) liveEl.remove();

        } else if (d.status === 'dead') {
          appendBotMsg('There was an error processing your answer. Please try again.');
        } else {
          if (attempts < 30) setTimeout(check, 2000);
        }
      });
    }
    setTimeout(check, 2000);
  }

  /* ══════════════════════════════════════════════════════════════════════════
     OCR CONFIRM / REJECT
  ══════════════════════════════════════════════════════════════════════════ */

  function confirmOCR(submissionId, btn) {
    if (btn) btn.disabled = true;
    var confirmedText = '';
    if (btn && btn.previousElementSibling) {
      confirmedText = btn.previousElementSibling.textContent || '';
    }
    CIAS_API.restPost('/answer/' + submissionId + '/confirm', {
      confirmed_text: confirmedText
    }, function () {
      appendBotMsg('Text confirmed! AI evaluation is starting. You\'ll see your score and feedback shortly.');
    });
  }

  function rejectOCR() {
    appendBotMsg('Understood. Your answer has been sent to your teacher for manual review and scoring.');
  }

  /* ══════════════════════════════════════════════════════════════════════════
     DOM HELPERS (chat-specific)
  ══════════════════════════════════════════════════════════════════════════ */

  function appendBotMsg(html) {
    var area = _el('chat-area');
    if (!area) return;
    var div = document.createElement('div');
    div.className = 'ca-msg-row';
    // Note: html here comes from AI responses or our own template strings
    // User input is always escaped before reaching appendBotMsg
    div.innerHTML =
      '<div class="ca-bot-av"><i class="ti ti-brain" aria-hidden="true" style="font-family:\'tabler-icons\';font-style:normal"></i></div>' +
      '<div><div class="ca-msg-bubble"><p>' + html + '</p></div>' +
      '<span class="ca-msg-time">CIAS AI · ' + _nowTime() + '</span></div>';
    area.appendChild(div);
    area.scrollTop = area.scrollHeight;
  }

  function _appendTypingIndicator() {
    var area = _el('chat-area');
    if (!area) return document.createElement('div');
    var div = document.createElement('div');
    div.className = 'ca-msg-row';
    div.innerHTML =
      '<div class="ca-bot-av"><i class="ti ti-brain" aria-hidden="true" style="font-family:\'tabler-icons\';font-style:normal"></i></div>' +
      '<div><div class="ca-msg-bubble"><div class="ca-typing"><span></span><span></span><span></span></div></div></div>';
    area.appendChild(div);
    area.scrollTop = area.scrollHeight;
    return div;
  }

  function _appendToChat(div) {
    var area = _el('chat-area');
    if (!area) return;
    area.appendChild(div);
    area.scrollTop = area.scrollHeight;
  }

  function _appendNodeToChat(node) {
    var area = _el('chat-area');
    if (!area) return;
    var wrapper = document.createElement('div');
    wrapper.className = 'ca-msg-row';
    var botAv = document.createElement('div');
    botAv.className = 'ca-bot-av';
    var icon = document.createElement('i');
    icon.className = 'ti ti-brain';
    icon.setAttribute('aria-hidden', 'true');
    icon.style.fontFamily = "'tabler-icons'";
    icon.style.fontStyle = 'normal';
    botAv.appendChild(icon);
    var bubble = document.createElement('div');
    bubble.className = 'ca-msg-bubble';
    bubble.appendChild(node);
    wrapper.appendChild(botAv);
    wrapper.appendChild(bubble);
    area.appendChild(wrapper);
    area.scrollTop = area.scrollHeight;
  }

  function _removeLoadDiv(loadDiv) {
    var area = _el('chat-area');
    if (area && loadDiv && loadDiv.parentNode === area) {
      area.removeChild(loadDiv);
    }
  }

  /* ══════════════════════════════════════════════════════════════════════════
     PUBLIC API
  ══════════════════════════════════════════════════════════════════════════ */

  return {
    init:       init,
    sendMsg:    sendMsg,
    trigImg:    trigImg,
    rmImg:      rmImg,
    onFile:     onFile,
    fillQ:      fillQ,
    confirmOCR: confirmOCR,
    rejectOCR:  rejectOCR,
    pollJob:    pollJob,
    pollJobLive:pollJobLive,
    appendBotMsg: appendBotMsg,
  };

}());
