# RAIL Score Module - Testing Guide

Complete testing guide for Drupal 9, 10, and 11 compatibility.

## 🎯 Testing Overview

This module has been built following Drupal coding standards and should work seamlessly across:
- **Drupal 9.x** (PHP 8.0+)
- **Drupal 10.x** (PHP 8.1+)
- **Drupal 11.x** (PHP 8.3+)

---

## 📋 Pre-Testing Checklist

### Environment Requirements

- [ ] Drupal 9.x, 10.x, or 11.x installed
- [ ] PHP 8.1 or higher
- [ ] Composer installed
- [ ] Drush installed (recommended)
- [ ] RAIL Score API key (from responsibleailabs.ai)

### Database Backup

```bash
# Always backup before testing
drush sql:dump > backup-$(date +%Y%m%d-%H%M%S).sql
```

---

## 🚀 Installation Testing

### Method 1: Composer Installation (Recommended)

```bash
# Navigate to your Drupal root
cd /path/to/drupal

# Option A: If published on Packagist
composer require drupal/rail_score

# Option B: Local development
composer config repositories.rail_score path /Users/sumitverma/Desktop/rail-score-drupal
composer require drupal/rail_score:@dev

# Enable the module
drush en rail_score -y

# Clear cache
drush cr

# Verify installation
drush pml | grep rail_score
```

**Expected Output:**
```
✓ Rail Score (rail_score)    Enabled    1.0.0
```

### Method 2: Manual Installation

```bash
# Copy module to Drupal modules directory
cp -r /Users/sumitverma/Desktop/rail-score-drupal /path/to/drupal/modules/contrib/rail_score

# Enable via Drush
drush en rail_score -y

# Or enable via UI
# Visit: /admin/modules
# Find "RAIL Score" and enable
```

### Installation Verification

```bash
# Check module is enabled
drush pml --status=enabled | grep rail_score

# Check services are registered
drush devel:services | grep rail_score

# Check routes are available
drush route:list | grep rail_score

# Check configuration is installed
drush config:get rail_score.settings
```

**Expected Configuration:**
```yaml
api_key: ''
base_url: 'https://api.responsibleailabs.ai'
auto_evaluate: true
threshold: 7
auto_unpublish: false
dimensions: {  }
enabled_content_types: {  }
```

---

## 🔧 Configuration Testing

### Test 1: Access Configuration Form

```bash
# Login as admin
drush uli

# Visit configuration page
# URL: /admin/config/content/rail-score
```

**Checklist:**
- [ ] Form loads without errors
- [ ] All fields are visible
- [ ] Help text is displayed
- [ ] No PHP warnings/errors in logs

### Test 2: Form Validation

**Test Invalid API Key:**
```
1. Enter API key: "short"
2. Click "Save configuration"
3. Expected: "API key must be at least 10 characters"
```

**Test Invalid URL:**
```
1. Enter Base URL: "not-a-url"
2. Click "Save configuration"
3. Expected: "Base URL must be a valid URL"
```

**Test Invalid Threshold:**
```
1. Enter Threshold: 15
2. Click "Save configuration"
3. Expected: "Threshold must be between 0 and 10"
```

### Test 3: Save Valid Configuration

```
1. API Key: [Your actual API key]
2. Base URL: https://api.responsibleailabs.ai
3. Auto Evaluate: Checked
4. Threshold: 7.0
5. Auto Unpublish: Unchecked
6. Dimensions: Check "Safety" and "Privacy"
7. Content Types: Check "Article"
8. Click "Save configuration"
9. Expected: "The configuration options have been saved"
```

**Verify via Drush:**
```bash
drush config:get rail_score.settings
```

---

## 📊 Dashboard Testing

### Test 4: Access Dashboard

```bash
# Visit dashboard
# URL: /admin/reports/rail-score
```

**Checklist:**
- [ ] Dashboard loads without errors
- [ ] "API Usage Statistics" section visible
- [ ] "Recent Evaluations" section visible
- [ ] No JavaScript errors in browser console
- [ ] CSS styles applied correctly

### Test 5: Dashboard Permissions

**Test as Anonymous User:**
```bash
# Logout
drush user:logout

# Try to access: /admin/reports/rail-score
# Expected: 403 Access Denied
```

**Test as Authenticated User (no permissions):**
```bash
# Create user without permissions
drush user:create testuser --mail=test@example.com --password=test

# Login as testuser
# Try to access: /admin/reports/rail-score
# Expected: 403 Access Denied
```

