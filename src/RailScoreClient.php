<?php

namespace Drupal\rail_score;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\GuzzleException;

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
   * Constructs a RailScoreClient object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('rail_score');
  }

  /**
   * Evaluate content with RAIL Score API.
   *
   * @param string $content
   *   The content to evaluate.
   * @param array $options
   *   Additional evaluation options:
   *   - dimensions: Array of dimensions to evaluate.
   *   - weights: Array of dimension weights.
   *
   * @return array|false
   *   The evaluation result array, or FALSE on failure.
   */
  public function evaluate(string $content, array $options = []) {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');
    $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] API key not configured');
      return FALSE;
    }

    // Prepare dimensions if configured.
    $dimensions = $config->get('dimensions') ?: [];
    if (!empty($dimensions)) {
      $options['dimensions'] = array_values(array_filter($dimensions));
    }

    $this->logger->info('[RAIL Score] Evaluating content (@length characters)', [
      '@length' => strlen($content),
    ]);

    try {
      $response = $this->httpClient->request('POST', $base_url . '/v1/evaluation/basic', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'json' => array_merge(['content' => $content], $options),
        'timeout' => 60,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['result']['rail_score']['score'])) {
        $score = $data['result']['rail_score']['score'];
        $this->logger->notice('[RAIL Score] ✓ Evaluation complete: score @score/10', [
          '@score' => number_format($score, 1),
        ]);
      }

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('[RAIL Score] ✗ API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Check GDPR compliance.
   *
   * @param string $content
   *   The content to check.
   * @param array $context
   *   Additional context information:
   *   - data_type: Type of data being processed.
   *   - processing_purpose: Purpose of data processing.
   *
   * @return array|false
   *   The compliance result array, or FALSE on failure.
   */
  public function checkGdprCompliance(string $content, array $context = []) {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');
    $base_url = $config->get('base_url') ?: 'https://api.responsibleailabs.ai';

    if (empty($api_key)) {
      $this->logger->error('[RAIL Score] API key not configured');
      return FALSE;
    }

    $this->logger->info('[RAIL Score] Checking GDPR compliance');

    try {
      $response = $this->httpClient->request('POST', $base_url . '/v1/compliance/gdpr', [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'json' => [
          'content' => $content,
          'context' => $context,
        ],
        'timeout' => 60,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      $this->logger->notice('[RAIL Score] ✓ GDPR compliance check complete');

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('[RAIL Score] ✗ GDPR check error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
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
      $response = $this->httpClient->request('GET', $base_url . '/v1/usage', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'query' => ['limit' => 50],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      return $data;
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
   * @return bool
   *   TRUE if connection is successful, FALSE otherwise.
   */
  public function testConnection() {
    $config = $this->configFactory->get('rail_score.settings');
    $api_key = $config->get('api_key');

    if (empty($api_key)) {
      return FALSE;
    }

    // Try to get usage stats as a connection test.
    $result = $this->getUsageStats();

    return $result !== FALSE;
  }

}
