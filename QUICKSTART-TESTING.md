# RAIL Score - Quick Start Testing Guide

Fast track guide to test the RAIL Score module on Drupal 9/10/11.

## ⚡ 5-Minute Quick Test

### Step 1: Install & Enable (2 minutes)

```bash
# Copy module to Drupal
cp -r rail_score /path/to/drupal/modules/contrib/

# Enable module
cd /path/to/drupal
drush en rail_score -y
drush cr

# Verify
drush pml --status=enabled | grep rail_score
```

**Expected:** `Rail Score (rail_score)  Enabled  1.0.0`

### Step 2: Run Automated Tests (2 minutes)

```bash
# Run installation test script
./modules/contrib/rail_score/scripts/test-installation.sh

# Or from module directory
cd modules/contrib/rail_score
./scripts/test-installation.sh /path/to/drupal
```

**Expected:** All tests pass ✅

### Step 3: Quick Manual Check (1 minute)

```bash
# Login as admin
drush uli

# Visit these URLs:
# 1. Configuration: /admin/config/content/rail-score
# 2. Dashboard: /admin/reports/rail-score
```

**Expected:** Both pages load without errors

---

## 🎯 Complete Test (30 minutes)

### Prerequisites

```bash
# Backup database
drush sql:dump > backup.sql

# Check requirements
php -v              # Should be 8.1+
drush status        # Should show Drupal 9/10/11
```

### Test Sequence

#### 1. Installation (5 min)

```bash
# Install module
composer require drupal/rail_score
drush en rail_score -y
drush cr

# Verify services
drush eval "var_dump(\Drupal::service('rail_score.client'));"
```

#### 2. Configuration (5 min)

```bash
# Get API key from https://responsibleailabs.ai
# Visit: /admin/config/content/rail-score

# Set configuration:
# - API Key: [your key]
# - Base URL: https://api.responsibleailabs.ai
# - Threshold: 7.0
# - Enable: Article content type
# - Click "Test Connection"
# - Save
```

#### 3. Field Setup (5 min)

```bash
# Create RAIL Score field
drush field:create node article field_rail_score \
  --field-type=decimal \
  --field-label="RAIL Score"

# Configure display
# Go to: /admin/structure/types/manage/article/display
# Set field_rail_score format to: "RAIL Score Display"
# Choose display mode: "Badge"
# Save
```

#### 4. Content Test (10 min)

```bash
# Create test article
drush generate:content --bundles=article 1

# Or manually:
# Visit: /node/add/article
# Title: "Test RAIL Score Evaluation"
# Body: "This content will be automatically evaluated by RAIL Score API for safety, privacy, and other dimensions."
# Save
```

**Check for:**
- ✅ Success message with score
- ✅ Field populated with score value
- ✅ No PHP errors

#### 5. Dashboard Check (5 min)

```bash
# Visit: /admin/reports/rail-score

# Should show:
# - API usage statistics
# - Recent evaluations
# - Test article in list
```

---

## 🧪 PHPUnit Testing

### Run All Tests

```bash
cd /path/to/drupal

# Run full test suite
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/

# Expected output:
# OK (12 tests, XX assertions)
```

### Run Specific Tests

```bash
# Test configuration form
./vendor/bin/phpunit -c core \
  --filter testConfigurationForm \
  modules/contrib/rail_score/tests/

# Test dashboard
./vendor/bin/phpunit -c core \
  --filter testDashboard \
  modules/contrib/rail_score/tests/

# Test services
./vendor/bin/phpunit -c core \
  --filter testServices \
  modules/contrib/rail_score/tests/
```

---

## 🔧 Version-Specific Testing

### Drupal 9 Test

```bash
# Verify Drupal 9
drush status | grep "Drupal version"
# Expected: 9.x

# Check PHP
php -v
# Expected: 8.0+ (8.1+ recommended)

# Run tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

### Drupal 10 Test

```bash
# Verify Drupal 10
drush status | grep "Drupal version"
# Expected: 10.x

