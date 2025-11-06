# Changelog

All notable changes to the RAIL Score Drupal module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [1.0.2] - 2025-11-06

### Fixed
- **Test Connection Button**: Fixed AJAX callback to use form values instead of saved configuration when testing API connection. The "Test Connection" button now properly tests with the API key and base URL currently entered in the form fields, not previously saved values. (Issue: Connection test was always using old/empty credentials)
- **API Connection Testing**: Updated `testConnection()` method to support the correct RAIL Score API endpoints due to upstream changes by Responsible AI Labs:
  - Now checks root endpoint (`/`) to verify API availability (no authentication required)
  - Falls back to `/health/check` endpoint if root endpoint is unavailable
  - Uses `/verify` endpoint for API key validation with proper authentication
  - Improved error handling and logging for different response scenarios (200, 401, 403, 404)
  - Better timeout handling (10 seconds instead of 30)

### Changed
- **RailScoreClient::testConnection()**: Method signature updated to accept optional `$api_key` and `$base_url` parameters, allowing both form-based testing and programmatic testing
- Enhanced error logging with clearer messages to help diagnose connection issues
- Added `http_errors: FALSE` option to handle non-200 responses gracefully
- Updated to use current RAIL Score API endpoints as specified by Responsible AI Labs

### Technical Details
- **Modified Files**:
  - `src/Form/RailScoreConfigForm.php` (lines 204-208)
  - `src/RailScoreClient.php` (lines 215-295)
- **Affected Version**: 1.0.0
- **External Dependency**: RAIL Score API endpoint changes by Responsible AI Labs

### Upgrade Notes
- No database updates required
- No configuration changes needed
- Fully backward compatible with 1.0.0
- Clear Drupal cache after updating: `drush cr`


## [1.0.1] - Minor Fix

## [1.0.0] - 2024-11-04

### Added

#### Core Features
- Initial release of RAIL Score Drupal module
- Full integration with RAIL Score API for content evaluation
- Support for Drupal 9, 10, and 11
- PHP 8.1+ compatibility

#### API Client
- `RailScoreClient` service for API interactions
- Content evaluation with `evaluate()` method
- GDPR compliance checking with `checkGdprCompliance()` method
- Usage statistics retrieval with `getUsageStats()` method
- Connection testing with `testConnection()` method
- Comprehensive error handling and logging

#### Configuration
- Admin configuration form at `/admin/config/content/rail-score`
- API key and base URL configuration
- Configurable quality threshold (0-10 scale)
- Auto-evaluation toggle
- Auto-unpublish low-scoring content option
- Selectable evaluation dimensions (8 dimensions)
- Content type selection for evaluation
- AJAX connection testing
- Form validation with detailed error messages

#### Dashboard
- Statistics dashboard at `/admin/reports/rail-score`
- API usage statistics display
- Local evaluation statistics
- Recent evaluations table with sorting
- Score-based visual indicators
- Quick action links
- Empty state messaging

#### Automatic Evaluation
- `hook_entity_presave()` implementation
- Automatic content evaluation on save
- Multi-field content extraction (title, body, custom fields)
- Threshold checking and warnings
- Auto-unpublish capability for low-scoring content
- Dimension score logging

#### Queue System
- `RailScoreEvaluationWorker` queue worker plugin
- Batch content evaluation support
- Cron-based processing (60 seconds per run)
- Error handling and retry logic
- Full result data storage option

#### Field Formatter
- `RailScoreFormatter` field formatter plugin
- Multiple display modes:
  - Badge mode
  - Progress bar mode
  - Text only mode
  - Full widget mode
- Configurable decimal places (0-2)
- Threshold indicator option
- Responsive design

#### Templates
- `rail-score-dashboard.html.twig` for dashboard display
- `rail-score-widget.html.twig` for score widgets
- Twig-based theming with proper documentation
- Score legend and status indicators
- Fully translatable strings

#### Styling & JavaScript
- Comprehensive admin CSS (`rail-score-admin.css`)
- Responsive design for mobile devices
- Visual score indicators (high/medium/low)
- Interactive JavaScript behaviors
- Drupal behaviors for dashboard and forms
- Real-time threshold display
- Auto-unpublish warning toggle
- Score-based row highlighting

#### Permissions
- `administer rail_score` - Configure module settings (restricted)
- `view rail_score dashboard` - Access statistics dashboard
- `evaluate content with rail_score` - Manually trigger evaluations

#### Developer Features
- PSR-4 autoloading
- Dependency injection throughout
- Event subscriber for entity operations
- Service container configuration
- Configuration schema definitions
- Helper functions (`rail_score_get_score()`, `rail_score_passes_threshold()`)
- Comprehensive hook implementations

#### Documentation
- Complete README.md with installation instructions
- API usage examples
- Troubleshooting guide
- Development guidelines
- Inline code documentation (PHPDoc)
- Twig template documentation
- MIT License

#### Logging
- Structured logging with context
- Evaluation tracking
- API error logging
- Configuration change tracking
- Cron run logging

#### Security
- Input validation on all forms
- Output sanitization in templates
- API key secure storage in configuration
- Access control via permissions
- CSRF protection (automatic in Drupal forms)
- SQL injection prevention via Entity API

#### Testing
- Functional test suite foundation
- Configuration form tests
- Test base classes
- PHPUnit integration

### Configuration Schema

```yaml
rail_score.settings:
  - api_key (string)
  - base_url (string)
  - auto_evaluate (boolean)
  - threshold (float, 0-10)
  - auto_unpublish (boolean)
  - dimensions (sequence of strings)
  - enabled_content_types (sequence of strings)
```

### Service Definitions

- `rail_score.client` - Main API client service
- `rail_score.entity_subscriber` - Entity event subscriber

### Routes

- `rail_score.settings` - Configuration form
- `rail_score.dashboard` - Statistics dashboard

### Plugin Types

- QueueWorker: `rail_score_evaluation`
- FieldFormatter: `rail_score_formatter`

### Theme Hooks

- `rail_score_dashboard`
- `rail_score_widget`

### Dependencies

- drupal:field
- drupal:node
- drupal:user
- guzzlehttp/guzzle ^7.0

## [Unreleased]

### Planned Features
- Bulk content re-evaluation UI
- Custom field support configuration
- Advanced dimension weight configuration
- API rate limiting handling
- Content score history tracking
- Exportable reports
- Integration with Drupal moderation workflows
- Multilingual support for evaluations
- Scheduled evaluation cron jobs
- Score trending and analytics
- REST API endpoints for external integrations
- Webhooks for real-time notifications

### Known Issues
- None reported

---

For more information, visit [https://www.drupal.org/project/rail_score](https://www.drupal.org/project/rail_score)
