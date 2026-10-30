# Changelog

## [0.3.0] - 2026-10-30

### Added

- Optional target role, country, and city preferences for resume analysis.
- Brave Search web research with source URLs, provider snippets, and retrieval timestamps.
- Adzuna job discovery with listing source URLs, retrieval/posted dates, disclosed salary provenance, and an explainable keyword-overlap score.
- Separate consent-gated search actions that send only career keywords and selected location, not the original resume.
- Bounded HTTPS JSON transport restricted to provider hosts, with TLS verification, timeouts, disabled redirects, response-size limits, and injectable mock transport.

### Changed

- Expanded analysis and research API schemas to accept validated preferences and explicit consent.
- Updated the UI to show provider results, source citations, job metadata, and the limitations of match scores and salary periods.
- Added server environment-variable examples for Brave and Adzuna.

### Fixed

- Reject unsafe provider result URLs and duplicate job listings; upgrade only known Adzuna HTTP listing links to HTTPS.
- Keep externally supplied search/job data as text and make provider errors explicit without logging credentials or resume content.
- Invalidate stale analysis and live-search results when resume or target preferences change.

### Security

- Restrict outbound search requests to fixed HTTPS providers and validate external result links before rendering.
- Require separate consent before research/job requests; no raw resume content is sent to search providers.

### Testing

- Added mocked Brave and Adzuna transport tests for query parameters, source validation, empty results, job deduplication, salary provenance, matching, and provider failures.
- Live provider calls remain unverified; this environment has no provider credentials or OpenSSL.

### Documentation

- Documented provider setup and API contracts, match-score formula, salary limitations, and live-data verification boundaries.

## [0.2.0] - 2026-10-30

### Added

- Repository architecture and runtime capability audit.
- Drag-and-drop and file-picker resume upload with an upload/review/analyze step indicator and editable extraction preview.
- Size-limited, random-temporary-file upload API with cleanup and request IDs.
- UTF-8 TXT extraction and bounded DOCX ZIP/XML extraction for document paragraphs and tables.
- Optional PDF text extraction through `smalot/pdfparser`; scanned PDFs clearly report that OCR is unavailable.
- Focused parser tests using generated DOCX ZIP fixtures.

### Changed

- Replaced the short README with project objectives, architecture, upload/API details, setup, environment, privacy, test, roadmap, and deployment documentation.
- Added Composer metadata for the optional PDF parser.

### Fixed

- Reject malformed DOCX archive metadata, CRC mismatches, unsupported compression, malformed XML, and empty or oversized document contents.

### Security

- Verify upload method, content type, size, filename extension, upload origin, and PDF/DOCX signatures before parsing.
- Use a private, server-generated temporary path; delete it after extraction and avoid logging resume content.
- Parse DOCX XML with network access disabled and reject DTD/entity declarations.

### Testing

- Added automated TXT/DOCX parser and malformed-input tests.
- AI transport is mocked in tests; real PDF parser integration is unverified locally because the environment lacks Composer and OpenSSL.

### Documentation

- Documented that live jobs, market research, salary information, OCR, saved reports, and roadmaps are not yet implemented.
- Documented missing deployment prerequisites and remaining production-readiness work.
