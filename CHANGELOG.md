# Changelog

All notable changes to the RAIL Score Drupal module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.4] - 2026-03-21

### Added

#### Telemetry infrastructure (Python SDK feature parity)
- `RailScoreAuditLogger` service: records every API request with timing, score,
  confidence, mode, domain, dimension scores, cache status, and error details
- `RailScoreAuditLogger::getMetricsSummary()`: aggregate metrics (total requests,
  error rate, average duration, cache hit rate, score distribution by band)
- `RailScoreAuditLogger::getOpenIncidents()`: query open incidents with optional date range
- `RailScoreAuditLogger::getRecentAuditLog()`: query audit log with optional date range
- `RailScoreAuditLogger::logIncident()`: raise structured incidents with unique IDs
  (`inc_<12-char-hex>`), severity, type, affected dimensions, and entity context
- `RailScoreAuditLogger::logComplianceIncident()`: auto-raise incident when compliance
  score breaches threshold
- `RailScoreAuditLogger::logScoreBreach()`: auto-raise incident on RAIL score breach
- `RailScoreAuditLogger::updateIncidentStatus()`: acknowledge or resolve incidents

#### Human review queue
- `RailScoreReviewQueue` service: persistent per-dimension review queue backed by DB
- `checkAndEnqueue()`: inspect all 8 dimensions from an eval result and enqueue any
  below the configured threshold
- `pending()`, `drain()`, `size()`, `pendingByDimension()`, `updateItemStatus()`
- All query methods accept optional `$start` and `$end` Unix timestamp params
  for date range filtering

#### Database schema
- `rail_score_audit_log` table: per-request telemetry
- `rail_score_incidents` table: incidents with status lifecycle (open / acknowledged / resolved)
- `rail_score_review_queue` table: per-dimension review items
- `rail_score_update_9001()` hook for existing installs

#### API client updates
- Fixed endpoints to match API v2.3.0: `/railscore/v1/eval`, `/railscore/v1/compliance/check`,
  `/railscore/v1/usage`, `/health`
- `evaluate()` now reads `domain`, `usecase`, `include_explanations`, `include_issues`,
  `include_suggestions` from config as defaults; all overridable per call
- `checkCompliance()` supports single and multi-framework requests; reads
  `compliance_frameworks` and `compliance_strict_mode` from config
- `checkGdprCompliance()` kept as a deprecated alias for backward compatibility
- Typed Guzzle exception handling: `ClientException`, `ServerException`, `GuzzleException`
- Per-status error messages (400, 401, 402, 403, 422, 429, 500, 503, etc.)
- Audit logger wired into every request and failure path

#### Configuration form additions
- **Evaluation Mode** radio (basic / deep)
- **Evaluation Context** fieldset: domain, use case, include_explanations,
  include_issues, include_suggestions
- **Compliance Settings** fieldset: enable toggle, framework checkboxes (GDPR, CCPA,
  HIPAA, EU AI Act, India DPDP, India AI Gov), strict mode, compliance incident threshold
- **Telemetry Settings** fieldset: configurable review queue threshold
- **Drupal AI Module Integration** fieldset: enable toggle (grayed out when AI module absent)
- Validation: review queue threshold must be below publish threshold; compliance requires
  at least one framework; max 5 frameworks enforced

#### Dashboard enhancements
- Date range filter form at top of dashboard (`?start=YYYY-MM-DD&end=YYYY-MM-DD`)
- **API Telemetry** section: total requests, error rate, average duration, cache hit rate,
  score distribution (low/mid/high), per-operation breakdown
- **Open Incidents** table: severity, type, title, score/threshold, affected dimensions,
  Acknowledge and Resolve action links (requires `manage rail_score incidents`)
- **Human Review Queue** table: dimension, score/threshold, issues, content preview,
  queued time, linked incident, Mark Reviewed and Dismiss action links
  (requires `manage rail_score review queue`)
- `RequestStack` injected for date query param reading

#### New routes
- `rail_score.incident_action`: `/admin/reports/rail-score/incident/{id}/{action}`
- `rail_score.review_item_action`: `/admin/reports/rail-score/review/{id}/{action}`

#### New permissions
- `manage rail_score incidents`
- `manage rail_score review queue`

#### Drupal AI module integration
- `AiResponseScorerSubscriber`: event subscriber that scores every response generated
  by any Drupal AI provider via `ai.generate_response.post`; uses `class_exists()` guard
  so the dependency is fully optional at runtime
- `RailScoreInterventionPlugin`: AI Automators intervention plugin for field-level scoring
  with optional block-on-low-score config
- New service: `rail_score.ai_response_scorer`
- `suggests: ai:ai` added to `rail_score.info.yml`

#### Config and schema
- All new settings added to `config/install/rail_score.settings.yml`
- All new settings added to `config/schema/rail_score.schema.yml`

### Fixed
- Corrected 8th dimension from `legal_compliance` to `inclusivity` in config form,
  module help text, and API documentation
- Removed dead code in `hook_entity_presave` (deletion check that could never be true)
- Replaced `match()` expression in `RailScoreAuditLogger` with `if/elseif` for PHP 7.4
  compatibility (Drupal 9 minimum)
- `include_suggestions` no longer always sent as `false` when not configured; now only
  included in the API payload when explicitly set
- Fixed docblock example in `RailScoreReviewQueue` that used PHP 8 named argument syntax
- `services.yml`: added missing `@rail_score.audit_logger` argument to entity_subscriber

### Upgrade notes
- Run `drush updatedb -y` to create the three new database tables
- Clear cache: `drush cr`
- No breaking changes to existing configuration

---

## [1.0.2] - 2025-11-06

### Fixed
- **Test Connection button**: now tests with values currently in the form, not previously
  saved config
- **API endpoints**: updated `testConnection()` to use the correct RAIL Score health endpoint;
  removed stale `/verify` and `/health/check` calls
- Improved error logging with clearer per-status messages

### Changed
- `testConnection()` accepts optional `$api_key` and `$base_url` params
- Better timeout handling (10 seconds for health check)

---

## [1.0.1] - 2025-10-xx

Minor fix release.

---

## [1.0.0] - 2024-11-04

### Added
- Initial release
- `RailScoreClient` service: `evaluate()`, `checkGdprCompliance()`, `getUsageStats()`,
  `testConnection()`
- Admin configuration form at `/admin/config/content/rail-score`
- Statistics dashboard at `/admin/reports/rail-score`
- `hook_entity_presave()` for automatic evaluation on save
- Queue worker `rail_score_evaluation` for batch processing
- `RailScoreFormatter` field formatter with badge, progress bar, text, and full widget modes
- Three permissions: administer, view dashboard, evaluate content
- Twig templates for dashboard and score widget
- Helper functions `rail_score_get_score()` and `rail_score_passes_threshold()`
