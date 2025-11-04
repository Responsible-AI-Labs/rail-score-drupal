#!/bin/bash

###############################################################################
# RAIL Score Module - Installation & Testing Script
#
# This script tests the RAIL Score module installation and basic functionality
# on Drupal 9/10/11
#
# Usage: ./scripts/test-installation.sh [drupal-root]
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Counters
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_TOTAL=0

# Functions
print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_test() {
    echo -e "${YELLOW}[TEST $TESTS_TOTAL]${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓ PASS:${NC} $1"
    ((TESTS_PASSED++))
}

print_failure() {
    echo -e "${RED}✗ FAIL:${NC} $1"
    ((TESTS_FAILED++))
}

print_info() {
    echo -e "${BLUE}ℹ INFO:${NC} $1"
}

run_test() {
    ((TESTS_TOTAL++))
    print_test "$1"
}

# Get Drupal root
DRUPAL_ROOT=${1:-$(pwd)}

if [ ! -f "$DRUPAL_ROOT/core/lib/Drupal.php" ]; then
    echo -e "${RED}Error: Not a valid Drupal root directory${NC}"
    echo "Usage: $0 [drupal-root]"
    exit 1
fi

cd "$DRUPAL_ROOT"

print_header "RAIL Score Module - Installation Test Suite"

# Check prerequisites
print_header "Checking Prerequisites"

run_test "PHP version >= 8.1"
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
if php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
    print_success "PHP version: $PHP_VERSION"
else
    print_failure "PHP version $PHP_VERSION is too old (need 8.1+)"
fi

run_test "Drush is available"
if command -v drush &> /dev/null; then
    DRUSH_VERSION=$(drush --version | grep -oP '\d+\.\d+\.\d+' || echo "unknown")
    print_success "Drush version: $DRUSH_VERSION"
else
    print_failure "Drush not found"
fi

run_test "Drupal is installed"
DRUPAL_VERSION=$(drush status --field=drupal-version 2>/dev/null || echo "unknown")
if [ "$DRUPAL_VERSION" != "unknown" ]; then
    print_success "Drupal version: $DRUPAL_VERSION"
else
    print_failure "Drupal not properly installed"
fi

run_test "Database connection"
if drush status --field=db-status | grep -q "Connected"; then
    print_success "Database connected"
else
    print_failure "Database not connected"
fi

# Module Installation
print_header "Testing Module Installation"

run_test "Module can be enabled"
if drush en rail_score -y &> /dev/null; then
    print_success "Module enabled successfully"
else
    print_failure "Failed to enable module"
fi

run_test "Module appears in module list"
if drush pml --status=enabled | grep -q "rail_score"; then
    print_success "Module is listed as enabled"
else
    print_failure "Module not found in enabled modules"
fi

run_test "Configuration was installed"
if drush config:get rail_score.settings &> /dev/null; then
    print_success "Configuration installed"
else
    print_failure "Configuration not found"
fi

run_test "Default configuration values"
BASE_URL=$(drush config:get rail_score.settings base_url --format=string 2>/dev/null || echo "")
if [ "$BASE_URL" == "https://api.responsibleailabs.ai" ]; then
    print_success "Default base_url is correct"
else
    print_failure "Default base_url is incorrect: $BASE_URL"
fi

# Service Registration
print_header "Testing Service Registration"

run_test "rail_score.client service exists"
if drush eval "try { \$s = \Drupal::service('rail_score.client'); echo 'OK'; } catch (\Exception \$e) { echo 'FAIL'; }" | grep -q "OK"; then
    print_success "RailScoreClient service registered"
else
    print_failure "RailScoreClient service not found"
fi

run_test "rail_score.entity_subscriber service exists"
if drush eval "try { \$s = \Drupal::service('rail_score.entity_subscriber'); echo 'OK'; } catch (\Exception \$e) { echo 'FAIL'; }" | grep -q "OK"; then
    print_success "EntityEventSubscriber service registered"
else
    print_failure "EntityEventSubscriber service not found"
fi

# Routes
print_header "Testing Routes"

run_test "Settings route exists"
if drush route:list | grep -q "rail_score.settings"; then
    print_success "Settings route registered"
else
    print_failure "Settings route not found"
fi

run_test "Dashboard route exists"
if drush route:list | grep -q "rail_score.dashboard"; then
    print_success "Dashboard route registered"
else
    print_failure "Dashboard route not found"
fi

# Permissions
print_header "Testing Permissions"

run_test "administer rail_score permission"
if drush eval "echo \Drupal::service('user.permissions')->getPermissions()['administer rail_score'] ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Admin permission exists"
else
    print_failure "Admin permission not found"
fi

run_test "view rail_score dashboard permission"
if drush eval "echo \Drupal::service('user.permissions')->getPermissions()['view rail_score dashboard'] ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "View dashboard permission exists"
else
    print_failure "View dashboard permission not found"
