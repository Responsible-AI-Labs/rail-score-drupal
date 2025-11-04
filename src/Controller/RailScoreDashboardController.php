<?php

namespace Drupal\rail_score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * RAIL Score dashboard controller.
 *
 * Displays usage statistics and recent content evaluations.
 */
class RailScoreDashboardController extends ControllerBase {

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
   * Constructs a RailScoreDashboardController object.
   *
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(RailScoreClient $rail_score_client, EntityTypeManagerInterface $entity_type_manager) {
    $this->railScoreClient = $rail_score_client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('rail_score.client'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Dashboard page.
   *
   * @return array
   *   Render array for the dashboard page.
   */
  public function dashboard() {
    // Get usage statistics from API.
    $stats = $this->railScoreClient->getUsageStats();

    // Get recent evaluations from the database.
    $recent_evaluations = $this->getRecentEvaluations();

    // Get local statistics.
    $local_stats = $this->getLocalStatistics();

    $build = [];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('View RAIL Score usage statistics and recent content evaluations.') . '</p>',
    ];

    // API Statistics section.
    if ($stats) {
      $build['api_stats'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('API Usage Statistics'),
      ];

      $build['api_stats']['content'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Total Evaluations: @count', ['@count' => $stats['total_records'] ?? 0]),
          $this->t('Credits Used: @count', ['@count' => $stats['total_credits_used'] ?? 0]),
        ],
      ];
    }
    else {
      $build['api_stats'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('Unable to retrieve API statistics. Please check your API configuration.') . '</div>',
      ];
    }

    // Local statistics section.
    if (!empty($local_stats)) {
      $build['local_stats'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Local Statistics'),
      ];

      $build['local_stats']['content'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Total Evaluated Content: @count', ['@count' => $local_stats['total']]),
          $this->t('Average Score: @score/10', ['@score' => number_format($local_stats['average'], 1)]),
          $this->t('Below Threshold: @count', ['@count' => $local_stats['below_threshold']]),
        ],
      ];
    }

    // Recent evaluations section.
    $build['recent'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Recent Evaluations'),
    ];

    if (!empty($recent_evaluations)) {
      $build['recent']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Title'),
          $this->t('Type'),
          $this->t('Score'),
          $this->t('Status'),
          $this->t('Date'),
          $this->t('Operations'),
        ],
        '#rows' => [],
      ];

      $config = $this->config('rail_score.settings');
      $threshold = $config->get('threshold') ?? 7.0;

      foreach ($recent_evaluations as $evaluation) {
        $score = $evaluation['score'];
        $status = $score >= $threshold ? $this->t('Pass') : $this->t('Review');
        $status_class = $score >= $threshold ? 'status-pass' : 'status-warning';

        $build['recent']['table']['#rows'][] = [
          ['data' => $evaluation['title']],
          ['data' => $evaluation['type']],
          ['data' => number_format($score, 1) . '/10'],
          ['data' => $status, 'class' => [$status_class]],
          ['data' => $this->t('@time ago', ['@time' => \Drupal::service('date.formatter')->formatInterval(time() - $evaluation['changed'])])],
          ['data' => [
            '#type' => 'link',
            '#title' => $this->t('View'),
            '#url' => $evaluation['url'],
          ]],
        ];
      }
    }
    else {
      $build['recent']['empty'] = [
        '#markup' => '<p>' . $this->t('No content has been evaluated yet. <a href="@config">Configure RAIL Score</a> to start evaluating content.', [
          '@config' => '/admin/config/content/rail-score',
        ]) . '</p>',
      ];
    }

    $build['#attached']['library'][] = 'rail_score/admin';

    return $build;
  }

  /**
   * Get recent evaluations from database.
   *
   * @return array
   *   Array of recent evaluations.
   */
  protected function getRecentEvaluations() {
    // Check if field_rail_score field exists on any node type.
    $field_storage = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->load('node.field_rail_score');

    if (!$field_storage) {
      return [];
    }

    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('field_rail_score', NULL, 'IS NOT NULL')
        ->sort('changed', 'DESC')
        ->range(0, 20)
        ->accessCheck(TRUE);

      $nids = $query->execute();

      if (empty($nids)) {
        return [];
      }

      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

      $evaluations = [];
      foreach ($nodes as $node) {
        if ($node->hasField('field_rail_score') && !$node->get('field_rail_score')->isEmpty()) {
          $evaluations[] = [
            'title' => $node->getTitle(),
            'type' => $node->type->entity->label(),
            'score' => (float) $node->get('field_rail_score')->value,
            'changed' => $node->getChangedTime(),
            'url' => $node->toUrl(),
          ];
        }
      }

      return $evaluations;
    }
    catch (\Exception $e) {
      $this->getLogger('rail_score')->error('Error loading recent evaluations: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get local statistics.
   *
   * @return array
   *   Array of local statistics.
   */
  protected function getLocalStatistics() {
    // Check if field_rail_score field exists.
    $field_storage = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->load('node.field_rail_score');

    if (!$field_storage) {
      return [];
    }

    try {
      $config = $this->config('rail_score.settings');
      $threshold = $config->get('threshold') ?? 7.0;

      // Get total count.
      $total_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('field_rail_score', NULL, 'IS NOT NULL')
        ->accessCheck(TRUE);
      $total = $total_query->count()->execute();

      if ($total == 0) {
        return [];
      }

      // Get below threshold count.
      $below_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('field_rail_score', $threshold, '<')
        ->accessCheck(TRUE);
      $below_threshold = $below_query->count()->execute();

      // Calculate average score.
      $nodes_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('field_rail_score', NULL, 'IS NOT NULL')
        ->accessCheck(TRUE);
      $nids = $nodes_query->execute();

      $sum = 0;
      $count = 0;
      if (!empty($nids)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
        foreach ($nodes as $node) {
          if ($node->hasField('field_rail_score') && !$node->get('field_rail_score')->isEmpty()) {
            $sum += (float) $node->get('field_rail_score')->value;
            $count++;
          }
        }
      }

      $average = $count > 0 ? $sum / $count : 0;

      return [
        'total' => $total,
        'average' => $average,
        'below_threshold' => $below_threshold,
      ];
    }
    catch (\Exception $e) {
      $this->getLogger('rail_score')->error('Error calculating local statistics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
