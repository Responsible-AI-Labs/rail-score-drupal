<?php

namespace Drupal\rail_score\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\NodeInterface;
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
   * Constructs an EntityEventSubscriber object.
   *
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    RailScoreClient $rail_score_client,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger
  ) {
    $this->railScoreClient = $rail_score_client;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
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
    $result = $this->railScoreClient->evaluate($content);

    if ($result && isset($result['result']['rail_score']['score'])) {
      $score = (float) $result['result']['rail_score']['score'];

      // Store score if field exists.
      if ($entity->hasField('field_rail_score')) {
        $entity->set('field_rail_score', $score);
      }

      // Check threshold.
      $threshold = (float) $config->get('threshold') ?? 7.0;

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
      if (isset($result['result']['dimensions'])) {
        $dimension_scores = [];
        foreach ($result['result']['dimensions'] as $dimension => $data) {
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
