<?php

namespace Drupal\rail_score\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\NodeInterface;
use Drupal\rail_score\Logger\RailScoreAuditLogger;
use Drupal\rail_score\Queue\RailScoreReviewQueue;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeEvents;
use Drupal\Core\Entity\EntityTypeEvent;

/**
 * Entity event subscriber for RAIL Score evaluation.
 *
 * Listens to entity events and triggers content evaluation when configured.
 */
class EntityEventSubscriber implements EventSubscriberInterface {

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The human review queue.
   *
   * @var \Drupal\rail_score\Queue\RailScoreReviewQueue
   */
  protected $reviewQueue;

  /**
   * The audit logger.
   *
   * @var \Drupal\rail_score\Logger\RailScoreAuditLogger
   */
  protected $auditLogger;

  /**
   * Constructs an EntityEventSubscriber object.
   *
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\rail_score\Queue\RailScoreReviewQueue $review_queue
   *   The human review queue.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger.
   */
  public function __construct(
    RailScoreClient $rail_score_client,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    RailScoreReviewQueue $review_queue,
    RailScoreAuditLogger $audit_logger
  ) {
    $this->railScoreClient = $rail_score_client;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->reviewQueue = $review_queue;
    $this->auditLogger = $audit_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Subscribe to entity type events.
    // Note: For entity CRUD events, we use hooks in the .module file.
    $events[EntityTypeEvents::CREATE][] = ['onEntityTypeCreate'];
    return $events;
  }

  /**
   * Responds to entity type creation event.
   *
   * @param \Drupal\Core\Entity\EntityTypeEvent $event
   *   The entity type event.
   */
  public function onEntityTypeCreate(EntityTypeEvent $event) {
    // This can be used for future enhancements when new entity types are created.
  }

  /**
   * Evaluates entity content with RAIL Score.
   *
   * This method is called from hook_entity_presave() in the .module file.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to evaluate.
   */
  public function evaluateEntity(EntityInterface $entity) {
    // Only process nodes.
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $config = $this->configFactory->get('rail_score.settings');

    // Check if auto-evaluation is enabled.
    if (!$config->get('auto_evaluate')) {
      return;
    }

    // Check if this content type is enabled.
    $enabled_types = $config->get('enabled_content_types') ?: [];
    if (!in_array($entity->bundle(), array_filter($enabled_types))) {
      return;
    }

    // Get content to evaluate.
    $content = $this->extractContent($entity);

    if (empty($content)) {
      return;
    }

    // Evaluate with RAIL Score.
    $eval_options = ['mode' => $config->get('mode') ?: 'basic'];
    $result = $this->railScoreClient->evaluate($content, $eval_options);

    if ($result && isset($result['result']['rail_score']['score'])) {
      $score = (float) $result['result']['rail_score']['score'];

      // Store score if field exists.
      if ($entity->hasField('field_rail_score')) {
        $entity->set('field_rail_score', $score);
      }

      // Check threshold.
      $threshold = (float) ($config->get('threshold') ?? 7.0);

      if ($score < $threshold) {
        $this->messenger->addWarning(
          t('Content has a RAIL Score of @score, which is below the threshold of @threshold.', [
            '@score' => number_format($score, 1),
            '@threshold' => number_format($threshold, 1),
          ])
        );

        // Auto-unpublish if configured.
        if ($config->get('auto_unpublish')) {
          $entity->setUnpublished();
          $this->messenger->addWarning(
            t('Content has been automatically unpublished due to low RAIL Score.')
          );
        }
      }
      else {
        $this->messenger->addStatus(
          t('Content evaluated successfully with a RAIL Score of @score/10.', [
            '@score' => number_format($score, 1),
          ])
        );
      }

      // Log dimension scores if available.
      if (isset($result['result']['dimension_scores'])) {
        $dimension_scores = [];
        foreach ($result['result']['dimension_scores'] as $dimension => $data) {
          if (isset($data['score'])) {
            $dimension_scores[] = ucfirst($dimension) . ': ' . number_format($data['score'], 1);
          }
        }

        if (!empty($dimension_scores)) {
          \Drupal::logger('rail_score')->info('[RAIL Score] Dimension scores for @title: @scores', [
            '@title' => $entity->label(),
            '@scores' => implode(', ', $dimension_scores),
          ]);
        }
      }

      // Human review queue: flag any dimension scoring below review threshold.
      // Uses a separate configurable low threshold distinct from the publish
      // threshold so borderline content is flagged without being blocked.
      $review_threshold = (float) ($config->get('review_queue_threshold') ?? RailScoreReviewQueue::DEFAULT_THRESHOLD);
      $this->reviewQueue->checkAndEnqueue(
        $result,
        substr($content, 0, 200),
        $review_threshold,
        $score < $threshold,
        [],
        $entity->getEntityTypeId(),
        (int) $entity->id()
      );

      // Compliance check: run after eval if compliance checking is enabled.
      if ($config->get('enable_compliance')) {
        $compliance_result = $this->railScoreClient->checkCompliance($content);
        if ($compliance_result) {
          $this->auditLogger->logComplianceResult(
            $compliance_result,
            substr($content, 0, 200)
          );
          $compliance_threshold = (float) ($config->get('compliance_threshold') ?? 5.0);
          $this->auditLogger->logComplianceIncident(
            $compliance_result,
            $compliance_threshold,
            [
              'entity_type' => $entity->getEntityTypeId(),
              'entity_id' => (int) $entity->id(),
            ]
          );
        }
      }
    }
    else {
      $this->messenger->addError(
        t('Failed to evaluate content with RAIL Score. Please check the configuration and try again.')
      );
    }
  }

  /**
   * Extract content from entity for evaluation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract content from.
   *
   * @return string
   *   The extracted content.
   */
  protected function extractContent(EntityInterface $entity) {
    $content_parts = [];

    // Add title if available.
    if (method_exists($entity, 'getTitle')) {
      $content_parts[] = $entity->getTitle();
    }

    // Add body field if available.
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body_value = $entity->get('body')->value;
      // Strip HTML tags for cleaner evaluation.
      $body_text = strip_tags($body_value);
      $content_parts[] = $body_text;
    }

    // Add other text fields if available.
    $text_fields = ['field_summary', 'field_description', 'field_text'];
    foreach ($text_fields as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $field_value = $entity->get($field_name)->value;
        $field_text = strip_tags($field_value);
        $content_parts[] = $field_text;
      }
    }

    return implode("\n\n", array_filter($content_parts));
  }

}
