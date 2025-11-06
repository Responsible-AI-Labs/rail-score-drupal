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
    // Check if config.typed service exists (Drupal 11+).
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

    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#description' => $this->t('Configure your RAIL Score API connection.'),
    ];

    $form['api_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('RAIL Score API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('Get your API key from <a href="@url" target="_blank">responsibleailabs.ai</a>', [
        '@url' => 'https://responsibleailabs.ai',
      ]),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['api_settings']['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $config->get('base_url') ?: 'https://api.responsibleailabs.ai',
      '#description' => $this->t('The RAIL Score API base URL. Leave default unless using a custom endpoint.'),
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

    $form['evaluation_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Evaluation Settings'),
      '#description' => $this->t('Configure how content is evaluated.'),
    ];

    $form['evaluation_settings']['auto_evaluate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically evaluate content on save'),
      '#default_value' => $config->get('auto_evaluate') ?? TRUE,
      '#description' => $this->t('Automatically evaluate content when it is saved. Disable to manually trigger evaluations.'),
    ];

    $form['evaluation_settings']['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Score Threshold'),
      '#default_value' => $config->get('threshold') ?? 7.0,
      '#min' => 0,
      '#max' => 10,
      '#step' => 0.1,
      '#description' => $this->t('Content with scores below this threshold will be flagged for review.'),
      '#required' => TRUE,
    ];

    $form['evaluation_settings']['auto_unpublish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-unpublish low-scoring content'),
      '#default_value' => $config->get('auto_unpublish') ?? FALSE,
      '#description' => $this->t('Automatically unpublish content that falls below the threshold. <strong>Use with caution.</strong>'),
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
        'legal_compliance' => $this->t('Legal Compliance'),
        'user_impact' => $this->t('User Impact'),
      ],
      '#default_value' => $config->get('dimensions') ?: [],
      '#description' => $this->t('Select which dimensions to evaluate. Leave empty to evaluate all dimensions.'),
    ];

    $form['content_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content Types'),
      '#description' => $this->t('Select which content types should be automatically evaluated.'),
    ];

    // Load available content types.
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
      '#description' => $this->t('Select which content types should be evaluated with RAIL Score.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to test API connection.
   */
  public function testConnectionCallback(array &$form, FormStateInterface $form_state) {
    $element = $form['api_settings']['connection_result'];

    // Get values from the form (not saved config).
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('rail_score.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('base_url', $form_state->getValue('base_url'))
      ->set('auto_evaluate', $form_state->getValue('auto_evaluate'))
      ->set('threshold', (float) $form_state->getValue('threshold'))
      ->set('auto_unpublish', $form_state->getValue('auto_unpublish'))
      ->set('dimensions', array_filter($form_state->getValue('dimensions')))
      ->set('enabled_content_types', array_filter($form_state->getValue('enabled_content_types')))
      ->save();

    parent::submitForm($form, $form_state);

    $this->messenger()->addStatus($this->t('RAIL Score settings have been saved.'));
  }

}