# Check PHP
php -v
# Expected: 8.1+

# Run tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

### Drupal 11 Test

```bash
# Verify Drupal 11
drush status | grep "Drupal version"
# Expected: 11.x

# Check PHP
php -v
# Expected: 8.3+

# Run tests
./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/
```

---

## 🚨 Common Issues & Quick Fixes

### Issue: "Class not found"

```bash
# Fix:
drush cr
composer dump-autoload
```

### Issue: "Permission denied"

```bash
# Fix:
drush role:perm:add authenticated 'view rail_score dashboard'
drush cr
```

### Issue: "Configuration schema error"

```bash
# Fix:
drush config:delete rail_score.settings
drush en rail_score -y
drush cr
```

### Issue: "API connection failed"

```bash
# Check:
# 1. Verify API key is correct
# 2. Test connection manually:
curl -H "Authorization: Bearer YOUR_API_KEY" \
  https://api.responsibleailabs.ai/v1/usage

# 3. Check firewall settings
# 4. Review logs:
drush watchdog:show --type=rail_score
```

---

## ✅ Quick Validation Checklist

Use this for rapid validation:

### Installation ✓
- [ ] Module enabled without errors
- [ ] Config installed
- [ ] Services registered
- [ ] Routes available

### Configuration ✓
- [ ] Form loads
- [ ] Validation works
- [ ] Settings save
- [ ] Connection test works

### Functionality ✓
- [ ] Content evaluated on save
- [ ] Score stored in field
- [ ] Dashboard displays stats
- [ ] No PHP errors in logs

### Compatibility ✓
- [ ] Works on Drupal 9
- [ ] Works on Drupal 10
- [ ] Works on Drupal 11
- [ ] PHP 8.1+ compatible

---

## 📊 Test Results Template

```
RAIL Score Module - Test Results
================================

Date: __________
Tester: __________
Drupal Version: __________
PHP Version: __________

Installation Test:    [ PASS / FAIL ]
Configuration Test:   [ PASS / FAIL ]
Content Eval Test:    [ PASS / FAIL ]
Dashboard Test:       [ PASS / FAIL ]
PHPUnit Tests:        [ PASS / FAIL ] (__ / 12)
Code Standards:       [ PASS / FAIL ]

Overall Result:       [ PASS / FAIL ]

Notes:
__________________________________________
__________________________________________
__________________________________________
```

---

## 🎓 Next Steps After Testing

### If All Tests Pass ✅

1. **Deploy to staging**
   ```bash
   # Export config
   drush config:export

   # Deploy via git/composer
   composer require drupal/rail_score
   ```

2. **Configure production settings**
   - Add production API key
   - Set appropriate threshold
   - Enable desired content types

3. **Monitor initial usage**
   ```bash
   # Watch logs
   drush watchdog:tail

   # Check queue
   drush queue:list
   ```

### If Tests Fail ❌

1. **Check error logs**
   ```bash
   drush watchdog:show --type=rail_score --severity=Error
   ```

2. **Review compatibility**
   - See COMPATIBILITY.md
   - Verify PHP/Drupal versions

3. **Report issue**
   - Include full error message
   - Include environment details
   - Include steps to reproduce

---

## 📞 Support Resources

- **Full Testing Guide**: TESTING.md
- **Compatibility Matrix**: COMPATIBILITY.md
- **Documentation**: README.md
- **Issue Tracker**: [GitHub Issues]

---

## ⏱️ Time Estimates

| Task | Time | Notes |
|------|------|-------|
| Quick Install | 2 min | Just enable & verify |
| Full Installation | 10 min | With field setup |
| Configuration | 5 min | Including API key |
| Content Testing | 10 min | Create & evaluate |
| PHPUnit Tests | 5 min | Automated tests |
| Manual Testing | 20 min | Complete workflow |
| **Total** | **30-60 min** | Comprehensive test |

---

**Version:** 1.0.0
**Last Updated:** 2024-11-04
**Tested On:** Drupal 9/10/11
