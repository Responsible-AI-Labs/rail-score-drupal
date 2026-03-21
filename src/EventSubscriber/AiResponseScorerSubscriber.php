<?php

namespace Drupal\rail_score\EventSubscriber;

use Drupal\rail_score\Logger\RailScoreAuditLogger;
use Drupal\rail_score\Queue\RailScoreReviewQueue;
use Drupal\rail_score\RailScoreClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Scores AI-generated responses using the RAIL Score API.
 *
 * Integrates with the Drupal AI module (drupal/ai) by listening to the
 * post-generate-response event. Every response produced by any registered AI
 * provider is automatically evaluated against the 8 RAIL dimensions.
 *
 * Activation requirements:
 *   1. The `drupal/ai` module must be installed.
 *   2. "Enable Drupal AI module integration" must be checked in RAIL Score settings.
 *
 * This subscriber is registered unconditionally, but its event subscription
 * returns an empty array (no-op) when the integration is not available, so it
 * has zero cost when the AI module is absent.
 */
class AiResponseScorerSubscriber implements EventSubscriberInterface {

  /**
   * The Drupal AI post-generate-response event name (module 1.x).
   */
  const AI_POST_GENERATE_EVENT = 'ai.generate_response.post';

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
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
   * Constructs an AiResponseScorerSubscriber.
   *
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger.
   * @param \Drupal\rail_score\Queue\RailScoreReviewQueue $review_queue
   *   The review queue.
   */
  public function __construct(
    RailScoreClient $rail_score_client,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    RailScoreAuditLogger $audit_logger,
    RailScoreReviewQueue $review_queue
  ) {
    $this->railScoreClient = $rail_score_client;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->auditLogger = $audit_logger;
    $this->reviewQueue = $review_queue;
  }

  /**
   * {@inheritdoc}
   *
   * Only subscribe if the Drupal AI module's event class is present on disk.
   * This prevents a fatal error when the AI module is not installed.
   */
  public static function getSubscribedEvents() {
    // Guard: do not subscribe if the Drupal AI event class doesn't exist.
    // This makes the dependency entirely optional at runtime.
    if (!class_exists('\\Drupal\\ai\\Event\\PostGenerateResponseEvent')) {
      return [];
    }

    return [
      self::AI_POST_GENERATE_EVENT => ['onAiResponseGenerated', 0],
    ];
  }

