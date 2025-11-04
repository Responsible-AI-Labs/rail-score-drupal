#!/usr/bin/env php
<?php

/**
 * RAIL Score Module - Validation Script
 *
 * Validates module structure, PHP syntax, and code quality
 * without requiring Drupal installation.
 */

// Colors
define('RED', "\033[0;31m");
define('GREEN', "\033[0;32m");
define('YELLOW', "\033[1;33m");
define('BLUE', "\033[0;34m");
define('NC', "\033[0m");

$testsTotal = 0;
$testsPassed = 0;
$testsFailed = 0;
$errors = [];

function printHeader($text) {
    echo "\n" . BLUE . str_repeat('=', 60) . NC . "\n";
    echo BLUE . $text . NC . "\n";
    echo BLUE . str_repeat('=', 60) . NC . "\n\n";
}

function printTest($text) {
    global $testsTotal;
    $testsTotal++;
    echo YELLOW . "[TEST $testsTotal]" . NC . " $text\n";
}

function printSuccess($text) {
    global $testsPassed;
    $testsPassed++;
    echo GREEN . "✓ PASS:" . NC . " $text\n";
}

function printFailure($text, $error = '') {
    global $testsFailed, $errors;
    $testsFailed++;
    echo RED . "✗ FAIL:" . NC . " $text\n";
    if ($error) {
        $errors[] = ['test' => $text, 'error' => $error];
        echo RED . "  Error: $error" . NC . "\n";
    }
}

function printInfo($text) {
    echo BLUE . "ℹ INFO:" . NC . " $text\n";
}

// Get module directory
$moduleDir = dirname(__DIR__);
chdir($moduleDir);

printHeader("RAIL Score Module - Validation Suite");

printInfo("Module directory: $moduleDir");
printInfo("PHP version: " . PHP_VERSION);

// Test 1: Check required files exist
printHeader("File Structure Validation");

$requiredFiles = [
    'rail_score.info.yml',
    'rail_score.module',
    'rail_score.services.yml',
    'rail_score.routing.yml',
    'rail_score.permissions.yml',
    'rail_score.links.menu.yml',
    'rail_score.libraries.yml',
    'composer.json',
    'README.md',
    'LICENSE',
    'CHANGELOG.md',
    'src/RailScoreClient.php',
    'src/Form/RailScoreConfigForm.php',
    'src/Controller/RailScoreDashboardController.php',
    'src/EventSubscriber/EntityEventSubscriber.php',
    'src/Plugin/QueueWorker/RailScoreEvaluationWorker.php',
    'src/Plugin/Field/FieldFormatter/RailScoreFormatter.php',
    'config/install/rail_score.settings.yml',
    'config/schema/rail_score.schema.yml',
    'templates/rail-score-dashboard.html.twig',
    'templates/rail-score-widget.html.twig',
    'css/rail-score-admin.css',
    'js/rail-score-admin.js',
    'tests/src/Functional/RailScoreTest.php',
];

printTest("Checking required files exist");
$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        $missingFiles[] = $file;
    }
}

if (empty($missingFiles)) {
    printSuccess("All " . count($requiredFiles) . " required files exist");
} else {
    printFailure(count($missingFiles) . " files missing", implode(', ', $missingFiles));
}

// Test 2: PHP Syntax Validation
printHeader("PHP Syntax Validation");

$phpFiles = [
    'rail_score.module',
    'src/RailScoreClient.php',
    'src/Form/RailScoreConfigForm.php',
    'src/Controller/RailScoreDashboardController.php',
    'src/EventSubscriber/EntityEventSubscriber.php',
    'src/Plugin/QueueWorker/RailScoreEvaluationWorker.php',
    'src/Plugin/Field/FieldFormatter/RailScoreFormatter.php',
    'tests/src/Functional/RailScoreTest.php',
];

