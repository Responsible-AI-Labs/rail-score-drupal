# RAIL Score

Drupal integration for the RAIL Score API - automated responsible AI content evaluation and moderation.

## Overview

The RAIL Score module integrates the [RAIL Score API](https://responsibleailabs.ai) into Drupal, providing automated content evaluation based on responsible AI principles. Evaluate content across multiple dimensions including safety, privacy, fairness, transparency, accountability, reliability, legal compliance, and user impact.

## Features

- **Automatic Content Evaluation**: Evaluate content automatically when saved
- **Multi-Dimensional Scoring**: Assess content across 8 key dimensions
- **Configurable Thresholds**: Set custom quality thresholds for your content
- **Auto-Moderation**: Automatically unpublish content below quality thresholds
- **Dashboard**: View usage statistics and recent evaluations
- **Queue Processing**: Batch evaluate large amounts of content
- **Field Formatter**: Multiple display options for RAIL Scores
- **GDPR Compliance Checking**: Verify content meets GDPR requirements
- **Extensive Logging**: Track all evaluations and API calls

## Requirements

- Drupal ^9 || ^10 || ^11
- PHP >= 8.1
- Guzzle HTTP client (included in Drupal core)
- RAIL Score API key ([get one here](https://responsibleailabs.ai))

## Installation

### Using Composer (Recommended)

```bash
composer require drupal/rail_score
drush en rail_score -y
```

### Manual Installation

1. Download the module and extract to `modules/contrib/rail_score`
2. Enable the module:
   ```bash
   drush en rail_score -y
   ```
   Or via admin UI: `/admin/modules`

## Configuration

### Initial Setup

1. **Get an API Key**
   - Visit [responsibleailabs.ai](https://responsibleailabs.ai)
   - Sign up and obtain your API key

2. **Configure the Module**
   - Navigate to: `/admin/config/content/rail-score`
   - Enter your API key
   - Test the connection
   - Configure evaluation settings

3. **Add RAIL Score Field (Optional but Recommended)**
   ```bash
   drush field:create node article field_rail_score \
     --field-type=decimal \
     --field-label="RAIL Score"
   ```

   Or via admin UI:
   - Go to Structure > Content types > [Your Type] > Manage fields
   - Add field: Type = Decimal, Label = "RAIL Score"

4. **Enable Content Types**
   - In the configuration form, select which content types to evaluate
   - Set your quality threshold (default: 7.0)
   - Choose evaluation dimensions

### Configuration Options

| Setting | Description | Default |
|---------|-------------|---------|
| **API Key** | Your RAIL Score API key | Required |
| **Base URL** | API endpoint URL | `https://api.responsibleailabs.ai` |
| **Auto Evaluate** | Evaluate content on save | Enabled |
| **Threshold** | Minimum acceptable score | 7.0 |
| **Auto Unpublish** | Unpublish low-scoring content | Disabled |
| **Dimensions** | Which dimensions to evaluate | All |
| **Content Types** | Which content types to evaluate | None |

## Usage

### Automatic Evaluation

Once configured, content is automatically evaluated when saved:

1. Create or edit content
2. Save the content
3. RAIL Score is automatically calculated and stored
4. Warning message appears if below threshold
5. Content auto-unpublished if configured

### Manual Evaluation via Queue

For batch processing or re-evaluation:

```php
// Add item to queue
$queue = \Drupal::queue('rail_score_evaluation');
$queue->createItem([
  'entity_type' => 'node',
  'entity_id' => 123,
  'options' => ['dimensions' => ['safety', 'privacy']],
]);
```

Process queue:
```bash
drush queue:run rail_score_evaluation
```

### Programmatic Usage

```php
// Get the RAIL Score client
$client = \Drupal::service('rail_score.client');

// Evaluate content
$result = $client->evaluate('Your content here', [
  'dimensions' => ['safety', 'privacy', 'fairness'],
]);

if ($result && isset($result['result']['rail_score']['score'])) {
  $score = $result['result']['rail_score']['score'];
  // Do something with the score
}

// Check GDPR compliance
$compliance = $client->checkGdprCompliance('Your content', [
  'data_type' => 'user_profile',
  'processing_purpose' => 'marketing',
]);

// Get usage statistics
$stats = $client->getUsageStats();
```

### Helper Functions

```php
// Get RAIL Score for an entity
$score = rail_score_get_score($node);

// Check if content passes threshold
$passes = rail_score_passes_threshold($node);
```

## Field Formatter

The module includes a configurable field formatter with multiple display modes:

1. **Badge Mode**: Simple score badge
2. **Progress Bar**: Visual progress bar
3. **Text Only**: Plain text display
4. **Full Widget**: Complete widget with dimension breakdown

Configure at: Structure > Content types > [Type] > Manage display

## Dashboard

View comprehensive statistics at: `/admin/reports/rail-score`

Features:
- API usage statistics
- Local evaluation statistics
- Recent evaluations table
- Score trends
- Quick actions

## Permissions

The module provides three permissions:

| Permission | Description |
|------------|-------------|
| **Administer RAIL Score** | Configure settings (restricted access) |
| **View RAIL Score dashboard** | Access the dashboard |
| **Evaluate content with RAIL Score** | Manually trigger evaluations |

Configure at: `/admin/people/permissions`

## Dimensions

RAIL Score evaluates content across 8 dimensions:

1. **Safety**: Content safety and harm prevention
2. **Privacy**: Data privacy and protection
3. **Fairness**: Bias detection and fairness
4. **Transparency**: Clarity and explainability
5. **Accountability**: Responsibility and traceability
6. **Reliability**: Accuracy and consistency
7. **Legal Compliance**: Regulatory compliance
8. **User Impact**: Effect on users and stakeholders

## Troubleshooting

### Connection Issues

**Problem**: "Connection failed" error
- Verify API key is correct
- Check base URL is accessible
- Ensure outbound HTTPS connections allowed
- Review Drupal logs: `/admin/reports/dblog`

### No Scores Appearing

**Problem**: Content not being evaluated
- Verify content type is enabled in settings
- Check auto-evaluate is enabled
- Confirm field_rail_score field exists
- Review logs for API errors

### Performance Issues

**Problem**: Slow content saving
- Consider disabling auto-evaluate
- Use queue worker for batch processing
- Reduce number of evaluated dimensions
- Check API response times

### Low Scores

**Problem**: Unexpected low scores
- Review dimension-specific scores in logs
- Check content quality and completeness
- Verify appropriate threshold setting
- Consider content improvements

## Development

### Running Tests

```bash
# Functional tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/

# With coverage
./vendor/bin/phpunit -c core \
  --coverage-html build/coverage \
  modules/contrib/rail_score/tests/
```

### Coding Standards

```bash
# Check standards
./vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  modules/contrib/rail_score/

# Fix automatically
./vendor/bin/phpcbf --standard=Drupal,DrupalPractice \
  modules/contrib/rail_score/
```

## API Reference

### RailScoreClient Service

Main service for API interactions:

```php
$client = \Drupal::service('rail_score.client');

// Methods:
$client->evaluate(string $content, array $options = []);
$client->checkGdprCompliance(string $content, array $context = []);
$client->getUsageStats();
$client->testConnection();
```

### Events

The module dispatches standard Drupal entity events. Subscribe via event subscribers.

### Hooks

- `hook_rail_score_entity_presave()` - Modify evaluation before save
- Standard Drupal hooks: `hook_entity_presave()`, `hook_theme()`, etc.

## Support

- **Issues**: [GitHub Issues](https://github.com/responsibleailabs/rail-score-drupal/issues)
- **Documentation**: [Full documentation](https://docs.responsibleailabs.ai/drupal)
- **API Docs**: [RAIL Score API](https://responsibleailabs.ai/docs)
- **Drupal.org**: [Project page](https://www.drupal.org/project/rail_score)

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Follow Drupal coding standards
4. Add tests for new features
5. Submit a pull request

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

**Developed by**: Responsible AI Labs
**Website**: [responsibleailabs.ai](https://responsibleailabs.ai)
**Maintained by**: The RAIL Score community

---

**Version**: 1.0.0
**Drupal Compatibility**: 9.x, 10.x, 11.x
**Last Updated**: 2024
