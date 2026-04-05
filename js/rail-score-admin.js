/**
 * @file
 * RAIL Score admin JavaScript.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for RAIL Score dashboard enhancements.
   */
  Drupal.behaviors.railScoreDashboard = {
    attach: function (context, settings) {
      // Add visual indicators for scores.
      $('.rail-score-dashboard table tbody tr', context).once('railScoreHighlight').each(function () {
        var $row = $(this);
        var scoreText = $row.find('td:nth-child(3)').text();
        var score = parseFloat(scoreText);

        if (!isNaN(score)) {
          if (score >= 8) {
            $row.addClass('score-high');
          }
          else if (score >= 7) {
            $row.addClass('score-medium');
          }
          else {
            $row.addClass('score-low');
          }
        }
      });

      // Add sorting capability to table headers.
      $('.rail-score-dashboard table th', context).once('railScoreSort').each(function () {
        var $th = $(this);
        $th.css('cursor', 'pointer');
        $th.attr('title', Drupal.t('Click to sort'));
      });
    }
  };

  /**
   * Behavior for RAIL Score configuration form.
   */
  Drupal.behaviors.railScoreConfigForm = {
    attach: function (context, settings) {
      // Toggle auto-unpublish warning.
      $('input[name="auto_unpublish"]', context).once('railScoreUnpublishWarning').on('change', function () {
        var $checkbox = $(this);
        var $warning = $('#auto-unpublish-warning');

        if ($checkbox.is(':checked')) {
          if ($warning.length === 0) {
            var warningHtml = '<div id="auto-unpublish-warning" class="messages messages--warning" style="margin-top: 10px;">' +
              '<strong>' + Drupal.t('Warning:') + '</strong> ' +
              Drupal.t('Content below the threshold will be automatically unpublished. Use this feature with caution.') +
              '</div>';
            $checkbox.closest('.form-item').after(warningHtml);
          }
        }
        else {
          $warning.remove();
        }
      }).trigger('change');

      // Show/hide dimensions based on enabled content types.
      $('input[name^="enabled_content_types"]', context).once('railScoreContentTypes').on('change', function () {
        var anyChecked = $('input[name^="enabled_content_types"]:checked').length > 0;
        var $dimensionsFieldset = $('.form-item-dimensions').closest('fieldset');

        if (anyChecked) {
          $dimensionsFieldset.show();
        }
        else {
          $dimensionsFieldset.hide();
        }
      });

      // Threshold value display.
      $('input[name="threshold"]', context).once('railScoreThreshold').on('input', function () {
        var value = parseFloat($(this).val());
        var $display = $('#threshold-display');

        if ($display.length === 0) {
          $display = $('<div id="threshold-display" style="margin-top: 5px; font-weight: bold;"></div>');
          $(this).closest('.form-item').append($display);
        }

        if (!isNaN(value)) {
          var status = '';
          var color = '';

          if (value >= 8) {
            status = Drupal.t('High quality standard');
            color = '#28a745';
          }
          else if (value >= 7) {
            status = Drupal.t('Moderate quality standard');
            color = '#ffc107';
          }
          else if (value >= 5) {
            status = Drupal.t('Basic quality standard');
            color = '#fd7e14';
          }
          else {
            status = Drupal.t('Low quality standard');
            color = '#dc3545';
          }

          $display.html(Drupal.t('Threshold: @value/10 - @status', {
            '@value': value.toFixed(1),
            '@status': status
          })).css('color', color);
        }
      }).trigger('input');
    }
  };

  /**
   * Behavior for RAIL Score widget.
   */
  Drupal.behaviors.railScoreWidget = {
    attach: function (context, settings) {
      $('.rail-score-widget .score-display', context).once('railScoreWidget').each(function () {
        var $display = $(this);
        var scoreText = $display.text();
        var score = parseFloat(scoreText);

        if (!isNaN(score)) {
          if (score >= 8) {
            $display.addClass('score-high');
          }
          else if (score >= 7) {
            $display.addClass('score-medium');
          }
          else {
            $display.addClass('score-low');
          }
        }
      });
    }
  };

  /**
   * Helper function to format numbers.
   */
  Drupal.railScore = Drupal.railScore || {};
  Drupal.railScore.formatScore = function (score) {
    return parseFloat(score).toFixed(1);
  };

})(jQuery, Drupal);
