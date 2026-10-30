# ResumeLab

ResumeLab is a privacy-conscious AI resume analyzer for students and job seekers. It extracts resume text from supported documents, lets the user review it, and can request evidence-based career feedback from a server-configured AI provider.

## Objectives

- Help candidates understand the experience and skills their resume actually demonstrates.
- Offer practical, non-guaranteed guidance on roles, skills to explore, learning, and resume improvements.
- Keep provider credentials server-side and avoid retaining uploaded resumes.
- Clearly distinguish implemented capabilities from planned research and career-intelligence features.

## Current features

- Responsive resume-analysis interface with paste-in text and a three-step upload, review, and analysis flow.
- Drag-and-drop and file-browser upload for PDF, DOCX, and TXT, capped at 5 MB.
- UTF-8 TXT extraction and bounded DOCX ZIP/XML extraction, including text in paragraphs and tables.
- PDF signature checks and an optional server-side PDF text extractor. Scanned PDFs are not OCR'd.
- Temporary, randomized upload files are removed after extraction. Extracted text is returned to the user's browser for review.
- Server-side, OpenAI-compatible Chat Completions integration for summary, skills, directional role recommendations, skills to explore, learning suggestions, and resume improvements.
- Optional target-role/country/city preferences passed to AI analysis after explicit consent.
- Optional live market-source search through Brave Search, with source URLs, snippets, and retrieval timestamps.
- Optional current job listings through the Adzuna API, with provider sources, retrieval dates, salary provenance, and an explainable keyword-overlap match.
- Separate consent before external market/job search; only career keywords and selected location, never the resume text, are sent to Brave or Adzuna.
- Safe text rendering, provider output validation, basic security headers, printable reports, and dependency-free backend tests.

The live providers require server-side credentials and have not been tested with live credentials in this environment. Job listings and web search snippets are displayed as returned; employer availability and search claims are not independently verified. The app does **not** currently provide company-career-page verification, independent market salary benchmarks, user accounts, saved reports, roadmaps with persistence, PDF/CSV report downloads, or OCR. The UI never fabricates those results.

## Technology and architecture

- PHP 8.2+, server-rendered HTML, CSS, and browser JavaScript.
- `ResumeParser` handles uploaded document validation/extraction. `ResumeAnalyzer` validates requests and calls the configured AI provider. PHP API scripts handle HTTP concerns.
- `JsonHttpClient` restricts outbound requests to the fixed Brave and Adzuna HTTPS hosts, disables redirects, verifies TLS, limits timeouts and response size, and supports mocked transports for tests.
- `BraveSearchProvider` and `AdzunaJobProvider` normalize provider responses into source-linked results. Search terms are built from the selected role/location and analyzed skill names, not the original resume.
- DOCX ZIP reading uses bounded PHP, zlib, and DOM functionality. `smalot/pdfparser` is the optional PDF extraction dependency.
- No database, Composer framework, frontend package manager, or persistent resume storage.

```text
.
├── public/
│   ├── api/
│   │   ├── analyze.php
│   │   ├── jobs.php
│   │   ├── market-research.php
│   │   └── upload.php
│   ├── assets/
│   │   ├── css/app.css
│   │   └── js/app.js
│   └── index.php
├── src/
│   ├── AdzunaJobProvider.php
│   ├── BraveSearchProvider.php
│   ├── JsonHttpClient.php
│   ├── ProviderData.php
│   ├── ResumeAnalyzer.php
│   └── ResumeParser.php
├── tests/run.php
├── .env.example
├── .gitignore
├── composer.json
├── CHANGELOG.md
└── README.md
```

### Request flow

1. The browser uploads a document to `POST /api/upload.php`. The endpoint checks request method, upload status, extension, size, and file signature/content before extraction.
2. The endpoint moves the file to a randomly named, private temporary path, extracts supported text, and deletes the temporary file. No resume text is written to the application database or logs.
3. Extracted text appears in the browser's editable text area. The user can review/correct it.
4. After the user confirms consent, the browser sends text and optional role/country/city preferences to `POST /api/analyze.php`.
5. After analysis, the user may separately consent to a Brave market search or Adzuna job query. Those endpoints receive only the role, selected location, analyzed skill names, and a consent flag—not the resume.
6. The backend validates AI/provider response shapes and returns the report, job listings, or cited web results. The browser inserts all provider/model content as text, never executable HTML.

## Installation and local development

Requirements:

- PHP 8.2 or newer.
- `openssl`, `zlib`, `iconv`, and `dom` extensions; `allow_url_fopen` enabled for the current native AI transport.
- Composer for optional PDF extraction.
- Server-side credentials for the live AI, web-search, and job providers you intend to use. The UI and local TXT/DOCX extraction can be exercised without keys; live endpoints return explicit configuration errors until keys are set.

