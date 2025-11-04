<?php

namespace Drupal\rail_score\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'rail_score_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "rail_score_formatter",
 *   label = @Translation("RAIL Score Display"),
 *   field_types = {
 *     "decimal",
 *     "float",
 *     "integer"
 *   }
 * )
 */
class RailScoreFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'display_mode' => 'badge',
      'show_threshold' => TRUE,
      'threshold_value' => 7.0,
      'decimal_places' => 1,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Display Mode'),
      '#options' => [
        'badge' => $this->t('Badge'),
        'progress_bar' => $this->t('Progress Bar'),
        'text' => $this->t('Text Only'),
        'widget' => $this->t('Full Widget'),
      ],
      '#default_value' => $this->getSetting('display_mode'),
      '#description' => $this->t('Choose how to display the RAIL Score.'),
    ];

    $elements['show_threshold'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show threshold indicator'),
      '#default_value' => $this->getSetting('show_threshold'),
      '#description' => $this->t('Display a visual indicator when score is below threshold.'),
    ];

    $elements['threshold_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Threshold Value'),
      '#default_value' => $this->getSetting('threshold_value'),
      '#min' => 0,
      '#max' => 10,
      '#step' => 0.1,
      '#description' => $this->t('The threshold value for quality checking.'),
      '#states' => [
        'visible' => [
          ':input[name*="show_threshold"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $elements['decimal_places'] = [
      '#type' => 'select',
      '#title' => $this->t('Decimal Places'),
      '#options' => [
        0 => '0',
        1 => '1',
        2 => '2',
      ],
      '#default_value' => $this->getSetting('decimal_places'),
      '#description' => $this->t('Number of decimal places to display.'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $display_mode = $this->getSetting('display_mode');
    $show_threshold = $this->getSetting('show_threshold');
    $threshold_value = $this->getSetting('threshold_value');
    $decimal_places = $this->getSetting('decimal_places');

    $display_modes = [
      'badge' => $this->t('Badge'),
      'progress_bar' => $this->t('Progress Bar'),
      'text' => $this->t('Text Only'),
      'widget' => $this->t('Full Widget'),
    ];

    $summary[] = $this->t('Display mode: @mode', ['@mode' => $display_modes[$display_mode]]);
    $summary[] = $this->t('Decimal places: @places', ['@places' => $decimal_places]);

    if ($show_threshold) {
      $summary[] = $this->t('Threshold: @threshold', ['@threshold' => $threshold_value]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $display_mode = $this->getSetting('display_mode');
    $show_threshold = $this->getSetting('show_threshold');
    $threshold_value = (float) $this->getSetting('threshold_value');
    $decimal_places = (int) $this->getSetting('decimal_places');

    foreach ($items as $delta => $item) {
      $score = (float) $item->value;

      // Determine score class.
      $score_class = 'score-medium';
      if ($score >= 8.0) {
        $score_class = 'score-high';
      }
      elseif ($show_threshold && $score < $threshold_value) {
        $score_class = 'score-low';
      }

      switch ($display_mode) {
        case 'badge':
          $elements[$delta] = [
            '#theme' => 'html_tag',
            '#tag' => 'span',
            '#value' => number_format($score, $decimal_places) . '/10',
            '#attributes' => [
              'class' => ['rail-score-badge', $score_class],
            ],
            '#attached' => [
              'library' => ['rail_score/admin'],
            ],
          ];
          break;

        case 'progress_bar':
          $percentage = ($score / 10) * 100;
          $elements[$delta] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['rail-score-progress']],
          ];
          $elements[$delta]['bar'] = [
            '#theme' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'class' => ['progress-bar', $score_class],
              'style' => 'width: ' . $percentage . '%;',
            ],
            '#value' => number_format($score, $decimal_places) . '/10',
          ];
          $elements[$delta]['#attached']['library'][] = 'rail_score/admin';
          break;

        case 'text':
          $elements[$delta] = [
            '#markup' => $this->t('RAIL Score: @score/10', [
              '@score' => number_format($score, $decimal_places),
            ]),
          ];
          break;

        case 'widget':
          $elements[$delta] = [
            '#theme' => 'rail_score_widget',
            '#score' => $score,
            '#threshold' => $threshold_value,
            '#dimensions' => NULL,
            '#attached' => [
              'library' => ['rail_score/admin'],
            ],
          ];
          break;
      }

      // Add threshold warning if needed.
      if ($show_threshold && $score < $threshold_value && $display_mode !== 'widget') {
        $elements[$delta]['#suffix'] = '<div class="rail-score-warning">' .
          $this->t('Below threshold of @threshold', ['@threshold' => $threshold_value]) .
          '</div>';
      }
    }

    return $elements;
  }

}
