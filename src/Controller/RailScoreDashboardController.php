<?php

namespace Drupal\rail_score\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\rail_score\Logger\RailScoreAuditLogger;
use Drupal\rail_score\Queue\RailScoreReviewQueue;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
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
   * The audit logger.
   *
   * @var \Drupal\rail_score\Logger\RailScoreAuditLogger
   */
  protected $auditLogger;

  /**
   * The human review queue.
   *
   * @var \Drupal\rail_score\Queue\RailScoreReviewQueue
   */
  protected $reviewQueue;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a RailScoreDashboardController object.
   *
   * @param \Drupal\rail_score\RailScoreClient $rail_score_client
   *   The RAIL Score client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger.
   * @param \Drupal\rail_score\Queue\RailScoreReviewQueue $review_queue
   *   The human review queue.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    RailScoreClient $rail_score_client,
    EntityTypeManagerInterface $entity_type_manager,
    RailScoreAuditLogger $audit_logger,
    RailScoreReviewQueue $review_queue,
    RequestStack $request_stack
  ) {
    $this->railScoreClient = $rail_score_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->auditLogger = $audit_logger;
    $this->reviewQueue = $review_queue;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('rail_score.client'),
      $container->get('entity_type.manager'),
      $container->get('rail_score.audit_logger'),
      $container->get('rail_score.review_queue'),
      $container->get('request_stack')
    );
  }

  /**
   * Dashboard page.
   *
   * @return array
   *   Render array for the dashboard page.
   */
  public function dashboard() {
    $request = $this->requestStack->getCurrentRequest();

    // Parse optional date-range query parameters (?start=YYYY-MM-DD&end=YYYY-MM-DD).
    $start = NULL;
    $end = NULL;
    $start_raw = $request->query->get('start');
    $end_raw = $request->query->get('end');

    if ($start_raw) {
      $ts = strtotime($start_raw);
      if ($ts !== FALSE) {
        $start = $ts;
      }
    }
    if ($end_raw) {
      $ts = strtotime($end_raw . ' 23:59:59');
      if ($ts !== FALSE) {
        $end = $ts;
      }
    }

    // Get usage statistics from API.
    $stats = $this->railScoreClient->getUsageStats();

    // Get recent evaluations from the database.
    $recent_evaluations = $this->getRecentEvaluations();

    // Get local statistics.
    $local_stats = $this->getLocalStatistics();

    $build = [];

    // -----------------------------------------------------------------------
    // Date filter form (plain HTML form — no CSRF needed for report filters).
    // -----------------------------------------------------------------------
    $start_val = $start_raw ?: '';
    $end_val = $end_raw ?: '';
    $build['date_filter'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Filter by Date Range'),
      'form' => [
        '#markup' => '<form method="get" action="" class="rail-score-date-filter">'
          . '<label for="rs-start">' . $this->t('From') . '</label> '
          . '<input type="date" id="rs-start" name="start" value="' . htmlspecialchars($start_val, ENT_QUOTES) . '">'
          . '&nbsp;<label for="rs-end">' . $this->t('To') . '</label> '
          . '<input type="date" id="rs-end" name="end" value="' . htmlspecialchars($end_val, ENT_QUOTES) . '">'
          . '&nbsp;<button type="submit">' . $this->t('Apply') . '</button>'
          . ($start || $end ? '&nbsp;<a href="' . Url::fromRoute('rail_score.dashboard')->toString() . '">' . $this->t('Clear') . '</a>' : '')
          . '</form>',
      ],
    ];

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

    // -----------------------------------------------------------------------
    // Telemetry: API metrics summary (RAILInstrumentor equivalent).
    // -----------------------------------------------------------------------
    $metrics = $this->auditLogger->getMetricsSummary($start, $end);

    $build['metrics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Telemetry — Request Metrics'),
    ];

    if (!empty($metrics)) {
      $score_dist = $metrics['score_distribution'];
      $build['metrics']['content'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Total API Requests: @count', ['@count' => $metrics['total_requests']]),
          $this->t('Total Errors: @count (@rate% error rate)', [
            '@count' => $metrics['total_errors'],
            '@rate' => $metrics['error_rate'],
          ]),
          $this->t('Average Request Duration: @ms ms', ['@ms' => $metrics['avg_duration_ms'] ?? 'n/a']),
          $this->t('Average RAIL Score: @score/10', ['@score' => $metrics['avg_score'] ?? 'n/a']),
          $this->t('Cache Hit Rate: @rate%', ['@rate' => $metrics['cache_hit_rate']]),
          $this->t('Score Distribution — Low (0–3): @low | Mid (4–6): @mid | High (7–10): @high', [
            '@low' => $score_dist['low_0_3'],
            '@mid' => $score_dist['mid_4_6'],
            '@high' => $score_dist['high_7_10'],
          ]),
        ],
      ];

      if (!empty($metrics['by_operation'])) {
        $op_parts = [];
        foreach ($metrics['by_operation'] as $op => $count) {
          $op_parts[] = strtoupper($op) . ': ' . $count;
        }
        $build['metrics']['by_operation'] = [
          '#markup' => '<p>' . $this->t('By operation: @ops', ['@ops' => implode(', ', $op_parts)]) . '</p>',
        ];
      }
    }
    else {
      $build['metrics']['empty'] = [
        '#markup' => '<p>' . $this->t('No API telemetry recorded yet.') . '</p>',
      ];
    }

    // -----------------------------------------------------------------------
    // Telemetry: Open incidents (IncidentLogger equivalent).
    // -----------------------------------------------------------------------
    $open_incidents = $this->auditLogger->getOpenIncidents(10, $start, $end);
    $can_manage_incidents = $this->currentUser()->hasPermission('manage rail_score incidents');

    $build['incidents'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open Incidents (@count)', ['@count' => count($open_incidents)]),
    ];

    if (!empty($open_incidents)) {
      $build['incidents']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Severity'),
          $this->t('Type'),
          $this->t('Title'),
          $this->t('Score / Threshold'),
          $this->t('Dimensions'),
          $this->t('Raised'),
          $this->t('Actions'),
        ],
        '#rows' => [],
      ];
      foreach ($open_incidents as $incident) {
        $score_info = ($incident->score !== NULL)
          ? number_format($incident->score, 1) . ' / ' . number_format($incident->threshold, 1)
          : '—';

        $actions = [];
        if ($can_manage_incidents) {
          $actions[] = [
            '#type' => 'link',
            '#title' => $this->t('Acknowledge'),
            '#url' => Url::fromRoute('rail_score.incident_action', [
              'incident_id' => $incident->incident_id,
              'action' => 'acknowledged',
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ];
          $actions[] = ['#markup' => ' '];
          $actions[] = [
            '#type' => 'link',
            '#title' => $this->t('Resolve'),
            '#url' => Url::fromRoute('rail_score.incident_action', [
              'incident_id' => $incident->incident_id,
              'action' => 'resolved',
            ]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
          ];
        }

        $build['incidents']['table']['#rows'][] = [
          ['data' => $incident->incident_id],
          ['data' => strtoupper($incident->severity)],
          ['data' => $incident->incident_type],
          ['data' => $incident->title],
          ['data' => $score_info],
          ['data' => $incident->affected_dimensions ?: '—'],
          ['data' => $this->t('@time ago', [
            '@time' => \Drupal::service('date.formatter')->formatInterval(time() - $incident->timestamp),
          ])],
          ['data' => array_merge(['#type' => 'container'], $actions)],
        ];
      }
    }
    else {
      $build['incidents']['empty'] = [
        '#markup' => '<p>' . $this->t('No open incidents.') . '</p>',
      ];
    }

    // -----------------------------------------------------------------------
    // Telemetry: Human review queue (HumanReviewQueue equivalent).
    // -----------------------------------------------------------------------
    $pending_items = $this->reviewQueue->pending(NULL, 20, $start, $end);
    $pending_by_dim = $this->reviewQueue->pendingByDimension($start, $end);
    $total_pending = array_sum($pending_by_dim);
    $can_manage_queue = $this->currentUser()->hasPermission('manage rail_score review queue');

    $build['review_queue'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Human Review Queue (@count pending)', ['@count' => $total_pending]),
    ];

    if (!empty($pending_by_dim)) {
      $dim_parts = [];
      foreach (array_filter($pending_by_dim) as $dim => $count) {
        $dim_parts[] = ucfirst($dim) . ': ' . $count;
      }
      $build['review_queue']['summary'] = [
        '#markup' => '<p>' . $this->t('Pending by dimension: @dims', ['@dims' => implode(' | ', $dim_parts)]) . '</p>',
      ];
    }

    if (!empty($pending_items)) {
      $build['review_queue']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Item ID'),
          $this->t('Dimension'),
          $this->t('Score / Threshold'),
          $this->t('Issues'),
          $this->t('Content Preview'),
          $this->t('Queued'),
          $this->t('Incident'),
          $this->t('Actions'),
        ],
        '#rows' => [],
      ];
      foreach ($pending_items as $item) {
        $actions = [];
        if ($can_manage_queue) {
          $actions[] = [
            '#type' => 'link',
            '#title' => $this->t('Mark Reviewed'),
            '#url' => Url::fromRoute('rail_score.review_item_action', [
              'item_id' => $item->item_id,
              'action' => 'reviewed',
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ];
          $actions[] = ['#markup' => ' '];
          $actions[] = [
            '#type' => 'link',
            '#title' => $this->t('Dismiss'),
            '#url' => Url::fromRoute('rail_score.review_item_action', [
              'item_id' => $item->item_id,
              'action' => 'dismissed',
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ];
        }

        $build['review_queue']['table']['#rows'][] = [
          ['data' => $item->item_id],
          ['data' => strtoupper($item->dimension)],
          ['data' => number_format($item->score, 1) . ' / ' . number_format($item->threshold, 1)],
          ['data' => $item->issues ?: '—'],
          ['data' => $item->content_preview ? ('"' . $item->content_preview . '"') : '—'],
          ['data' => $this->t('@time ago', [
            '@time' => \Drupal::service('date.formatter')->formatInterval(time() - $item->timestamp),
          ])],
          ['data' => $item->incident_id ?: '—'],
          ['data' => array_merge(['#type' => 'container'], $actions)],
        ];
      }
    }
    else {
      $build['review_queue']['empty'] = [
        '#markup' => '<p>' . $this->t('No items pending human review.') . '</p>',
      ];
    }

    $build['#attached']['library'][] = 'rail_score/admin';

    return $build;
  }

  /**
   * Handles incident status changes (acknowledge or resolve).
   *
   * Accessible at /admin/reports/rail-score/incident/{incident_id}/{action}.
   * Requires 'manage rail_score incidents' permission.
   *
   * @param string $incident_id
   *   The unique incident ID (e.g. 'inc_a3f2b19c4d8e').
   * @param string $action
   *   Either 'acknowledged' or 'resolved'.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the dashboard.
   */
  public function incidentAction(string $incident_id, string $action): RedirectResponse {
    $allowed = ['acknowledged', 'resolved'];

    if (!in_array($action, $allowed, TRUE)) {
      $this->messenger()->addError($this->t('Invalid action "@action".', ['@action' => $action]));
    }
    elseif ($this->auditLogger->updateIncidentStatus($incident_id, $action)) {
      $this->messenger()->addStatus($this->t(
        'Incident @id has been marked as @action.',
        ['@id' => $incident_id, '@action' => $action]
      ));
    }
    else {
      $this->messenger()->addError($this->t(
        'Failed to update incident @id. It may not exist or may already be closed.',
        ['@id' => $incident_id]
      ));
    }

    return $this->redirect('rail_score.dashboard');
  }

  /**
   * Handles review queue item status changes (reviewed or dismissed).
   *
   * Accessible at /admin/reports/rail-score/review/{item_id}/{action}.
   * Requires 'manage rail_score review queue' permission.
   *
   * @param string $item_id
   *   The unique item ID (e.g. 'rev_c4d8e9f1a2b3').
   * @param string $action
   *   Either 'reviewed' or 'dismissed'.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the dashboard.
   */
  public function reviewItemAction(string $item_id, string $action): RedirectResponse {
    $allowed = ['reviewed', 'dismissed'];

    if (!in_array($action, $allowed, TRUE)) {
      $this->messenger()->addError($this->t('Invalid action "@action".', ['@action' => $action]));
    }
    elseif ($this->reviewQueue->updateItemStatus($item_id, $action)) {
      $this->messenger()->addStatus($this->t(
        'Review item @id has been marked as @action.',
        ['@id' => $item_id, '@action' => $action]
      ));
    }
    else {
      $this->messenger()->addError($this->t(
        'Failed to update review item @id. It may not exist or may already be resolved.',
        ['@id' => $item_id]
      ));
    }

    return $this->redirect('rail_score.dashboard');
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