**Grant Permission:**
```bash
# Grant view dashboard permission
drush role:perm:add authenticated 'view rail_score dashboard'

# Login as testuser again
# Try to access: /admin/reports/rail-score
# Expected: 200 OK, dashboard visible
```

---

## 📝 Content Evaluation Testing

### Test 6: Create Field for Scores

```bash
# Create field on Article content type
drush field:create node article field_rail_score \
  --field-type=decimal \
  --field-label="RAIL Score"

# Verify field was created
drush field:list node:article | grep rail_score
```

**Expected Output:**
```
field_rail_score   RAIL Score   decimal
```

### Test 7: Configure Content Type

1. Go to: `/admin/config/content/rail-score`
2. Under "Enabled Content Types", check "Article"
3. Save configuration
4. Verify:
   ```bash
   drush config:get rail_score.settings enabled_content_types
   ```

### Test 8: Create Content and Test Auto-Evaluation

**Note:** This requires a valid API key and will make actual API calls.

```bash
# Create test article
drush generate:content --bundles=article 1

# Or create via UI:
# Visit: /node/add/article
# Title: "Test Article for RAIL Score"
# Body: "This is test content for RAIL Score evaluation. It should be evaluated automatically when saved."
# Click "Save"
```

**Expected Behavior:**
- [ ] Content saves successfully
- [ ] Message appears: "Content evaluated successfully with a RAIL Score of X/10"
- [ ] Score is stored in field_rail_score field
- [ ] Check logs: `drush watchdog:show --type=rail_score`

**Verify Score:**
```bash
# Get the node ID (NID) from the created node
drush sql:query "SELECT nid, title, field_rail_score_value FROM node_field_data LEFT JOIN node__field_rail_score ON node_field_data.nid = node__field_rail_score.entity_id WHERE type = 'article' LIMIT 5"
```

### Test 9: Test Threshold Enforcement

**Setup:**
1. Set threshold to 8.0 in configuration
2. Create content that might score below 8.0
3. Save content

**Expected:**
- [ ] Warning message if score < 8.0: "Content has a RAIL Score of X, which is below the threshold of 8.0"
- [ ] Content remains published (if auto_unpublish is disabled)

### Test 10: Test Auto-Unpublish

**Setup:**
1. Enable "Auto-unpublish low-scoring content"
2. Set threshold to 9.0 (high threshold for testing)
3. Create simple content
4. Save

**Expected:**
- [ ] Warning: "Content has a RAIL Score of X, which is below the threshold"
- [ ] Warning: "Content has been automatically unpublished due to low RAIL Score"
- [ ] Content status = Unpublished

**Verify:**
```bash
drush sql:query "SELECT nid, title, status, field_rail_score_value FROM node_field_data LEFT JOIN node__field_rail_score ON node_field_data.nid = node__field_rail_score.entity_id WHERE type = 'article' ORDER BY nid DESC LIMIT 1"
```

---

## 🔌 Service & API Testing

### Test 11: Test RAIL Score Client Service

**Create test script:** `test-rail-score-client.php`

```php
<?php

use Drupal\rail_score\RailScoreClient;

// Get service
$client = \Drupal::service('rail_score.client');

// Test connection
$connected = $client->testConnection();
echo "Connection test: " . ($connected ? "SUCCESS" : "FAILED") . "\n";

// Test evaluation
$result = $client->evaluate("This is test content for evaluation.");
if ($result) {
  echo "Evaluation result:\n";
  print_r($result);
} else {
  echo "Evaluation failed\n";
}

// Test GDPR compliance
$compliance = $client->checkGdprCompliance("User data here", [
  'data_type' => 'user_profile',
]);
if ($compliance) {
  echo "GDPR compliance check:\n";
  print_r($compliance);
}

// Test usage stats
$stats = $client->getUsageStats();
if ($stats) {
  echo "Usage statistics:\n";
  print_r($stats);
}
```

**Run via Drush:**
```bash
drush php:script test-rail-score-client.php
```

### Test 12: Test Helper Functions

```bash
drush php:eval "
  \$node = \Drupal::entityTypeManager()->getStorage('node')->load(1);
  \$score = rail_score_get_score(\$node);
  echo 'Score: ' . \$score . '\n';
  \$passes = rail_score_passes_threshold(\$node);
  echo 'Passes threshold: ' . (\$passes ? 'YES' : 'NO') . '\n';
"
```

