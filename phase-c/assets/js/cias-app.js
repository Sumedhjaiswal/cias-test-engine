/**
 * CIAS App – cias-app.js
 * Bootstrapped from window.ciasApp (set by PHP shortcode).
 * Uses Phase B REST endpoints where available; AJAX fallback otherwise.
 */

var CIASApp = (function () {
  'use strict';

  // ── Dependency guards ──────────────────────────────────────────────────
  // If core/api.js or chat.js failed to load (404 on server),
  // stubs ensure CIASApp always initialises and tabs always work.
  if (typeof CIAS_API === 'undefined') {
    console.error('[CIAS] core/api.js not loaded. Check core/ folder exists on server.');
    window.CIAS_API = {
      init: function(){},
      restGet:  function(p,cb){ if(cb) cb({success:false,error:{code:'api_missing',message:'API module not loaded.'}}); },
      restPost: function(p,b,cb){ if(cb) cb({success:false,error:{code:'api_missing',message:'API module not loaded.'}}); },
      ajaxPost: function(a,d,cb){ if(cb) cb({success:false}); },
      logError: function(m,msg){ console.error('[CIAS:'+m+']', msg); },
      handleAuthFailure: function(){},
    };
  }
  if (typeof CIASChat === 'undefined') {
    console.error('[CIAS] chat.js not loaded. Guru chat unavailable.');
    window.CIASChat = {
      init:function(){}, sendMsg:function(){}, trigImg:function(){},
      rmImg:function(){}, onFile:function(){}, fillQ:function(){},
      autoRes:function(t){ if(t){t.style.height='auto';t.style.height=Math.min(t.scrollHeight,80)+'px';} },
      confirmOCR:function(){}, rejectOCR:function(){},
      pollJob:function(id,cb){ if(cb) cb(null); },
      pollJobLive:function(){}, appendBotMsg:function(){},
    };
  }


  /* ── State ──────────────────────────────────────────────── */
  var D = window.ciasApp || {};
  var currentTab   = 'home';
  var vocabMode    = 'flash';
  var cardIdx      = 0;
  var cardFlipped  = false;
  var dueWords     = [];
  var imgData      = null;
  var imgFile      = null;
  var currentJobId = null;
  var pollTimer    = null;
  var sessionId    = '';

  /* ── REST / AJAX helpers ─────────────────────────────────── */
  /* ── API — delegates to CIAS_API module (core/api.js) ──────── */
  // All REST/AJAX transport, nonce handling, timeouts,
  // auth failure detection, and logging live in core/api.js
  function restGet(path, cb)        { CIAS_API.restGet(path, cb); }
  function restPost(path, body, cb) { CIAS_API.restPost(path, body, cb); }
  function ajaxPost(action, data, cb){ CIAS_API.ajaxPost(action, data, cb); }

  /* ── Boot ─────────────────────────────────────────────────── */
  function boot() {
    if (!D.user) return;
    console.log('[CIAS] app version 3.21.0 loaded');
    sessionId = 'ses_' + D.user.id + '_' + Date.now().toString(36);

    // ── Init API + Chat modules FIRST (before any render calls) ────────────
    CIAS_API.init(D);

    CIASChat.init({
      data:      D,
      el:        el,
      esc:       esc,
      nowTime:   nowTime,
      setText:   setText,
      goTab:     goTab,
      sessionId: sessionId,
    });

    populateTopbar();
    populateHome();
    populateVocab();
    populateTests();
    populateProgress();
    populateProfile();
    populateGuruStats();
    populateHeatmap();
    populateRank();
    renderInitialChat();

    goTab('home');
  }

  /* ── Topbar ───────────────────────────────────────────────── */
  function populateTopbar() {
    setText('hdr-cr-num', D.credits.remaining);
    setText('hdr-avatar', D.user.initials);
  }

  /* ── Home ─────────────────────────────────────────────────── */
  function populateHome() {
    var hour = new Date().getHours();
    var greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    setText('home-greeting', greet + ', ' + D.user.name + ' \uD83D\uDC4B');
    setText('home-sub', (D.due_today.length) + ' words due · ' + D.stats.tests_taken + ' tests · ' + D.stats.avg_score + '% accuracy');
    setText('st-words', D.stats.words_mastered);
    setText('st-acc', D.stats.avg_score + '%');
    setText('st-tests', D.stats.tests_taken);
    setText('st-streak', D.streak.current);
    setText('vocab-due-badge', D.due_today.length);
    // Wire up the new action card labels
    var streakEl = document.getElementById('act-streak-lbl');
    if (streakEl) streakEl.textContent = (D.streak.current || 0) + ' day';
    var rankEl = document.getElementById('act-rank-lbl');
    if (rankEl && D.rank && D.rank.rank) rankEl.textContent = '#' + D.rank.rank;
    else if (rankEl) rankEl.style.display = 'none';

    // ── Due Today: all three sections ───────────────────────
    populateDueVocab();
    populateDueTests();
    populateDueRevisions();

    // ── Today's Study Plan ───────────────────────────────────
    populateStudyPlanToday();

    // ── Update home subtitle with total due count ────────────
    var totalDue = (D.due_today ? D.due_today.length : 0)
                 + (D.due_tests ? D.due_tests.length : 0)
                 + (D.due_revisions ? D.due_revisions.length : 0);
    setText('home-sub', totalDue + ' item' + (totalDue !== 1 ? 's' : '') + ' due today · ' + D.stats.avg_score + '% accuracy');
  }

  // ── Due Today helpers ────────────────────────────────────────────────────────

  function dueBadgeHtml(tag, label) {
    var map = {
      hard:      'background:#fee2e2;color:#991b1b',
      weak:      'background:#fce7f3;color:#9d174d',
      weak_area: 'background:#fce7f3;color:#9d174d',
      review:    'background:#f5f3ff;color:#5b21b6',
      assigned:  'background:#fef3c7;color:#92400e',
      new:       'background:#dcfce7;color:#166534',
    };
    var style = map[tag] || map['review'];
    return '<span class="ca-due-tag" style="' + style + '">' + esc(label) + '</span>';
  }

  function dueItemHtml(iconClass, iconBg, iconFg, name, why, tag, tagLabel, onclick) {
    return '<div class="ca-due-item" onclick="' + onclick + '" role="button" tabindex="0">' +
      '<div class="ca-due-icon" style="background:' + iconBg + '">' +
      '<i class="ti ' + iconClass + '" style="color:' + iconFg + ';font-size:16px" aria-hidden="true"></i></div>' +
      '<div class="ca-due-main">' +
      '<div class="ca-due-name">' + esc(name) + '</div>' +
      '<div class="ca-due-why">' + esc(why) + '</div></div>' +
      dueBadgeHtml(tag, tagLabel) + '</div>';
  }

  function populateDueVocab() {
    var words = D.due_today || [];
    var cnt   = el('due-vocab-cnt');
    var list  = el('due-vocab-list');
    var more  = el('due-vocab-more');
    var group = el('due-vocab-group');
    if (!list) return;

    if (cnt) cnt.textContent = words.length + ' word' + (words.length !== 1 ? 's' : '');

    if (words.length === 0) {
      list.innerHTML = '<div class="ca-due-empty">All caught up on vocabulary ✓</div>';
      return;
    }

    var tagMap  = { hard: 'hard', review: 'review', easy: 'new' };
    var lblMap  = { hard: 'Hard', review: 'Review', easy: 'New' };
    var html = '';
    words.slice(0, 2).forEach(function(w) {
      var tag = tagMap[w.difficulty] || 'review';
      var lbl = lblMap[w.difficulty] || 'Review';
      var why = w.difficulty === 'hard'
        ? 'Needs extra attention · ease factor low'
        : (w.difficulty === 'easy' ? 'First review today' : 'Due for spaced repetition');
      html += dueItemHtml('ti-book-2', '#f5f3ff', '#7c3aed',
        w.word, why, tag, lbl, "CIASApp.goTab('vocab')");
    });
    list.innerHTML = html;

    if (words.length > 2 && more) {
      more.style.display = 'block';
      more.textContent = '+ ' + (words.length - 2) + ' more words · Start session →';
    }
  }

  function populateDueTests() {
    var tests = D.due_tests || [];
    var group = el('due-tests-group');
    var cnt   = el('due-tests-cnt');
    var list  = el('due-tests-list');
    if (!group) return;

    if (tests.length === 0) { group.style.display = 'none'; return; }
    group.style.display = 'block';
    if (cnt) cnt.textContent = tests.length + ' pending';

    var html = '';
    tests.slice(0, 2).forEach(function(t) {
      var q   = (t.q_count || 20) + ' Qs';
      var time = t.time_limit ? ' · ' + t.time_limit + ' min' : '';
      var why = esc(t.subject_name || 'General') + ' · ' + q + time;
      html += dueItemHtml('ti-clipboard-list', '#fff7ed', '#e8431a',
        t.title, why, t.tag || 'assigned', t.tag_label || 'Assigned',
        "CIASApp.goTab('tests')");
    });
    list.innerHTML = html;
  }

  function populateDueRevisions() {
    var revs  = D.due_revisions || [];
    var group = el('due-revisions-group');
    var cnt   = el('due-revisions-cnt');
    var list  = el('due-revisions-list');
    if (!group) return;

    if (revs.length === 0) { group.style.display = 'none'; return; }
    group.style.display = 'block';
    if (cnt) cnt.textContent = revs.length + ' topic' + (revs.length !== 1 ? 's' : '');

    var html = '';
    revs.slice(0, 2).forEach(function(r) {
      var name = (r.subject_name || 'Subject') + (r.topic_name ? ' · ' + r.topic_name : '');
      html += dueItemHtml('ti-refresh', '#ecfdf5', '#059669',
        name, r.reason || 'Due for revision', r.tag || 'review', r.tag_label || 'Review',
        "CIASApp.goTab('tutor')");
    });
    list.innerHTML = html;
  }

  function populateStudyPlanToday() {
    var plan  = D.study_plan_today;
    var card  = el('plan-today-card');
    var tasks = el('plan-today-tasks');
    var quote = el('plan-today-quote');
    var hrs   = el('plan-today-hrs');
    var sub   = el('plan-today-sub');
    if (!card || !plan) return;

    if (quote) quote.textContent = plan.motivation || '';
    if (hrs)   hrs.textContent   = (plan.total_hrs || '--') + ' hrs';
    if (sub)   sub.textContent   = plan.generated === 'ai'
      ? 'AI · personalised for you' : 'Based on your performance data';

    if (!tasks || !plan.tasks) return;
    var html = '';
    (plan.tasks || []).forEach(function(t) {
      html += '<div class="ca-plan-task-row">' +
        '<div class="ca-plan-task-icon" style="background:' + (t.icon_bg||'#f3f4f6') + '">' +
        '<i class="ti ' + (t.icon||'ti-check') + '" style="color:' + (t.icon_fg||'#374151') + ';font-size:15px" aria-hidden="true"></i></div>' +
        '<div class="ca-plan-task-body">' +
        '<div class="ca-plan-task-name">' + esc(t.name) + '</div>' +
        '<div class="ca-plan-task-why">' + esc(t.why) + '</div></div>' +
        '<span class="ca-plan-task-count">' + esc(t.count) + '</span>' +
        '</div>';
    });
    tasks.innerHTML = html;
  }

  /* ── Vocab ─────────────────────────────────────────────────── */
  function populateVocab() {
    dueWords = D.due_today || [];
    setText('vocab-greeting', 'Hi, ' + D.user.name + ' \uD83D\uDC4B');
    setText('vocab-streak', '\uD83D\uDD25 ' + D.streak.current + ' day streak');
    setText('voc-due', dueWords.length);
    setText('voc-due2', dueWords.length);
    setText('voc-mastered', D.stats.words_mastered);
  }

  function selectMode(m) {
    vocabMode = m;
    toggleClass('mode-flash', 'active', m === 'flash');
    toggleClass('mode-quiz',  'active', m === 'quiz');
    attr('mode-flash', 'aria-pressed', m === 'flash' ? 'true' : 'false');
    attr('mode-quiz',  'aria-pressed', m === 'quiz'  ? 'true' : 'false');
  }

  function startVocabSession() {
    if (D.vocab_bridge) {
      // Bridge active → fetch fresh live words from vocab plugin via AJAX
      ajaxPost(D.vocab_session_action || 'cias_vocab_session', {}, function (res) {
        if (res && res.success && res.data && res.data.words && res.data.words.length) {
          dueWords = res.data.words;
        }
        // If AJAX fails or returns empty, fall back to bootstrap data
        if (!dueWords.length) dueWords = D.due_today || [];
        if (!dueWords.length) { alert('No words due today!'); return; }
        cardIdx = 0; cardFlipped = false;
        hide('vocab-landing');
        show('vocab-session');
        loadCard();
      });
    } else {
      if (!dueWords.length) { alert('No words due today!'); return; }
      cardIdx = 0; cardFlipped = false;
      hide('vocab-landing');
      show('vocab-session');
      loadCard();
    }
  }

  function endSession() {
    show('vocab-landing');
    hide('vocab-session');
  }

  function loadCard() {
    var w = dueWords[cardIdx];
    if (!w) { endSession(); return; }

    // Route to appropriate mode
    if (vocabMode === 'quiz') {
      // Show flashcard container (needed for prog bar) but hide the card itself
      var flashcard = el('flashcard');
      if (flashcard) flashcard.style.display = 'none';
      loadQuizCard();
      return;
    }

    // Flashcard mode (default)
    var flashcard = el('flashcard');
    if (flashcard) flashcard.style.display = '';
    var qwrap = el('quiz-wrap');
    if (qwrap) qwrap.style.display = 'none';
    setText('fc-word', w.word);
    setText('fc-tag',  w.part_of_speech || 'Word');
    setText('fc-def',  w.definition || '');
    setText('sess-count', 'Card ' + (cardIdx + 1) + ' of ' + dueWords.length);
    el('fc-prog').style.width = Math.round((cardIdx / dueWords.length) * 100) + '%';
    removeClass('fc-def', 'show');
    show('fc-hint');
    hide('card-actions');
    cardFlipped = false;
  }

  function flipCard() {
    if (cardFlipped) return;
    cardFlipped = true;
    hide('fc-hint');
    addClass('fc-def', 'show');
    show('card-actions');
  }

  // ── Quiz mode: generate 4-option MCQ from word list ────────────────────────
  function loadQuizCard() {
    var w = dueWords[cardIdx];
    if (!w) { endSession(); return; }

    // Pick 3 wrong answers from other words
    var others = dueWords.filter(function(_, i) { return i !== cardIdx; });
    var wrongs  = [];
    var shuffled = others.slice().sort(function() { return Math.random() - 0.5; });
    for (var i = 0; i < shuffled.length && wrongs.length < 3; i++) {
      if (shuffled[i].definition && shuffled[i].definition !== w.definition) {
        wrongs.push(shuffled[i].definition);
      }
    }
    // Pad wrongs if not enough words
    var fallbackDefs = ['A formal agreement between parties','The process of official declaration','An action taken to resolve a dispute','A method of systematic organization'];
    while (wrongs.length < 3) wrongs.push(fallbackDefs[wrongs.length]);

    // Build 4 options (correct + 3 wrong) and shuffle
    var opts = [{ text: w.definition, correct: true }].concat(
      wrongs.map(function(d) { return { text: d, correct: false }; })
    ).sort(function() { return Math.random() - 0.5; });

    var letters = ['A', 'B', 'C', 'D'];
    var sessEl = el('vocab-session');
    var wrap = el('quiz-wrap');
    if (!wrap) {
      // Build quiz UI dynamically if not present
      wrap = document.createElement('div');
      wrap.id = 'quiz-wrap';
      wrap.className = 'ca-quiz-wrap';
      sessEl.appendChild(wrap);
    }

    // Update progress bar
    setText('sess-count', 'Q ' + (cardIdx + 1) + ' of ' + dueWords.length);
    el('fc-prog').style.width = Math.round((cardIdx / dueWords.length) * 100) + '%';

    var html = '<div class="ca-quiz-question">What is the meaning of<br><strong style="font-size:16px;color:#6c63ff">' +
      esc(w.word) + '</strong>' +
      (w.part_of_speech ? '<br><span style="font-size:11px;font-weight:400;color:#9ca3af">' + esc(w.part_of_speech) + '</span>' : '') +
      '</div>' +
      '<div class="ca-quiz-opts">';

    opts.forEach(function(opt, i) {
      html += '<button class="ca-quiz-opt" onclick="CIASApp.quizAnswer(this,' + opt.correct + ',' + cardIdx + ')" ' +
        'data-correct="' + opt.correct + '">' +
        '<span class="ca-quiz-opt-letter">' + letters[i] + '</span>' +
        '<span>' + esc(opt.text.substring(0, 90)) + '</span>' +
        '</button>';
    });

    html += '</div><div class="ca-quiz-result" id="quiz-result"></div>';
    wrap.innerHTML = html;

    // Hide flashcard, show quiz wrap
    var flashcard = el('flashcard');
    if (flashcard) flashcard.style.display = 'none';
    hide('fc-hint');
    hide('card-actions');
    wrap.style.display = 'block';
  }

  function quizAnswer(btn, isCorrect, wordIdx) {
    // Disable all buttons
    var opts = btn.closest('.ca-quiz-opts').querySelectorAll('.ca-quiz-opt');
    opts.forEach(function(b) {
      b.disabled = true;
      if (b.dataset.correct === 'true') b.classList.add('reveal');
    });
    btn.classList.add(isCorrect ? 'correct' : 'wrong');

    var result = el('quiz-result');
    if (result) {
      result.className = 'ca-quiz-result ' + (isCorrect ? 'correct-msg' : 'wrong-msg');
      result.textContent = isCorrect ? '✓ Correct!' : '✗ Incorrect — the highlighted answer is correct';
    }

    // Auto-advance after 1.5s
    setTimeout(function() {
      var rating = isCorrect ? 'good' : 'hard';
      nextCard(rating);
    }, 1600);
  }

  function nextCard(rating) {
    var w = dueWords[cardIdx];
    if (w && w.id) {
      // Use bridge action if active, otherwise CIAS own handler
      var action = D.vocab_rate_action || 'cias_vocab_rate';
      ajaxPost(action, { word_id: w.id, rating: rating }, function (res) {
        if (res && res.success && D.vocab_bridge) {
          // Refresh due count badge from bridge stats
          ajaxPost(D.vocab_stats_action || 'cias_vocab_stats', {}, function (sr) {
            if (sr && sr.success && sr.data) {
              var due = sr.data.due_today || 0;
              setText('voc-due', due);
              setText('voc-due2', due);
              setText('vocab-due-badge', due);
              D.stats.words_mastered = sr.data.mastered || D.stats.words_mastered;
              setText('st-words', D.stats.words_mastered);
              setText('pg-words', D.stats.words_mastered);
              setText('voc-mastered', sr.data.mastered || 0);
            }
          });
        }
      });
    }
    cardIdx++;
    if (cardIdx >= dueWords.length) {
      endSession();
      alert('Session complete! Great work, ' + D.user.name + '!');
      return;
    }
    cardFlipped = false;
    loadCard();
  }

  /* ═══════════════════════════════════════════════════════════
     TESTS — Real-time list, exam engine, timer, auto-save
  ═══════════════════════════════════════════════════════════ */
  var allTests      = [];
  var currentFilter = 'all';
  var examState     = null;
  var examTimer     = null;
  var examSaveQ     = null; // debounce save timer

  // ── Load test list from real API ────────────────────────────
  function populateTests() { loadTests(); }

  function loadTests() {
    var list = el('test-list');
    if (!list) return;
    list.innerHTML = '<div style="display:flex;align-items:center;gap:10px;padding:20px 14px"><div class="ca-typing"><span></span><span></span><span></span></div><span style="font-size:13px;color:#9ca3af">Loading tests...</span></div>';
    ajaxPost('cias_get_tests', {}, function(res) {
      if (res && res.success) {
        if (res.data.tests && res.data.tests.length) {
          allTests = res.data.tests;
          buildFilterChips();
          renderTestList(allTests);
        } else if (res.data.html) {
          list.innerHTML = res.data.html;
        } else {
          list.innerHTML = testEmptyHtml('No tests assigned to your batch yet.');
        }
      } else {
        list.innerHTML = testEmptyHtml('Could not load tests. Tap the refresh button to try again.');
      }
    });
  }

  function testEmptyHtml(msg) {
    return '<div style="text-align:center;padding:40px 20px;color:#9ca3af"><div style="font-size:40px;margin-bottom:10px">📋</div><p style="font-size:14px;font-weight:500;color:#374151;margin-bottom:6px">No tests found</p><p style="font-size:13px">' + msg + '</p></div>';
  }

  function buildFilterChips() {
    var subjects = {};
    allTests.forEach(function(t) { if (t.subject_name) subjects[t.subject_name] = true; });
    var chips = el('tests-filter-chips');
    if (!chips) return;
    var subBtns = Object.keys(subjects).slice(0, 3).map(function(s) {
      return '<button class="ca-fc-chip" data-filter="subj:' + esc(s) + '" onclick="CIASApp.filterTests(this)">' + esc(s) + '</button>';
    }).join('');
    chips.innerHTML =
      '<button class="ca-fc-chip active" data-filter="all"       onclick="CIASApp.filterTests(this)">All</button>' +
      '<button class="ca-fc-chip"        data-filter="available" onclick="CIASApp.filterTests(this)">Available</button>' +
      '<button class="ca-fc-chip"        data-filter="completed" onclick="CIASApp.filterTests(this)">Completed</button>' +
      '<button class="ca-fc-chip"        data-filter="upcoming"  onclick="CIASApp.filterTests(this)">Upcoming</button>' +
      subBtns;
  }

  function filterTests(btn) {
    btn.closest('.ca-filter-chips').querySelectorAll('.ca-fc-chip').forEach(function(c) { c.classList.remove('active'); });
    btn.classList.add('active');
    currentFilter = btn.dataset.filter || 'all';
    var f = currentFilter;
    var filtered = allTests.filter(function(t) {
      if (f === 'all')       return true;
      if (f === 'available') return t.status === 'available' || t.status === 'in_progress';
      if (f === 'completed') return t.status === 'completed';
      if (f === 'upcoming')  return t.status === 'upcoming';
      if (f.startsWith('subj:')) return t.subject_name === f.slice(5);
      return true;
    });
    renderTestList(filtered);
  }

  function selFilter(btn) { filterTests(btn); }

  function renderTestList(tests) {
    var list = el('test-list');
    if (!list) return;
    if (!tests || !tests.length) {
      list.innerHTML = testEmptyHtml('No tests in this category.');
      return;
    }
    var html = '';
    tests.forEach(function(t) {
      var col    = t.subject_color || '#6C63FF';
      var subj   = esc(t.subject_name || 'General');
      var title  = esc(t.title);
      var qs     = (t.q_count || 0) + ' Qs';
      var tim    = t.time_limit ? t.time_limit + ' min' : 'No limit';
      var desc   = t.description ? '<div class="ca-test-desc">' + esc(t.description) + '</div>' : '';

      var badge = '', btn = '';
      if (t.status === 'completed') {
        var pct = t.score !== null ? Math.round(t.score) : '--';
        var passed = t.score !== null && t.score >= (t.pass_mark || 60);
        badge = '<span class="ca-test-badge" style="background:' + (passed?'#dcfce7':'#fee2e2') + ';color:' + (passed?'#166534':'#991b1b') + '">' + pct + '% · ' + (passed?'Pass':'Fail') + '</span>';
        btn   = '<button class="ca-btn-review-test" onclick="CIASApp.reviewTest(' + t.id + ')">Review Answers</button>';
      } else if (t.status === 'upcoming') {
        badge = '<span class="ca-test-badge" style="background:#f3f4f6;color:#6b7280">⏰ Upcoming</span>';
        btn   = '<button class="ca-btn-start-test" disabled style="opacity:.5;cursor:not-allowed">Not yet available</button>';
      } else if (t.status === 'in_progress') {
        badge = '<span class="ca-test-badge" style="background:#dbeafe;color:#1d4ed8">🔄 In Progress</span>';
        btn   = '<button class="ca-btn-start-test" onclick="CIASApp.startTest(' + t.id + ',' + (t.has_pin?'true':'false') + ')">Continue →</button>';
      } else {
        badge = '<span class="ca-test-badge" style="background:#dcfce7;color:#166534">🟢 Available</span>';
        btn   = t.has_pin
          ? '<button class="ca-btn-start-test" onclick="CIASApp.startTest(' + t.id + ',true)">🔐 Enter PIN to Start</button>'
          : '<button class="ca-btn-start-test" onclick="CIASApp.startTest(' + t.id + ',false)">Start Test →</button>';
      }
      var modeBadge = t.test_mode === 'offline' ? '<span class="ca-test-badge" style="background:#fef3c7;color:#92400e;margin-left:4px">📝 Classroom</span>' : '';

      html += '<div class="ca-test-card">' +
        '<div class="ca-test-card-top">' +
        '<span class="ca-ts-pill" style="background:' + col + '18;color:' + col + ';border:1px solid ' + col + '40">' + subj + '</span>' +
        badge + modeBadge + '</div>' +
        '<div class="ca-test-title">' + title + '</div>' + desc +
        '<div class="ca-test-meta">' +
        '<span>❓ ' + qs + '</span><span>⏱ ' + tim + '</span><span>🎯 Pass: ' + (t.pass_mark || 60) + '%</span>' +
        '</div>' + btn + '</div>';
    });
    list.innerHTML = html;
  }

  // ── Start Test ──────────────────────────────────────────────
  function startTest(testId, requirePin) {
    if (requirePin) {
      var pin = prompt('Enter the PIN to start this test:');
      if (!pin) return;
      ajaxPost('cias_verify_pin', { test_id: testId, pin: pin }, function(res) {
        if (res && res.success) beginExam(testId);
        else alert((res && res.data && res.data.message) || 'Invalid PIN. Please try again.');
      });
    } else {
      beginExam(testId);
    }
  }

  function beginExam(testId) {
    var overlay = el('exam-submit-overlay');
    if (overlay) overlay.style.display = 'none';
    goTab('exam');
    var body = el('exam-body');
    if (body) body.innerHTML = '<div style="text-align:center;padding:30px"><div class="ca-typing"><span></span><span></span><span></span></div><p style="color:#9ca3af;font-size:13px;margin-top:10px">Loading questions...</p></div>';

    ajaxPost('cias_start_test', { test_id: testId }, function(res) {
      if (!res || !res.success) {
        alert((res && res.data && res.data.message) || 'Could not start test. Please try again.');
        goTab('tests');
        return;
      }
      var d = res.data;
      // Validate questions array
      if (!d.questions || !d.questions.length) {
        alert('This test has no questions yet. Please ask your teacher to add questions.');
        goTab('tests');
        return;
      }
      examState = {
        testId:      testId,
        attemptId:   d.attempt_id,
        title:       d.test_title,
        questions:   d.questions,
        saved:       d.saved || {},
        current:     0,
        timeLeft:    d.time_limit > 0 ? d.time_limit * 60 : null,
        timeLimitMin:d.time_limit,
        passmark:    d.pass_mark || 60,
      };
      if (el('exam-title')) {
        setText('exam-title', d.test_title);
        buildQNav();
        renderQuestion(0);
        startExamTimer();
      } else {
        // Exam screen not found — redirect to portal page
        CIAS_API.logError('beginExam', 'Exam screen elements not found in DOM', 'error');
        alert('Please use the app at vocabulary-practice page to take tests.');
        goTab('tests');
      }
    });
  }

  // ── Question render ─────────────────────────────────────────
  function renderQuestion(idx) {
    if (!examState || !examState.questions || !examState.questions[idx]) return;
    examState.current = idx;
    var q    = examState.questions[idx];
    var tot  = examState.questions.length;
    var saved= examState.saved;
    var prog = el('exam-prog');
    var qtext = el('exam-qtext');

    setText('exam-qnum', 'Q' + (idx + 1) + ' of ' + tot);
    if (prog) prog.style.width = Math.round((idx / tot) * 100) + '%';

    // Question text
    if (qtext) qtext.textContent = q.question_text || '';

    // Statements (for statement-type questions)
    var stmtsEl = el('exam-statements');
    var stmts = q.statements ? JSON.parse(q.statements) : [];
    if (stmts && stmts.length) {
      stmtsEl.style.display = 'block';
      stmtsEl.innerHTML = stmts.map(function(s, i) {
        return '<div class="ca-exam-stmt"><span class="ca-exam-stmt-num">' + (i+1) + '</span><span>' + esc(s) + '</span></div>';
      }).join('');
    } else {
      stmtsEl.style.display = 'none';
    }

    // Options
    var opts    = ['a','b','c','d'];
    var optTxts = [q.option_a, q.option_b, q.option_c, q.option_d];
    var selected = saved[q.id] || null;
    el('exam-opts').innerHTML = opts.map(function(letter, i) {
      var isSelected = selected === letter;
      return '<button class="ca-exam-opt' + (isSelected ? ' ca-exam-opt--selected' : '') + '" ' +
        'onclick="CIASApp.selectOpt(this,\'' + letter + '\',' + q.id + ')" ' +
        'data-letter="' + letter + '">' +
        '<span class="ca-exam-opt-letter">' + letter.toUpperCase() + '</span>' +
        '<span>' + esc(optTxts[i] || '') + '</span>' +
        '</button>';
    }).join('');

    // Prev/Next buttons
    el('exam-prev').disabled = idx === 0;
    var nextBtn = el('exam-next');
    if (nextBtn) nextBtn.textContent = idx === tot - 1 ? 'Review' : 'Next ›';

    // Highlight current Q in nav
    var navBtns = el('exam-qnav') ? el('exam-qnav').querySelectorAll('.ca-qnav-btn') : [];
    navBtns.forEach(function(b, i) {
      b.classList.toggle('ca-qnav-btn--current', i === idx);
    });
  }

  function buildQNav() {
    if (!examState) return;
    var nav = el('exam-qnav');
    if (!nav) return;
    nav.innerHTML = examState.questions.map(function(q, i) {
      var answered = !!examState.saved[q.id];
      return '<button class="ca-qnav-btn' + (answered?' ca-qnav-btn--answered':'') + '" ' +
        'onclick="CIASApp.goToQ(' + i + ')">' + (i + 1) + '</button>';
    }).join('');
  }

  function goToQ(idx) { renderQuestion(idx); }

  function examNav(dir) {
    if (!examState) return;
    var next = examState.current + dir;
    if (next < 0) return;
    if (next >= examState.questions.length) { confirmSubmit(); return; }
    renderQuestion(next);
  }

  function selectOpt(btn, letter, qId) {
    // Visual update
    el('exam-opts').querySelectorAll('.ca-exam-opt').forEach(function(b) { b.classList.remove('ca-exam-opt--selected'); });
    btn.classList.add('ca-exam-opt--selected');

    // Update saved state
    examState.saved[qId] = letter;

    // Update nav pill
    var navBtns = el('exam-qnav') ? el('exam-qnav').querySelectorAll('.ca-qnav-btn') : [];
    if (navBtns[examState.current]) navBtns[examState.current].classList.add('ca-qnav-btn--answered');

    // Debounced auto-save
    clearTimeout(examSaveQ);
    examSaveQ = setTimeout(function() {
      ajaxPost('cias_save_answer', { attempt_id: examState.attemptId, question_id: qId, selected: letter }, function() {});
    }, 600);
  }

  // ── Timer ───────────────────────────────────────────────────
  function startExamTimer() {
    clearInterval(examTimer);
    if (!examState || examState.timeLeft === null) {
      setText('exam-timer', '∞');
      return;
    }
    examTimer = setInterval(function() {
      if (!examState) { clearInterval(examTimer); return; }
      examState.timeLeft--;
      if (examState.timeLeft <= 0) {
        clearInterval(examTimer);
        setText('exam-timer', '0:00');
        alert('Time is up! Your test will be submitted now.');
        submitExam();
        return;
      }
      var m = Math.floor(examState.timeLeft / 60);
      var s = examState.timeLeft % 60;
      var display = m + ':' + (s < 10 ? '0' : '') + s;
      setText('exam-timer', display);
      // Warn when < 5 min
      var timerEl = el('exam-timer');
      if (timerEl) timerEl.style.color = examState.timeLeft < 300 ? '#dc2626' : '#fff';
    }, 1000);
  }

  // ── Submit ──────────────────────────────────────────────────
  function confirmSubmit() {
    if (!examState) return;
    var answered  = Object.keys(examState.saved).length;
    var total     = examState.questions.length;
    var unanswered= total - answered;
    var overlay   = el('exam-submit-overlay');
    el('exam-confirm-msg').textContent =
      'You have answered ' + answered + ' of ' + total + ' questions.' +
      (unanswered > 0 ? ' ' + unanswered + ' question' + (unanswered > 1 ? 's' : '') + ' left blank.' : ' All done!');
    if (overlay) { overlay.style.display = 'flex'; }
  }

  function closeConfirm() {
    var overlay = el('exam-submit-overlay');
    if (overlay) overlay.style.display = 'none';
  }

  function submitExam() {
    clearInterval(examTimer);
    closeConfirm();
    if (!examState) return;

    var submitBtn = el('exam-submit-hdr');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting...'; }

    ajaxPost('cias_submit_test', { attempt_id: examState.attemptId }, function(res) {
      examState = null;
      if (res && res.success) {
        showResults(res.data);
      } else {
        alert('Error submitting test. Please try again.');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit'; }
      }
    });
  }

  // ── Results ─────────────────────────────────────────────────
  function showResults(data) {
    var score   = data.score || 0;
    var total   = data.total || 0;
    var pct     = data.percentage || 0;
    var passed  = data.passed;
    var timeTaken = data.time_taken || 0;
    var m       = Math.floor(timeTaken / 60);
    var s       = timeTaken % 60;

    goTab('results');
    var wrap = el('results-wrap');
    if (!wrap) return;

    wrap.innerHTML =
      '<div class="ca-results-hero ' + (passed ? 'ca-results-pass' : 'ca-results-fail') + '">' +
      '<div class="ca-results-icon">' + (passed ? '🎉' : '📚') + '</div>' +
      '<div class="ca-results-title">' + (passed ? 'Well Done!' : 'Keep Practising!') + '</div>' +
      '<div class="ca-results-score">' + score + '<span>/' + total + '</span></div>' +
      '<div class="ca-results-pct">' + Math.round(pct) + '%</div>' +
      '</div>' +
      '<div class="ca-results-stats">' +
      '<div class="ca-rs-card ca-rs-green"><div class="ca-rs-val">' + score + '</div><div class="ca-rs-lbl">Correct</div></div>' +
      '<div class="ca-rs-card ca-rs-red"><div class="ca-rs-val">' + (total - score) + '</div><div class="ca-rs-lbl">Wrong</div></div>' +
      '<div class="ca-rs-card ca-rs-blue"><div class="ca-rs-val">' + m + ':' + (s < 10 ? '0' : '') + s + '</div><div class="ca-rs-lbl">Time</div></div>' +
      '<div class="ca-rs-card ' + (passed ? 'ca-rs-green' : 'ca-rs-red') + '"><div class="ca-rs-val">' + (passed ? 'Pass' : 'Fail') + '</div><div class="ca-rs-lbl">Result</div></div>' +
      '</div>' +
      '<div style="padding:16px 14px;display:flex;gap:10px">' +
      '<button class="ca-btn-start-test" style="flex:1" onclick="CIASApp.reviewTest(' + (data.attempt_id || 0) + ')">View Answer Key</button>' +
      '<button class="ca-btn-review-test" style="flex:1" onclick="CIASApp.goTab(\x27tests\x27);CIASApp.loadTests()">Back to Tests</button>' +
      '</div>';

    // Refresh test list in background
    setTimeout(function() { ajaxPost('cias_get_tests', {}, function(res) { if (res && res.success && res.data.tests) { allTests = res.data.tests; } }); }, 1000);
  }

  // ── Review answers ──────────────────────────────────────────
  function reviewTest(attemptId) {
    if (!attemptId) return;
    goTab('results');
    var wrap = el('results-wrap');
    if (wrap) wrap.innerHTML = '<div style="text-align:center;padding:30px"><div class="ca-typing"><span></span><span></span><span></span></div><p style="color:#9ca3af;font-size:13px;margin-top:10px">Loading answer key...</p></div>';

    ajaxPost('cias_get_results', { attempt_id: attemptId }, function(res) {
      if (res && res.success && res.data && res.data.html) {
        if (wrap) wrap.innerHTML = '<button onclick="CIASApp.goTab(&quot;tests&quot;)" style="margin:12px 14px 0;display:flex;align-items:center;gap:6px;background:none;border:none;color:#6c63ff;font-weight:600;font-size:13px;cursor:pointer">&#8592; Back to Tests</button>' + res.data.html;
      } else {
        if (wrap) wrap.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af">Results not available.</div>';
      }
    });
  }

  /* ── AI Guru ─────────────────────────────────────────────── */
  function populateGuruStats() {
    setText('guru-streak', D.streak.current);
    setText('guru-avg', D.stats.avg_score + '%');
    setText('guru-tests', D.stats.tests_taken);
    setText('plan-vocab-due', D.due_today.length + ' words');
    setText('plan-motivation', 'Every question solved is a step closer to IAS, ' + D.user.name + '!');
  }

  function renderInitialChat() {
    var area = el('chat-area');
    if (!area) return;
    area.innerHTML = '';
    appendBotMsg(
      'Namaste, ' + D.user.name + '! \uD83D\uDE4F I\'m your CIAS AI Guru — your personal UPSC mentor. Ask me anything about polity, economy, vocabulary, current affairs. Each question uses <strong>1 credit.</strong>' +
      '<p style="margin-top:8px;font-size:12px;color:#6b7280">Tip: Tap the <i class="ti ti-photo-up" style="font-size:13px;vertical-align:-2px" aria-hidden="true"></i> button to upload your handwritten answer for AI evaluation.</p>'
    );
  }

  function guruTab(t) {
    ['mentor', 'plan', 'lec', 'rank', 'heat'].forEach(function (p) {
      toggleClass('gt-' + p, 'active', p === t);
      toggleClass('gp-' + p, 'active', p === t);
      attr('gt-' + p, 'aria-selected', p === t ? 'true' : 'false');
    });
  }


  /* ── Chat — delegates to CIASChat module (chat.js) ─────────────── */
  function fillQ(q)            { CIASChat.fillQ(q); }
  function trigImg()           { CIASChat.trigImg(); }
  function rmImg()             { CIASChat.rmImg(); }
  function onFile(e)           { CIASChat.onFile(e); }
  function sendMsg()           { CIASChat.sendMsg(); }
  function confirmOCR(id, btn) { CIASChat.confirmOCR(id, btn); }
  function rejectOCR(id)       { CIASChat.rejectOCR(id); }
  function pollJob(jid, cb)    { return CIASChat.pollJob(jid, cb); }
  function appendBotMsg(html)  { CIASChat.appendBotMsg(html); }
  function autoRes(textarea)   { CIASChat.autoRes(textarea); }

  
  /* ── Progress ─────────────────────────────────────────────── */
  function populateProgress() {
    setText('pg-tests',   D.stats.tests_taken);
    setText('pg-streak',  D.streak.current);
    setText('pg-words',   D.stats.words_mastered);
    setText('pg-answers', D.stats.answers_submitted);

    // Subject accuracy bars
    var colors = ['#3b82f6','#8b5cf6','#22c55e','#f97316','#06b6d4','#ec4899'];
    var subjs = D.subject_accuracy && D.subject_accuracy.length ? D.subject_accuracy : [
      {subject:'Polity',accuracy:78},{subject:'History',accuracy:65},
      {subject:'Economy',accuracy:72},{subject:'Geography',accuracy:55},
      {subject:'Environment',accuracy:88},{subject:'Sci & Tech',accuracy:60}
    ];
    var saEl = el('subj-accuracy');
    if (saEl) {
      saEl.innerHTML = subjs.map(function (s, i) {
        return '<div class="ca-subj-row">' +
          '<span class="ca-subj-nm">' + esc(s.subject || s.subject_id) + '</span>' +
          '<div class="ca-subj-bw"><div class="ca-subj-bf" style="background:' + colors[i % colors.length] + ';width:' + (s.accuracy || 0) + '%"></div></div>' +
          '<span class="ca-subj-pct">' + Math.round(s.accuracy || 0) + '%</span>' +
          '</div>';
      }).join('');
    }

    // Streak calendar
    buildStreakGrid();

    // Writing scores
    var wsEl = el('writing-scores');
    if (wsEl) {
      var scores = D.writing_scores && D.writing_scores.length ? D.writing_scores : [
        {question_text:'Article 370 — Impact',subject_name:'Polity',score:76,max_score:100,evaluated_at:'2026-05-15'},
        {question_text:'Fiscal Federalism',subject_name:'Economy',score:61,max_score:100,evaluated_at:'2026-05-12'},
        {question_text:'Biodiversity Hotspots',subject_name:'Environment',score:82,max_score:100,evaluated_at:'2026-05-10'}
      ];
      wsEl.innerHTML = scores.map(function (s) {
        var sc = parseInt(s.score, 10);
        var color = sc >= 75 ? '#22c55e' : sc >= 50 ? '#f97316' : '#ef4444';
        var dt = s.evaluated_at ? s.evaluated_at.substring(0, 10) : '';
        return '<div class="ca-wr-row">' +
          '<div><div class="ca-wr-title">' + esc((s.question_text || '').substring(0, 40)) + '</div>' +
          '<div class="ca-wr-sub">' + esc(s.subject_name || '') + (dt ? ' · ' + dt : '') + '</div></div>' +
          '<div style="text-align:center"><span class="ca-wr-score" style="color:' + color + '">' + sc + '</span>' +
          '<span class="ca-wr-of">/' + (s.max_score || 100) + '</span></div></div>';
      }).join('');
    }
  }

  function buildStreakGrid() {
    var g = el('streak-grid');
    if (!g) return;
    g.innerHTML = '';
    var activeSet = {};
    (D.activity_days || []).forEach(function (d) { activeSet[d] = true; });
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var todayStr = today.toISOString().substring(0, 10);
    // Fill first day-of-week offset
    var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    var offset = firstDay.getDay();
    for (var i = 0; i < offset; i++) {
      var blank = document.createElement('div');
      blank.className = 'ca-sd';
      g.appendChild(blank);
    }
    var daysInMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
    for (var day = 1; day <= daysInMonth; day++) {
      var dateStr = today.getFullYear() + '-' +
        String(today.getMonth() + 1).padStart(2, '0') + '-' +
        String(day).padStart(2, '0');
      var d2 = document.createElement('div');
      var cls = 'ca-sd ';
      if (dateStr === todayStr) cls += 'tod';
      else if (activeSet[dateStr]) cls += 'act';
      else cls += 'mis';
      d2.className = cls;
      d2.textContent = day;
      g.appendChild(d2);
    }
  }

  /* ── Profile ──────────────────────────────────────────────── */
  function populateProfile() {
    setText('prof-av',    D.user.initials);
    setText('prof-name',  D.user.display_name || D.user.name);
    setText('prof-email', D.user.email);

    var planName = (D.plan && D.plan.name) || 'Free';
    setText('prof-plan-lbl', planName + ' Plan · Active');
    setText('prof-cr',  D.credits.remaining);
    setText('prof-cr-of', 'of ' + D.credits.monthly + ' remaining');
    setText('cred-reset', 'Resets in ' + D.credits.reset_days + ' days');
    setText('cbar-used', 'Used: ' + D.credits.used);
    setText('cbar-max',  D.credits.monthly);
    var pct = Math.round((D.credits.used / D.credits.monthly) * 100);
    var bf = el('cbar-fill');
    if (bf) bf.style.width = Math.min(pct, 100) + '%';

    // Plans
    var plans = [
      { key: 'free',  name: 'Free',  price: '₹0',   per: '/month', desc: 'For beginners',       feats: ['100 vocab · 5 AI credits · 2 mock tests'] },
      { key: 'paid',  name: 'Pro',   price: '₹499', per: '/month', desc: 'Most popular',        feats: ['Unlimited vocabulary & mock tests','50 AI credits/month','Performance analytics','CIAS full test series'] },
      { key: 'elite', name: 'Elite', price: '₹999', per: '/month', desc: 'For serious aspirants',feats: ['Everything in Pro','200 AI credits/month','Essay evaluation by AI','1-on-1 mentor session'] }
    ];
    var currentPlan = (D.plan && D.plan.key) || 'free';
    var poEl = el('plan-options');
    if (poEl) {
      poEl.innerHTML = plans.map(function (p) {
        var isCur = p.key === currentPlan;
        return '<div class="ca-plan-opt' + (isCur ? ' cur' : '') + '">' +
          (isCur ? '<div class="ca-cur-tag">+ Current Plan</div>' : '') +
          '<div class="ca-plan-r"><span class="ca-plan-nm">' + p.name + '</span>' +
          '<span class="ca-plan-pr">' + p.price + ' <span>' + p.per + '</span></span></div>' +
          '<div class="ca-plan-desc">' + p.desc + '</div>' +
          '<div class="ca-plan-feats">' +
          p.feats.map(function (f) { return '<div class="ca-feat-row"><i class="ti ti-check" style="color:#22c55e;font-size:13px" aria-hidden="true"></i> ' + esc(f) + '</div>'; }).join('') +
          '</div>' +
          (!isCur ? '<button class="ca-upg-btn" onclick="CIASApp.upgradePlan(\'' + p.key + '\')">Upgrade to ' + p.name + ' →</button>' : '') +
          '</div>';
      }).join('');
    }

    // Credit history
    var chEl = el('credit-history');
    if (chEl) {
      var history = D.credits.history && D.credits.history.length ? D.credits.history : [
        {delta:-1, type:'usage',    note:'AI Tutor question',        created_at:'Today, 9:42 AM'},
        {delta:50, type:'purchase', note:'Monthly Pro reset',        created_at:'May 1, 2026'},
        {delta:20, type:'manual',   note:'Top-up · 20 credits',     created_at:'Apr 18, 2026'}
      ];
      chEl.innerHTML = history.map(function (h) {
        var d = parseInt(h.delta, 10);
        var cls = d < 0 ? 'ca-hn' : d > 0 ? 'ca-hp' : 'ca-hpa';
        var val = d >= 0 ? '+' + d + ' credit' + (Math.abs(d) !== 1 ? 's' : '') : d + ' credit';
        var when = h.created_at || '';
        if (when.length > 10 && when.includes('-')) {
          when = when.substring(0, 10);
        }
        return '<div class="ca-hist-row">' +
          '<div><div class="ca-hist-title">' + esc(h.note || h.type) + '</div>' +
          '<div class="ca-hist-date">' + esc(when) + '</div></div>' +
          '<span class="' + cls + '">' + val + '</span></div>';
      }).join('');
    }
  }

  function buyCredits() {
    goTab('profile');
    // Scroll to buy button
    setTimeout(function () { var b = el('prof-cr'); if (b) b.scrollIntoView({ behavior: 'smooth' }); }, 200);
  }

  function upgradePlan(plan) {
    alert('Upgrade to ' + plan + ' coming soon! Contact admin for manual upgrade.');
  }

  /* ── Heatmap & Rank ──────────────────────────────────────── */
  function populateHeatmap() {
    var colors = ['#3b82f6','#8b5cf6','#22c55e','#f97316','#06b6d4','#ec4899'];
    var subjs = D.subject_accuracy && D.subject_accuracy.length ? D.subject_accuracy : [
      {subject:'Polity',accuracy:78},{subject:'History',accuracy:65},
      {subject:'Economy',accuracy:72},{subject:'Geography',accuracy:55},
      {subject:'Environment',accuracy:88},{subject:'Sci & Tech',accuracy:60}
    ];
    var hb = el('heatmap-bars');
    if (hb) {
      hb.innerHTML = subjs.map(function (s, i) {
        return '<div class="ca-hmap-row">' +
          '<span class="ca-hmap-subj">' + esc(s.subject || s.subject_id || '') + '</span>' +
          '<div class="ca-hmap-bar-wrap"><div class="ca-hmap-bar" style="background:' + colors[i % colors.length] + ';width:' + (s.accuracy || 0) + '%"></div></div>' +
          '<span class="ca-hmap-pct">' + Math.round(s.accuracy || 0) + '%</span></div>';
      }).join('');
    }
    var weak = subjs.filter(function (s) { return (s.accuracy || 0) < 60; });
    var wl = el('weak-topics-list');
    if (wl) {
      wl.innerHTML = weak.length
        ? weak.map(function (s) { return '<div class="ca-feat-row"><i class="ti ti-alert-triangle" style="color:#ef4444;font-size:14px" aria-hidden="true"></i> ' + esc(s.subject || '') + ' (' + Math.round(s.accuracy) + '%)</div>'; }).join('')
        : '<div style="color:#22c55e;font-size:13px">No weak topics below 60% — great work!</div>';
    }
  }

  function populateRank() {
    var avg = D.stats.avg_score || 68;
    var low = Math.max(50, Math.round(avg * 0.9));
    var high = Math.min(200, Math.round(avg * 1.15));
    setText('rank-range', low + ' – ' + high);
    var conf = Math.min(90, Math.round(avg * 0.8 + 20));
    setText('rank-conf', conf + '% confidence');
    var subjs = D.subject_accuracy && D.subject_accuracy.length ? D.subject_accuracy : [];
    var strong = subjs.filter(function (s) { return (s.accuracy || 0) >= 75; });
    var weak2  = subjs.filter(function (s) { return (s.accuracy || 0) < 60; });
    var rf = el('rank-factors');
    if (rf) {
      rf.innerHTML = '<div class="ca-plan-card-title">Key factors</div>' +
        strong.slice(0, 2).map(function (s) { return '<div class="ca-feat-row"><i class="ti ti-circle-check" style="color:#22c55e;font-size:15px" aria-hidden="true"></i> Strong ' + esc(s.subject || '') + ' score (' + Math.round(s.accuracy) + '%)</div>'; }).join('') +
        weak2.slice(0, 2).map(function (s) { return '<div class="ca-feat-row"><i class="ti ti-alert-circle" style="color:#f97316;font-size:15px" aria-hidden="true"></i> ' + esc(s.subject || '') + ' needs urgent work (' + Math.round(s.accuracy) + '%)</div>'; }).join('');
    }
  }

  /* ── Tab navigation ──────────────────────────────────────── */
  function goTab(t) {
    currentTab = t;
    // All screens — including new exam + results
    var allScreens = ['home','tests','progress','profile','vocab','tutor','exam','results'];
    allScreens.forEach(function(s) {
      var sc = el('scr-' + s);
      if (!sc) return;
      if (s === 'vocab' || s === 'tutor' || s === 'exam') {
        sc.style.display = s === t ? 'flex' : 'none';
      } else {
        sc.style.display = s === t ? 'block' : 'none';
      }
    });

    // Nav tab highlight — exam/results show Tests as active
    var navTab = (t === 'exam' || t === 'results') ? 'tests' : t;
    ['home','vocab','tests','tutor','progress','profile'].forEach(function(tab) {
      var btn = el('tab-' + tab);
      if (btn) {
        btn.classList.toggle('active', tab === navTab);
        btn.setAttribute('aria-current', tab === navTab ? 'page' : 'false');
      }
    });

    if (t === 'vocab') { show('vocab-landing'); hide('vocab-session'); }
  }

  /* ── DOM utilities ───────────────────────────────────────── */
  function el(id) { return document.getElementById(id); }
  function setText(id, val) { var e = el(id); if (e) e.textContent = String(val != null ? val : ''); }
  function show(id) { var e = el(id); if (e) e.style.display = ''; }
  function hide(id) { var e = el(id); if (e) e.style.display = 'none'; }
  function addClass(id, cls) { var e = el(id); if (e) e.classList.add(cls); }
  function removeClass(id, cls) { var e = el(id); if (e) e.classList.remove(cls); }
  function toggleClass(id, cls, v) { var e = el(id); if (e) e.classList.toggle(cls, v); }
  function attr(id, a, v) { var e = el(id); if (e) e.setAttribute(a, v); }
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function nowTime() {
    var d = new Date(); return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
  }

  /* ── Public API ──────────────────────────────────────────── */
  return {
    boot:          boot,
    goTab:         goTab,
    guruTab:       guruTab,
    fillQ:         fillQ,
    autoRes:       autoRes,
    trigImg:       trigImg,
    onFile:        onFile,
    rmImg:         rmImg,
    sendMsg:       sendMsg,
    selectMode:    selectMode,
    startVocabSession: startVocabSession,
    quizAnswer:    quizAnswer,
    loadTests:     loadTests,
    filterTests:   filterTests,
    selFilter:     filterTests,
    startTest:     startTest,
    examNav:       examNav,
    selectOpt:     selectOpt,
    goToQ:         goToQ,
    confirmSubmit: confirmSubmit,
    closeConfirm:  closeConfirm,
    submitExam:    submitExam,
    reviewTest:    reviewTest,
    endSession:    endSession,
    flipCard:      flipCard,
    nextCard:      nextCard,
    buyCredits:    buyCredits,
    upgradePlan:   upgradePlan,
    confirmOCR:    confirmOCR,
    rejectOCR:     rejectOCR
  };

})();

document.addEventListener('DOMContentLoaded', function () { CIASApp.boot(); });
