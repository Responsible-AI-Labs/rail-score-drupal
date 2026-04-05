<?php

namespace Drupal\rail_score\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Processes RAIL Score evaluations in a queue.
 *
 * @QueueWorker(
 *   id = "rail_score_evaluation",
 *   title = @Translation("RAIL Score Evaluation Worker"),
 *   cron = {"time" = 60}
 * )
 */
class RailScoreEvaluationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The RAIL Score client.
   *
   * @var \Drupal\rail_score\RailScoreClient
   */
  protected $railScoreClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a RailScoreEvaluationWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RailScoreClient $rail_score_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->railScoreClient = $rail_score_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('rail_score');
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
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!isset($data['entity_type']) || !isset($data['entity_id'])) {
      $this->logger->error('[RAIL Score] Invalid queue item: missing entity_type or entity_id');
      return;
    }

    $entity_type = $data['entity_type'];
    $entity_id = $data['entity_id'];

    try {
      // Load the entity.
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);

      if (!$entity) {
        $this->logger->warning('[RAIL Score] Entity @type:@id not found in queue worker', [
          '@type' => $entity_type,
          '@id' => $entity_id,
        ]);
        return;
      }

      $this->logger->info('[RAIL Score] Processing queued evaluation for @type:@id', [
        '@type' => $entity_type,
        '@id' => $entity_id,
      ]);

      // Extract content for evaluation.
      $content = $this->extractContent($entity);

      if (empty($content)) {
        $this->logger->warning('[RAIL Score] No content to evaluate for @type:@id', [
          '@type' => $entity_type,
          '@id' => $entity_id,
        ]);
        return;
      }

      // Perform evaluation.
      $result = $this->railScoreClient->evaluate($content, $data['options'] ?? []);

      if ($result && isset($result['result']['rail_score']['score'])) {
        $score = (float) $result['result']['rail_score']['score'];

        // Store the score if field exists.
        if ($entity->hasField('field_rail_score')) {
          $entity->set('field_rail_score', $score);
          $entity->save();

          $this->logger->notice('[RAIL Score] ✓ Queue evaluation complete for @label: @score/10', [
            '@label' => $entity->label(),
            '@score' => number_format($score, 1),
          ]);
        }

        // Store full result data if requested.
        if (!empty($data['store_full_result']) && $entity->hasField('field_rail_score_data')) {
          $entity->set('field_rail_score_data', json_encode($result));
          $entity->save();
        }
      }
      else {
        $this->logger->error('[RAIL Score] ✗ Queue evaluation failed for @type:@id', [
          '@type' => $entity_type,
          '@id' => $entity_id,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('[RAIL Score] Exception in queue worker: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
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
  protected function extractContent($entity) {
    $content_parts = [];

    // Add title if available.
    if (method_exists($entity, 'getTitle')) {
      $content_parts[] = $entity->getTitle();
    }
    elseif (method_exists($entity, 'label')) {
      $content_parts[] = $entity->label();
    }

    // Add body field if available.
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body_value = $entity->get('body')->value;
      $body_text = strip_tags($body_value);
      $content_parts[] = $body_text;
    }

    // Add other common text fields.
    $text_fields = ['field_summary', 'field_description', 'field_text', 'field_content'];
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