---

## 🎨 Field Formatter Testing

### Test 13: Configure Field Formatter

1. Go to: `/admin/structure/types/manage/article/display`
2. Find "RAIL Score" field
3. Change format to "RAIL Score Display"
4. Click settings icon
5. Test each display mode:
   - Badge
   - Progress Bar
   - Text Only
   - Full Widget
6. Save

**View node to verify:**
```bash
# View a node with RAIL Score
# URL: /node/[NID]
```

**Checklist:**
- [ ] Badge mode displays correctly
- [ ] Progress bar shows proper width
- [ ] Text only shows score
- [ ] Widget displays all dimensions
- [ ] Styles applied correctly
- [ ] No JavaScript errors

---

## ⚙️ Queue Worker Testing

### Test 14: Queue Processing

**Add item to queue:**
```bash
drush php:eval "
  \$queue = \Drupal::queue('rail_score_evaluation');
  \$queue->createItem([
    'entity_type' => 'node',
    'entity_id' => 1,
    'options' => ['dimensions' => ['safety', 'privacy']],
  ]);
  echo 'Item added to queue\n';
"
```

**Process queue:**
```bash
# Process queue
drush queue:run rail_score_evaluation

# Check watchdog logs
drush watchdog:show --type=rail_score --count=10
```

**Expected in logs:**
- `[RAIL Score] Processing queued evaluation for node:1`
- `[RAIL Score] ✓ Queue evaluation complete for [title]: X/10`

---

## 🧪 Automated Testing

### Test 15: Run PHPUnit Tests

```bash
# From Drupal root
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/

# Run specific test
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/src/Functional/RailScoreTest.php

# With verbose output
./vendor/bin/phpunit -c core --verbose modules/contrib/rail_score/tests/

# Generate coverage report
./vendor/bin/phpunit -c core \
  --coverage-html build/coverage \
  modules/contrib/rail_score/tests/
```

**Expected Tests:**
- ✅ testConfigurationForm
- ✅ testDashboard
- ✅ testModuleInstallation
- ✅ testServices
- ✅ testHelperFunctions
- ✅ testPermissions
- ✅ testMenuLinks
- ✅ testHelpPage
- ✅ testQueueWorker
- ✅ testFieldFormatter
- ✅ testThemeHooks
- ✅ testLibraries

### Test 16: Code Standards

```bash
# Check coding standards
./vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/contrib/rail_score/

# Check specific file
./vendor/bin/phpcs --standard=Drupal modules/contrib/rail_score/src/RailScoreClient.php

# Auto-fix issues
./vendor/bin/phpcbf --standard=Drupal modules/contrib/rail_score/
```

**Expected:** 0 errors, 0 warnings

---

## 🌐 Multi-Version Testing

### Drupal 9 Specific Tests

```bash
# Verify PHP version
php -v
# Expected: PHP 8.0 or higher

# Check Drupal version
drush status | grep "Drupal version"
# Expected: 9.x

# Run all tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

### Drupal 10 Specific Tests

```bash
# Verify PHP version
php -v
# Expected: PHP 8.1 or higher

# Check Drupal version
drush status | grep "Drupal version"
# Expected: 10.x

# Test with Symfony 6 components
drush php:eval "echo 'Symfony version: ' . \Symfony\Component\HttpKernel\Kernel::VERSION;"

# Run all tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

### Drupal 11 Specific Tests

```bash
# Verify PHP version
php -v
# Expected: PHP 8.3 or higher

# Check Drupal version
drush status | grep "Drupal version"
# Expected: 11.x

# Run all tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

---

## 🔍 Error Testing

### Test 17: Test with Invalid API Key

1. Configure with invalid API key
2. Create content
3. Expected: Error message + log entry
4. Check logs:
   ```bash
   drush watchdog:show --type=rail_score --severity=Error
   ```

### Test 18: Test Network Failure

**Simulate by setting invalid base URL:**
1. Set base_url to `https://invalid-url-that-does-not-exist.com`
2. Create content
3. Expected: Evaluation fails gracefully
4. Content still saves
5. Error logged

### Test 19: Test Missing Field

1. Create content type without field_rail_score
2. Enable auto-evaluation for that type
3. Create content
4. Expected: Evaluation runs but score not stored (graceful degradation)

