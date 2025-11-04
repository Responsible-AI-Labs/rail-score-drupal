<?php

namespace Drupal\Tests\rail_score\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests RAIL Score module functionality.
 *
 * @group rail_score
 */
class RailScoreTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rail_score', 'node', 'field', 'text'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with administrative permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A user with dashboard view permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $dashboardUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create users with different permissions.
    $this->adminUser = $this->drupalCreateUser([
      'administer rail_score',
      'view rail_score dashboard',
      'access content',
      'create article content',
      'edit any article content',
    ]);

    $this->dashboardUser = $this->drupalCreateUser([
      'view rail_score dashboard',
      'access content',
    ]);

    // Create article content type.
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Tests the configuration form.
   */
  public function testConfigurationForm() {
    // Test access denied for anonymous user.
    $this->drupalGet('admin/config/content/rail-score');
    $this->assertSession()->statusCodeEquals(403);

    // Test access for admin user.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/rail-score');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('RAIL Score Settings');

    // Test form fields exist.
    $this->assertSession()->fieldExists('api_key');
    $this->assertSession()->fieldExists('base_url');
    $this->assertSession()->fieldExists('auto_evaluate');
    $this->assertSession()->fieldExists('threshold');
    $this->assertSession()->fieldExists('auto_unpublish');

    // Test form validation - empty API key.
    $this->submitForm([
      'api_key' => '',
      'base_url' => 'https://api.responsibleailabs.ai',
      'threshold' => 7.0,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('API Key field is required');

    // Test form validation - short API key.
    $this->submitForm([
      'api_key' => 'short',
      'base_url' => 'https://api.responsibleailabs.ai',
      'threshold' => 7.0,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('API key must be at least 10 characters');

    // Test form validation - invalid URL.
    $this->submitForm([
      'api_key' => 'valid_api_key_12345',
      'base_url' => 'not-a-valid-url',
      'threshold' => 7.0,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Base URL must be a valid URL');

    // Test form validation - invalid threshold.
    $this->submitForm([
      'api_key' => 'valid_api_key_12345',
      'base_url' => 'https://api.responsibleailabs.ai',
      'threshold' => 15,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Threshold must be between 0 and 10');

    // Test successful form submission.
    $this->submitForm([
      'api_key' => 'valid_api_key_12345',
      'base_url' => 'https://api.responsibleailabs.ai',
      'auto_evaluate' => TRUE,
      'threshold' => 7.0,
      'auto_unpublish' => FALSE,
      'dimensions[safety]' => TRUE,
      'dimensions[privacy]' => TRUE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved.
    $config = $this->config('rail_score.settings');
    $this->assertEquals('valid_api_key_12345', $config->get('api_key'));
    $this->assertEquals('https://api.responsibleailabs.ai', $config->get('base_url'));
    $this->assertTrue($config->get('auto_evaluate'));
    $this->assertEquals(7.0, $config->get('threshold'));
    $this->assertFalse($config->get('auto_unpublish'));
  }

  /**
   * Tests the dashboard page.
   */
  public function testDashboard() {
    // Test access denied for anonymous user.
    $this->drupalGet('admin/reports/rail-score');
    $this->assertSession()->statusCodeEquals(403);

    // Test access for dashboard user.
    $this->drupalLogin($this->dashboardUser);
    $this->drupalGet('admin/reports/rail-score');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('RAIL Score Dashboard');
    $this->assertSession()->pageTextContains('API Usage Statistics');
    $this->assertSession()->pageTextContains('Recent Evaluations');

    // Test dashboard for admin user.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/reports/rail-score');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests module installation.
   */
  public function testModuleInstallation() {
    // Verify module is installed.
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('rail_score'));

    // Verify configuration schema exists.
    $config = $this->config('rail_score.settings');
    $this->assertNotNull($config);

    // Verify default configuration values.
    $this->assertEquals('https://api.responsibleailabs.ai', $config->get('base_url'));
    $this->assertTrue($config->get('auto_evaluate'));
    $this->assertEquals(7.0, $config->get('threshold'));
    $this->assertFalse($config->get('auto_unpublish'));
  }

  /**
   * Tests service definitions.
   */
  public function testServices() {
    // Test RAIL Score client service exists.
    $client = \Drupal::service('rail_score.client');
    $this->assertNotNull($client);
    $this->assertInstanceOf('Drupal\rail_score\RailScoreClient', $client);

    // Test entity subscriber service exists.
    $subscriber = \Drupal::service('rail_score.entity_subscriber');
    $this->assertNotNull($subscriber);
    $this->assertInstanceOf('Drupal\rail_score\EventSubscriber\EntityEventSubscriber', $subscriber);
  }

  /**
   * Tests helper functions.
   */
  public function testHelperFunctions() {
    // Create a test node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article',
      'body' => [
        'value' => 'Test content for RAIL Score evaluation.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    // Test rail_score_get_score() without field.
    $score = rail_score_get_score($node);
    $this->assertNull($score);

    // Test rail_score_passes_threshold() without score.
    $passes = rail_score_passes_threshold($node);
    $this->assertTrue($passes); // Should return TRUE when no score exists.
  }

  /**
   * Tests permissions.
   */
  public function testPermissions() {
    $permissions = [
      'administer rail_score',
      'view rail_score dashboard',
      'evaluate content with rail_score',
    ];

    foreach ($permissions as $permission) {
      $this->assertTrue(
        \Drupal::service('user.permissions')->getPermissions()[$permission] !== NULL,
        "Permission '$permission' exists."
      );
    }
  }

  /**
   * Tests menu links.
   */
  public function testMenuLinks() {
    $this->drupalLogin($this->adminUser);

    // Test settings link appears in Configuration menu.
    $this->drupalGet('admin/config/content');
    $this->assertSession()->linkExists('RAIL Score');

    // Test dashboard link appears in Reports menu.
    $this->drupalGet('admin/reports');
    $this->assertSession()->linkExists('RAIL Score');
  }

  /**
   * Tests help page.
   */
  public function testHelpPage() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/help/rail_score');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('About');
    $this->assertSession()->pageTextContains('RAIL Score');
    $this->assertSession()->pageTextContains('Features');
    $this->assertSession()->pageTextContains('Configuration');
  }

  /**
   * Tests queue worker plugin.
   */
  public function testQueueWorker() {
    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager */
    $queue_manager = \Drupal::service('plugin.manager.queue_worker');
    $definitions = $queue_manager->getDefinitions();

    // Verify queue worker is defined.
    $this->assertArrayHasKey('rail_score_evaluation', $definitions);
    $this->assertEquals('RAIL Score Evaluation Worker', (string) $definitions['rail_score_evaluation']['title']);
  }

  /**
   * Tests field formatter plugin.
   */
  public function testFieldFormatter() {
    /** @var \Drupal\Core\Field\FormatterPluginManager $formatter_manager */
    $formatter_manager = \Drupal::service('plugin.manager.field.formatter');
    $definitions = $formatter_manager->getDefinitions();

    // Verify field formatter is defined.
    $this->assertArrayHasKey('rail_score_formatter', $definitions);
    $this->assertEquals('RAIL Score Display', (string) $definitions['rail_score_formatter']['label']);

    // Verify supported field types.
    $supported_types = $definitions['rail_score_formatter']['field_types'];
    $this->assertContains('decimal', $supported_types);
    $this->assertContains('float', $supported_types);
    $this->assertContains('integer', $supported_types);
  }

  /**
   * Tests theme hooks.
   */
  public function testThemeHooks() {
    $theme_registry = \Drupal::service('theme.registry')->get();

    // Verify theme hooks are registered.
    $this->assertArrayHasKey('rail_score_dashboard', $theme_registry);
    $this->assertArrayHasKey('rail_score_widget', $theme_registry);
  }

  /**
   * Tests library definitions.
   */
  public function testLibraries() {
    /** @var \Drupal\Core\Asset\LibraryDiscoveryInterface $library_discovery */
    $library_discovery = \Drupal::service('library.discovery');
    $libraries = $library_discovery->getLibrariesByExtension('rail_score');

    // Verify admin library exists.
    $this->assertArrayHasKey('admin', $libraries);
    $this->assertArrayHasKey('css', $libraries['admin']);
    $this->assertArrayHasKey('js', $libraries['admin']);
  }

}
