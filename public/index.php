<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f6f7f5">
  <meta name="description" content="Get practical, AI-powered feedback on your resume, skills, and next career steps.">
  <title>ResumeLab — Make your next move</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script src="/assets/js/app.js" defer></script>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
      <a class="brand" href="/" aria-label="ResumeLab home">
        <span class="brand-mark" aria-hidden="true">r.</span>
        <span>resumelab<span class="brand-dot">.</span></span>
      </a>
      <div class="sidebar-caption">YOUR WORKSPACE</div>
      <nav class="nav-list">
        <a class="nav-link active" href="#analyze" aria-current="page"><span class="nav-icon">✳</span> Resume analysis</a>
        <a class="nav-link" href="#how-it-works"><span class="nav-icon">◷</span> How it works</a>
      </nav>
      <div class="sidebar-bottom">
        <div class="privacy-card">
          <span class="privacy-icon" aria-hidden="true">◈</span>
          <strong>Your resume stays yours</strong>
          <p>Your text is sent to the configured AI provider for feedback. ResumeLab does not save it.</p>
        </div>
        <div class="sidebar-credit"><span class="status-dot"></span> Thoughtful feedback, powered by AI</div>
      </div>
    </aside>

    <main id="analyze" class="main-content">
      <header class="topbar">
        <span class="breadcrumb">Workspace <span>/</span> Resume analysis</span>
        <a class="topbar-link" href="#how-it-works">How it works <span aria-hidden="true">↗</span></a>
      </header>

      <section class="page-intro">
        <div class="eyebrow"><span class="eyebrow-line"></span> YOUR NEXT CHAPTER STARTS HERE</div>
        <h1>A clearer path to<br><span>your next opportunity.</span></h1>
        <p class="intro-copy">Get a thoughtful read on your resume, find roles that fit, and turn your next steps into a plan.</p>
      </section>

      <div class="workspace-grid">
        <section class="input-panel panel" aria-labelledby="resume-heading">
          <ol class="workflow-steps" aria-label="Resume analysis steps">
            <li class="is-current" data-workflow-step="1" aria-current="step"><span>01</span> Add resume</li>
            <li data-workflow-step="2"><span>02</span> Review text</li>
            <li data-workflow-step="3"><span>03</span> Analyze</li>
          </ol>
          <div class="panel-heading">
            <div>
              <div class="step-label"><span>01</span> &nbsp; START WITH YOUR STORY</div>
              <h2 id="resume-heading">Your resume</h2>
            </div>
            <span class="private-badge"><span aria-hidden="true">♧</span> Private</span>
          </div>
          <p class="panel-description">Paste the text from your resume. Include your experience, projects, education, and skills for the most useful feedback.</p>
          <label class="file-drop-zone" id="file-drop-zone" for="resume-file">
            <input id="resume-file" type="file" accept=".pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain">
            <span class="file-drop-icon" aria-hidden="true">↑</span>
            <strong id="file-drop-title">Drop your resume here or <span>browse files</span></strong>
            <span class="file-drop-help">PDF, DOCX or TXT · Up to 5 MB</span>
          </label>
          <div class="upload-status" id="upload-status" hidden>
            <div class="upload-file-icon" aria-hidden="true">↗</div>
            <div class="upload-file-details"><strong id="upload-file-name"></strong><span id="upload-file-detail"></span></div>
            <button type="button" class="upload-remove-button" id="remove-upload" aria-label="Remove uploaded resume">×</button>
          </div>
          <progress class="upload-progress" id="upload-progress" max="100" value="0" aria-label="Resume upload progress" hidden></progress>
          <p class="upload-feedback" id="upload-feedback" role="status" hidden></p>
          <div class="paste-divider"><span>OR PASTE YOUR RESUME TEXT</span></div>
          <label class="sr-only" for="resume-input">Resume text</label>
          <textarea id="resume-input" maxlength="20000" placeholder="Paste your resume here…&#10;&#10;Tip: Include your experience, projects, education, and skills."></textarea>
          <div class="input-meta">
            <span id="char-count">0 / 20,000 characters</span>
            <button type="button" class="text-button" id="sample-button">Try a sample resume <span aria-hidden="true">↗</span></button>
          </div>
          <div class="preferences-grid">
            <label for="target-role">Role you have in mind <span>Optional</span>
              <input id="target-role" type="text" maxlength="120" placeholder="e.g. Frontend developer">
            </label>
            <label for="target-country">Target country <span>Optional</span>
              <select id="target-country">
                <option value="">Choose a country</option>
                <option value="AU">Australia</option>
                <option value="AT">Austria</option>
                <option value="BE">Belgium</option>
                <option value="BR">Brazil</option>
                <option value="CA">Canada</option>
                <option value="CH">Switzerland</option>
                <option value="DE">Germany</option>
                <option value="ES">Spain</option>
                <option value="FR">France</option>
                <option value="GB">United Kingdom</option>
                <option value="IE">Ireland</option>
                <option value="IN">India</option>
                <option value="IT">Italy</option>
                <option value="MX">Mexico</option>
                <option value="NL">Netherlands</option>
                <option value="NZ">New Zealand</option>
                <option value="PL">Poland</option>
                <option value="SG">Singapore</option>
                <option value="US">United States</option>
                <option value="ZA">South Africa</option>
              </select>
            </label>
            <label for="target-city">City <span>Optional</span>
              <input id="target-city" type="text" maxlength="100" placeholder="e.g. Bengaluru">
            </label>
          </div>
          <label class="consent-row" for="consent-checkbox">
            <input id="consent-checkbox" type="checkbox">
            <span>I understand my resume text and any career preferences I provide will be sent to the configured AI provider for this analysis.</span>
          </label>
          <div id="form-error" class="form-error" role="alert" hidden></div>
          <button type="button" class="analyze-button" id="analyze-button">
            <span class="button-label">Analyze my resume</span><span class="button-arrow" aria-hidden="true">→</span>
          </button>
          <div class="input-footnote"><span aria-hidden="true">✦</span> Usually takes about 20 seconds</div>
        </section>

        <aside class="side-column" aria-label="Analysis overview">
          <section class="panel includes-panel">
            <div class="step-label"><span>02</span> &nbsp; YOUR PERSONAL BRIEF</div>
            <h2>More than a<br>first impression.</h2>
            <p class="panel-description">A useful starting point for understanding where you are and where you could go.</p>
            <div class="includes-list">
              <div class="include-item"><span class="include-icon lilac">✳</span><div><strong>A fresh perspective</strong><span>Your summary &amp; skill strengths</span></div></div>
              <div class="include-item"><span class="include-icon peach">↗</span><div><strong>Directions to explore</strong><span>Roles that connect to your story</span></div></div>
              <div class="include-item"><span class="include-icon mint">⌁</span><div><strong>A plan to grow</strong><span>Skills, learning &amp; next steps</span></div></div>
            </div>
          </section>
          <section class="quote-card">
            <span class="quote-mark" aria-hidden="true">“</span>
            <p>Progress doesn’t come from being perfect. It comes from knowing what to work on next.</p>
            <span class="quote-attribution">A LITTLE PERSPECTIVE</span>
          </section>
        </aside>
      </div>

      <section id="report" class="report-section" aria-live="polite" hidden>
        <div class="report-header">
          <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> YOUR PERSONAL BRIEF</div>
            <h2>Your next steps, <span>with clarity.</span></h2>
            <p>Use this as a starting point, not a verdict. Your experience is more than a score.</p>
          </div>
          <button type="button" class="print-button" id="print-button"><span aria-hidden="true">↗</span> Save or print</button>
        </div>
        <section class="report-card live-search-panel" aria-labelledby="live-search-heading">
          <div class="report-card-title"><span class="report-icon roles-icon">⌕</span><div><span class="report-kicker">CURRENT SOURCES, NOT AI-GENERATED LISTINGS</span><h3 id="live-search-heading">Explore the live market</h3></div></div>
          <p>Request current job listings from Adzuna or web sources from Brave Search. Your resume text is not sent to these services; only your selected role, country, city, and analyzed skill keywords are shared when you search.</p>
          <label class="consent-row research-consent" for="research-consent">
            <input id="research-consent" type="checkbox">
            <span>I consent to sending these career keywords and location to Adzuna or Brave Search when I request live results.</span>
          </label>
          <div class="live-search-actions">
            <button type="button" class="secondary-button" id="find-jobs-button">Find current jobs <span aria-hidden="true">↗</span></button>
            <button type="button" class="secondary-button" id="research-button">Research the market <span aria-hidden="true">↗</span></button>
          </div>
          <p id="live-search-feedback" class="live-search-feedback" role="status" hidden></p>
          <section id="jobs-results" class="live-results" aria-live="polite" hidden></section>
          <section id="research-results" class="live-results" aria-live="polite" hidden></section>
        </section>
        <section class="report-card summary-card">
          <div class="report-card-title"><span class="report-icon summary-icon">✳</span><div><span class="report-kicker">THE BIG PICTURE</span><h3>Your resume summary</h3></div></div>
          <p id="summary-output" class="summary-copy"></p>
        </section>
        <div class="report-grid">
          <section class="report-card">
            <div class="report-card-title"><span class="report-icon skills-icon">◈</span><div><span class="report-kicker">WHAT YOU BRING</span><h3>Skills &amp; strengths</h3></div></div>
            <div id="skills-output" class="skills-list"></div>
          </section>
          <section class="report-card">
            <div class="report-card-title"><span class="report-icon roles-icon">↗</span><div><span class="report-kicker">POSSIBLE DIRECTIONS</span><h3>Roles to explore</h3></div></div>
            <div id="roles-output" class="roles-list"></div>
          </section>
          <section class="report-card">
            <div class="report-card-title"><span class="report-icon gaps-icon">⌁</span><div><span class="report-kicker">ROOM TO GROW</span><h3>Skills to explore</h3></div></div>
            <div id="missing-output" class="suggestion-list"></div>
          </section>
          <section class="report-card">
            <div class="report-card-title"><span class="report-icon learning-icon">↗</span><div><span class="report-kicker">SMALL STEPS, REAL MOMENTUM</span><h3>A learning plan</h3></div></div>
            <div id="learning-output" class="suggestion-list"></div>
          </section>
        </div>
        <section class="report-card">
          <div class="report-card-title"><span class="report-icon improve-icon">✎</span><div><span class="report-kicker">MAKE YOUR STORY SHINE</span><h3>Resume improvements</h3></div></div>
          <div id="improvements-output" class="improvement-list"></div>
        </section>
        <p class="report-disclaimer">AI-generated feedback can be incomplete or inaccurate. Review every suggestion and use your own judgment. Role matches are directional, not a guarantee of employment.</p>
      </section>

      <section id="how-it-works" class="how-section">
        <div><span class="eyebrow"><span class="eyebrow-line"></span> A THOUGHTFUL FIRST STEP</span><h2>Clarity over guesswork.</h2></div>
        <p>ResumeLab helps you see patterns and possibilities in your experience. You bring the ambition; we help you find a place to begin.</p>
      </section>
      <footer class="page-footer"><span>ResumeLab <span class="brand-dot">·</span> A more considered next step.</span><span>AI feedback is a guide, not a guarantee.</span></footer>
    </main>
  </div>
  <div id="loading-overlay" class="loading-overlay" hidden>
    <div class="loading-card" role="status" aria-live="polite">
      <div class="loading-orb"><span>✳</span></div>
      <span class="eyebrow">GIVE US A MOMENT</span>
      <h2>Finding the shape<br>of your story.</h2>
      <p>We’re looking for strengths, possible directions, and practical next steps.</p>
      <div class="loading-track"><span></span></div>
      <span class="loading-footnote">Your resume is only used for this analysis.</span>
    </div>
  </div>
</body>
</html>
