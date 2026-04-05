<?php

namespace Drupal\rail_score\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rail_score\RailScoreClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure RAIL Score settings.
 */
class RailScoreConfigForm extends ConfigFormBase {

  /**
   * The RAIL Score client.
   *
   * @var \Drupal\rail_score\RailScoreClient
   */
  protected $railScoreClient;

  /**
   * Constructs a RailScoreConfigForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface|null $typed_config_manager
   *   The typed config manager (Drupal 11+).
   * @param \Drupal\rail_score\RailScoreClient|null $rail_score_client
   *   The RAIL Score client.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ?TypedConfigManagerInterface $typed_config_manager = NULL,
    ?RailScoreClient $rail_score_client = NULL
  ) {
    // Support both Drupal 9/10 (1 param) and Drupal 11 (2 params).
    if ($typed_config_manager) {
      parent::__construct($config_factory, $typed_config_manager);
    }
    else {
      parent::__construct($config_factory);
    }
    $this->railScoreClient = $rail_score_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $typed_config = $container->has('config.typed') ? $container->get('config.typed') : NULL;

    return new static(
      $container->get('config.factory'),
      $typed_config,
      $container->get('rail_score.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['rail_score.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rail_score_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rail_score.settings');

    // =========================================================================
    // API Settings
    // =========================================================================
    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#description' => $this->t('Configure your RAIL Score API connection.'),
    ];

    $form['api_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('RAIL Score API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Get your API key from <a href="@url" target="_blank">responsibleailabs.ai</a>.', [
        '@url' => 'https://responsibleailabs.ai',
      ]),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['api_settings']['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $config->get('base_url') ?: 'https://api.responsibleailabs.ai',
      '#description' => $this->t('Leave default unless using a custom or self-hosted endpoint.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['api_settings']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnectionCallback',
        'wrapper' => 'connection-test-result',
      ],
    ];

    $form['api_settings']['connection_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    // =========================================================================
    // Evaluation Settings
    // =========================================================================
    $form['evaluation_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Evaluation Settings'),
      '#description' => $this->t('Configure how content is scored.'),
    ];

    $form['evaluation_settings']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Evaluation Mode'),
      '#options' => [
        'basic' => $this->t('Basic — fast, cost-efficient scoring'),
        'deep' => $this->t('Deep — detailed scoring with per-dimension explanations'),
      ],
      '#default_value' => $config->get('mode') ?: 'basic',
      '#description' => $this->t('Basic is recommended for automatic evaluation on save. Use deep for manual or compliance-critical reviews.'),
      '#required' => TRUE,
    ];

    $form['evaluation_settings']['auto_evaluate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically evaluate content on save'),
      '#default_value' => $config->get('auto_evaluate') ?? TRUE,
      '#description' => $this->t('Triggers evaluation inside <code>hook_entity_presave</code>. Disable to manually trigger evaluations.'),
    ];

    $form['evaluation_settings']['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Score Threshold'),
      '#default_value' => $config->get('threshold') ?? 7.0,
      '#min' => 0,
      '#max' => 10,
      '#step' => 0.1,
      '#description' => $this->t('Content scoring below this will be flagged. Used as the publish gate when auto-unpublish is on.'),
      '#required' => TRUE,
    ];

    $form['evaluation_settings']['auto_unpublish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-unpublish low-scoring content'),
      '#default_value' => $config->get('auto_unpublish') ?? FALSE,
      '#description' => $this->t('Automatically unpublish nodes that fall below the threshold. <strong>Use with caution.</strong>'),
    ];

    $form['evaluation_settings']['dimensions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Dimensions to Evaluate'),
      '#options' => [
        'safety' => $this->t('Safety'),
        'privacy' => $this->t('Privacy'),
        'fairness' => $this->t('Fairness'),
        'transparency' => $this->t('Transparency'),
        'accountability' => $this->t('Accountability'),
        'reliability' => $this->t('Reliability'),
        'inclusivity' => $this->t('Inclusivity'),
        'user_impact' => $this->t('User Impact'),
      ],
      '#default_value' => $config->get('dimensions') ?: [],
      '#description' => $this->t('Check the dimensions to evaluate. Leave all unchecked to evaluate all 8 dimensions.'),
    ];

    // =========================================================================
    // Evaluation Context
    // Maps to the Python SDK's domain, usecase, and include_* parameters.
    // =========================================================================
    $form['evaluation_context'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Evaluation Context'),
      '#description' => $this->t('Hint the API about the content domain and use-case to improve scoring accuracy.'),
    ];

    $form['evaluation_context']['domain'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Domain'),
      '#options' => [
        'general' => $this->t('General'),
        'healthcare' => $this->t('Healthcare'),
        'finance' => $this->t('Finance'),
        'legal' => $this->t('Legal'),
        'education' => $this->t('Education'),
        'code' => $this->t('Code'),
      ],
      '#default_value' => $config->get('domain') ?: 'general',
      '#description' => $this->t('Domain-specific scoring applies stricter criteria for fields like healthcare and finance.'),
      '#required' => TRUE,
    ];

    $form['evaluation_context']['usecase'] = [
      '#type' => 'select',
      '#title' => $this->t('Use Case'),
      '#options' => [
        'general' => $this->t('General'),
        'chatbot' => $this->t('Chatbot response'),
        'content_generation' => $this->t('Content generation'),
        'summarization' => $this->t('Summarization'),
        'translation' => $this->t('Translation'),
        'code_generation' => $this->t('Code generation'),
      ],
      '#default_value' => $config->get('usecase') ?: 'general',
      '#description' => $this->t('Describes how the content is being produced or used.'),
      '#required' => TRUE,
    ];

    $form['evaluation_context']['include_explanations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include per-dimension text explanations'),
      '#default_value' => $config->get('include_explanations') ?? FALSE,
      '#description' => $this->t('Returns a human-readable explanation for each dimension score. Automatically enabled in deep mode.'),
    ];

    $form['evaluation_context']['include_issues'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include per-dimension issue tags'),
      '#default_value' => $config->get('include_issues') ?? FALSE,
      '#description' => $this->t('Returns structured issue tags (e.g. <em>hallucination</em>, <em>stereotype</em>) per dimension.'),
    ];

    $form['evaluation_context']['include_suggestions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include improvement suggestions'),
      '#default_value' => $config->get('include_suggestions') ?? FALSE,
      '#description' => $this->t('Returns actionable improvement suggestions for low-scoring dimensions.'),
    ];

    // =========================================================================
    // Compliance Settings
    // Maps to the Python SDK's compliance_check() method.
    // =========================================================================
    $form['compliance_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Compliance Settings'),
      '#description' => $this->t('Run regulatory compliance checks alongside content evaluations.'),
    ];

    $form['compliance_settings']['enable_compliance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable compliance checking'),
      '#default_value' => $config->get('enable_compliance') ?? FALSE,
      '#description' => $this->t('Automatically run a compliance check each time content is evaluated.'),
    ];

    $compliance_visible = [
      'visible' => [':input[name="enable_compliance"]' => ['checked' => TRUE]],
    ];

    $form['compliance_settings']['compliance_frameworks'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Frameworks to check'),
      '#options' => [
        'gdpr' => $this->t('GDPR (EU General Data Protection Regulation)'),
        'ccpa' => $this->t('CCPA (California Consumer Privacy Act)'),
        'hipaa' => $this->t('HIPAA (Health Insurance Portability and Accountability Act)'),
        'eu_ai_act' => $this->t('EU AI Act'),
        'india_dpdp' => $this->t('India DPDP (Digital Personal Data Protection Act)'),
        'india_ai_gov' => $this->t('India AI Governance Framework'),
      ],
      '#default_value' => $config->get('compliance_frameworks') ?: ['gdpr'],
      '#description' => $this->t('Select one or more frameworks. Selecting multiple frameworks counts toward the 5-framework API limit.'),
      '#states' => $compliance_visible,
    ];

    $form['compliance_settings']['compliance_strict_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strict mode'),
      '#default_value' => $config->get('compliance_strict_mode') ?? FALSE,
      '#description' => $this->t('Use stricter threshold evaluation. Recommended for high-risk content domains.'),
      '#states' => $compliance_visible,
    ];

    $form['compliance_settings']['compliance_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Compliance incident threshold'),
      '#default_value' => $config->get('compliance_threshold') ?? 5.0,
      '#min' => 0,
      '#max' => 10,
      '#step' => 0.1,
      '#description' => $this->t('An incident is raised when the compliance score falls below this value. Default 5.0.'),
      '#states' => $compliance_visible,
    ];

    // =========================================================================
    // Content Types
    // =========================================================================
    $form['content_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content Types'),
      '#description' => $this->t('Select which content types are automatically evaluated on save.'),
    ];

    $content_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $options = [];
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }

    $form['content_types']['enabled_content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Content Types'),
      '#options' => $options,
      '#default_value' => $config->get('enabled_content_types') ?: [],
      '#description' => $this->t('Only checked content types will be evaluated when auto-evaluate is on.'),
    ];

    // =========================================================================
    // Drupal AI Module Integration
    // =========================================================================
    $form['ai_module_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Drupal AI Module Integration'),
      '#description' => $this->t('Automatically score responses generated by any AI provider registered through the <a href="https://www.drupal.org/project/ai" target="_blank">Drupal AI module</a>.'),
    ];

    $ai_available = \Drupal::hasService('ai.provider_manager');
    if (!$ai_available) {
      $form['ai_module_settings']['ai_not_installed'] = [
        '#markup' => '<div class="messages messages--warning">'
          . $this->t('The Drupal AI module is not installed. Install <code>drupal/ai</code> to enable this integration.')
          . '</div>',
      ];
    }

    $form['ai_module_settings']['enable_ai_integration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Drupal AI module integration'),
      '#default_value' => $config->get('enable_ai_integration') ?? FALSE,
      '#description' => $this->t('Score every response generated by any Drupal AI provider. Requires the <code>drupal/ai</code> module.'),
      '#disabled' => !$ai_available,
    ];

    // =========================================================================
    // Telemetry Settings
    // =========================================================================
    $form['telemetry_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Telemetry &amp; Review Queue Settings'),
      '#description' => $this->t('Configure thresholds for the built-in audit log, incident tracking, and human review queue.'),
    ];

    $form['telemetry_settings']['review_queue_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Review Queue Threshold'),
      '#default_value' => $config->get('review_queue_threshold') ?? 2.0,
      '#min' => 0,
      '#max' => 10,
      '#step' => 0.1,
      '#description' => $this->t('Any individual dimension scoring strictly below this value is added to the human review queue. Keep lower than the publish threshold (e.g. 2.0) to catch only severe outliers.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to test API connection.
   */
  public function testConnectionCallback(array &$form, FormStateInterface $form_state) {
    $element = $form['api_settings']['connection_result'];

    $api_key = $form_state->getValue('api_key');
    $base_url = $form_state->getValue('base_url');

    if ($this->railScoreClient->testConnection($api_key, $base_url)) {
      $element['#markup'] = '<div class="messages messages--status">' . $this->t('Connection successful!') . '</div>';
    }
    else {
      $element['#markup'] = '<div class="messages messages--error">' . $this->t('Connection failed. Please check your API key and base URL.') . '</div>';
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $api_key = $form_state->getValue('api_key');
    if (empty($api_key) || strlen($api_key) < 10) {
      $form_state->setErrorByName('api_key', $this->t('API key must be at least 10 characters.'));
    }

    $base_url = $form_state->getValue('base_url');
    if (!filter_var($base_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('base_url', $this->t('Base URL must be a valid URL.'));
    }

    $threshold = $form_state->getValue('threshold');
    if ($threshold < 0 || $threshold > 10) {
      $form_state->setErrorByName('threshold', $this->t('Threshold must be between 0 and 10.'));
    }

    $rq_threshold = $form_state->getValue('review_queue_threshold');
    if ($rq_threshold < 0 || $rq_threshold > 10) {
      $form_state->setErrorByName('review_queue_threshold', $this->t('Review queue threshold must be between 0 and 10.'));
    }

    if ($rq_threshold >= $threshold) {
      $form_state->setErrorByName('review_queue_threshold', $this->t('Review queue threshold (@rq) should be lower than the publish threshold (@pub) to avoid overlapping alerts.', [
        '@rq' => $rq_threshold,
        '@pub' => $threshold,
      ]));
    }

    $compliance_threshold = $form_state->getValue('compliance_threshold');
    if ($compliance_threshold !== NULL && ($compliance_threshold < 0 || $compliance_threshold > 10)) {
      $form_state->setErrorByName('compliance_threshold', $this->t('Compliance threshold must be between 0 and 10.'));
    }

    // At least one compliance framework must be selected when compliance is on.
    if ($form_state->getValue('enable_compliance')) {
      $frameworks = array_filter($form_state->getValue('compliance_frameworks') ?: []);
      if (empty($frameworks)) {
        $form_state->setErrorByName('compliance_frameworks', $this->t('Select at least one compliance framework.'));
      }
      if (count($frameworks) > 5) {
        $form_state->setErrorByName('compliance_frameworks', $this->t('A maximum of 5 frameworks can be checked per request.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('rail_score.settings')
      // API
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('base_url', $form_state->getValue('base_url'))
      // Evaluation
      ->set('mode', $form_state->getValue('mode'))
      ->set('auto_evaluate', $form_state->getValue('auto_evaluate'))
      ->set('threshold', (float) $form_state->getValue('threshold'))
      ->set('auto_unpublish', $form_state->getValue('auto_unpublish'))
      ->set('dimensions', array_filter($form_state->getValue('dimensions')))
      // Evaluation context
      ->set('domain', $form_state->getValue('domain'))
      ->set('usecase', $form_state->getValue('usecase'))
      ->set('include_explanations', (bool) $form_state->getValue('include_explanations'))
      ->set('include_issues', (bool) $form_state->getValue('include_issues'))
      ->set('include_suggestions', (bool) $form_state->getValue('include_suggestions'))
      // Compliance
      ->set('enable_compliance', (bool) $form_state->getValue('enable_compliance'))
      ->set('compliance_frameworks', array_values(array_filter($form_state->getValue('compliance_frameworks') ?: [])))
      ->set('compliance_strict_mode', (bool) $form_state->getValue('compliance_strict_mode'))
      ->set('compliance_threshold', (float) $form_state->getValue('compliance_threshold'))
      // Content types
      ->set('enabled_content_types', array_filter($form_state->getValue('enabled_content_types')))
      // Telemetry
      ->set('review_queue_threshold', (float) $form_state->getValue('review_queue_threshold'))
      // Drupal AI integration
      ->set('enable_ai_integration', (bool) $form_state->getValue('enable_ai_integration'))
      ->save();

    parent::submitForm($form, $form_state);

    $this->messenger()->addStatus($this->t('RAIL Score settings have been saved.'));
  }

}