  /**
   * Scores an AI-generated response with RAIL Score.
   *
   * Fired after any Drupal AI provider produces a response. Extracts the
   * response text, evaluates it, and attaches the score to the event metadata
   * so downstream subscribers and the caller can read it.
   *
   * @param object $event
   *   The PostGenerateResponseEvent from the Drupal AI module.
   */
  public function onAiResponseGenerated($event): void {
    $config = $this->configFactory->get('rail_score.settings');

    // Bail if RAIL Score / AI integration is not configured.
    if (!$config->get('enable_ai_integration')) {
      return;
    }

    // Extract response text from the Drupal AI event object.
    $response_text = $this->extractResponseText($event);
    if (empty($response_text)) {
      return;
    }

    // Build eval options — use the domain/mode from config, but override
    // usecase to 'chatbot' since this is an AI-generated chat/text response.
    $eval_options = [
      'mode' => $config->get('mode') ?: 'basic',
      'domain' => $config->get('domain') ?: 'general',
      'usecase' => 'chatbot',
    ];

    // Add AI-specific context (provider, operation type) if available.
    $provider_id = $this->safeCall($event, 'getProviderId');
    $operation_type = $this->safeCall($event, 'getOperationType');
    $model_id = $this->safeCall($event, 'getModelId');

    if ($provider_id || $operation_type) {
      $eval_options['context'] = implode(' | ', array_filter([
        $provider_id ? "provider: $provider_id" : NULL,
        $operation_type ? "operation: $operation_type" : NULL,
        $model_id ? "model: $model_id" : NULL,
      ]));
    }

    $result = $this->railScoreClient->evaluate($response_text, $eval_options);

    if (!$result || !isset($result['result']['rail_score']['score'])) {
      return;
    }

    $score = (float) $result['result']['rail_score']['score'];
    $threshold = (float) ($config->get('threshold') ?? 7.0);

    // Attach the score to the event so callers can read it.
    if (method_exists($event, 'setMetadata')) {
      $event->setMetadata('rail_score', [
        'score' => $score,
        'threshold' => $threshold,
        'pass' => $score >= $threshold,
        'provider' => $provider_id,
        'operation' => $operation_type,
      ]);
    }

    // Warn admin when a generated response scores below threshold.
    if ($score < $threshold) {
      $this->messenger->addWarning(
        t('AI-generated response has a RAIL Score of @score (threshold: @threshold). Review recommended.', [
          '@score' => number_format($score, 1),
          '@threshold' => number_format($threshold, 1),
        ])
      );

      // Flag low-scoring dimensions for human review.
      $review_threshold = (float) ($config->get('review_queue_threshold') ?? RailScoreReviewQueue::DEFAULT_THRESHOLD);
      $this->reviewQueue->checkAndEnqueue(
        $result,
        substr($response_text, 0, 200),
        $review_threshold,
        TRUE,
        array_filter([
          'provider' => $provider_id,
          'operation' => $operation_type,
          'model' => $model_id,
        ])
      );
    }
    else {
      // Score passed — log at info level only (no admin messenger spam).
      \Drupal::logger('rail_score')->info(
        '[RAIL Score] AI response from @provider scored @score/10 (pass).',
        [
          '@provider' => $provider_id ?: 'unknown',
          '@score' => number_format($score, 1),
        ]
      );
    }

    // Run compliance check on AI-generated responses if enabled.
    if ($config->get('enable_compliance')) {
      $compliance_result = $this->railScoreClient->checkCompliance($response_text);
      if ($compliance_result) {
        $this->auditLogger->logComplianceResult($compliance_result, substr($response_text, 0, 200));
        $compliance_threshold = (float) ($config->get('compliance_threshold') ?? 5.0);
        $this->auditLogger->logComplianceIncident(
          $compliance_result,
          $compliance_threshold,
          array_filter([
            'metadata' => array_filter([
              'provider' => $provider_id,
              'operation' => $operation_type,
            ]),
          ])
        );
      }
    }
  }

  /**
   * Extract plain text from a Drupal AI PostGenerateResponseEvent.
   *
   * The AI module's response object may be a string, a NormalizedResponseText,
   * or another typed value object depending on the operation type. Try the
   * most common accessor methods in order before falling back to string cast.
   *
   * @param object $event
   *   The Drupal AI post-generate event.
   *
   * @return string
   *   The plain text response, or empty string if it cannot be extracted.
   */
  protected function extractResponseText($event): string {
    // getNormalizedResponse() is the standard method on PostGenerateResponseEvent.
    $text = $this->safeCall($event, 'getNormalizedResponse');
    if (is_string($text) && !empty(trim($text))) {
      return trim($text);
    }

    // Some operation types wrap in a response value object.
    $response = $this->safeCall($event, 'getResponse');
    if (is_string($response)) {
      return trim($response);
    }
    if (is_object($response)) {
      // Try getText() first (NormalizedResponseText pattern).
      $inner = $this->safeCall($response, 'getText');
      if (is_string($inner)) {
        return trim($inner);
      }
      return trim((string) $response);
    }

    return '';
  }

  /**
   * Call a method on an object if it exists, returning NULL on failure.
   *
   * Used to handle varying Drupal AI event APIs across versions without
   * raising fatal errors.
   *
   * @param object $object
   *   The object to call the method on.
   * @param string $method
   *   The method name.
   *
   * @return mixed
   *   The return value, or NULL if the method does not exist.
   */
  protected function safeCall($object, string $method) {
    if (is_object($object) && method_exists($object, $method)) {
      return $object->$method();
    }
    return NULL;
  }

}
