<?php
/**
 * CIAS App – Main HTML Template
 * Rendered by CIAS_Frontend::render_app() via [cias_app] shortcode.
 * Data is pre-loaded in window.ciasApp by the shortcode.
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="cias-app-shell" id="cias-app" role="application" aria-label="CIAS UPSC Prep">

  <!-- TOP BAR -->
  <header class="ca-topbar" role="banner">
    <div class="ca-brand">
      <div class="ca-logo" aria-hidden="true"><i class="ti ti-flame"></i></div>
      <span class="ca-brand-name">CIAS · UPSC Prep</span>
    </div>
    <div class="ca-topbar-right">
      <div class="ca-credits-badge" id="hdr-credits" aria-live="polite">
        <i class="ti ti-zap" aria-hidden="true"></i>
        <span id="hdr-cr-num">--</span> cr
      </div>
      <div class="ca-avatar" id="hdr-avatar" aria-label="User avatar">--</div>
    </div>
  </header>

  <!-- SCREENS -->
  <main class="ca-screens" aria-live="polite">

    <!-- HOME -->
    <section class="ca-screen active" id="scr-home" aria-label="Home">
      <div class="ca-hero" id="home-hero">
        <div class="ca-hero-tag">IAS 2026 Aspirant</div>
        <h1 class="ca-hero-title" id="home-greeting">Good morning!</h1>
        <p class="ca-hero-sub" id="home-sub">Loading your stats...</p>
        <div class="ca-xp-wrap"><div class="ca-xp-bar" id="home-xp"></div></div>
        <div class="ca-xp-lbl" id="home-xp-lbl">Loading XP...</div>
      </div>
      <div class="ca-stats-row" id="home-stats">
        <div class="ca-stat-card"><span class="ca-stat-val s-or" id="st-words">--</span><span class="ca-stat-lbl">Words</span></div>
        <div class="ca-stat-card"><span class="ca-stat-val s-bl" id="st-acc">--%</span><span class="ca-stat-lbl">Accuracy</span></div>
        <div class="ca-stat-card"><span class="ca-stat-val s-gr" id="st-tests">--</span><span class="ca-stat-lbl">Tests</span></div>
        <div class="ca-stat-card"><span class="ca-stat-val s-pu" id="st-streak">--</span><span class="ca-stat-lbl">Streak</span></div>
      </div>
      <div class="ca-action-grid">

        <button class="ca-act-card ca-act-purple" onclick="CIASApp.goTab('tutor')" aria-label="Ask AI">
          <div class="ca-act-top">
            <i class="ti ti-message-chatbot ca-act-icon" aria-hidden="true"></i>
            <span class="ca-act-pill">AI</span>
          </div>
          <div class="ca-act-name">Ask AI</div>
          <div class="ca-act-sub">Doubt? Ask your mentor</div>
        </button>

        <button class="ca-act-card ca-act-green" onclick="CIASApp.loadPractice()" aria-label="Practice">
          <div class="ca-act-top">
            <i class="ti ti-writing ca-act-icon" aria-hidden="true"></i>
            <span class="ca-act-pill ca-act-pill-green">NEW</span>
          </div>
          <div class="ca-act-name">Practice</div>
          <div class="ca-act-sub">Upload &amp; evaluate</div>
        </button>

        <button class="ca-act-card ca-act-blue" onclick="CIASApp.goTab('progress')" aria-label="My Progress">
          <div class="ca-act-top">
            <i class="ti ti-chart-bar ca-act-icon" aria-hidden="true"></i>
            <span class="ca-act-streak" id="act-streak-lbl">-- day</span>
          </div>
          <div class="ca-act-name">My Progress</div>
          <div class="ca-act-sub">Stats &amp; heatmap</div>
        </button>

        <button class="ca-act-card ca-act-amber" onclick="CIASApp.goTab('progress')" aria-label="Leaderboard">
          <div class="ca-act-top">
            <i class="ti ti-trophy ca-act-icon" aria-hidden="true"></i>
            <span class="ca-act-rank" id="act-rank-lbl">--</span>
          </div>
          <div class="ca-act-name">Leaderboard</div>
          <div class="ca-act-sub">Rank in your batch</div>
        </button>

      </div>
      <!-- Due Today: vocab + tests + revisions -->
      <div class="ca-sec-hdr">
        <span class="ca-sec-title">Due today</span>
        <button class="ca-sec-see" onclick="CIASApp.goTab('vocab')">See all →</button>
      </div>

      <!-- Vocabulary group -->
      <div class="ca-due-group" id="due-vocab-group">
        <div class="ca-due-glabel">
          <span class="ca-due-gtxt">Vocabulary</span>
          <div class="ca-due-gline"></div>
          <span class="ca-due-gcount ca-due-gcount--purple" id="due-vocab-cnt">-- words</span>
        </div>
        <div id="due-vocab-list"></div>
        <button class="ca-due-more ca-due-more--purple" id="due-vocab-more" onclick="CIASApp.goTab('vocab')" style="display:none">
          Start vocabulary session →
        </button>
      </div>

      <!-- Tests group -->
      <div class="ca-due-group" id="due-tests-group" style="display:none">
        <div class="ca-due-glabel">
          <span class="ca-due-gtxt">Tests</span>
          <div class="ca-due-gline"></div>
          <span class="ca-due-gcount ca-due-gcount--orange" id="due-tests-cnt">-- pending</span>
        </div>
        <div id="due-tests-list"></div>
      </div>

      <!-- Revisions group -->
      <div class="ca-due-group" id="due-revisions-group" style="display:none">
        <div class="ca-due-glabel">
          <span class="ca-due-gtxt">Revisions</span>
          <div class="ca-due-gline"></div>
          <span class="ca-due-gcount ca-due-gcount--green" id="due-revisions-cnt">-- topics</span>
        </div>
        <div id="due-revisions-list"></div>
      </div>

      <!-- Today's Study Plan -->
      <div class="ca-sec-hdr" style="padding-top:18px">
        <span class="ca-sec-title">Today's study plan</span>
        <span class="ca-sec-ai-lbl">AI · personalised</span>
      </div>
      <div class="ca-plan-today" id="plan-today-card">
        <div class="ca-plan-today-head">
          <div class="ca-plan-today-head-left">
            <div class="ca-plan-ai-pulse"></div>
            <div>
              <div class="ca-plan-today-title">Your plan for today</div>
              <div class="ca-plan-today-sub" id="plan-today-sub">Based on your weak areas</div>
            </div>
          </div>
          <div class="ca-plan-today-hrs" id="plan-today-hrs">-- hrs</div>
        </div>
        <div class="ca-plan-today-quote" id="plan-today-quote">Loading your personalised plan...</div>
        <div id="plan-today-tasks"></div>
      </div>

      <!-- Collapsible: Subject accuracy (heatmap) — real data only -->
      <div class="ca-home-collapse" id="home-heat-card" style="display:none">
        <button class="ca-home-collapse-hdr" onclick="CIASApp.toggleHomeCard('heat')" aria-expanded="false" aria-controls="home-heat-body">
          <span class="ca-hc-left"><i class="ti ti-chart-bar ca-hc-icon" aria-hidden="true"></i>
            <span><span class="ca-hc-title">Subject accuracy</span><span class="ca-hc-sub" id="home-heat-sub">Your performance by subject</span></span>
          </span>
          <i class="ti ti-chevron-down ca-hc-chev" id="home-heat-chev" aria-hidden="true"></i>
        </button>
        <div class="ca-home-collapse-body" id="home-heat-body" style="display:none">
          <div id="home-heat-bars"></div>
          <button class="ca-hc-more" onclick="CIASApp.goTab('progress')">Open full heatmap →</button>
        </div>
      </div>

      <!-- Collapsible: Prelims rank estimate — real data only -->
      <div class="ca-home-collapse" id="home-rank-card" style="display:none">
        <button class="ca-home-collapse-hdr" onclick="CIASApp.toggleHomeCard('rank')" aria-expanded="false" aria-controls="home-rank-body">
          <span class="ca-hc-left"><i class="ti ti-trophy ca-hc-icon" aria-hidden="true"></i>
            <span><span class="ca-hc-title">Prelims estimate</span><span class="ca-hc-sub" id="home-rank-sub">Based on your accuracy</span></span>
          </span>
          <i class="ti ti-chevron-down ca-hc-chev" id="home-rank-chev" aria-hidden="true"></i>
        </button>
        <div class="ca-home-collapse-body" id="home-rank-body" style="display:none">
          <div class="ca-hc-rank-row">
            <div class="ca-hc-rank-range" id="home-rank-range">-- – --</div>
            <div class="ca-hc-rank-meta"><span id="home-rank-conf">--%</span> confidence · marks /200</div>
          </div>
          <div id="home-rank-factors"></div>
          <button class="ca-hc-more" onclick="CIASApp.goTab('progress')">See full breakdown →</button>
        </div>
      </div>
    </section>

    <!-- VOCAB -->
    <section class="ca-screen ca-vocab-screen" id="scr-vocab" aria-label="Vocabulary">
      <!-- Landing -->
      <div id="vocab-landing">
        <div class="ca-vocab-top">
          <div class="ca-vocab-gr-row">
            <div class="ca-vocab-greeting" id="vocab-greeting">Hi there!</div>
            <button class="ca-logout-btn" onclick="CIASApp.goTab('home')">Close</button>
          </div>
          <div class="ca-vocab-streak" id="vocab-streak"><i class="ti ti-flame" aria-hidden="true"></i> -- day streak</div>
          <div class="ca-vocab-stats">
            <div class="ca-vs-pill"><span class="ca-vs-num" id="voc-due">--</span><span class="ca-vs-lbl">Due today</span></div>
            <div class="ca-vs-pill"><span class="ca-vs-num" id="voc-mastered">--</span><span class="ca-vs-lbl">Mastered</span></div>
          </div>
        </div>
        <div class="ca-vocab-body">
          <div class="ca-vocab-hero">
            <div class="ca-vocab-book" aria-hidden="true" role="img" aria-label="Book">📖</div>
            <h2 class="ca-vocab-ready">Ready to study?</h2>
            <p class="ca-vocab-sub">You have <strong id="voc-due2">--</strong> words due for review today.</p>
            <div class="ca-mode-grid">
              <div class="ca-mode-card active" id="mode-flash" onclick="CIASApp.selectMode('flash')" role="button" tabindex="0" aria-pressed="true">
                <span class="ca-mode-emoji" aria-hidden="true">🃏</span>
                <span class="ca-mode-name">Flashcards</span>
                <span class="ca-mode-desc">Flip to reveal meaning</span>
              </div>
              <div class="ca-mode-card" id="mode-quiz" onclick="CIASApp.selectMode('quiz')" role="button" tabindex="0" aria-pressed="false">
                <span class="ca-mode-emoji" aria-hidden="true">🎯</span>
                <span class="ca-mode-name">Quiz</span>
                <span class="ca-mode-desc">4-option multiple choice</span>
              </div>
            </div>
            <button class="ca-start-btn" onclick="CIASApp.startVocabSession()">
              Start Session <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </button>
            <button class="ca-progress-link" onclick="CIASApp.goTab('progress')">View my progress →</button>
          </div>
        </div>
      </div>
      <!-- Session -->
      <div id="vocab-session" style="display:none;padding:12px;padding-bottom:80px">
        <div class="ca-sess-header">
          <span class="ca-sess-count" id="sess-count">Card 1 of --</span>
          <button class="ca-end-btn" onclick="CIASApp.endSession()">End</button>
        </div>
        <div class="ca-fc-prog-wrap"><div class="ca-fc-prog-fill" id="fc-prog"></div></div>
        <div class="ca-flashcard" id="flashcard" onclick="CIASApp.flipCard()" role="button" tabindex="0" aria-label="Flashcard, tap to reveal">
          <div class="ca-fc-tag" id="fc-tag">Adjective</div>
          <div class="ca-fc-word" id="fc-word">Loading...</div>
          <div class="ca-fc-hint" id="fc-hint">Tap to reveal meaning</div>
          <div class="ca-fc-def" id="fc-def"></div>
        </div>
        <div id="card-actions" style="display:none">
          <div class="ca-card-actions">
            <button class="ca-btn-hard" onclick="CIASApp.nextCard('hard')"><i class="ti ti-x" aria-hidden="true"></i> Hard</button>
            <button class="ca-btn-good" onclick="CIASApp.nextCard('good')"><i class="ti ti-check" aria-hidden="true"></i> Good</button>
            <button class="ca-btn-easy" onclick="CIASApp.nextCard('easy')"><i class="ti ti-checks" aria-hidden="true"></i> Easy</button>
          </div>
          <p class="ca-card-count">Tap an option to continue</p>
        </div>
      </div>
    </section>

    <!-- TESTS LIST -->
    <section class="ca-screen" id="scr-tests" aria-label="Tests">
      <div class="ca-tests-hdr">
        <h2>Tests</h2>
        <button class="ca-tests-refresh" onclick="CIASApp.loadTests()" title="Refresh">
          <i class="ti ti-refresh" style="font-family:'tabler-icons';font-style:normal"></i>
        </button>
      </div>
      <div class="ca-filter-chips" id="tests-filter-chips" role="group" aria-label="Test filters">
        <button class="ca-fc-chip active" data-filter="all"     onclick="CIASApp.filterTests(this)">All</button>
        <button class="ca-fc-chip"        data-filter="available" onclick="CIASApp.filterTests(this)">Available</button>
        <button class="ca-fc-chip"        data-filter="completed" onclick="CIASApp.filterTests(this)">Completed</button>
        <button class="ca-fc-chip"        data-filter="upcoming"  onclick="CIASApp.filterTests(this)">Upcoming</button>
      </div>
      <div class="ca-test-list" id="test-list">
        <div class="ca-test-loading">
          <div class="ca-typing"><span></span><span></span><span></span></div>
          <span>Loading tests...</span>
        </div>
      </div>
    </section>

    <!-- EXAM SCREEN (full-screen overlay) -->
    <section class="ca-screen ca-exam-screen" id="scr-exam" aria-label="Exam" style="display:none">
      <!-- Exam header -->
      <div class="ca-exam-hdr">
        <div class="ca-exam-title" id="exam-title">Test</div>
        <div class="ca-exam-timer" id="exam-timer" aria-live="polite">--:--</div>
        <button class="ca-exam-submit-hdr" id="exam-submit-hdr" onclick="CIASApp.confirmSubmit()">Submit</button>
      </div>
      <!-- Progress bar -->
      <div class="ca-exam-prog-wrap"><div class="ca-exam-prog-fill" id="exam-prog"></div></div>
      <!-- Q navigation pills -->
      <div class="ca-exam-qnav" id="exam-qnav" role="navigation" aria-label="Question navigation"></div>
      <!-- Question body -->
      <div class="ca-exam-body" id="exam-body">
        <div class="ca-exam-qnum" id="exam-qnum">Question 1</div>
        <div class="ca-exam-qtext" id="exam-qtext"></div>
        <div class="ca-exam-statements" id="exam-statements" style="display:none"></div>
        <div class="ca-exam-opts" id="exam-opts" role="radiogroup" aria-label="Options"></div>
      </div>
      <!-- Bottom nav buttons -->
      <div class="ca-exam-nav">
        <button class="ca-exam-nav-btn" id="exam-prev" onclick="CIASApp.examNav(-1)">
          <i class="ti ti-arrow-left" style="font-family:'tabler-icons';font-style:normal"></i> Prev
        </button>
        <button class="ca-exam-nav-btn ca-exam-nav-btn--skip" onclick="CIASApp.examNav(1)">Skip</button>
        <button class="ca-exam-nav-btn ca-exam-nav-btn--next" id="exam-next" onclick="CIASApp.examNav(1)">
          Next <i class="ti ti-arrow-right" style="font-family:'tabler-icons';font-style:normal"></i>
        </button>
      </div>
      <!-- Submit confirm overlay -->
      <div id="exam-submit-overlay" style="display:none;position:absolute;inset:0;background:rgba(0,0,0,.55);z-index:20;display:none;align-items:center;justify-content:center;padding:24px">
        <div class="ca-exam-confirm">
          <div class="ca-exam-confirm-icon">📋</div>
          <div class="ca-exam-confirm-title">Submit Test?</div>
          <div class="ca-exam-confirm-msg" id="exam-confirm-msg">You have answered 0 of 0 questions.</div>
          <button class="ca-exam-confirm-yes" onclick="CIASApp.submitExam()">Yes, Submit</button>
          <button class="ca-exam-confirm-no"  onclick="CIASApp.closeConfirm()">Continue Test</button>
        </div>
      </div>
    </section>

    <!-- RESULTS SCREEN -->
    <section class="ca-screen" id="scr-results" aria-label="Test Results" style="display:none">
      <div class="ca-results-wrap" id="results-wrap"></div>
    </section>

    <!-- PRACTICE (adaptive) -->
    <section class="ca-screen" id="scr-practice" aria-label="Practice" style="display:none">
      <style>
        #scr-practice{--ct-bg:#f5f5fb;--ct-card:#fff;--ct-text:#1a1560;--ct-border:#e9e9fb;--ct-muted:#9ca3af;--ct-red:#ef4444}
        #scr-practice .cias-section-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ct-muted);padding:14px 14px 8px}
        #scr-practice .cias-btn{border:none;border-radius:10px;padding:9px 16px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
        #scr-practice .cias-btn-primary{background:#6c63ff;color:#fff}
        #scr-practice .cias-btn-sm{padding:6px 12px;font-size:12px;background:#f3f4f6;color:#374151}
        #scr-practice .cias-test-card{background:var(--ct-card);border-radius:14px;padding:14px;margin:0 14px 10px;box-shadow:0 1px 4px rgba(0,0,0,.05);border-left:4px solid #6c63ff}
        #scr-practice .cias-test-card-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
        #scr-practice .cias-subject-tag{font-size:11px;font-weight:600;padding:3px 10px;border-radius:99px;border:1px solid}
        #scr-practice .cias-result-badge{font-size:11px;font-weight:600;padding:3px 10px;border-radius:99px}
        #scr-practice .cias-test-title{font-size:15px;font-weight:700;color:var(--ct-text);margin:4px 0 8px}
        #scr-practice .cias-test-meta{display:flex;gap:14px;font-size:12px;color:var(--ct-muted);margin-bottom:10px}
        #scr-practice .cias-practice-wrap{padding-bottom:20px}
      </style>
      <div style="padding:14px 14px 0"><button onclick="CIASApp.goTab('home')" style="display:flex;align-items:center;gap:6px;background:none;border:none;color:#6c63ff;font-weight:600;font-size:13px;cursor:pointer">&#8592; Back to Home</button></div>
      <div class="ca-practice-wrap" id="practice-wrap"></div>
    </section>

    <!-- AI GURU -->
    <section class="ca-screen ca-guru-screen" id="scr-tutor" aria-label="AI Guru">

      <!-- AI Mentor panel -->
      <div class="ca-guru-panel active" id="gp-mentor" role="tabpanel">
        <div class="ca-guru-hero-card">
          <div class="ca-guru-hero-row">
            <div class="ca-guru-avatar" aria-hidden="true"><i class="ti ti-brain"></i></div>
            <div>
              <div class="ca-guru-name">CIAS AI Guru</div>
              <div class="ca-guru-tagline">Your personal UPSC mentor · Always available</div>
            </div>
          </div>
          <div class="ca-guru-stats">
            <div class="ca-gs"><span class="ca-gs-val" id="guru-streak">--</span><span class="ca-gs-lbl">Day Streak</span></div>
            <div class="ca-gs"><span class="ca-gs-val" id="guru-avg">--%</span><span class="ca-gs-lbl">Avg Score</span></div>
            <div class="ca-gs"><span class="ca-gs-val" id="guru-tests">--</span><span class="ca-gs-lbl">Tests Taken</span></div>
          </div>
        </div>
        <div class="ca-prompt-chips" id="prompt-chips" role="list" aria-label="Quick prompt suggestions">
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('What should I study today based on my weak areas?')" role="listitem">📚 What to study today?</button>
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('What are my weakest topics and how do I improve?')" role="listitem">⚠️ My weak areas</button>
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('What is my predicted UPSC Prelims score?')" role="listitem">🎯 Predicted score</button>
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('Give me a strong motivational push for UPSC!')" role="listitem">💪 Motivate me</button>
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('Am I on track for UPSC Prelims?')" role="listitem">📊 Am I on track?</button>
          <button class="ca-prompt-chip" onclick="CIASApp.fillQ('Which subjects need the most urgent attention?')" role="listitem">🔴 Urgent topics</button>
        </div>
        <div class="ca-chat-area" id="chat-area" aria-live="polite" aria-label="Chat messages"></div>
        <div class="ca-img-strip" id="img-strip">
          <img src="" alt="Selected image" class="ca-img-thumb" id="img-thumb">
          <span class="ca-img-lbl" id="img-lbl">Image ready</span>
          <button class="ca-rm-img" onclick="CIASApp.rmImg()" aria-label="Remove image"><i class="ti ti-x"></i></button>
        </div>
        <div class="ca-powered-row">
          Powered by Claude · 1 credit per question ·
          <button class="ca-link-btn" onclick="CIASApp.goTab('profile')">Buy credits</button>
        </div>
        <div class="ca-guru-input-area">
          <div class="ca-guru-input-wrap">
            <textarea id="chat-inp" placeholder="Ask your UPSC doubt..." rows="1"
                      aria-label="Type your question" oninput="CIASApp.autoRes(this)"></textarea>
            <button class="ca-attach-btn" onclick="CIASApp.trigImg()"
                    aria-label="Upload answer image for evaluation"
                    title="Upload handwritten answer for AI evaluation">
              <i class="ti ti-photo-up"></i>
            </button>
          </div>
          <button class="ca-guru-send" id="send-btn" onclick="CIASApp.sendMsg()" aria-label="Send message">
            <i class="ti ti-send" aria-hidden="true"></i>
          </button>
        </div>
        <input type="file" id="file-inp" accept="image/*,application/pdf"
               style="display:none" aria-hidden="true" onchange="CIASApp.onFile(event)">
      </div>

    </section>

    <!-- PROGRESS -->
    <section class="ca-screen" id="scr-progress" aria-label="Progress">
      <div class="ca-prog-hdr"><h2>Progress</h2><p>Analytics &amp; trends</p></div>
      <div class="ca-prog-body">
        <div class="ca-stats-row" id="prog-stats">
          <div class="ca-stat-card"><span class="ca-stat-val s-or" id="pg-tests">--</span><span class="ca-stat-lbl">Tests</span></div>
          <div class="ca-stat-card"><span class="ca-stat-val s-gr" id="pg-streak">--</span><span class="ca-stat-lbl">Streak</span></div>
          <div class="ca-stat-card"><span class="ca-stat-val s-bl" id="pg-words">--</span><span class="ca-stat-lbl">Words</span></div>
          <div class="ca-stat-card"><span class="ca-stat-val s-pu" id="pg-answers">--</span><span class="ca-stat-lbl">Answers</span></div>
        </div>
        <div class="ca-prog-card">
          <div class="ca-prog-title">Subject-wise accuracy</div>
          <div id="subj-accuracy"></div>
        </div>
        <div class="ca-prog-card">
          <div class="ca-prog-title">Prelims score estimate</div>
          <div class="ca-rank-card" style="text-align:center;padding:6px 0">
            <div class="ca-rank-range" id="rank-range" style="font-size:30px;font-weight:700;color:#6c63ff">-- – --</div>
            <p style="font-size:12px;color:#9ca3af;margin-top:2px">marks out of 200</p>
            <span class="ca-rank-conf" id="rank-conf" style="font-size:12px;color:#9ca3af">--% confidence</span>
          </div>
          <div id="rank-factors" style="margin-top:10px"></div>
        </div>
        <div class="ca-prog-card">
          <div class="ca-prog-title">Subject accuracy matrix</div>
          <div id="heatmap-bars"></div>
          <div id="weak-topics-card" style="margin-top:12px">
            <div class="ca-prog-title" style="font-size:13px">Weak topics (below 60%)</div>
            <div id="weak-topics-list" style="font-size:13px;color:#374151;line-height:2"></div>
          </div>
        </div>
        <div class="ca-prog-card">
          <div class="ca-prog-title">Activity — last 31 days</div>
          <div class="ca-week-labels"><span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span></div>
          <div class="ca-streak-grid" id="streak-grid"></div>
        </div>
        <div class="ca-prog-card">
          <div class="ca-prog-title">Mains writing scores</div>
          <div id="writing-scores"></div>
        </div>
      </div>
    </section>

    <!-- PROFILE -->
    <section class="ca-screen" id="scr-profile" aria-label="Profile">
      <div class="ca-prof-hdr">
        <div class="ca-prof-av" id="prof-av">--</div>
        <div class="ca-prof-name" id="prof-name">Loading...</div>
        <div class="ca-prof-email" id="prof-email">--</div>
        <div class="ca-plan-badge" id="prof-plan"><i class="ti ti-shield-check" aria-hidden="true"></i> <span id="prof-plan-lbl">--</span></div>
      </div>
      <div class="ca-prof-body">
        <div class="ca-pf-card">
          <div class="ca-pf-hdr-row">
            <div class="ca-pf-title">AI Tutor Credits</div>
            <span class="ca-reset-lbl" id="cred-reset">Resets in -- days</span>
          </div>
          <div class="ca-cred-row">
            <span class="ca-cred-big" id="prof-cr">--</span>
            <span class="ca-cred-of" id="prof-cr-of">of -- remaining</span>
          </div>
          <div class="ca-cbar"><div class="ca-cbar-fill" id="cbar-fill"></div></div>
          <div class="ca-cbar-lbl"><span>0</span><span id="cbar-used">Used: --</span><span id="cbar-max">--</span></div>
          <button class="ca-buy-btn" onclick="CIASApp.buyCredits()"><i class="ti ti-zap" aria-hidden="true"></i> Buy Extra AI Credits</button>
        </div>
        <div class="ca-pf-card">
          <div class="ca-pf-title">Subscription</div>
          <div id="plan-options"></div>
        </div>
        <div class="ca-pf-card">
          <div class="ca-pf-title">Credit History</div>
          <div id="credit-history"></div>
        </div>
      </div>
    </section>

  </main>

  <!-- BOTTOM NAV -->
  <nav class="ca-bottom-nav" role="navigation" aria-label="Main navigation">
    <button class="ca-nav-tab active" id="tab-home" onclick="CIASApp.goTab('home')" aria-label="Home" aria-current="page">
      <i class="ti ti-home" aria-hidden="true"></i><span>Home</span>
    </button>
    <button class="ca-nav-tab" id="tab-vocab" onclick="CIASApp.goTab('vocab')" aria-label="Vocabulary">
      <i class="ti ti-book-2" aria-hidden="true"></i><span>Vocab</span>
    </button>
    <button class="ca-nav-tab" id="tab-tests" onclick="CIASApp.goTab('tests')" aria-label="Tests">
      <i class="ti ti-clipboard-list" aria-hidden="true"></i><span>Tests</span>
    </button>
    <button class="ca-nav-tab" id="tab-tutor" onclick="CIASApp.goTab('tutor')" aria-label="AI Guru">
      <i class="ti ti-brain" aria-hidden="true"></i><span>AI Guru</span>
    </button>
    <button class="ca-nav-tab" id="tab-progress" onclick="CIASApp.goTab('progress')" aria-label="Progress">
      <i class="ti ti-chart-line" aria-hidden="true"></i><span>Progress</span>
    </button>
    <button class="ca-nav-tab" id="tab-profile" onclick="CIASApp.goTab('profile')" aria-label="Profile">
      <i class="ti ti-user-circle" aria-hidden="true"></i><span>Profile</span>
    </button>
  </nav>

</div><!-- /.cias-app-shell -->