---

## 📊 Performance Testing

### Test 20: Batch Content Creation

```bash
# Generate 100 articles
drush generate:content --bundles=article 100

# Monitor queue
drush queue:list

# Check how long evaluation takes
time drush queue:run rail_score_evaluation

# Check memory usage
drush php:eval "echo 'Memory: ' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB';"
```

### Test 21: Concurrent Requests

```bash
# Use Apache Bench (if available)
ab -n 100 -c 10 http://your-drupal-site.local/admin/reports/rail-score

# Monitor logs during load
tail -f /path/to/drupal/sites/default/files/logs/drupal.log
```

---

## 🧹 Uninstallation Testing

### Test 22: Clean Uninstall

```bash
# Export config before uninstall
drush config:export

# Uninstall module
drush pmu rail_score -y

# Verify removal
drush pml | grep rail_score
# Expected: No output

# Check for leftover config
drush config:get rail_score.settings
# Expected: Config object has not been created

# Check for leftover tables
drush sql:query "SHOW TABLES LIKE '%rail_score%'"
# Expected: Empty set

# Check for leftover services
drush devel:services | grep rail_score
# Expected: No output

# Reinstall test
drush en rail_score -y
drush config:get rail_score.settings
# Expected: Default config restored
```

---

## ✅ Final Validation Checklist

### Code Quality
- [ ] No PHP errors or warnings
- [ ] No deprecated function calls
- [ ] All classes use dependency injection
- [ ] All strings translatable
- [ ] Proper docblocks on all functions

### Functionality
- [ ] Module installs without errors
- [ ] Configuration form works
- [ ] Dashboard displays correctly
- [ ] Content evaluation works
- [ ] Threshold enforcement works
- [ ] Auto-unpublish works (when enabled)
- [ ] Queue worker processes items
- [ ] Field formatter displays correctly

### Security
- [ ] Input validation on all forms
- [ ] Output sanitization in templates
- [ ] Permissions enforced correctly
- [ ] No XSS vulnerabilities
- [ ] No SQL injection risks
- [ ] API keys stored securely

### Compatibility
- [ ] Works on Drupal 9.x
- [ ] Works on Drupal 10.x
- [ ] Works on Drupal 11.x
- [ ] Compatible with PHP 8.1+
- [ ] No deprecated API usage

### User Experience
- [ ] Clear error messages
- [ ] Helpful form descriptions
- [ ] Responsive design
- [ ] No JavaScript errors
- [ ] Accessible (WCAG compliant)

### Documentation
- [ ] README complete
- [ ] CHANGELOG updated
- [ ] Inline code documented
- [ ] Help page useful
- [ ] Examples provided

---

## 🐛 Troubleshooting

### Common Issues

**Issue: "Class not found" errors**
```bash
drush cr
composer dump-autoload
```

**Issue: Config schema errors**
```bash
drush config:inspect rail_score.settings
```

**Issue: Permission denied**
```bash
drush role:perm:add authenticated 'view rail_score dashboard'
```

**Issue: API connection fails**
- Verify API key is correct
- Check firewall/proxy settings
- Test with curl:
  ```bash
  curl -H "Authorization: Bearer YOUR_API_KEY" \
    https://api.responsibleailabs.ai/v1/usage
  ```

---

## 📈 Test Results Template

Use this template to document your test results:

```
## Test Results

**Date:** [Date]
**Drupal Version:** [9.x / 10.x / 11.x]
**PHP Version:** [8.x]
**Tester:** [Name]

### Installation: [PASS/FAIL]
- Notes:

### Configuration: [PASS/FAIL]
- Notes:

### Content Evaluation: [PASS/FAIL]
- Notes:

### Dashboard: [PASS/FAIL]
- Notes:

### Queue Processing: [PASS/FAIL]
- Notes:

### PHPUnit Tests: [PASS/FAIL]
- Results: X/X tests passed

### Code Standards: [PASS/FAIL]
- Errors: 0
- Warnings: 0

### Overall Result: [PASS/FAIL]
```

---

## 📞 Support

If you encounter issues during testing:

1. Check logs: `drush watchdog:show --type=rail_score`
2. Review [Troubleshooting](README.md#troubleshooting) section
3. Report issues with full error messages and steps to reproduce

---

**Testing Guide Version:** 1.0.0
**Last Updated:** 2024-11-04
