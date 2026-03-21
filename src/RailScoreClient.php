<?php

namespace Drupal\rail_score;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rail_score\Logger\RailScoreAuditLogger;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * RAIL Score API client service.
 *
 * Provides methods for evaluating content using the RAIL Score API.
 * Handles authentication, error handling, and response processing.
 *
 * @see https://responsibleailabs.ai/docs
 */
class RailScoreClient {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The audit logger for structured telemetry.
   *
   * @var \Drupal\rail_score\Logger\RailScoreAuditLogger
   */
  protected $auditLogger;

  /**
   * Constructs a RailScoreClient object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\rail_score\Logger\RailScoreAuditLogger $audit_logger
   *   The audit logger.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    RailScoreAuditLogger $audit_logger
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('rail_score');
    $this->auditLogger = $audit_logger;
  }

  /**
   * Evaluate content with RAIL Score API.
   *
   * @param string $content
   *   The content to evaluate (10–10,000 characters).
   * @param array $options
   *   Evaluation options:
   *   - mode: 'basic' (fast) or 'deep' (detailed). Defaults to config value.
   *   - dimensions: Array of dimension names to evaluate. Valid values:
   *     fairness, safety, reliability, transparency, privacy, accountability,
   *     inclusivity, user_impact. Defaults to all 8.
   *   - weights: Array of dimension weights (must sum to 100).
   *   - context: Additional evaluation context string.
   *   - domain: 'general', 'healthcare', 'finance', 'legal', 'education',
   *     or 'code'. Defaults to 'general'.
   *   - usecase: 'general', 'chatbot', 'content_generation', 'summarization',
   *     'translation', or 'code_generation'. Defaults to 'general'.
   *   - include_explanations: bool — include per-dimension text explanations.
   *   - include_issues: bool — include per-dimension issue lists.
   *   - include_suggestions: bool — include improvement suggestions.
   *
   * @return array|false
   *   The evaluation result array, or FALSE on failure. On success the array
   *   contains a 'result' key with:
   *   - rail_score: ['score', 'confidence', 'summary']
   *   - dimension_scores: keyed by dimension name, each with 'score',
   *     'confidence', 'explanation' (if requested), 'issues' (if requested)
   *   - explanation: overall explanation string
   *   - issues: array of issue objects (if requested)
   *   - improvement_suggestions: array of strings (if requested)
   *   - from_cache: bool
   */
  public function evaluate(string $content, array $options = []) {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');
    $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] API key not configured');
      return FALSE;
    }

    // Build payload — prefer $options, fall back to saved config for every
    // parameter so site-wide defaults are respected without callers needing to
    // repeat them each time.
    $payload = [
      'content' => $content,
      'mode' => $options['mode'] ?? $config->get('mode') ?: 'basic',
      'domain' => $options['domain'] ?? $config->get('domain') ?: 'general',
      'usecase' => $options['usecase'] ?? $config->get('usecase') ?: 'general',
    ];

    // include_suggestions: only send if explicitly set (true) to avoid
    // overriding the API default when not configured.
    $include_suggestions = $options['include_suggestions'] ?? $config->get('include_suggestions');
    if ($include_suggestions) {
      $payload['include_suggestions'] = TRUE;
    }

    // Dimensions: prefer options, fall back to config.
    $dimensions = $options['dimensions'] ?? NULL;
    if (empty($dimensions)) {
      $config_dimensions = $config->get('dimensions') ?: [];
      $dimensions = array_values(array_filter($config_dimensions));
    }
    if (!empty($dimensions)) {
      $payload['dimensions'] = $dimensions;
    }

    if (!empty($options['weights'])) {
      $payload['weights'] = $options['weights'];
    }
    if (isset($options['context'])) {
      $payload['context'] = $options['context'];
    }

    // include_explanations: explicit option > config > omit (API default).
    $include_explanations = $options['include_explanations'] ?? $config->get('include_explanations');
    if ($include_explanations !== NULL) {
      $payload['include_explanations'] = (bool) $include_explanations;
    }

    // include_issues: explicit option > config > omit (API default).
    $include_issues = $options['include_issues'] ?? $config->get('include_issues');
    if ($include_issues !== NULL) {
      $payload['include_issues'] = (bool) $include_issues;
    }

    $this->logger->info('[RAIL Score] Evaluating content (@length chars, mode: @mode)', [
      '@length' => strlen($content),
      '@mode' => $payload['mode'],
    ]);

    $request_context = [
      'mode' => $payload['mode'],
      'domain' => $payload['domain'],
      'content_length' => strlen($content),
    ];
    $start_time = microtime(TRUE);

    try {
      $response = $this->httpClient->request('POST', $base_url . '/railscore/v1/eval', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'json' => $payload,
        'timeout' => 60,
      ]);

      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['result']['rail_score']['score'])) {
        $score = $data['result']['rail_score']['score'];
        $this->logger->notice('[RAIL Score] ✓ Evaluation complete: score @score/10', [
          '@score' => number_format($score, 1),
        ]);
      }

      $this->auditLogger->logRequest('eval', $request_context, $data, $duration_ms, TRUE);

      return $data;
    }
    catch (ClientException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $status = $e->getResponse()->getStatusCode();
      $this->logApiError('Evaluation failed', $e);
      $this->auditLogger->logRequest('eval', $request_context, NULL, $duration_ms, FALSE, $status, $this->statusCodeMessage($status));
      return FALSE;
    }
    catch (ServerException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $status = $e->getResponse()->getStatusCode();
      $this->logger->error('[RAIL Score] ✗ Evaluation failed: server error HTTP @status', [
        '@status' => $status,
      ]);
      $this->auditLogger->logRequest('eval', $request_context, NULL, $duration_ms, FALSE, $status, "Server error HTTP $status");
      return FALSE;
    }
    catch (GuzzleException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $this->logger->error('[RAIL Score] ✗ Evaluation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->auditLogger->logRequest('eval', $request_context, NULL, $duration_ms, FALSE, 0, $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Check content compliance against one or more regulatory frameworks.
   *
   * Supported frameworks: gdpr, ccpa, hipaa, eu_ai_act, india_dpdp,
   * india_ai_gov.
   *
   * @param string $content
   *   The content to evaluate (max 50,000 characters).
   * @param array $options
   *   Compliance options:
   *   - framework: string — single framework ID (e.g. 'gdpr').
   *   - frameworks: array — list of framework IDs (max 5).
   *     Provide either 'framework' or 'frameworks', not both.
   *   - context: array — optional context with keys: domain, system_type,
   *     jurisdiction, data_subjects, decision_type,
   *     processes_personal_data, high_risk_indicators.
   *   - strict_mode: bool — use stricter threshold evaluation.
   *   - include_explanations: bool — include per-dimension explanations.
   *
   * @return array|false
   *   The compliance result array, or FALSE on failure.
   *   For single framework: contains 'result' key with compliance data.
   *   For multiple frameworks: contains 'results' and
   *   'cross_framework_summary' keys.
   */
  public function checkCompliance(string $content, array $options = []) {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');
    $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] API key not configured');
      return FALSE;
    }

    $payload = [
      'content' => $content,
      'strict_mode' => $options['strict_mode'] ?? (bool) $config->get('compliance_strict_mode'),
      'include_explanations' => $options['include_explanations'] ?? TRUE,
    ];

    // Framework selection: explicit option > saved config > default GDPR.
    if (!empty($options['frameworks'])) {
      $payload['frameworks'] = $options['frameworks'];
    }
    elseif (!empty($options['framework'])) {
      $payload['framework'] = $options['framework'];
    }
    else {
      $config_frameworks = array_values(array_filter($config->get('compliance_frameworks') ?: []));
      if (count($config_frameworks) > 1) {
        $payload['frameworks'] = $config_frameworks;
      }
      elseif (count($config_frameworks) === 1) {
        $payload['framework'] = $config_frameworks[0];
      }
      else {
        $payload['framework'] = 'gdpr';
      }
    }

    if (!empty($options['context'])) {
      $payload['context'] = $options['context'];
    }

    $frameworks_label = isset($payload['frameworks'])
      ? implode(', ', $payload['frameworks'])
      : $payload['framework'];

    $this->logger->info('[RAIL Score] Checking compliance: @frameworks', [
      '@frameworks' => $frameworks_label,
    ]);

    $request_context = ['framework' => $frameworks_label];
    $start_time = microtime(TRUE);

    try {
      $response = $this->httpClient->request('POST', $base_url . '/railscore/v1/compliance/check', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'json' => $payload,
        'timeout' => 60,
      ]);

      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $data = json_decode($response->getBody()->getContents(), TRUE);

      $this->logger->notice('[RAIL Score] ✓ Compliance check complete: @frameworks', [
        '@frameworks' => $frameworks_label,
      ]);

      $this->auditLogger->logRequest('compliance', $request_context, $data, $duration_ms, TRUE);

      return $data;
    }
    catch (ClientException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $status = $e->getResponse()->getStatusCode();
      $this->logApiError('Compliance check failed', $e);
      $this->auditLogger->logRequest('compliance', $request_context, NULL, $duration_ms, FALSE, $status, $this->statusCodeMessage($status));
      return FALSE;
    }
    catch (ServerException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $status = $e->getResponse()->getStatusCode();
      $this->logger->error('[RAIL Score] ✗ Compliance check failed: server error HTTP @status', [
        '@status' => $status,
      ]);
      $this->auditLogger->logRequest('compliance', $request_context, NULL, $duration_ms, FALSE, $status, "Server error HTTP $status");
      return FALSE;
    }
    catch (GuzzleException $e) {
      $duration_ms = (microtime(TRUE) - $start_time) * 1000;
      $this->logger->error('[RAIL Score] ✗ Compliance check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->auditLogger->logRequest('compliance', $request_context, NULL, $duration_ms, FALSE, 0, $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Check GDPR compliance.
   *
   * @param string $content
   *   The content to check.
   * @param array $context
   *   Optional context array.
   *
   * @return array|false
   *   The compliance result array, or FALSE on failure.
   *
   * @deprecated Use checkCompliance() with ['framework' => 'gdpr'] instead.
   */
  public function checkGdprCompliance(string $content, array $context = []) {
    $options = ['framework' => 'gdpr'];
    if (!empty($context)) {
      $options['context'] = $context;
    }
    return $this->checkCompliance($content, $options);
  }

  /**
   * Get usage statistics from RAIL Score API.
   *
   * @return array|false
   *   Usage statistics array, or FALSE on failure.
   */
  public function getUsageStats() {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');
    $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] API key not configured');
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('GET', $base_url . '/railscore/v1/usage', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'query' => ['limit' => 50],
        'timeout' => 30,
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (ClientException $e) {
      $this->logApiError('Usage stats failed', $e);
      return FALSE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('[RAIL Score] ✗ Usage stats error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Test API connection.
   *
   * Checks that the RAIL Score API is reachable using the health endpoint.
   * Key validation occurs naturally on the first evaluation call.
   *
   * @param string|null $api_key
   *   Optional API key to test. If not provided, uses saved config.
   * @param string|null $base_url
   *   Optional base URL to test. If not provided, uses saved config.
   *
   * @return bool
   *   TRUE if the API is reachable, FALSE otherwise.
   */
  public function testConnection($api_key = NULL, $base_url = NULL) {
    $config = $this->configFactory->get('rail_score.settings');

    if ($api_key === NULL) {
      $api_key = $config->get('api_key');
    }
    if ($base_url === NULL) {
      $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';
    }

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] ✗ Connection test failed: API key is empty');
      return FALSE;
    }

    try {
      // The /health endpoint requires no authentication and confirms the
      // service is up. Key validity is confirmed on the first real API call.
      $response = $this->httpClient->request('GET', $base_url . '/health', [
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      $status_code = $response->getStatusCode();

      if ($status_code === 200) {
        $this->logger->notice('[RAIL Score] ✓ Connection test successful — API is reachable');
        return TRUE;
      }

      $this->logger->error('[RAIL Score] ✗ API health check returned HTTP @status at @url', [
        '@status' => $status_code,
        '@url' => $base_url,
      ]);
      return FALSE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('[RAIL Score] ✗ Connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Log a typed error message from a Guzzle client or server exception.
   *
   * Maps HTTP status codes to actionable log messages so operators can
   * diagnose problems without reading raw API responses.
   *
   * @param string $context
   *   Short description of the operation that failed.
   * @param \GuzzleHttp\Exception\ClientException|\GuzzleHttp\Exception\ServerException $e
   *   The exception thrown by Guzzle.
   */
  protected function logApiError(string $context, \Exception $e): void {
    if (!method_exists($e, 'getResponse') || !$e->getResponse()) {
      $this->logger->error('[RAIL Score] ✗ @context: @message', [
        '@context' => $context,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    $status = $e->getResponse()->getStatusCode();

    $message = $this->statusCodeMessage($status);

    $this->logger->error('[RAIL Score] ✗ @context: @message', [
      '@context' => $context,
      '@message' => $message,
    ]);
  }

  /**
   * Map an HTTP status code to an actionable error message.
   *
   * @param int $status
   *   HTTP status code.
   *
   * @return string
   *   Human-readable error message.
   */
  protected function statusCodeMessage(int $status): string {
    $messages = [
      400 => 'Invalid request parameters — check content length and dimension names',
      401 => 'Invalid or missing API key — verify your key in RAIL Score settings',
      402 => 'Insufficient credits — please top up your RAIL Score account',
      403 => 'This feature is not available on your current plan',
      410 => 'Session expired',
      422 => 'Content too harmful to process (score below minimum threshold)',
      429 => 'Rate limit exceeded — requests are being sent too quickly',
      500 => 'API evaluation failed due to an internal server error',
      501 => 'This feature is not yet implemented by the API server',
      503 => 'API service is temporarily unavailable — try again shortly',
    ];
    return $messages[$status] ?? "Unexpected HTTP $status response";
  }

}