fi

# Plugins
print_header "Testing Plugin Registration"

run_test "Queue worker plugin"
if drush eval "\$manager = \Drupal::service('plugin.manager.queue_worker'); \$defs = \$manager->getDefinitions(); echo isset(\$defs['rail_score_evaluation']) ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Queue worker plugin registered"
else
    print_failure "Queue worker plugin not found"
fi

run_test "Field formatter plugin"
if drush eval "\$manager = \Drupal::service('plugin.manager.field.formatter'); \$defs = \$manager->getDefinitions(); echo isset(\$defs['rail_score_formatter']) ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Field formatter plugin registered"
else
    print_failure "Field formatter plugin not found"
fi

# Theme
print_header "Testing Theme Registration"

run_test "rail_score_dashboard theme hook"
if drush eval "\$registry = \Drupal::service('theme.registry')->get(); echo isset(\$registry['rail_score_dashboard']) ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Dashboard theme hook registered"
else
    print_failure "Dashboard theme hook not found"
fi

run_test "rail_score_widget theme hook"
if drush eval "\$registry = \Drupal::service('theme.registry')->get(); echo isset(\$registry['rail_score_widget']) ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Widget theme hook registered"
else
    print_failure "Widget theme hook not found"
fi

# Libraries
print_header "Testing Asset Libraries"

run_test "admin library exists"
if drush eval "\$discovery = \Drupal::service('library.discovery'); \$libs = \$discovery->getLibrariesByExtension('rail_score'); echo isset(\$libs['admin']) ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "Admin library registered"
else
    print_failure "Admin library not found"
fi

# Helper Functions
print_header "Testing Helper Functions"

run_test "rail_score_get_score() function"
if drush eval "echo function_exists('rail_score_get_score') ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "rail_score_get_score() exists"
else
    print_failure "rail_score_get_score() not found"
fi

run_test "rail_score_passes_threshold() function"
if drush eval "echo function_exists('rail_score_passes_threshold') ? 'OK' : 'FAIL';" | grep -q "OK"; then
    print_success "rail_score_passes_threshold() exists"
else
    print_failure "rail_score_passes_threshold() not found"
fi

# Menu Links
print_header "Testing Menu Links"

run_test "Settings menu link"
if drush eval "\$menuTree = \Drupal::service('menu.link_tree'); \$params = \$menuTree->getCurrentRouteMenuTreeParameters('admin'); \$tree = \$menuTree->load('admin', \$params); foreach (\$tree as \$item) { if (strpos(\$item->link->getRouteName(), 'rail_score.settings') !== false) { echo 'OK'; exit; } } echo 'FAIL';" | grep -q "OK"; then
    print_success "Settings menu link exists"
else
    print_info "Settings menu link (may need cache clear)"
fi

# Logs Check
print_header "Checking Error Logs"

run_test "No PHP errors during installation"
ERROR_COUNT=$(drush watchdog:show --type=php --severity=Error --count=10 | grep -c "rail_score" || echo "0")
if [ "$ERROR_COUNT" -eq 0 ]; then
    print_success "No PHP errors logged"
else
    print_failure "$ERROR_COUNT PHP errors found"
fi

# Cache
print_header "Cache Operations"

run_test "Cache clear"
if drush cr &> /dev/null; then
    print_success "Cache cleared successfully"
else
    print_failure "Failed to clear cache"
fi

# PHPUnit Tests (optional)
print_header "PHPUnit Tests (Optional)"

if [ -f "vendor/bin/phpunit" ]; then
    run_test "Running PHPUnit tests"
    if ./vendor/bin/phpunit -c core modules/contrib/rail_score/tests/ &> /tmp/phpunit-output.log; then
        TEST_COUNT=$(grep -oP '\d+ tests?' /tmp/phpunit-output.log | head -1 || echo "0")
        print_success "PHPUnit tests passed ($TEST_COUNT)"
    else
        print_failure "PHPUnit tests failed (see /tmp/phpunit-output.log)"
    fi
else
    print_info "PHPUnit not found - skipping unit tests"
fi

# Summary
print_header "Test Summary"

echo -e "Total Tests:  ${BLUE}$TESTS_TOTAL${NC}"
echo -e "Passed:       ${GREEN}$TESTS_PASSED${NC}"
echo -e "Failed:       ${RED}$TESTS_FAILED${NC}"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "\n${GREEN}✓ All tests passed!${NC}"
    echo -e "${GREEN}The RAIL Score module is properly installed and configured.${NC}\n"
    exit 0
else
    echo -e "\n${RED}✗ Some tests failed.${NC}"
    echo -e "${RED}Please review the failures above.${NC}\n"
    exit 1
fi