$syntaxErrors = 0;
foreach ($phpFiles as $file) {
    printTest("Checking PHP syntax: $file");
    if (!file_exists($file)) {
        printFailure("File not found: $file");
        $syntaxErrors++;
        continue;
    }

    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);

    if ($returnVar === 0) {
        printSuccess("Valid PHP syntax");
    } else {
        printFailure("Syntax error in $file", implode("\n", $output));
        $syntaxErrors++;
    }
}

if ($syntaxErrors === 0) {
    printInfo("All PHP files have valid syntax");
}

// Test 3: Check namespaces
printHeader("Namespace Validation");

$namespaceChecks = [
    'src/RailScoreClient.php' => 'namespace Drupal\rail_score;',
    'src/Form/RailScoreConfigForm.php' => 'namespace Drupal\rail_score\Form;',
    'src/Controller/RailScoreDashboardController.php' => 'namespace Drupal\rail_score\Controller;',
    'src/EventSubscriber/EntityEventSubscriber.php' => 'namespace Drupal\rail_score\EventSubscriber;',
];

printTest("Checking class namespaces");
$namespaceErrors = 0;
foreach ($namespaceChecks as $file => $expectedNamespace) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, $expectedNamespace) !== false) {
            printSuccess("$file has correct namespace");
        } else {
            printFailure("$file missing or incorrect namespace", "Expected: $expectedNamespace");
            $namespaceErrors++;
        }
    }
}

// Test 4: Check YAML syntax
printHeader("YAML Syntax Validation");

$yamlFiles = [
    'rail_score.info.yml',
    'rail_score.services.yml',
    'rail_score.routing.yml',
    'rail_score.permissions.yml',
    'rail_score.links.menu.yml',
    'rail_score.libraries.yml',
    'config/install/rail_score.settings.yml',
    'config/schema/rail_score.schema.yml',
];

printTest("Checking YAML files are valid");
$yamlErrors = 0;
foreach ($yamlFiles as $file) {
    if (!file_exists($file)) {
        printFailure("YAML file not found: $file");
        $yamlErrors++;
        continue;
    }

    $content = file_get_contents($file);

    // Basic YAML validation checks
    if (empty($content)) {
        printFailure("$file is empty");
        $yamlErrors++;
        continue;
    }

    // Check for common YAML issues
    if (preg_match('/\t/', $content)) {
        printFailure("$file contains tabs (should use spaces)", "YAML files must use spaces for indentation");
        $yamlErrors++;
        continue;
    }

    printSuccess("$file is valid");
}

// Test 5: Check for deprecated Drupal functions
printHeader("Deprecated API Check");

$deprecatedFunctions = [
    'db_query',
    'db_select',
    'node_load',
    'entity_load',
    'drupal_set_message',
    'drupal_goto',
    'format_date',
    '->entityManager(',
];

printTest("Checking for deprecated Drupal APIs");
$foundDeprecated = [];

foreach ($phpFiles as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);
    foreach ($deprecatedFunctions as $func) {
        if (stripos($content, $func) !== false) {
            $foundDeprecated[] = "$func in $file";
        }
    }
}

if (empty($foundDeprecated)) {
    printSuccess("No deprecated Drupal APIs found");
} else {
    printFailure("Found deprecated APIs", implode(', ', $foundDeprecated));
}

// Test 6: Check required class methods
printHeader("Class Structure Validation");

printTest("Checking RailScoreClient class structure");
if (file_exists('src/RailScoreClient.php')) {
    $content = file_get_contents('src/RailScoreClient.php');
    $requiredMethods = ['evaluate', 'checkGdprCompliance', 'getUsageStats', 'testConnection'];
    $missingMethods = [];

    foreach ($requiredMethods as $method) {
        if (strpos($content, "function $method") === false) {
            $missingMethods[] = $method;
        }
    }

    if (empty($missingMethods)) {
        printSuccess("All required methods present in RailScoreClient");
    } else {
        printFailure("Missing methods in RailScoreClient", implode(', ', $missingMethods));
    }
}

