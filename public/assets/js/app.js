(() => {
  const resumeInput = document.getElementById('resume-input');
  const count = document.getElementById('char-count');
  const error = document.getElementById('form-error');
  const analyzeButton = document.getElementById('analyze-button');
  const consentCheckbox = document.getElementById('consent-checkbox');
  const loading = document.getElementById('loading-overlay');
  const report = document.getElementById('report');
  const fileInput = document.getElementById('resume-file');
  const dropZone = document.getElementById('file-drop-zone');
  const uploadStatus = document.getElementById('upload-status');
  const uploadProgress = document.getElementById('upload-progress');
  const uploadFeedback = document.getElementById('upload-feedback');
  const liveSearchFeedback = document.getElementById('live-search-feedback');
  const researchConsent = document.getElementById('research-consent');
  let currentAnalysis = null;
  let analysisRevision = 0;
  let uploadedText = null;
  let uploadRequest = null;

  const sampleResume = `JORDAN LEE
Computer Science student | jordan.lee@email.com | Bengaluru, India

SUMMARY
Final-year computer science student interested in building reliable, user-friendly software. Enjoy working across frontend and backend projects, learning new tools, and collaborating with peers to solve practical problems.

EDUCATION
B.E. Computer Science, Northstar Institute of Technology | 2022–2026
Relevant coursework: Data Structures, Database Systems, Computer Networks, Operating Systems

PROJECTS
Campus Connect — React, JavaScript, Node.js, MySQL
Built a student community platform with event listings and club pages. Designed responsive interfaces and worked with a three-person team using Git. Improved page load times by optimizing images and removing redundant API calls.

Expense Tracker — Python, Flask, SQLite
Created a web app to log monthly expenses and display spending summaries. Wrote input validation and tested the core routes with pytest.

EXPERIENCE
Software Engineering Intern, BrightPath Labs | May–Aug 2025
Helped the product team develop internal dashboard features. Fixed UI bugs, reviewed pull requests with a mentor, and documented setup steps for new contributors.

SKILLS
JavaScript, Python, React, Node.js, Flask, MySQL, SQLite, Git, REST APIs, responsive design, teamwork`;

  resumeInput.addEventListener('input', () => {
    count.textContent = `${Array.from(resumeInput.value).length.toLocaleString()} / 20,000 characters`;
    error.hidden = true;
    invalidateAnalysis();
  });
  ['target-role', 'target-country', 'target-city'].forEach((id) => {
    document.getElementById(id).addEventListener('input', invalidateAnalysis);
    document.getElementById(id).addEventListener('change', invalidateAnalysis);
  });
  consentCheckbox.addEventListener('change', () => { error.hidden = true; });
  researchConsent.addEventListener('change', () => { liveSearchFeedback.hidden = true; });

  fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) uploadResume(fileInput.files[0]);
  });
  dropZone.addEventListener('dragover', (event) => {
    event.preventDefault();
    dropZone.classList.add('is-dragging');
  });
  dropZone.addEventListener('dragleave', (event) => {
    if (!dropZone.contains(event.relatedTarget)) dropZone.classList.remove('is-dragging');
  });
  dropZone.addEventListener('drop', (event) => {
    event.preventDefault();
    dropZone.classList.remove('is-dragging');
    const [file] = event.dataTransfer.files;
    if (file) uploadResume(file);
  });
  document.getElementById('remove-upload').addEventListener('click', () => {
    if (uploadRequest) uploadRequest.abort();
    fileInput.value = '';
    uploadStatus.hidden = true;
    uploadProgress.hidden = true;
    uploadFeedback.hidden = true;
    dropZone.hidden = false;
    if (uploadedText !== null && resumeInput.value === uploadedText) {
      resumeInput.value = '';
      resumeInput.dispatchEvent(new Event('input'));
    }
    uploadedText = null;
  });

  document.getElementById('sample-button').addEventListener('click', () => {
    resumeInput.value = sampleResume;
    resumeInput.dispatchEvent(new Event('input'));
    resumeInput.focus();
  });

  document.getElementById('print-button').addEventListener('click', () => window.print());
  document.getElementById('find-jobs-button').addEventListener('click', () => searchJobs());
  document.getElementById('research-button').addEventListener('click', () => researchMarket());

  analyzeButton.addEventListener('click', async () => {
    const resume = resumeInput.value.trim();
    if (resume.length < 80) {
      error.textContent = 'Add at least 80 characters before analyzing your resume.';
      error.hidden = false;
      resumeInput.focus();
      return;
    }
    if (!consentCheckbox.checked) {
      error.textContent = 'Please confirm that you understand your resume will be sent to the configured AI provider.';
      error.hidden = false;
      consentCheckbox.focus();
      return;
    }

    error.hidden = true;
    invalidateAnalysis();
    const revision = analysisRevision;
    analyzeButton.disabled = true;
    loading.hidden = false;
    document.body.classList.add('is-loading');
    try {
      const response = await requestJson('/api/analyze.php', {
        resume,
        preferences: readPreferences(),
        consent: true,
      }, 38000);
      if (revision !== analysisRevision) return;
      const result = response.body;
      if (!response.ok) {
        throw new Error(result.error || 'We could not analyze that resume. Please try again.');
      }
      renderAnalysis(result.analysis);
      currentAnalysis = result.analysis;
      report.hidden = false;
      updateWorkflowStep(3);
      report.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (requestError) {
      if (revision !== analysisRevision) return;
      error.textContent = requestError instanceof TypeError
        ? 'We could not reach the analyzer. Check your connection and try again.'
        : requestError.message;
      error.hidden = false;
      error.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } finally {
      analyzeButton.disabled = false;
      loading.hidden = true;
      document.body.classList.remove('is-loading');
    }
  });

  function invalidateAnalysis() {
    analysisRevision += 1;
    report.hidden = true;
    currentAnalysis = null;
    researchConsent.checked = false;
    ['jobs-results', 'research-results'].forEach((id) => {
      const section = document.getElementById(id);
      section.hidden = true;
      section.replaceChildren();
    });
    liveSearchFeedback.hidden = true;
    updateWorkflowStep(resumeInput.value.trim().length > 0 ? 2 : 1);
  }

  function readPreferences() {
    const preferences = {
      target_role: document.getElementById('target-role').value.trim(),
      country: document.getElementById('target-country').value,
      city: document.getElementById('target-city').value.trim(),
    };
    return Object.fromEntries(Object.entries(preferences).filter(([, value]) => value !== ''));
  }

  async function requestJson(path, payload, timeoutMs) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });
      let body;
      try {
        body = await response.json();
      } catch {
        throw new Error('The server returned an unreadable response. Please try again.');
      }
      return { ok: response.ok, body };
    } catch (requestError) {
      if (requestError.name === 'AbortError') {
        throw new Error('The request took too long. Please try again.');
      }
      throw requestError;
    } finally {
      clearTimeout(timeout);
    }
  }

  function getLiveSearchContext() {
    const preferences = readPreferences();
    const roles = currentAnalysis?.roles || [];
    const role = preferences.target_role || roles[0]?.title || '';
    const skills = (currentAnalysis?.skills || []).map((skill) => skill.name).slice(0, 10);
    return {
      role,
      skills,
      country: preferences.country || '',
      city: preferences.city || '',
    };
  }

  async function runLiveSearch(path, payload, button, render) {
    if (!researchConsent.checked) {
      liveSearchFeedback.textContent = 'Confirm consent before sending career keywords and location to an external search provider.';
      liveSearchFeedback.hidden = false;
      researchConsent.focus();
      return;
    }
    if (!currentAnalysis) {
      liveSearchFeedback.textContent = 'Run a new resume analysis before searching for related roles or market sources.';
      liveSearchFeedback.hidden = false;
      return;
    }

    const revision = analysisRevision;
    button.disabled = true;
    liveSearchFeedback.textContent = 'Requesting current results from the selected provider…';
    liveSearchFeedback.hidden = false;
    try {
      const response = await requestJson(path, { ...payload, consent: true }, 15000);
      if (revision !== analysisRevision) return;
      if (!response.ok) throw new Error(response.body.error || 'The live search could not be completed.');
      render(response.body);
      liveSearchFeedback.textContent = 'Results received. Review the source and retrieval details below.';
    } catch (requestError) {
      if (revision !== analysisRevision) return;
      liveSearchFeedback.textContent = requestError instanceof TypeError
        ? 'We could not reach the live search service. Check your connection and try again.'
        : requestError.message;
    } finally {
      if (revision === analysisRevision) liveSearchFeedback.hidden = false;
      button.disabled = false;
    }
  }

  function searchJobs() {
    const context = getLiveSearchContext();
    if (!context.country) {
      liveSearchFeedback.textContent = 'Choose a target country before searching current job listings.';
      liveSearchFeedback.hidden = false;
      document.getElementById('target-country').focus();
      return;
    }
    runLiveSearch('/api/jobs.php', context, document.getElementById('find-jobs-button'), renderJobs);
  }

  function researchMarket() {
    const context = getLiveSearchContext();
    runLiveSearch('/api/market-research.php', context, document.getElementById('research-button'), renderResearch);
  }

  function renderJobs(result) {
    const section = document.getElementById('jobs-results');
    const jobs = Array.isArray(result.jobs) ? result.jobs : [];
    const heading = makeText('h4', 'live-results-heading', `Adzuna listings (${jobs.length})`);
    const note = makeText('p', 'live-results-note', result.status_note || 'Listings are shown as returned by the provider.');
    const list = document.createElement('div');
    list.className = 'job-results-list';
    if (jobs.length === 0) {
      list.append(makeText('p', 'live-empty-state', 'No listings were returned for this search. Try a broader title or nearby location.'));
    }
    jobs.forEach((job) => {
      if (!job || typeof job.title !== 'string' || typeof job.listing_url !== 'string'
        || !job.listing_url.startsWith('https://')) return;
      const card = document.createElement('article');
      card.className = 'job-result-card';
      const header = document.createElement('div');
      header.className = 'job-result-heading';
      const identity = document.createElement('div');
      identity.append(makeText('h5', '', job.title), makeText('span', 'job-company', job.company || 'Company not disclosed'));
      const score = makeText('span', 'job-match-score', `${Number(job.match_score) || 0}%`);
      score.setAttribute('aria-label', 'Resume keyword overlap score, not a hiring probability');
      header.append(identity, score);
      card.append(header);
      const metadata = [job.location, job.employment_type].filter(Boolean).join(' · ');
      if (metadata) card.append(makeText('p', 'job-result-meta', metadata));
      card.append(makeText('p', 'job-result-description', job.description || 'No description was provided.'));
      if (Array.isArray(job.match_reasons)) {
        const reasons = document.createElement('ul');
        reasons.className = 'job-match-reasons';
        job.match_reasons.forEach((reason) => reasons.append(makeText('li', '', reason)));
        card.append(reasons);
      }
      if (Array.isArray(job.skills_not_mentioned) && job.skills_not_mentioned.length > 0) {
        card.append(makeText('p', 'job-result-meta', `Resume skills not mentioned in the listing excerpt: ${job.skills_not_mentioned.join(', ')}. This does not mean they are required.`));
      }
      if (job.salary && typeof job.salary.minimum === 'number' && typeof job.salary.maximum === 'number') {
        card.append(makeText('p', 'job-result-meta', `Salary range: ${job.salary.currency} ${job.salary.minimum.toLocaleString()}–${job.salary.maximum.toLocaleString()} · ${job.salary.period} · ${job.salary.basis}`));
      }
      const dates = [];
      if (job.posted_at) dates.push(`Posted ${formatDate(job.posted_at)}`);
      if (job.retrieved_at) dates.push(`Retrieved ${formatDate(job.retrieved_at)}`);
      if (dates.length) card.append(makeText('p', 'job-result-meta', dates.join(' · ')));
      const link = makeText('a', 'job-result-link', 'View listing at Adzuna ↗');
      link.href = job.listing_url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      card.append(link);
      list.append(card);
    });
    section.replaceChildren(heading, note, list);
    section.hidden = false;
  }

  function renderResearch(result) {
    const section = document.getElementById('research-results');
    const sources = Array.isArray(result.sources) ? result.sources : [];
    const heading = makeText('h4', 'live-results-heading', `Brave Search sources (${sources.length})`);
    const note = document.createElement('p');
    note.className = 'live-results-note';
    note.textContent = sources.length
      ? `Search query: “${result.query}”. Sources were retrieved ${formatDate(result.retrieved_at)}; snippets are not independently verified.`
      : 'No web sources were returned for this query.';
    const list = document.createElement('div');
    list.className = 'research-results-list';
    sources.forEach((source) => {
      if (!source || typeof source.title !== 'string' || typeof source.url !== 'string'
        || !source.url.startsWith('https://')) return;
      const card = document.createElement('article');
      card.className = 'research-result-card';
      const link = makeText('a', 'research-result-link', source.title);
      link.href = source.url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      card.append(link, makeText('span', 'research-source-host', source.source || 'Web source'));
      card.append(makeText('p', 'research-result-snippet', source.snippet || 'No source description was provided.'));
      card.append(makeText('span', 'research-retrieved', `Retrieved ${formatDate(source.retrieved_at)}`));
      list.append(card);
    });
    section.replaceChildren(heading, note, list);
    section.hidden = false;
  }

  function formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'date unavailable' : date.toLocaleString();
  }

  function uploadResume(file) {
    const extension = file.name.split('.').pop().toLowerCase();
    if (!['pdf', 'docx', 'txt'].includes(extension)) {
      showUploadError('Choose a PDF, DOCX, or TXT resume.');
      return;
    }
    if (file.size === 0) {
      showUploadError('The selected file is empty.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      showUploadError('Resume files must be 5 MB or smaller.');
      return;
    }

    if (uploadRequest) uploadRequest.abort();
    error.hidden = true;
    uploadFeedback.hidden = true;
    invalidateAnalysis();
    uploadProgress.hidden = false;
    uploadProgress.value = 0;
    dropZone.classList.add('is-uploading');
    const request = new XMLHttpRequest();
    uploadRequest = request;
    request.open('POST', '/api/upload.php');
    request.timeout = 35000;
    request.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) uploadProgress.value = Math.min(95, Math.round(event.loaded / event.total * 95));
    });
    request.addEventListener('load', () => {
      let result;
      try {
        result = JSON.parse(request.responseText);
      } catch {
        showUploadError('The server returned an unreadable response. Please try again.');
        return;
      }
      if (request.status < 200 || request.status >= 300) {
        showUploadError(result.error || 'The resume could not be extracted. Please try another file.');
        return;
      }
      uploadedText = result.text;
      resumeInput.value = result.text;
      resumeInput.dispatchEvent(new Event('input'));
      document.getElementById('upload-file-name').textContent = result.filename;
      document.getElementById('upload-file-detail').textContent =
        `${(result.size / 1024).toFixed(0)} KB · ${result.characters.toLocaleString()} extracted characters`;
      uploadStatus.hidden = false;
      uploadProgress.value = 100;
      uploadFeedback.textContent = Array.isArray(result.warnings) && result.warnings.length
        ? result.warnings.join(' ')
        : 'Text extracted. Review and edit it before continuing.';
      uploadFeedback.classList.toggle('has-warning', Boolean(result.warnings?.length));
      uploadFeedback.hidden = false;
      dropZone.hidden = true;
      updateWorkflowStep(2);
    });
    request.addEventListener('error', () => showUploadError('We could not reach the upload service. Check your connection and try again.'));
    request.addEventListener('timeout', () => showUploadError('Resume extraction took too long. Please try again.'));
    request.addEventListener('abort', () => {});
    request.addEventListener('loadend', () => {
      if (uploadRequest === request) uploadRequest = null;
      dropZone.classList.remove('is-uploading');
    });
    const formData = new FormData();
    formData.append('resume', file, file.name);
    request.send(formData);
  }

  function showUploadError(message) {
    uploadFeedback.textContent = message;
    uploadFeedback.classList.add('has-warning');
    uploadFeedback.hidden = false;
    uploadProgress.hidden = true;
    dropZone.classList.remove('is-uploading');
  }

  function updateWorkflowStep(current) {
    document.querySelectorAll('[data-workflow-step]').forEach((step) => {
      const stepNumber = Number(step.dataset.workflowStep);
      step.classList.toggle('is-current', stepNumber === current);
      step.classList.toggle('is-complete', stepNumber < current);
      if (stepNumber === current) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });
  }

  function makeText(tag, className, value) {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = value;
    return element;
  }

  function renderAnalysis(analysis) {
    document.getElementById('summary-output').textContent = analysis.summary;

    const skills = document.getElementById('skills-output');
    skills.replaceChildren(...analysis.skills.map((skill) => {
      const row = document.createElement('div');
      row.className = 'skill-row';
      const name = makeText('strong', '', skill.name);
      const meta = document.createElement('div');
      meta.className = 'skill-meta';
      meta.append(makeText('span', 'skill-category', skill.category));
      meta.append(makeText('span', 'skill-level', skill.level));
      row.append(name, meta);
      return row;
    }));

    const roles = document.getElementById('roles-output');
    roles.replaceChildren(...analysis.roles.map((role) => {
      const row = document.createElement('div');
      row.className = 'role-row';
      const detail = document.createElement('div');
      detail.className = 'role-detail';
      detail.append(makeText('strong', '', role.title), makeText('span', '', role.reason));
      const score = makeText('span', 'match-score', `${role.match}%`);
      score.setAttribute('aria-label', `${role.match}% directional match`);
      row.append(detail, score);
      return row;
    }));

    fillSuggestions('missing-output', analysis.missing_skills, 'name', 'reason');
    fillSuggestions('learning-output', analysis.learning_suggestions, 'title', 'why', 'timeframe');
    fillSuggestions('improvements-output', analysis.improvements, 'title', 'detail');
  }

  function fillSuggestions(id, items, titleKey, bodyKey, metaKey) {
    const list = document.getElementById(id);
    list.replaceChildren(...items.map((item) => {
      const entry = document.createElement('div');
      entry.className = 'suggestion-item';
      entry.append(makeText('strong', '', item[titleKey]));
      if (metaKey) entry.append(makeText('span', 'suggestion-meta', item[metaKey]));
      entry.append(makeText('p', '', item[bodyKey]));
      return entry;
    }));
  }
})();
