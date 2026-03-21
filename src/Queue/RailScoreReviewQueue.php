<?php

namespace Drupal\rail_score\Queue;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rail_score\Logger\RailScoreAuditLogger;

/**
 * Human review queue for low-scoring RAIL dimensions.
 *
 * Persistent DB-backed equivalent of the Python SDK's HumanReviewQueue.
 *
 * Any dimension that scores below the configured threshold is enqueued for
 * human review. Items survive across requests (stored in the DB) and can be
 * actioned by any admin with the 'administer rail score' permission.
 *
 * Usage example from code:
 * @code
 *   $review_queue = \Drupal::service('rail_score.review_queue');
 *
 *   // Auto-check all 8 dimensions from an evaluate() result.
 *   $flagged = $review_queue->checkAndEnqueue(
 *     $eval_result,
 *     substr($content, 0, 200),
 *     2.0,
 *     TRUE
 *   );
 *
 *   // Inspect pending items per dimension.
 *   $safety_items = $review_queue->pending('safety');
 *
 *   // Drain all items for external handling.
 *   $all_items = $review_queue->drain();
 * @endcode
 */
class RailScoreReviewQueue {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The audit logger.
   *
   * @var \Drupal\rail_score\Logger\RailScoreAuditLogger
   */
  protected $auditLogger;

  /**
   * All eight RAIL dimensions.
   */
  const DIMENSIONS = [
    'fairness', 'safety', 'reliability', 'transparency',
    'privacy', 'accountability', 'inclusivity', 'user_impact',
  ];

  /**
   * Default review threshold — items below this score are flagged.
   */
  const DEFAULT_THRESHOLD = 2.0;

  /**
   * Constructs a RailScoreReviewQueue.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger (used to link incidents when link_incident=TRUE).
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user,
    RailScoreAuditLogger $audit_logger
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('rail_score');
    $this->currentUser = $current_user;
    $this->auditLogger = $audit_logger;
  }

  // ---------------------------------------------------------------------------
  // Primary API
  // ---------------------------------------------------------------------------

  /**
   * Inspect all 8 dimensions of an eval result and enqueue any below threshold.
   *
   * Equivalent to HumanReviewQueue::check_and_enqueue(). Returns the list of
   * items that were enqueued (empty array if nothing was flagged).
   *
   * @param array $eval_result
   *   The raw result array from RailScoreClient::evaluate(). Must contain
   *   $eval_result['result']['dimension_scores'].
   * @param string $content_preview
   *   Optional snippet of the evaluated content (truncated to 200 chars).
   * @param float $threshold
   *   Scores strictly below this value are flagged. Default 2.0.
   * @param bool $link_incident
   *   If TRUE, also raise an incident for each flagged dimension and store the
   *   incident_id on the review item.
   * @param array $metadata
   *   Extra key-value pairs attached to every enqueued item.
   * @param string|null $entity_type
   *   Drupal entity type being evaluated, if applicable.
   * @param int|null $entity_id
   *   Drupal entity ID being evaluated, if applicable.
   *
   * @return array
   *   Array of enqueued item arrays (item_id, dimension, score, threshold, …).
   */
  public function checkAndEnqueue(
    array $eval_result,
    string $content_preview = '',
    float $threshold = self::DEFAULT_THRESHOLD,
    bool $link_incident = FALSE,
    array $metadata = [],
    ?string $entity_type = NULL,
    ?int $entity_id = NULL
  ): array {
    $dimension_scores = $eval_result['result']['dimension_scores'] ?? [];
    $flagged = [];

    foreach (self::DIMENSIONS as $dim) {
      $ds = $dimension_scores[$dim] ?? NULL;
      if ($ds === NULL) {
        continue;
      }

      $score = (float) ($ds['score'] ?? 10.0);
      if ($score >= $threshold) {
        continue;
      }

      $explanation = $ds['explanation'] ?? NULL;
      $issues = !empty($ds['issues']) ? $ds['issues'] : NULL;

      $incident_id = NULL;
      if ($link_incident) {
        $affected_dims = [];
        foreach (self::DIMENSIONS as $d) {
          $ds2 = $dimension_scores[$d] ?? NULL;
          if ($ds2 && (float) ($ds2['score'] ?? 10.0) < $threshold) {
            $affected_dims[] = $d;
          }
        }
        $incident_id = $this->auditLogger->logScoreBreach(
          $score,
          $threshold,
          $content_preview,
          [$dim],
          array_filter([
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
          ])
        );
      }

      $item = $this->enqueue(
        $dim,
        $score,
        $threshold,
        $content_preview,
        $explanation,
        $issues,
        $incident_id,
        $metadata,
        $entity_type,
        $entity_id
      );

      $flagged[] = $item;
    }

    return $flagged;
  }