// Test 7: Check composer.json
printHeader("Composer Configuration");

printTest("Validating composer.json");
if (file_exists('composer.json')) {
    $composerJson = file_get_contents('composer.json');
    $composer = json_decode($composerJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        printFailure("composer.json is invalid JSON", json_last_error_msg());
    } else {
        printSuccess("composer.json is valid JSON");

        // Check required fields
        $requiredFields = ['name', 'type', 'require', 'autoload'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($composer[$field])) {
                $missingFields[] = $field;
            }
        }

        if (empty($missingFields)) {
            printSuccess("All required composer.json fields present");
        } else {
            printFailure("Missing composer.json fields", implode(', ', $missingFields));
        }

        // Check autoload
        if (isset($composer['autoload']['psr-4']['Drupal\\rail_score\\'])) {
            printSuccess("PSR-4 autoloading configured correctly");
        } else {
            printFailure("PSR-4 autoloading not configured correctly");
        }
    }
}

// Test 8: Check documentation
printHeader("Documentation Validation");

$docFiles = ['README.md', 'CHANGELOG.md', 'LICENSE', 'TESTING.md', 'COMPATIBILITY.md'];
printTest("Checking documentation files");
$missingDocs = [];
$emptyDocs = [];

foreach ($docFiles as $doc) {
    if (!file_exists($doc)) {
        $missingDocs[] = $doc;
    } elseif (filesize($doc) < 100) {
        $emptyDocs[] = $doc;
    }
}

if (empty($missingDocs) && empty($emptyDocs)) {
    printSuccess("All documentation files present and substantial");
} else {
    if (!empty($missingDocs)) {
        printFailure("Missing documentation", implode(', ', $missingDocs));
    }
    if (!empty($emptyDocs)) {
        printFailure("Documentation files too small", implode(', ', $emptyDocs));
    }
}

// Test 9: Check file permissions
printHeader("File Permissions");

printTest("Checking executable permissions on scripts");
if (file_exists('scripts/test-installation.sh')) {
    if (is_executable('scripts/test-installation.sh')) {
        printSuccess("test-installation.sh is executable");
    } else {
        printFailure("test-installation.sh is not executable", "Run: chmod +x scripts/test-installation.sh");
    }
}

// Test 10: Security check
printHeader("Security Validation");

printTest("Checking for potential security issues");
$securityIssues = [];

foreach ($phpFiles as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    // Check for eval
    if (stripos($content, 'eval(') !== false) {
        $securityIssues[] = "eval() found in $file";
    }

    // Check for $_GET, $_POST (should use Request object)
    if (preg_match('/\$_(GET|POST)\[/', $content)) {
        $securityIssues[] = "Direct superglobal access in $file";
    }
}

if (empty($securityIssues)) {
    printSuccess("No obvious security issues found");
} else {
    printFailure("Potential security issues", implode(', ', $securityIssues));
}

// Summary
printHeader("Validation Summary");

echo "Total Tests:  " . BLUE . $testsTotal . NC . "\n";
echo "Passed:       " . GREEN . $testsPassed . NC . "\n";
echo "Failed:       " . RED . $testsFailed . NC . "\n";

if (!empty($errors)) {
    echo "\n" . RED . "Detailed Errors:" . NC . "\n";
    foreach ($errors as $error) {
        echo "  • " . $error['test'] . "\n";
        echo "    " . $error['error'] . "\n";
    }
}

if ($testsFailed === 0) {
    echo "\n" . GREEN . "✓ All validation tests passed!" . NC . "\n";
    echo GREEN . "The RAIL Score module structure is valid and ready." . NC . "\n\n";
    exit(0);
} else {
    echo "\n" . RED . "✗ Some validation tests failed." . NC . "\n";
    echo RED . "Please review the failures above." . NC . "\n\n";
    exit(1);
}