1. Install the optional PDF parser:

   ```powershell
   composer install
   ```

   The manifest requires `smalot/pdfparser` `^2.13.1`. Its current stable release supports the project PHP version and requires zlib and iconv. The application does not currently have a committed `composer.lock`; generate and commit one before a production deployment.

2. Set server environment variables. PowerShell example:

   ```powershell
   $env:AI_API_KEY = "your-key"
   $env:AI_BASE_URL = "https://api.openai.com/v1"
   $env:AI_MODEL = "gpt-4o-mini"
   $env:BRAVE_SEARCH_API_KEY = "your-brave-key"
   $env:ADZUNA_APP_ID = "your-adzuna-app-id"
   $env:ADZUNA_APP_KEY = "your-adzuna-app-key"
   php -S 127.0.0.1:8000 -t public
   ```

3. Open <http://127.0.0.1:8000>.

`.env.example` documents the variables but PHP does not load `.env` automatically. Configure secrets in the hosting platform's secret manager or process environment. Never put provider keys in browser code or commit them. Do not use the PHP development server for production.

The current execution environment has PHP, zlib, iconv, and DOM, but lacks OpenSSL, Composer, and provider credentials. It cannot make a verified TLS request or install/test the optional PDF dependency. Install a supported PHP distribution with OpenSSL enabled and configure the provider credentials before using live AI, web search, job search, or PDF extraction.

## Environment variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `AI_API_KEY` | For analysis | None | Server-side bearer token for the configured AI provider. |
| `AI_BASE_URL` | For analysis | `https://api.openai.com/v1` | HTTPS base URL for an OpenAI-compatible Chat Completions API. |
| `AI_MODEL` | For analysis | `gpt-4o-mini` | Provider model that supports JSON-object response format. |
| `BRAVE_SEARCH_API_KEY` | For market research | None | Server-side Brave Search API token. Brave's official web-search endpoint is used for cited web results. |
| `ADZUNA_APP_ID` | For job listings | None | Server-side Adzuna application ID. |
| `ADZUNA_APP_KEY` | For job listings | None | Server-side Adzuna application key. |