  /**
   * Manually enqueue a single dimension for human review.
   *
   * Equivalent to HumanReviewQueue::enqueue(). Persists immediately to DB
   * and logs a warning so the item appears in real-time in watchdog/dblog.
   *
   * @param string $dimension
   *   The RAIL dimension name (e.g. 'safety').
   * @param float $score
   *   The dimension score that breached the threshold.
   * @param float $threshold
   *   The threshold configured at enqueue time.
   * @param string $content_preview
   *   Optional content snippet (truncated to 200 chars).
   * @param string|null $explanation
   *   Per-dimension explanation from deep eval, if available.
   * @param array|null $issues
   *   Issue tags returned for this dimension, if available.
   * @param string|null $incident_id
   *   Linked incident ID if one was raised.
   * @param array $metadata
   *   Extra key-value pairs.
   * @param string|null $entity_type
   *   Drupal entity type.
   * @param int|null $entity_id
   *   Drupal entity ID.
   *
   * @return array
   *   The enqueued item as an associative array.
   */
  public function enqueue(
    string $dimension,
    float $score,
    float $threshold = self::DEFAULT_THRESHOLD,
    string $content_preview = '',
    ?string $explanation = NULL,
    ?array $issues = NULL,
    ?string $incident_id = NULL,
    array $metadata = [],
    ?string $entity_type = NULL,
    ?int $entity_id = NULL
  ): array {
    $item_id = 'rev_' . substr(bin2hex(random_bytes(8)), 0, 12);
    $timestamp = \Drupal::time()->getRequestTime();
    $issues_str = !empty($issues) ? implode(',', $issues) : NULL;

    $item = [
      'item_id' => $item_id,
      'timestamp' => $timestamp,
      'dimension' => $dimension,
      'score' => $score,
      'threshold' => $threshold,
      'status' => 'pending',
      'content_preview' => substr($content_preview, 0, 200),
      'explanation' => $explanation,
      'issues' => $issues_str,
      'incident_id' => $incident_id,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'metadata' => !empty($metadata) ? json_encode($metadata) : NULL,
      'uid' => $this->currentUser->id(),
    ];

    try {
      $this->database->insert('rail_score_review_queue')
        ->fields($item)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('[RAIL Score] Review queue write failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    $this->logger->warning(
      '[RAIL Review @id] @dim score @score is below threshold @threshold — queued for human review.@incident',
      [
        '@id' => $item_id,
        '@dim' => strtoupper($dimension),
        '@score' => number_format($score, 1),
        '@threshold' => number_format($threshold, 1),
        '@incident' => $incident_id ? " Incident: $incident_id." : '',
      ]
    );

    return $item;
  }

  // ---------------------------------------------------------------------------
  // Queue inspection
  // ---------------------------------------------------------------------------

  /**
   * Return pending review items without removing them.
   *
   * Equivalent to HumanReviewQueue::pending().
   *
   * @param string|null $dimension
   *   Filter to a specific dimension. Returns all if omitted.
   * @param int $limit
   *   Maximum number of items to return.
   * @param int|null $start
   *   Optional Unix timestamp — only return items queued at or after this time.
   * @param int|null $end
   *   Optional Unix timestamp — only return items queued at or before this time.
   *
   * @return array
   *   Array of review item row objects.
   */
  public function pending(?string $dimension = NULL, int $limit = 50, ?int $start = NULL, ?int $end = NULL): array {
    try {
      $query = $this->database->select('rail_score_review_queue', 'q')
        ->fields('q')
        ->condition('status', 'pending')
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

      if ($dimension !== NULL) {
        $query->condition('dimension', $dimension);
      }
      if ($start !== NULL) {
        $query->condition('timestamp', $start, '>=');
      }
      if ($end !== NULL) {
        $query->condition('timestamp', $end, '<=');
      }

      return $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Remove and return all pending items (optionally filtered by dimension).
   *
   * Equivalent to HumanReviewQueue::drain(). Items are marked 'reviewed' in
   * the DB rather than deleted so there is a permanent audit trail.
   *
   * @param string|null $dimension
   *   Filter to a specific dimension. Drains all if omitted.
   *
   * @return array
   *   Array of drained review item row objects.
   */
  public function drain(?string $dimension = NULL): array {
    $items = $this->pending($dimension, 500);

    if (empty($items)) {
      return [];
    }

    $ids = array_column($items, 'item_id');

    try {
      $query = $this->database->update('rail_score_review_queue')
        ->fields(['status' => 'reviewed'])
        ->condition('item_id', $ids, 'IN');
      $query->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('[RAIL Score] Review queue drain failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $items;
  }

  /**
   * Return the number of pending review items.
   *
   * Equivalent to HumanReviewQueue::size().
   *
   * @param string|null $dimension
   *   Filter to a specific dimension. Counts all if omitted.
   *
   * @return int
   *   Number of pending items.
   */
  public function size(?string $dimension = NULL): int {
    try {
      $query = $this->database->select('rail_score_review_queue', 'q')
        ->condition('status', 'pending');
      $query->addExpression('COUNT(*)', 'count');

      if ($dimension !== NULL) {
        $query->condition('dimension', $dimension);
      }

      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Update the status of a single review item.
   *
   * @param string $item_id
   *   The item_id to update.
   * @param string $status
   *   New status: 'reviewed' or 'dismissed'.
   *
   * @return bool
   *   TRUE on success.
   */
  public function updateItemStatus(string $item_id, string $status): bool {
    try {
      $updated = $this->database->update('rail_score_review_queue')
        ->fields(['status' => $status])
        ->condition('item_id', $item_id)
        ->execute();
      return (bool) $updated;
    }
    catch (\Exception $e) {
      $this->logger->error('[RAIL Score] Failed to update review item status: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Return a per-dimension breakdown of pending item counts.
   *
   * @param int|null $start
   *   Optional Unix timestamp — only count items queued at or after this time.
   * @param int|null $end
   *   Optional Unix timestamp — only count items queued at or before this time.
   *
   * @return array
   *   Associative array keyed by dimension name, values are counts.
   */
  public function pendingByDimension(?int $start = NULL, ?int $end = NULL): array {
    try {
      $query = $this->database->select('rail_score_review_queue', 'q')
        ->fields('q', ['dimension'])
        ->condition('status', 'pending')
        ->groupBy('dimension');
      $query->addExpression('COUNT(*)', 'count');

      if ($start !== NULL) {
        $query->condition('timestamp', $start, '>=');
      }
      if ($end !== NULL) {
        $query->condition('timestamp', $end, '<=');
      }

      $counts = array_fill_keys(self::DIMENSIONS, 0);
      foreach ($query->execute() as $row) {
        $counts[$row->dimension] = (int) $row->count;
      }
      return $counts;
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
