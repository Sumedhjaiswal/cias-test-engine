var CIASApp = (function ($) {
    'use strict';

    var CT = window.CIASTest || {};
    var state = {
        attempt_id: null,
        questions:  [],
        answers:    {},
        current:    0,
        timer_id:   null,
        seconds_left: 0,
        time_limit:   0,
    };

    /* ════════════════════════════
       INIT
    ════════════════════════════ */
    function init() {
        if (!CT.is_logged_in) return;
        loadTests();
        $(document).on('click', '.cias-tab-btn', function () {
            var tab = $(this).data('tab');
            $('.cias-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.cias-tab').hide();
            $('#cias-tab-' + tab).show();
            if (tab === 'tests')    loadTests();
            if (tab === 'practice') loadPractice(0);
            if (tab === 'history')  loadHistory();
            if (tab === 'guru' && window.CIAS_Guru) CIAS_Guru.init();
        });
    }

    /* ── Load Tests ── */
    function loadTests() {
        $('#cias-tests-list').html('<div class="cias-loading">Loading tests…</div>');
        $.post(CT.ajax_url, { action: 'cias_get_tests', nonce: CT.nonce }, function (r) {
            if (r.success) {
                $('#cias-tests-list').html(r.data.html);
                startCountdowns();
            } else {
                $('#cias-tests-list').html('<div class="cias-empty"><p>Could not load tests. Try refreshing.</p></div>');
            }
        });
    }

    /* ── Load Practice ── */
    function loadPractice(subject_id) {
        subject_id = subject_id || 0;
        $('#cias-practice-list').html('<div class="cias-loading">Loading…</div>');
        $.post(CT.ajax_url, { action: 'cias_get_practice', nonce: CT.nonce, subject_id: subject_id }, function (r) {
            if (r.success) $('#cias-practice-list').html(r.data.html);
        });
    }

    /* Live countdown for upcoming tests */
    function startCountdowns() {
        $('.cias-countdown-timer').each(function () {
            var $el  = $(this);
            var ts   = parseInt($el.data('ts'));
            var tick = function () {
                var now  = Math.floor(Date.now() / 1000);
                var diff = ts - now;
                if (diff <= 0) {
                    $el.text('Starting now — refresh!');
                    $el.closest('.cias-test-card').find('button').prop('disabled', false).removeClass('cias-btn-disabled').addClass('cias-btn-primary').text('Start Test →');
                    return;
                }
                var d = Math.floor(diff / 86400);
                var h = Math.floor((diff % 86400) / 3600);
                var m = Math.floor((diff % 3600) / 60);
                var s = diff % 60;
                var str = d > 0 ? d + 'd ' + pad(h) + 'h ' + pad(m) + 'm' : pad(h) + ':' + pad(m) + ':' + pad(s);
                $el.text('⏳ ' + str + ' remaining');
                setTimeout(tick, 1000);
            };
            tick();
        });
    }

    function pad(n) { return n < 10 ? '0' + n : n; }

    /* ════════════════════════════
       START TEST (with PIN check)
    ════════════════════════════ */
    function startTest(test_id, requires_pin) {
        if (requires_pin) {
            showPinModal(test_id);
        } else {
            doStartTest(test_id);
        }
    }

    function showPinModal(test_id) {
        var modal = '<div id="cias-pin-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center">' +
            '<div style="background:#fff;border-radius:16px;padding:32px;max-width:360px;width:90%;text-align:center">' +
            '<div style="font-size:32px;margin-bottom:12px">🔐</div>' +
            '<div style="font-size:18px;font-weight:700;margin-bottom:6px">Enter Test PIN</div>' +
            '<div style="font-size:13px;color:#6b7280;margin-bottom:20px">Ask your teacher for the PIN to unlock this test.</div>' +
            '<input type="text" id="cias-pin-input" maxlength="6" placeholder="_ _ _ _ _ _"' +
            ' style="width:100%;font-size:28px;letter-spacing:8px;text-align:center;padding:14px;border:2px solid #6C63FF;border-radius:10px;font-weight:700;margin-bottom:14px">' +
            '<div id="cias-pin-error" style="color:#dc2626;font-size:13px;margin-bottom:10px;display:none"></div>' +
            '<button id="cias-pin-submit" onclick="verifyPin(' + test_id + ')"' +
            ' style="width:100%;background:#6C63FF;color:#fff;border:none;border-radius:10px;padding:12px;font-size:15px;font-weight:600;cursor:pointer">Unlock Test</button>' +
            '<button onclick="jQuery(\'#cias-pin-modal\').remove()" style="background:none;border:none;color:#9ca3af;font-size:13px;cursor:pointer;margin-top:10px;display:block;width:100%">Cancel</button>' +
            '</div></div>';
        $('body').append(modal);
        setTimeout(function(){ $('#cias-pin-input').focus(); }, 100);
        $('#cias-pin-input').on('keydown', function(e){ if(e.key==='Enter') verifyPin(test_id); });
    }

    window.verifyPin = function(test_id) {
        var pin = $('#cias-pin-input').val().trim();
        if (!pin || pin.length < 4) { $('#cias-pin-error').text('Please enter the full PIN.').show(); return; }
        $('#cias-pin-submit').text('Verifying…').prop('disabled', true);
        $.post(CT.ajax_url, { action: 'cias_verify_pin', nonce: CT.nonce, test_id: test_id, pin: pin }, function(r) {
            if (r.success) {
                $('#cias-pin-modal').remove();
                doStartTest(test_id);
            } else {
                $('#cias-pin-error').text(r.data.message || 'Incorrect PIN.').show();
                $('#cias-pin-submit').text('Unlock Test').prop('disabled', false);
                $('#cias-pin-input').val('').focus();
            }
        });
    };

    function doStartTest(test_id) {
        $('#cias-tests-list').html('<div class="cias-loading">Starting test…</div>');
        $.post(CT.ajax_url, { action: 'cias_start_test', nonce: CT.nonce, test_id: test_id }, function (r) {
            if (!r.success) { alert(r.data.message || 'Could not start test.'); loadTests(); return; }
            var d = r.data;
            state.attempt_id   = d.attempt_id;
            state.questions    = d.questions;
            state.answers      = d.saved || {};
            state.current      = 0;
            state.time_limit   = d.time_limit;
            state.seconds_left = d.time_limit * 60;
            state.test_id      = test_id;

            showTab('exam');
            renderExam(d.test_title);
            if (d.time_limit > 0) startTimer();

            // Start heartbeat — checks every 30s if student was kicked
            state.heartbeat = setInterval(function() {
                $.post(CT.ajax_url, { action: 'cias_session_heartbeat', nonce: CT.nonce, test_id: test_id }, function(hr) {
                    if (!hr.success && hr.data && hr.data.kicked) {
                        clearInterval(state.heartbeat);
                        alert('⚠️ ' + (hr.data.message || 'You have been removed from this test session.'));
                        showTab('tests');
                        loadTests();
                    }
                });
            }, 30000);
        }).fail(function () { alert('Server error. Please try again.'); loadTests(); });
    }

    /* ════════════════════════════
       RENDER EXAM
    ════════════════════════════ */
    function renderExam(title) {
        var q   = state.questions;
        var idx = state.current;
        var w   = q[idx];
        if (!w) return;

        var timer_html = state.time_limit > 0
            ? '<div class="cias-timer" id="cias-timer">' + formatTime(state.seconds_left) + '</div>'
            : '<div class="cias-timer">No limit</div>';

        var q_nav = q.map(function (_, i) {
            var cls = (state.answers[_.id] ? 'answered ' : '') + (i === idx ? 'current' : '');
            return '<button class="cias-q-btn ' + cls + '" onclick="CIASApp.goToQ(' + i + ')">' + (i + 1) + '</button>';
        }).join('');

        var opts = ['a','b','c','d'];
        var labels = ['A','B','C','D'];
        var options_html = opts.map(function (opt, i) {
            var text = esc(w['option_' + opt]);
            var sel  = state.answers[w.id] === opt ? ' selected' : '';
            return '<div class="cias-option' + sel + '" onclick="CIASApp.selectOption(\'' + opt + '\')">' +
                '<span class="cias-opt-badge">' + labels[i] + '</span>' + text + '</div>';
        }).join('');

        // Build question body based on type
        var q_body = '';

        // Tags row
        var tags = w.question_tags ? w.question_tags.split(',').filter(Boolean) : [];
        var tag_colors = {'Static':'#1D9E75','Current':'#e85d04','Conceptual':'#6C63FF','Factual':'#BA7517','Analytical':'#3b82f6','High probability':'#dc2626','Medium probability':'#f59e0b','Low probability':'#6b7280'};
        if (tags.length || w.year_asked) {
            var tags_html = '<div class="cias-q-tags">';
            tags.forEach(function(t) {
                t = t.trim();
                var col = tag_colors[t] || '#6C63FF';
                tags_html += '<span class="cias-q-tag" style="background:' + col + '20;color:' + col + ';border-color:' + col + '40">' + esc(t) + '</span>';
            });
            if (w.year_asked) {
                tags_html += '<span class="cias-q-tag" style="background:#fef3c7;color:#92400e;border-color:#fde68a">UPSC ' + w.year_asked + '</span>';
            }
            tags_html += '</div>';
            q_body += tags_html;
        }

        // Question stem
        q_body += '<div class="cias-q-text">' + esc(w.question_text) + '</div>';

        // Statements for statement-based questions
        if (w.question_type === 'statement' && w.statements) {
            var stmts = [];
            try { stmts = JSON.parse(w.statements); } catch(e) {}
            if (stmts.length) {
                q_body += '<div class="cias-q-statements">';
                stmts.forEach(function(s, i) {
                    q_body += '<div class="cias-q-stmt"><span class="cias-q-stmt-num">' + (i+1) + '.</span><span>' + esc(s) + '</span></div>';
                });
                q_body += '</div>';
                q_body += '<div class="cias-q-select-hint">Select the correct answer:</div>';
            }
        }

        var answered = Object.keys(state.answers).length;
        var total    = q.length;
        var pct      = Math.round((answered / total) * 100);

        $('#cias-exam-wrap').html(
            '<div class="cias-exam">' +
            '<div class="cias-exam-header"><span class="cias-exam-title">' + esc(title || 'Test') + '</span>' + timer_html + '</div>' +
            '<div class="cias-exam-progress"><div class="cias-prog-bar"><div class="cias-prog-fill" id="cias-prog" style="width:' + pct + '%"></div></div>' +
            '<div class="cias-prog-info"><span>' + answered + ' of ' + total + ' answered</span><span>Q ' + (idx+1) + ' / ' + total + '</span></div></div>' +
            '<div class="cias-q-nav" id="cias-q-nav">' + q_nav + '</div>' +
            '<div class="cias-question-wrap">' +
            '<div class="cias-q-num">Question ' + (idx+1) + ' of ' + total + '</div>' +
            q_body +
            '<div class="cias-options" id="cias-opts">' + options_html + '</div>' +
            '</div>' +
            '<div class="cias-exam-footer">' +
            '<div class="cias-answered-info"><span id="cias-ans-count">' + answered + '</span> / ' + total + ' answered</div>' +
            '<div style="display:flex;gap:10px">' +
            (idx > 0 ? '<button class="cias-btn cias-btn-outline" onclick="CIASApp.goToQ(' + (idx-1) + ')">← Prev</button>' : '') +
            (idx < total-1 ? '<button class="cias-btn cias-btn-primary" onclick="CIASApp.goToQ(' + (idx+1) + ')">Next →</button>' : '<button class="cias-btn cias-btn-primary" onclick="CIASApp.confirmSubmit()" id="cias-submit-btn">Submit Test ✓</button>') +
            '</div></div>' +
            '</div>'
        );
    }

    /* ════════════════════════════
       SELECT OPTION
    ════════════════════════════ */
    function selectOption(opt) {
        var w = state.questions[state.current];
        if (!w) return;
        state.answers[w.id] = opt;

        // Update UI
        $('#cias-opts .cias-option').removeClass('selected');
        $('#cias-opts .cias-option').eq(['a','b','c','d'].indexOf(opt)).addClass('selected');

        // Update nav button
        updateNavBtn(state.current, true);
        updateProgress();

        // Auto-save
        $.post(CT.ajax_url, {
            action: 'cias_save_answer',
            nonce: CT.nonce,
            attempt_id: state.attempt_id,
            question_id: w.id,
            selected: opt
        });
    }

    function updateNavBtn(idx, answered) {
        var $btn = $('#cias-q-nav .cias-q-btn').eq(idx);
        if (answered) $btn.addClass('answered');
        $btn.addClass('current').siblings().removeClass('current');
    }

    function updateProgress() {
        var answered = Object.keys(state.answers).length;
        var total    = state.questions.length;
        var pct      = Math.round((answered / total) * 100);
        $('#cias-prog').css('width', pct + '%');
        $('#cias-ans-count').text(answered);
        $('.cias-prog-info span:first').text(answered + ' of ' + total + ' answered');
    }

    /* ════════════════════════════
       NAVIGATE
    ════════════════════════════ */
    function goToQ(idx) {
        if (idx < 0 || idx >= state.questions.length) return;
        state.current = idx;
        var title = $('.cias-exam-title').text();
        renderExam(title);
    }

    /* ════════════════════════════
       TIMER
    ════════════════════════════ */
    function startTimer() {
        clearInterval(state.timer_id);
        state.timer_id = setInterval(function () {
            state.seconds_left--;
            var $t = $('#cias-timer');
            $t.text(formatTime(state.seconds_left));
            if (state.seconds_left <= 60)  $t.removeClass('warning').addClass('danger');
            else if (state.seconds_left <= 300) $t.addClass('warning');
            if (state.seconds_left <= 0) { clearInterval(state.timer_id); submitTest(true); }
        }, 1000);
    }

    function formatTime(s) {
        if (s < 0) s = 0;
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    /* ════════════════════════════
       SUBMIT
    ════════════════════════════ */
    function confirmSubmit() {
        var answered = Object.keys(state.answers).length;
        var total    = state.questions.length;
        var unanswered = total - answered;
        var msg = unanswered > 0
            ? unanswered + ' question(s) unanswered. Are you sure you want to submit?'
            : 'Submit your test? You cannot change answers after submission.';
        if (confirm(msg)) submitTest(false);
    }

    function submitTest(auto) {
        clearInterval(state.timer_id);
        if (auto) alert('Time\'s up! Your test has been submitted automatically.');
        $('#cias-submit-btn').prop('disabled', true).text('Submitting…');
        $.post(CT.ajax_url, { action: 'cias_submit_test', nonce: CT.nonce, attempt_id: state.attempt_id }, function (r) {
            if (r.success) {
                viewResults(r.data.attempt_id);
            } else {
                alert('Submission failed: ' + (r.data.message || 'Unknown error'));
            }
        });
    }

    /* ════════════════════════════
       RESULTS
    ════════════════════════════ */
    function viewResults(attempt_id) {
        showTab('results');
        $('#cias-results-wrap').html('<div class="cias-loading">Loading results…</div>');
        $.post(CT.ajax_url, { action: 'cias_get_results', nonce: CT.nonce, attempt_id: attempt_id }, function (r) {
            if (r.success) {
                $('#cias-results-wrap').html(r.data.html);
                $('#cias-tab-results')[0].scrollIntoView({ behavior: 'smooth' });
            } else {
                $('#cias-results-wrap').html('<div class="cias-empty"><p>' + (r.data.message || 'Could not load results.') + '</p><button class="cias-btn cias-btn-outline" onclick="CIASApp.goTests()">← Back</button></div>');
            }
        });
    }

    /* ── Start adaptive/practice/drill test ── */
    function startAdaptive(subject_id, topic_id, subtopic_id, type) {
        showTab('exam');
        $('#cias-exam-wrap').html('<div class="cias-loading">Generating your personalised test…</div>');
        var q_count = parseInt($('#prac-count').val()) || 15;
        $.post(CT.ajax_url, {
            action: 'cias_start_adaptive',
            nonce: CT.nonce,
            subject_id: subject_id,
            topic_id: topic_id || 0,
            subtopic_id: subtopic_id || 0,
            adaptive_type: type || 'practice',
            q_count: q_count
        }, function (r) {
            if (!r.success) {
                $('#cias-exam-wrap').html('<div class="cias-empty"><p>' + (r.data.message||'Could not generate test.') + '</p><button class="cias-btn cias-btn-outline" onclick="CIASApp.goTests()">← Back</button></div>');
                return;
            }
            var d = r.data;
            state.attempt_id   = d.attempt_id;
            state.questions    = d.questions;
            state.answers      = d.saved || {};
            state.current      = 0;
            state.time_limit   = d.time_limit;
            state.seconds_left = 0;
            renderExam(d.test_title + (d.level ? ' [' + d.level.charAt(0).toUpperCase() + d.level.slice(1) + ']' : ''));
        }).fail(function () {
            $('#cias-exam-wrap').html('<div class="cias-empty"><p>Server error. Please try again.</p></div>');
        });
    }

    function goTests() {
        clearInterval(state.timer_id);
        showTab('tests');
        loadTests();
        $.post(CT.ajax_url, {action:'cias_get_due_revisions',nonce:CT.nonce}, function(r){
            if (r.success && r.data.count > 0) {
                $('#cias-pending-count').closest('.cias-pill').after(
                    '<div class="cias-pill" style="border:1px solid #fca5a5;background:#fef2f2"><span class="cias-pill-num" style="color:#dc2626">'+r.data.count+'</span><span style="color:#dc2626">Due revision</span></div>'
                );
            }
        });
    }

    /* ════════════════════════════
       HISTORY
    ════════════════════════════ */
    function loadHistory() {
        $('#cias-history-list').html('<div class="cias-loading">Loading…</div>');
        var online  = $.post(CT.ajax_url, { action: 'cias_get_history',         nonce: CT.nonce });
        var offline = $.post(CT.ajax_url, { action: 'cias_get_offline_history',  nonce: CT.nonce });
        $.when(online, offline).done(function(o, of) {
            var html = '';
            if (o[0] && o[0].success)  html += o[0].data.html;
            if (of[0] && of[0].success && of[0].data.html) html += of[0].data.html;
            $('#cias-history-list').html(html || '<div class="cias-empty"><p>No history yet.</p></div>');
        });
    }

    /* ════════════════════════════
       HELPERS
    ════════════════════════════ */
    function showTab(name) {
        $('.cias-tab').hide();
        $('#cias-tab-' + name).show();
        $('.cias-tab-btn').removeClass('active');
        $('.cias-tab-btn[data-tab="' + name + '"]').addClass('active');
        if (name === 'bot') renderBot();
    }

    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ════════════════════════════
       AI TUTOR BOT
    ════════════════════════════ */
    var botHistory = [];

    function renderBot() {
        var st = (CIASTest.bot_status || {});
        var freeLeft = Math.max(0, 5 - (st.free_used_today || 0));
        var credits  = st.credits || 0;
        var revoked  = st.revoked || false;
        var rzpKey   = CIASTest.razorpay_key || '';
        var packs    = CIASTest.bot_packs || [];

        var creditBadge = credits > 0
            ? '<span style="background:#f0eeff;color:#534AB7;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600">' + credits + ' credits</span>'
            : '';

        var packHtml = '';
        if (rzpKey && !revoked) {
            packs.forEach(function(p) {
                packHtml += '<button onclick="buyCredits(\'' + p.id + '\',' + p.credits + ')" class="cias-btn" style="font-size:12px;padding:6px 14px">'
                    + p.price + ' — ' + p.label + '</button> ';
            });
        }

        var html = '<div style="max-width:680px;margin:0 auto">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
            + '<div style="font-size:15px;font-weight:500">🤖 AI Study Tutor</div>'
            + '<div style="display:flex;align-items:center;gap:8px">'
            + (freeLeft > 0 ? '<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:99px;font-size:11px">' + freeLeft + ' free left today</span>' : '')
            + creditBadge
            + '</div></div>';

        if (revoked) {
            html += '<div class="cias-notice" style="background:#fee2e2;color:#991b1b">Your AI tutor access has been revoked. Please contact your teacher.</div>';
        } else if (freeLeft === 0 && credits === 0) {
            html += '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:14px;font-size:13px">'
                + '⚠️ You have used all 5 free questions for today. Buy a credit pack to keep asking questions.<br><div style="margin-top:10px;display:flex;gap:8px">' + packHtml + '</div></div>';
        }

        html += '<div id="cias-bot-messages" style="min-height:220px;max-height:420px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#f9fafb;margin-bottom:12px">';
        if (botHistory.length === 0) {
            html += '<div style="text-align:center;color:#9ca3af;padding:40px 0;font-size:13px">'
                + '👋 Ask me anything about UPSC — History, Polity, Economy, Current Affairs, Geography, and more.<br>'
                + '<span style="font-size:11px">Answers are based on standard UPSC sources.</span></div>';
        } else {
            botHistory.forEach(function(m) {
                if (m.role === 'user') {
                    html += '<div style="display:flex;justify-content:flex-end;margin-bottom:10px">'
                        + '<div style="background:#6C63FF;color:#fff;border-radius:12px 12px 2px 12px;padding:10px 14px;max-width:75%;font-size:13px;line-height:1.5">' + esc(m.content) + '</div></div>';
                } else {
                    html += '<div style="display:flex;margin-bottom:10px;gap:8px">'
                        + '<div style="width:28px;height:28px;background:#f0eeff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">🤖</div>'
                        + '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px 12px 12px 2px;padding:10px 14px;max-width:80%;font-size:13px;line-height:1.6">'
                        + m.content.replace(/\n/g,'<br>') + '</div></div>';
                }
            });
        }
        html += '</div>';

        html += '<div style="display:flex;gap:8px">'
            + '<textarea id="cias-bot-input" rows="2" placeholder="Ask a question about your UPSC studies…" '
            + 'style="flex:1;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:13px;resize:none;font-family:inherit" '
            + 'onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();sendBotQuestion();}"></textarea>'
            + '<button onclick="sendBotQuestion()" class="cias-btn cias-btn-primary" style="padding:10px 18px;font-size:14px" id="cias-bot-send">Ask</button></div>';

        if (rzpKey && credits === 0 && freeLeft > 0) {
            html += '<div style="margin-top:10px;font-size:12px;color:#9ca3af">Running low? Buy credits: '
                + packHtml + '</div>';
        }

        html += '</div>';
        $('#cias-bot-wrap').html(html);

        // Scroll to bottom
        var msgs = document.getElementById('cias-bot-messages');
        if (msgs) msgs.scrollTop = msgs.scrollHeight;
    }

    window.sendBotQuestion = function() {
        var q = $('#cias-bot-input').val().trim();
        if (!q) return;
        $('#cias-bot-send').prop('disabled', true).text('…');
        $('#cias-bot-input').val('').prop('disabled', true);

        botHistory.push({role:'user', content:q});
        renderBot();

        var historyToSend = botHistory.slice(-6); // send last 6 messages for context
        $.post(CIASTest.ajax_url, {
            action: 'cias_ask_bot',
            nonce: CIASTest.nonce,
            question: q,
            history: JSON.stringify(historyToSend.slice(0,-1)),
        }, function(r) {
            if (r.success) {
                botHistory.push({role:'assistant', content: r.data.answer});
                if (r.data.status) CIASTest.bot_status = r.data.status;
            } else {
                botHistory.push({role:'assistant', content:'⚠️ ' + (r.data.message || 'Could not get an answer. Please try again.')});
                if (r.data && r.data.show_upgrade) {
                    // Trigger upgrade prompt
                }
            }
            renderBot();
            $('#cias-bot-input').prop('disabled', false).focus();
        }).fail(function(){
            botHistory.push({role:'assistant',content:'⚠️ Network error. Please try again.'});
            renderBot();
            $('#cias-bot-input').prop('disabled', false);
        });
    };

    window.buyCredits = function(pack_id, credits) {
        if (!CIASTest.razorpay_key) { alert('Payment not configured. Contact admin.'); return; }

        $.post(CIASTest.ajax_url, {
            action: 'cias_create_razorpay_order',
            nonce: CIASTest.nonce,
            pack_id: pack_id,
        }, function(r) {
            if (!r.success) { alert(r.data.message || 'Could not create order.'); return; }
            var d = r.data;
            var opts = {
                key:          CIASTest.razorpay_key,
                amount:       d.amount,
                currency:     d.currency,
                name:         d.site_name,
                description:  credits + ' AI Credits',
                order_id:     d.order_id,
                prefill:      { name: d.user_name, email: d.user_email },
                theme:        { color: '#6C63FF' },
                handler: function(response) {
                    $.post(CIASTest.ajax_url, {
                        action:               'cias_verify_payment',
                        nonce:                CIASTest.nonce,
                        razorpay_order_id:    response.razorpay_order_id,
                        razorpay_payment_id:  response.razorpay_payment_id,
                        razorpay_signature:   response.razorpay_signature,
                        credits:              credits,
                    }, function(vr) {
                        if (vr.success) {
                            CIASTest.bot_status = vr.data.status;
                            alert(vr.data.message);
                            renderBot();
                        } else {
                            alert(vr.data.message || 'Verification failed. Contact admin with payment ID: ' + response.razorpay_payment_id);
                        }
                    });
                },
            };
            var rzp = new Razorpay(opts);
            rzp.open();
        });
    };

    /* Boot */
    $(document).ready(init);

    return { startTest: startTest, viewResults: viewResults, goTests: goTests, goToQ: goToQ, selectOption: selectOption, confirmSubmit: confirmSubmit, startAdaptive: startAdaptive, loadPractice: loadPractice };

}(jQuery));

/* ═══════════════════════════════════════════════════════════════
   CIAS AI GURU MODULE
═══════════════════════════════════════════════════════════════ */
var CIAS_Guru = (function ($) {
  'use strict';

  var CT       = window.CIASTest || {};
  var nonce    = CT.caig_nonce || CT.nonce || '';
  var ajax_url = CT.ajax_url || '';
  var guru_inited   = false;
  var plan_loaded   = false;
  var lec_loaded    = false;
  var rank_loaded   = false;
  var heatmap_loaded = false;
  var chat_history  = [];

  /* ── Sub-nav panel switching ── */
  $(document).on('click', '.caig-snav-btn', function () {
    var panel = $(this).data('panel');
    $('.caig-snav-btn').removeClass('active');
    $(this).addClass('active');
    $('.caig-panel').hide();
    $('#caig-panel-' + panel).show();
    if (panel === 'planner' && !plan_loaded)    { loadPlan(false); plan_loaded = true; }
    if (panel === 'lectures' && !lec_loaded)    { loadLectures(); lec_loaded = true; }
    if (panel === 'rank'    && !rank_loaded)    { loadRank(false); rank_loaded = true; }
    if (panel === 'heatmap' && !heatmap_loaded) { loadHeatmap(); heatmap_loaded = true; }
  });

  /* ── Init (called when Guru tab is activated) ── */
  function init() {
    if (guru_inited) return;
    guru_inited = true;
    loadHeroStats();
  }

  function loadHeroStats() {
    $.post(ajax_url, { action: 'caig_guru_chat', nonce: nonce, question: '__stats_only__', history: '[]' }, function (r) {
      if (r.success && r.data.profile) {
        var p = r.data.profile;
        $('#caig-stat-streak').text(p.streak || '0');
        $('#caig-stat-avg').text(p.avg ? p.avg + '%' : '—');
        $('#caig-stat-tests').text(p.tests || '0');
      }
    });
  }

  /* ── Chat ── */
  $(document).on('click', '.caig-chip', function () {
    $('#caig-input').val($(this).data('q'));
    sendMessage();
  });
  $(document).on('click', '#caig-send', sendMessage);
  $(document).on('keydown', '#caig-input', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    // Auto-grow
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
  });

  function sendMessage() {
    var input = $('#caig-input');
    var q = $.trim(input.val());
    if (!q) return;
    appendMsg('user', q);
    input.val('').css('height', 'auto');
    $('#caig-send').prop('disabled', true);
    var tid = 'caig-t-' + Date.now();
    appendTyping(tid);
    $.post(ajax_url, {
      action: 'caig_guru_chat', nonce: nonce, question: q,
      history: JSON.stringify(chat_history.slice(-6)),
    }, function (r) {
      $('#' + tid).remove();
      $('#caig-send').prop('disabled', false);
      if (r.success) {
        var text = r.data.response;
        chat_history.push({ role: 'user', content: q });
        chat_history.push({ role: 'assistant', content: text });
        appendMsg('ai', text);
        if (r.data.profile) {
          $('#caig-stat-streak').text(r.data.profile.streak || '—');
          $('#caig-stat-avg').text(r.data.profile.avg ? r.data.profile.avg + '%' : '—');
          $('#caig-stat-tests').text(r.data.profile.tests || '—');
        }
      } else {
        appendMsg('ai', '⚠️ ' + (r.data && r.data.message ? r.data.message : 'Something went wrong.'));
      }
      scrollChat();
    }).fail(function () {
      $('#' + tid).remove();
      $('#caig-send').prop('disabled', false);
      appendMsg('ai', '⚠️ Connection error. Please check your internet and try again.');
      scrollChat();
    });
  }

  function appendMsg(role, text) {
    var isAI = role === 'ai';
    var av   = isAI ? '🧠' : '👤';
    var html = '<div class="caig-msg ' + (isAI ? 'caig-ai' : 'caig-user') + '">'
      + '<div class="caig-msg-av">' + av + '</div>'
      + '<div class="caig-msg-bbl">' + formatText(text) + '</div>'
      + '</div>';
    $('#caig-chat-box').append(html);
    scrollChat();
  }

  function appendTyping(id) {
    var html = '<div class="caig-msg caig-ai" id="' + id + '">'
      + '<div class="caig-msg-av">🧠</div>'
      + '<div class="caig-msg-bbl"><div class="caig-typing-dots"><span></span><span></span><span></span></div></div>'
      + '</div>';
    $('#caig-chat-box').append(html);
    scrollChat();
  }

  function formatText(text) {
    // Convert • bullets and newlines to HTML
    text = escHtml(text);
    text = text.replace(/•\s*(.+)/g, '<li>$1</li>');
    if (text.indexOf('<li>') > -1) text = '<ul style="margin:6px 0 0 12px;padding:0">' + text + '</ul>';
    text = text.replace(/\n/g, '<br>');
    return text;
  }

  function scrollChat() {
    var box = document.getElementById('caig-chat-box');
    if (box) box.scrollTop = box.scrollHeight;
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ── Study Plan ── */
  function loadPlan(force) {
    $('#caig-plan-wrap').html(skeletonHtml('greeting') + skeletonHtml('card') + skeletonHtml('card') + skeletonHtml('card'));
    $.post(ajax_url, { action: 'caig_get_study_plan', nonce: nonce, force_refresh: force ? 1 : 0 }, function (r) {
      if (!r.success) { $('#caig-plan-wrap').html(emptyHtml('📅', 'Could not load study plan', r.data && r.data.message ? r.data.message : 'Please try again.')); return; }
      renderPlan(r.data.plan);
    }).fail(function () {
      $('#caig-plan-wrap').html(emptyHtml('⚠️', 'Connection error', 'Please check your internet.'));
    });
  }

  $(document).on('click', '#caig-plan-refresh', function () {
    plan_loaded = true;
    loadPlan(true);
  });

  function renderPlan(plan) {
    var taskIcons = { mcq: '📝', vocab: '📖', lecture: '🎬', revision: '🔄' };
    var taskLabels = { mcq: 'MCQ Practice', vocab: 'Vocabulary', lecture: 'Watch Lecture', revision: 'Revision' };

    var html = '<div class="caig-plan-greeting">'
      + '<div class="caig-plan-greeting-text">💫 ' + escHtml(plan.greeting || '') + '</div>'
      + '<div class="caig-plan-meta">📅 ' + (plan.date || '') + ' &nbsp;·&nbsp; ⏱ ~' + (plan.estimated_hours || 0) + ' hours</div>'
      + '</div>';

    html += '<div class="caig-plan-tasks">';
    (plan.tasks || []).forEach(function (t) {
      var icon  = taskIcons[t.type]  || '📌';
      var label = taskLabels[t.type] || t.type;
      var subtitle = t.subject ? escHtml(t.subject) + (t.topic ? ' · ' + escHtml(t.topic) : '') : (t.deck ? escHtml(t.deck) : '');
      if (t.type === 'mcq' && t.count) subtitle += ' (' + t.count + ' Qs)';
      if (t.type === 'vocab' && t.count) subtitle += ' (' + t.count + ' words)';

      html += '<div class="caig-task-card">'
        + '<div class="caig-task-icon ' + t.type + '">' + icon + '</div>'
        + '<div>'
        + '<div class="caig-task-title">' + label + '</div>'
        + '<div class="caig-task-sub">' + subtitle + '</div>'
        + (t.why ? '<div class="caig-task-why">' + escHtml(t.why) + '</div>' : '')
        + '</div></div>';
    });
    html += '</div>';

    if (plan.focus_tip) {
      html += '<div class="caig-plan-footer">'
        + '<span style="font-size:18px;flex-shrink:0">💡</span>'
        + '<div><strong>Today\'s tip:</strong> ' + escHtml(plan.focus_tip) + '</div>'
        + '</div>';
    }
    $('#caig-plan-wrap').html(html);
  }

  /* ── Lectures ── */
  function loadLectures() {
    $('#caig-lec-wrap').html(skeletonHtml('lec') + skeletonHtml('lec') + skeletonHtml('lec'));
    $.post(ajax_url, { action: 'caig_get_lecture_recs', nonce: nonce }, function (r) {
      if (!r.success) { $('#caig-lec-wrap').html(emptyHtml('🎬', 'Could not load lectures', 'Please try again.')); return; }
      renderLectures(r.data.recommendations);
    }).fail(function () {
      $('#caig-lec-wrap').html(emptyHtml('⚠️', 'Connection error', ''));
    });
  }

  function renderLectures(recs) {
    if (!recs || !recs.length) {
      $('#caig-lec-wrap').html(
        '<div class="caig-lec-empty">'
        + '<div style="font-size:36px;margin-bottom:10px">🎬</div>'
        + '<div style="font-size:14px;font-weight:500;color:#6b7280;margin-bottom:6px">No lectures available yet</div>'
        + '<div style="font-size:13px;color:#9ca3af">Your admin needs to add lectures in CIAS Tests → Lecture Mgr.<br>They will be recommended based on your weak topics.</div>'
        + '</div>'
      );
      return;
    }
    var html = '<div class="caig-lec-grid">';
    recs.forEach(function (l) {
      var thumb = l.thumbnail
        ? '<img src="' + escHtml(l.thumbnail) + '" alt="' + escHtml(l.title) + '" loading="lazy">'
        : '<div style="display:flex;align-items:center;justify-content:center;height:110px;background:linear-gradient(135deg,#6C63FF20,#8B5CF620);font-size:36px">🎬</div>';

      html += '<div class="caig-lec-card">'
        + '<div class="caig-lec-thumb">' + thumb + '</div>'
        + '<div class="caig-lec-body">'
        + '<div class="caig-lec-subject">' + escHtml(l.subject) + '</div>'
        + '<div class="caig-lec-title">Lec ' + (l.lecture_number || '') + ': ' + escHtml(l.title) + '</div>'
        + '<div class="caig-lec-reason">' + escHtml(l.reason) + '</div>'
        + '<div class="caig-lec-footer">'
        + '<span class="caig-lec-dur">' + (l.duration_min ? '⏱ ' + l.duration_min + ' min' : '') + '</span>'
        + (l.url
          ? '<a href="' + escHtml(l.url) + '" target="_blank" class="caig-lec-play">▶ Watch</a>'
          : '<span style="font-size:11px;color:#9ca3af">No URL</span>')
        + '</div>'
        + '</div></div>';
    });
    html += '</div>';
    $('#caig-lec-wrap').html(html);
  }

  /* ── Rank Predictor ── */
  function loadRank(force) {
    $('#caig-rank-wrap').html(skeletonHtml('rank'));
    $.post(ajax_url, { action: 'caig_get_rank_prediction', nonce: nonce, force_refresh: force ? 1 : 0 }, function (r) {
      if (!r.success) {
        var msg = r.data && r.data.message ? r.data.message : 'Could not predict rank.';
        $('#caig-rank-wrap').html(
          '<div class="caig-rank-notready">'
          + '<div class="caig-rank-emoji">🏆</div>'
          + '<div style="font-size:14px;font-weight:500;color:#6b7280;margin-bottom:6px">' + escHtml(msg) + '</div>'
          + '<div style="font-size:12px;color:#9ca3af">Keep practising — more data = better prediction.</div>'
          + '</div>'
        );
        return;
      }
      renderRank(r.data.prediction, r.data.cached, r.data.predicted_at);
    }).fail(function () {
      $('#caig-rank-wrap').html(emptyHtml('⚠️', 'Connection error', ''));
    });
  }

  $(document).on('click', '#caig-rank-refresh', function () {
    rank_loaded = true;
    loadRank(true);
  });

  function renderRank(p, cached, ts) {
    var confColor = p.confidence >= 70 ? '#10b981' : (p.confidence >= 50 ? '#f59e0b' : '#ef4444');
    var low = p.prelims_low || 0;
    var high = p.prelims_high || 0;
    var midScore = Math.round((low + high) / 2);

    var html = '<div class="caig-rank-card">'
      + '<div class="caig-rank-hero">'
      + '<div class="caig-rank-label">Estimated Prelims Score</div>'
      + '<div class="caig-rank-score">' + midScore + '</div>'
      + '<div class="caig-rank-range">Range: ' + low + ' – ' + high + ' out of 200</div>'
      + '<span class="caig-rank-conf" style="background:' + confColor + '30;color:' + confColor + '">'
      + '🎯 ' + (p.confidence || 0) + '% confidence'
      + '</span>'
      + '</div>'
      + '<div class="caig-rank-body">';

    if (p.cutoff_comparison) {
      html += '<div class="caig-rank-cutoff">📊 <span>' + escHtml(p.cutoff_comparison) + '</span></div>';
    }

    if (p.key_factors && p.key_factors.length) {
      html += '<div class="caig-rank-section"><div class="caig-rank-section-title">Key factors</div>';
      p.key_factors.forEach(function (f) { html += '<div class="caig-rank-item">' + escHtml(f) + '</div>'; });
      html += '</div>';
    }
    if (p.improvement_areas && p.improvement_areas.length) {
      html += '<div class="caig-rank-section"><div class="caig-rank-section-title">To improve your score</div>';
      p.improvement_areas.forEach(function (a) { html += '<div class="caig-rank-item">' + escHtml(a) + '</div>'; });
      html += '</div>';
    }
    html += '<div class="caig-rank-disclaimer">'
      + (cached ? '⏱ Cached · Last updated: ' + (ts ? ts.substr(0,16) : '') + ' · ' : '')
      + escHtml(p.disclaimer || 'AI estimate based on practice data. Actual results may vary.')
      + '</div>';
    html += '</div></div>';

    $('#caig-rank-wrap').html(html);
  }

  /* ── Heatmap ── */
  function loadHeatmap() {
    $('#caig-heatmap-wrap').html(skeletonHtml('hmap'));
    $.post(ajax_url, { action: 'caig_get_heatmap', nonce: nonce }, function (r) {
      if (!r.success || !r.data.heatmap) { $('#caig-heatmap-wrap').html(emptyHtml('🔥', 'No data yet', 'Take some tests first!')); return; }
      renderHeatmap(r.data.heatmap);
    }).fail(function () {
      $('#caig-heatmap-wrap').html(emptyHtml('⚠️', 'Connection error', ''));
    });
  }

  function renderHeatmap(heatmap) {
    if (!heatmap.length) { $('#caig-heatmap-wrap').html(emptyHtml('🔥', 'No subject data yet', 'Complete some tests to build your heatmap.')); return; }

    var html = '<div class="caig-heatmap-grid">';
    heatmap.forEach(function (s) {
      var cls = 'caig-heat-' + s.confidence;
      html += '<div class="caig-heat-card ' + cls + '">'
        + '<div class="caig-heat-subject">' + escHtml(s.subject) + '</div>'
        + '<div class="caig-heat-pct">' + s.accuracy + '%</div>'
        + '<div class="caig-heat-label">' + escHtml(s.label) + '</div>'
        + '<div class="caig-heat-bar"><div class="caig-heat-bar-fill" style="width:' + Math.min(100, s.accuracy) + '%"></div></div>'
        + '</div>';
    });
    html += '</div>';

    html += '<div class="caig-heatmap-legend">'
      + '<span class="caig-legend-item"><span class="caig-legend-dot" style="background:#10b981"></span>Strong (80%+)</span>'
      + '<span class="caig-legend-item"><span class="caig-legend-dot" style="background:#f59e0b"></span>Moderate (60–79%)</span>'
      + '<span class="caig-legend-item"><span class="caig-legend-dot" style="background:#f97316"></span>Needs Work (40–59%)</span>'
      + '<span class="caig-legend-item"><span class="caig-legend-dot" style="background:#ef4444"></span>Critical (&lt;40%)</span>'
      + '</div>';

    $('#caig-heatmap-wrap').html(html);
  }

  /* ── Helpers ── */
  function skeletonHtml(type) {
    return '<div class="caig-skeleton caig-sk-' + type + '"></div>';
  }
  function emptyHtml(icon, title, text) {
    return '<div class="caig-empty"><div class="caig-empty-icon">' + icon + '</div>'
      + '<div class="caig-empty-title">' + title + '</div>'
      + '<div class="caig-empty-text">' + escHtml(text) + '</div></div>';
  }

  return { init: init };

}(jQuery));
