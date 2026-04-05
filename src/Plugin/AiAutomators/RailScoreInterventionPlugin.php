<?php

namespace Drupal\rail_score\Plugin\AiAutomators;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\rail_score\Logger\RailScoreAuditLogger;
use Drupal\rail_score\Queue\RailScoreReviewQueue;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RAIL Score intervention for the Drupal AI Automators module.
 *
 * When the AI Automators module (a submodule of drupal/ai) is installed and
 * configured to run an AI operation on a field, this intervention plugin runs
 * AFTER the AI generates its response but BEFORE the value is saved to the
 * field. It:
 *
 *   1. Scores the AI-generated text using the RAIL Score API.
 *   2. Stores the score on the entity's field_rail_score field (if it exists).
 *   3. Optionally blocks saving the field value when the score is too low
 *      (controlled by the "block_on_low_score" plugin config option).
 *   4. Logs everything to the audit trail and review queue.
 *
 * Plugin annotation uses the AiAutomators namespace convention, but the actual
 * @AiInterventionPlugin annotation is only active when the AI Automators module
 * is installed. When absent, this file loads but the plugin annotation is
 * ignored harmlessly.
 *
 * @AiInterventionPlugin(
 *   id = "rail_score_intervention",
 *   label = @Translation("RAIL Score Evaluation"),
 *   description = @Translation("Evaluate AI-generated content with RAIL Score before saving to the field. Flags low-scoring responses for human review."),
 *   operation_types = {"chat", "text_to_text", "summarize"},
 * )
 */
class RailScoreInterventionPlugin implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The RAIL Score client.
   *
   * @var \Drupal\rail_score\RailScoreClient
   */
  protected $railScoreClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The audit logger.
   *
   * @var \Drupal\rail_score\Logger\RailScoreAuditLogger
   */
  protected $auditLogger;

  /**
   * The review queue.
   *
   * @var \Drupal\rail_score\Queue\RailScoreReviewQueue
   */
  protected $reviewQueue;

  /**
   * Plugin configuration array.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Constructs a RailScoreInterventionPlugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger.
   * @param \Drupal\rail_score\Queue\RailScoreReviewQueue $review_queue
   *   The review queue.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RailScoreClient $rail_score_client,
    ConfigFactoryInterface $config_factory,
    RailScoreAuditLogger $audit_logger,
    RailScoreReviewQueue $review_queue
  ) {
    $this->configuration = $configuration;
    $this->railScoreClient = $rail_score_client;
    $this->configFactory = $config_factory;
    $this->auditLogger = $audit_logger;
    $this->reviewQueue = $review_queue;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('rail_score.client'),
      $container->get('config.factory'),
      $container->get('rail_score.audit_logger'),
      $container->get('rail_score.review_queue')
    );
  }

  /**
   * Intervene on AI-generated content before it is saved to a field.
   *
   * Called by the AI Automators module after the provider returns a response.
   * Return TRUE to allow the value to be saved; return FALSE to block it.
   *
   * @param string $value
   *   The AI-generated text value about to be saved.
   * @param array $context
   *   Context array from AI Automators, typically containing:
   *   - entity: the entity being saved.
   *   - field_name: the target field.
   *   - provider_id: the AI provider that generated the value.
   *   - operation_type: the type of AI operation (chat, summarize, etc.).
   *
   * @return bool
   *   TRUE to allow saving, FALSE to block.
   */
  public function intervene(string &$value, array $context = []): bool {
    if (empty($value)) {
      return TRUE;
    }

    $config = $this->configFactory->get('rail_score.settings');
    $result = $this->railScoreClient->evaluate($value, [
      'mode' => $config->get('mode') ?: 'basic',
      'domain' => $config->get('domain') ?: 'general',
      'usecase' => $context['operation_type'] ?? 'content_generation',
    ]);

    if (!$result || !isset($result['result']['rail_score']['score'])) {
      // API unavailable — allow saving to not block editorial workflow.
      return TRUE;
    }

    $score = (float) $result['result']['rail_score']['score'];
    $threshold = (float) ($config->get('threshold') ?? 7.0);

    // Store score on the entity if field_rail_score exists.
    if (!empty($context['entity']) && is_object($context['entity'])) {
      $entity = $context['entity'];
      if (method_exists($entity, 'hasField') && $entity->hasField('field_rail_score')) {
        $entity->set('field_rail_score', $score);
      }
    }

    // Log to review queue if score is below review threshold.
    $review_threshold = (float) ($config->get('review_queue_threshold') ?? RailScoreReviewQueue::DEFAULT_THRESHOLD);
    $this->reviewQueue->checkAndEnqueue(
      $result,
      substr($value, 0, 200),
      $review_threshold,
      $score < $threshold,
      array_filter([
        'provider' => $context['provider_id'] ?? NULL,
        'operation' => $context['operation_type'] ?? NULL,
        'field' => $context['field_name'] ?? NULL,
      ])
    );

    // Block if score is below threshold AND plugin config says to block.
    $block_on_low_score = !empty($this->configuration['block_on_low_score']);
    if ($score < $threshold && $block_on_low_score) {
      \Drupal::logger('rail_score')->warning(
        '[RAIL Score] AI Automators: blocked field write for @field — score @score below threshold @threshold.',
        [
          '@field' => $context['field_name'] ?? 'unknown',
          '@score' => number_format($score, 1),
          '@threshold' => number_format($threshold, 1),
        ]
      );
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Returns configuration options for the AI Automators admin UI.
   *
   * The AI Automators module calls this to build the per-field plugin config
   * form. The returned array is merged into the Automator's form.
   *
   * @return array
   *   Form elements for the intervention plugin's configuration.
   */
  public function buildConfigForm(): array {
    return [
      'block_on_low_score' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Block saving when score is below threshold'),
        '#default_value' => $this->configuration['block_on_low_score'] ?? FALSE,
        '#description' => $this->t(
          'When checked, prevents the AI-generated value from being saved to the field if the RAIL Score is below the configured publish threshold (@threshold/10).',
          ['@threshold' => $this->configFactory->get('rail_score.settings')->get('threshold') ?? 7.0]
        ),
      ],
    ];
  }

}