The official provider documentation is available at [Brave Search API](https://api-dashboard.search.brave.com/app/documentation) and [Adzuna job search](https://developer.adzuna.com/docs/search). API credentials are used server-side only. Adzuna requires its app ID/key in the outbound request query string; never log outbound URLs or request parameters.

## API

### `POST /api/upload.php`

Send `multipart/form-data` with a `resume` file field. Supported file extensions: `.pdf`, `.docx`, `.txt`; maximum file size: 5 MB.

Success response: `filename`, `size`, extracted `text`, character count, and any extraction warnings. Responses include an `X-Request-ID` header; handled errors also include the request ID in their JSON body.

The upload limit is also subject to PHP and reverse-proxy `post_max_size`/request-body settings. DOCX extraction accepts ordinary ZIP entries using stored or deflate compression, validates the document part and CRC, and refuses XML DTD/entity declarations. Legacy `.doc` is not supported. PDF extraction requires Composer dependencies; scanned PDFs return an OCR-unavailable error.

### `POST /api/analyze.php`

Send `Content-Type: application/json` with a `resume` string (80–20,000 characters). Returns an `analysis` JSON object or a non-2xx response with an `error`. Provider secrets and provider configuration are never accepted from the request.

The backend sends `POST {AI_BASE_URL}/chat/completions` with bearer authentication and JSON-object response format. The request must include `"consent": true`; an optional `preferences` object accepts `target_role`, two-letter `country`, and `city`. The AI integration is separate from the live research providers.

### `POST /api/market-research.php`

Send JSON `{ "role": "...", "skills": ["..."], "country": "IN", "city": "...", "consent": true }`; `country`, `city`, and `skills` are optional where appropriate. The backend queries Brave's HTTPS web-search endpoint using only career keywords and returns the query, source title/host/HTTPS URL, provider snippet, retrieval time, and request ID. This is source discovery, not a verified or AI-summarized claim. Set `BRAVE_SEARCH_API_KEY`.

### `POST /api/jobs.php`

Send JSON `{ "role": "...", "skills": ["..."], "country": "IN", "city": "...", "consent": true }`; optional `page` accepts 1–10. A supported country code is required. The endpoint queries Adzuna's HTTPS jobs API and returns listing title, company, location, a bounded description excerpt, source listing URL, posted/retrieved dates, and a match explanation. Repeated listing IDs and non-HTTPS/foreign destination hosts are discarded. The API requests at most 20 jobs per page.

When analyzed skills are available, the match score assigns 70 points proportionally to analyzed resume skills mentioned in the returned listing excerpt and 30 points when the full target-role text appears in its title. Without skill names, an exact role-title match is scored as 100 or 0. It is an excerpt keyword-overlap measure—not a probability of employment or proof that unmentioned skills are required. Adzuna may return a description snippet rather than a full job specification. The app labels every listing as retrieved, not independently verified as still open.

Adzuna salary values are shown only when the API returns a valid minimum/maximum pair and a supported country/currency mapping. The display identifies whether Adzuna marks values as estimated or reported to Adzuna, and explicitly says the pay period is unspecified. These listing-associated values are not market benchmarks.

## Privacy and security

- The interface describes and asks consent for sending resume text to the configured AI provider. Provider data retention is controlled by the provider; review its terms and obtain appropriate institutional approval before processing student data.
- A separate action and consent gate are required before Brave/Adzuna calls. Those providers receive only the selected role/location and AI-identified skill keywords, not the original resume. Provider retention/terms still need review.
- Uploaded files are size-limited, extension-checked, signature/content-checked, staged under a server-generated temporary filename, and deleted after extraction. The original browser filename is never used as a path.
- DOCX XML parsing disables network access and rejects DTD/entity declarations. ZIP contents are read but never extracted to the filesystem.
- API keys are server-side. The upload endpoint does not call an external service. No application database or intentional resume persistence exists.
- Outbound research URLs are fixed to the Brave/Adzuna HTTPS hosts, redirects are disabled, TLS verification is enabled, provider responses are size/time limited, and external result links are checked before display.
- Provider response fields and lengths are validated. Browser rendering uses `textContent`; model output is not treated as HTML.
- Do not enable request-body logging. Keep reverse-proxy/PHP logs free of uploaded contents, AI prompts, and secrets.
- The current stateless prototype has no authentication, CSRF/session workflow, edge rate limiting, per-user usage limits, or request concurrency budget.

## Testing

Run backend tests:

```powershell
php tests/run.php
```

Syntax checks:

```powershell
php -l src/ResumeAnalyzer.php
php -l src/ResumeParser.php
php -l src/JsonHttpClient.php
php -l src/ProviderData.php
php -l src/BraveSearchProvider.php
php -l src/AdzunaJobProvider.php
php -l public/index.php
php -l public/api/analyze.php
php -l public/api/upload.php
php -l public/api/market-research.php
php -l public/api/jobs.php
php -l tests/run.php
```

The suite uses mocked AI/search/job transports and generated in-memory DOCX ZIP fixtures; it requires no real credentials. Live AI, Brave, Adzuna, and PDF parser requests are not verified by the local suite. Exercise uploads through the app or test client, as the upload API requires a genuine HTTP file upload.

## Implementation status and roadmap

**Status: local prototype, not ready for a live student pilot or production deployment.**

Completed in the current milestones: repository audit, upload/review UI, bounded TXT/DOCX extraction, optional PDF parser integration, preference-aware analysis requests, separate consent for external research, provider adapters and endpoints for Brave Search and Adzuna, transparent listing-overlap scores, and mocked provider/parser tests.

Still needed for the requested career-intelligence platform:

1. Complete parser/provider integration validation with a Composer lock file, representative PDF fixtures, live credentials, and a PHP installation with OpenSSL and required extensions.
2. Expand evidence-based analysis with a transparent resume-quality rubric, detailed education/project/experience findings, before/after editing, and CSV/PDF exports.
3. Independently verify open job status and official employer application links; improve source freshness and job experience/employment filters.
4. Build skills-gap, company, project recommendations, salary benchmarks, and editable 30/60/90-day learning plans grounded in verified sources.
5. Add persistence only if saved reports/progress are required, with consent, deletion, and documented retention.
6. Add broader upload-security, browser/accessibility, rate-limit, load, privacy, and model-quality evaluation.
7. Deploy behind HTTPS with secrets management, rate/spend controls, monitoring, provider retention review, incident procedures, and a tested backup/recovery plan.

Never label an AI-generated estimate as verified market data, a job as live without a traceable source, a match score as hiring probability, or an unearned skill as a candidate qualification.

## Production deployment checklist

- Use PHP-FPM or managed PHP hosting behind HTTPS, not `php -S`.
- Enable required extensions; generate and commit `composer.lock`; deploy with `composer install --no-dev`.
- Set request-body limits consistently at PHP and proxy levels; confirm temporary directories are private and regularly monitored for cleanup failures.
- Add gateway rate limits, abuse protection, concurrency limits, provider quotas/spend caps, and operational monitoring before making the service public.
- Obtain institutional privacy/security approval and check AI-provider retention, processing, and residency terms before accepting real resumes.
- Add reliable outbound network controls, log redaction, alerting, accessibility/security tests, and a rollback/recovery plan.
- Configure and validate a real provider with a non-sensitive test resume in the deployment environment. No live integration is claimed by this repository's automated tests.
