<?php

namespace Drupal\rail_score\Logger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Structured audit logger for RAIL Score API activity.
 *
 * Covers three concerns from the Python SDK telemetry system:
 *
 * 1. Per-request instrumentation (equivalent to RAILInstrumentor):
 *    Every API call is recorded with timing, score, confidence, mode, domain,
 *    dimension scores, and error details into rail_score_audit_log.
 *
 * 2. Compliance logging (equivalent to ComplianceLogger):
 *    Compliance check results are logged per-framework with pass/fail counts
 *    and per-issue severity entries.
 *
 * 3. Incident logging (equivalent to IncidentLogger):
 *    Score breaches and compliance violations auto-raise incidents with unique
 *    IDs, severity classification, and affected dimension tracking, stored in
 *    rail_score_incidents.
 */
class RailScoreAuditLogger {

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
   * Valid RAIL dimensions.
   */
  const DIMENSIONS = [
    'fairness', 'safety', 'reliability', 'transparency',
    'privacy', 'accountability', 'inclusivity', 'user_impact',
  ];

  /**
   * Constructs a RailScoreAuditLogger.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('rail_score');
    $this->currentUser = $current_user;
  }

  // ---------------------------------------------------------------------------
  // Request instrumentation (RAILInstrumentor equivalent)
  // ---------------------------------------------------------------------------

  /**
   * Record a completed API request.
   *
   * Called after every evaluate() or checkCompliance() call — on both success
   * and failure — capturing the same attributes the Python SDK's RAILInstrumentor
   * records as OTEL span attributes and metrics.
   *
   * @param string $operation
   *   Operation type: 'eval', 'compliance', 'usage', 'health'.
   * @param array $request_context
   *   Request metadata: mode, domain, content_length, framework, etc.
   * @param array|null $result
   *   The parsed API result array, or NULL on failure.
   * @param float $duration_ms
   *   Round-trip duration in milliseconds.
   * @param bool $success
   *   Whether the call succeeded.
   * @param int $error_code
   *   HTTP status code on failure, 0 for network errors.
   * @param string $error_message
   *   Human-readable error description on failure.
   * @param string|null $entity_type
   *   Drupal entity type that triggered the call, if applicable.
   * @param int|null $entity_id
   *   Drupal entity ID that triggered the call, if applicable.
   */
  public function logRequest(
    string $operation,
    array $request_context,
    ?array $result,
    float $duration_ms,
    bool $success,
    int $error_code = 0,
    string $error_message = '',
    ?string $entity_type = NULL,
    ?int $entity_id = NULL
  ): void {
    $score = NULL;
    $confidence = NULL;
    $from_cache = 0;
    $dimension_scores_json = NULL;
    $framework = $request_context['framework'] ?? NULL;

    if ($success && $result) {
      $rail_score = $result['result']['rail_score'] ?? [];
      $score = isset($rail_score['score']) ? (float) $rail_score['score'] : NULL;
      $confidence = isset($rail_score['confidence']) ? (float) $rail_score['confidence'] : NULL;
      $from_cache = !empty($result['result']['from_cache']) ? 1 : 0;

      // Extract lean dimension scores (just score + confidence per dimension).
      if (!empty($result['result']['dimension_scores'])) {
        $dim_scores = [];
        foreach ($result['result']['dimension_scores'] as $dim => $data) {
          $dim_scores[$dim] = [
            'score' => $data['score'] ?? NULL,
            'confidence' => $data['confidence'] ?? NULL,
          ];
        }
        $dimension_scores_json = json_encode($dim_scores);
      }

      // For compliance, extract framework and score from the result.
      if ($operation === 'compliance') {
        if (isset($result['result']['framework'])) {
          $framework = $result['result']['framework'];
          $comp_score = $result['result']['compliance_score']['score'] ?? NULL;
          $score = $comp_score !== NULL ? (float) $comp_score : NULL;
        }
        elseif (isset($result['results'])) {
          $framework = implode(',', array_keys($result['results']));
        }
      }
    }

    try {
      $this->database->insert('rail_score_audit_log')
        ->fields([
          'timestamp' => \Drupal::time()->getRequestTime(),
          'operation' => $operation,
          'mode' => $request_context['mode'] ?? NULL,
          'domain' => $request_context['domain'] ?? NULL,
          'content_length' => $request_context['content_length'] ?? NULL,
          'score' => $score,
          'confidence' => $confidence,
          'from_cache' => $from_cache,
          'duration_ms' => round($duration_ms, 2),
          'dimension_scores' => $dimension_scores_json,
          'framework' => $framework,
          'success' => $success ? 1 : 0,
          'error_code' => $error_code ?: NULL,
          'error_message' => $error_message ?: NULL,
          'entity_type' => $entity_type,
          'entity_id' => $entity_id,
          'uid' => $this->currentUser->id(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Never let telemetry logging crash the main evaluation flow.
      $this->logger->warning('[RAIL Score] Audit log write failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Mirror to Drupal watchdog for real-time visibility.
    if ($success) {
      $score_str = $score !== NULL ? number_format($score, 1) . '/10' : 'n/a';
      $this->logger->info(
        '[RAIL Score Telemetry] @op completed in @ms ms — score: @score, mode: @mode, domain: @domain, cached: @cache',
        [
          '@op' => strtoupper($operation),
          '@ms' => round($duration_ms),
          '@score' => $score_str,
          '@mode' => $request_context['mode'] ?? 'n/a',
          '@domain' => $request_context['domain'] ?? 'n/a',
          '@cache' => $from_cache ? 'yes' : 'no',
        ]
      );
    }
    else {
      $this->logger->warning(
        '[RAIL Score Telemetry] @op failed after @ms ms — HTTP @code: @message',
        [
          '@op' => strtoupper($operation),
          '@ms' => round($duration_ms),
          '@code' => $error_code ?: 'network error',
          '@message' => $error_message,
        ]
      );
    }
  }

  // ---------------------------------------------------------------------------
  // Compliance logging (ComplianceLogger equivalent)
  // ---------------------------------------------------------------------------

  /**
   * Log a structured compliance check result.
   *
   * Emits an INFO-level log for the overall result, WARNING for each failed
   * requirement, and ERROR for high-severity issues — matching the Python SDK's
   * ComplianceLogger::log_compliance_result() behaviour.
   *
   * @param array $result
   *   The compliance result array from checkCompliance().
   * @param string $content_preview
   *   Optional first 200 chars of the evaluated content.
   */
  public function logComplianceResult(array $result, string $content_preview = ''): void {
    // Single-framework result.
    if (isset($result['result'])) {
      $this->logSingleComplianceResult($result['result'], $content_preview);
    }
    // Multi-framework result.
    elseif (isset($result['results'])) {
      $summary = $result['cross_framework_summary'] ?? [];
      $avg = isset($summary['average_score']) ? number_format($summary['average_score'], 1) : 'n/a';
      $weakest = $summary['weakest_framework'] ?? 'n/a';
      $weakest_score = isset($summary['weakest_score']) ? number_format($summary['weakest_score'], 1) : 'n/a';

      $this->logger->info(
        '[RAIL Compliance] Multi-framework: @n frameworks evaluated. Average=@avg, weakest=@weakest (@ws)',
        [
          '@n' => count($result['results']),
          '@avg' => $avg,
          '@weakest' => $weakest,
          '@ws' => $weakest_score,
        ]
      );

      foreach ($result['results'] as $fw_result) {
        $this->logSingleComplianceResult($fw_result, $content_preview);
      }
    }
  }

  /**
   * Log a single-framework compliance result.
   */
  protected function logSingleComplianceResult(array $result, string $content_preview): void {
    $framework = $result['framework'] ?? 'unknown';
    $compliance_score = $result['compliance_score'] ?? [];
    $score = isset($compliance_score['score']) ? number_format($compliance_score['score'], 1) : 'n/a';
    $label = $compliance_score['label'] ?? 'unknown';
    $reqs_passed = $result['requirements_passed'] ?? 0;
    $reqs_failed = $result['requirements_failed'] ?? 0;
    $summary = $compliance_score['summary'] ?? '';

    $this->logger->info(
      '[RAIL Compliance] @fw → @label (score=@score, passed=@passed, failed=@failed). @summary',
      [
        '@fw' => strtoupper($framework),
        '@label' => $label,
        '@score' => $score,
        '@passed' => $reqs_passed,
        '@failed' => $reqs_failed,
        '@summary' => $summary,
      ]
    );

    // Per-issue logs.
    foreach ($result['issues'] ?? [] as $issue) {
      $severity = $issue['severity'] ?? 'low';
      $issue_id = $issue['id'] ?? '';
      $desc = $issue['description'] ?? '';
      $article = $issue['article'] ?? '';
      $remediation = $issue['remediation_effort'] ?? '';

      $message = '[RAIL Compliance] [@fw] @sev issue (@id): @desc — @article (remediation: @rem)';
      $context = [
        '@fw' => strtoupper($framework),
        '@sev' => strtoupper($severity),
        '@id' => $issue_id,
        '@desc' => $desc,
        '@article' => $article,
        '@rem' => $remediation,
      ];

      if ($severity === 'high') {
        $this->logger->error($message, $context);
      }
      else {
        $this->logger->warning($message, $context);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Incident logging (IncidentLogger equivalent)
  // ---------------------------------------------------------------------------

  /**
   * Raise and persist a structured incident.
   *
   * Equivalent to IncidentLogger::log_incident(). Each incident gets a unique
   * ID that can be correlated across logs, the review queue, and external
   * ticketing systems.
   *
   * @param string $type
   *   Incident type: 'score_breach', 'compliance_violation', 'policy_violation'.
   * @param string $severity
   *   'critical', 'high', 'medium', or 'low'.
   * @param string $title
   *   Short human-readable title.
   * @param string $description
   *   Full description of what triggered the incident.
   * @param array $context
   *   Optional extra context:
   *   - framework: compliance framework involved.
   *   - score: the score that triggered the incident.
   *   - threshold: the threshold that was breached.
   *   - affected_dimensions: array of dimension names.
   *   - entity_type / entity_id: Drupal entity context.
   *   - metadata: array of extra key-value pairs.
   *
   * @return string
   *   The unique incident_id (e.g. 'inc_a3f2b19c4d8e').
   */
  public function logIncident(
    string $type,
    string $severity,
    string $title,
    string $description,
    array $context = []
  ): string {
    $incident_id = 'inc_' . substr(bin2hex(random_bytes(8)), 0, 12);
    $affected_dims = !empty($context['affected_dimensions'])
      ? implode(',', $context['affected_dimensions'])
      : NULL;

    if ($severity === 'critical') {
      $level = 'critical';
    }
    elseif ($severity === 'high') {
      $level = 'error';
    }
    elseif ($severity === 'medium') {
      $level = 'warning';
    }
    else {
      $level = 'notice';
    }

    try {
      $this->database->insert('rail_score_incidents')
        ->fields([
          'incident_id' => $incident_id,
          'timestamp' => \Drupal::time()->getRequestTime(),
          'incident_type' => $type,
          'severity' => $severity,
          'status' => 'open',
          'title' => substr($title, 0, 255),
          'description' => $description,
          'framework' => $context['framework'] ?? NULL,
          'score' => isset($context['score']) ? (float) $context['score'] : NULL,
          'threshold' => isset($context['threshold']) ? (float) $context['threshold'] : NULL,
          'affected_dimensions' => $affected_dims,
          'entity_type' => $context['entity_type'] ?? NULL,
          'entity_id' => $context['entity_id'] ?? NULL,
          'metadata' => !empty($context['metadata']) ? json_encode($context['metadata']) : NULL,
          'uid' => $this->currentUser->id(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('[RAIL Score] Incident write failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    $log_message = '[RAIL Incident @id] @sev @type: @title. @desc';
    $this->logger->$level($log_message, [
      '@id' => $incident_id,
      '@sev' => strtoupper($severity),
      '@type' => $type,
      '@title' => $title,
      '@desc' => $description,
    ]);

    return $incident_id;
  }

  /**
   * Auto-raise an incident if a compliance result score is below threshold.
   *
   * Equivalent to IncidentLogger::log_compliance_incident(). Returns the
   * incident_id if an incident was raised, NULL if score passed.
   *
   * @param array $result
   *   The compliance result from checkCompliance().
   * @param float $threshold
   *   Score threshold — incidents raised when score < threshold.
   * @param array $context
   *   Optional Drupal entity context (entity_type, entity_id).
   *
   * @return string|null
   *   The incident_id, or NULL if no incident was raised.
   */
  public function logComplianceIncident(array $result, float $threshold = 5.0, array $context = []): ?string {
    $fw_result = $result['result'] ?? $result;
    $framework = $fw_result['framework'] ?? 'unknown';
    $compliance_score = $fw_result['compliance_score'] ?? [];
    $score = (float) ($compliance_score['score'] ?? 0);
    $label = $compliance_score['label'] ?? 'unknown';
    $issues = $fw_result['issues'] ?? [];

    if ($score >= $threshold) {
      return NULL;
    }

    $high_issues = array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'high');
    $severity = $score < ($threshold * 0.5) ? 'critical' : 'high';
    $affected_dims = array_values(array_unique(array_filter(
      array_column($issues, 'dimension')
    )));

    return $this->logIncident(
      'compliance_violation',
      $severity,
      strtoupper($framework) . ' compliance score below threshold',
      sprintf(
        'Score %.1f breached threshold %.1f (label=%s, high-severity issues=%d).',
        $score,
        $threshold,
        $label,
        count($high_issues)
      ),
      array_merge($context, [
        'framework' => $framework,
        'score' => $score,
        'threshold' => $threshold,
        'affected_dimensions' => $affected_dims,
      ])
    );
  }

  /**
   * Raise an incident when a RAIL score drops below threshold.
   *
   * Equivalent to IncidentLogger::log_score_breach().
   *
   * @param float $score
   *   The actual RAIL score.
   * @param float $threshold
   *   The threshold that was breached.
   * @param string $content_preview
   *   Optional content preview for context.
   * @param array $affected_dims
   *   Array of dimension names that scored low.
   * @param array $context
   *   Optional Drupal entity context (entity_type, entity_id).
   *
   * @return string
   *   The incident_id.
   */
  public function logScoreBreach(
    float $score,
    float $threshold,
    string $content_preview = '',
    array $affected_dims = [],
    array $context = []
  ): string {
    $severity = $score < ($threshold * 0.5) ? 'critical' : 'high';
    $description = sprintf('RAIL score %.1f is below threshold %.1f.', $score, $threshold);
    if ($content_preview) {
      $description .= sprintf(' Content: "%s"', substr($content_preview, 0, 120));
    }

    return $this->logIncident(
      'score_breach',
      $severity,
      sprintf('RAIL score breach (score=%.1f, threshold=%.1f)', $score, $threshold),
      $description,
      array_merge($context, [
        'score' => $score,
        'threshold' => $threshold,
        'affected_dimensions' => $affected_dims,
      ])
    );
  }

  // ---------------------------------------------------------------------------
  // Incident status management
  // ---------------------------------------------------------------------------

  /**
   * Update the status of an incident.
   *
   * @param string $incident_id
   *   The incident ID to update.
   * @param string $status
   *   New status: 'acknowledged' or 'resolved'.
   *
   * @return bool
   *   TRUE on success.
   */
  public function updateIncidentStatus(string $incident_id, string $status): bool {
    try {
      $updated = $this->database->update('rail_score_incidents')
        ->fields(['status' => $status])
        ->condition('incident_id', $incident_id)
        ->execute();
      return (bool) $updated;
    }
    catch (\Exception $e) {
      $this->logger->error('[RAIL Score] Failed to update incident status: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  // ---------------------------------------------------------------------------
  // Query helpers for the dashboard
  // ---------------------------------------------------------------------------

  /**
   * Return the most recent audit log entries.
   *
   * @param int $limit
   *   Maximum number of entries to return.
   * @param int|null $start
   *   Optional Unix timestamp — only return entries at or after this time.
   * @param int|null $end
   *   Optional Unix timestamp — only return entries at or before this time.
   *
   * @return array
   *   Array of audit log row objects.
   */
  public function getRecentAuditLog(int $limit = 20, ?int $start = NULL, ?int $end = NULL): array {
    try {
      $query = $this->database->select('rail_score_audit_log', 'a')
        ->fields('a')
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

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
   * Return open incidents, newest first.
   *
   * @param int $limit
   *   Maximum number of incidents to return.
   * @param int|null $start
   *   Optional Unix timestamp — only return incidents at or after this time.
   * @param int|null $end
   *   Optional Unix timestamp — only return incidents at or before this time.
   *
   * @return array
   *   Array of incident row objects.
   */
  public function getOpenIncidents(int $limit = 20, ?int $start = NULL, ?int $end = NULL): array {
    try {
      $query = $this->database->select('rail_score_incidents', 'i')
        ->fields('i')
        ->condition('status', 'open')
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

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
   * Return aggregate API metrics — equivalent to the Python SDK's metric instruments.
   *
   * Covers: total requests, error count, avg duration, avg score,
   * cache hit rate, score distribution buckets (0-3, 4-6, 7-10),
   * and per-operation breakdown.
   *
   * @param int|null $start
   *   Optional Unix timestamp — only include entries at or after this time.
   * @param int|null $end
   *   Optional Unix timestamp — only include entries at or before this time.
   *
   * @return array
   *   Associative array of metric values, or empty array if no data.
   */
  public function getMetricsSummary(?int $start = NULL, ?int $end = NULL): array {
    try {
      $query = $this->database->select('rail_score_audit_log', 'a');
      $query->addExpression('COUNT(*)', 'total_requests');
      $query->addExpression('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END)', 'total_errors');
      $query->addExpression('AVG(duration_ms)', 'avg_duration_ms');
      $query->addExpression('AVG(CASE WHEN score IS NOT NULL THEN score END)', 'avg_score');
      $query->addExpression('SUM(CASE WHEN from_cache = 1 THEN 1 ELSE 0 END)', 'cache_hits');
      $query->addExpression("SUM(CASE WHEN score >= 0 AND score < 4 THEN 1 ELSE 0 END)", 'score_low');
      $query->addExpression("SUM(CASE WHEN score >= 4 AND score < 7 THEN 1 ELSE 0 END)", 'score_mid');
      $query->addExpression("SUM(CASE WHEN score >= 7 THEN 1 ELSE 0 END)", 'score_high');

      if ($start !== NULL) {
        $query->condition('timestamp', $start, '>=');
      }
      if ($end !== NULL) {
        $query->condition('timestamp', $end, '<=');
      }

      $row = $query->execute()->fetchAssoc();

      if (!$row || !$row['total_requests']) {
        return [];
      }

      // Per-operation breakdown (respects the same date range).
      $op_query = $this->database->select('rail_score_audit_log', 'a')
        ->fields('a', ['operation'])
        ->groupBy('operation');
      $op_query->addExpression('COUNT(*)', 'count');
      if ($start !== NULL) {
        $op_query->condition('timestamp', $start, '>=');
      }
      if ($end !== NULL) {
        $op_query->condition('timestamp', $end, '<=');
      }

      $by_operation = [];
      foreach ($op_query->execute() as $op_row) {
        $by_operation[$op_row->operation] = (int) $op_row->count;
      }

      $total = (int) $row['total_requests'];
      $cache_hits = (int) ($row['cache_hits'] ?? 0);

      return [
        'total_requests' => $total,
        'total_errors' => (int) ($row['total_errors'] ?? 0),
        'error_rate' => $total > 0 ? round(($row['total_errors'] / $total) * 100, 1) : 0,
        'avg_duration_ms' => $row['avg_duration_ms'] !== NULL ? round((float) $row['avg_duration_ms']) : NULL,
        'avg_score' => $row['avg_score'] !== NULL ? round((float) $row['avg_score'], 1) : NULL,
        'cache_hit_rate' => $total > 0 ? round(($cache_hits / $total) * 100, 1) : 0,
        'score_distribution' => [
          'low_0_3' => (int) ($row['score_low'] ?? 0),
          'mid_4_6' => (int) ($row['score_mid'] ?? 0),
          'high_7_10' => (int) ($row['score_high'] ?? 0),
        ],
        'by_operation' => $by_operation,
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
