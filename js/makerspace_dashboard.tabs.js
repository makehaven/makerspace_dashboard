/**
 * @file
 * Activates a dashboard tab based on the URL hash.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.makerspaceDashboardTabs = {
    attach: function (context, settings) {
      // Find all dashboard tabs and corresponding panes.
      var $tabs = $('.tabs a', context);
      var $panes = $('.vertical-tabs__pane', context);

      // Function to switch tabs.
      function switchTab(id) {
        // Remove 'is-active' class from all tabs and hide all panes.
        $tabs.removeClass('is-active');
        $panes.hide();

        // Find the tab and pane to activate.
        var $activeTab = $tabs.filter('[href="' + id + '"]');
        var $activePane = $(id, context);

        // Activate the tab and show the pane.
        if ($activeTab.length && $activePane.length) {
          $activeTab.addClass('is-active');
          $activePane.show();
        } else {
          // Default to the first tab if the hash is invalid.
          $tabs.first().addClass('is-active');
          $panes.first().show();
        }
      }

      // Check for a hash on page load.
      var hash = window.location.hash;
      if (hash) {
        switchTab(hash);
      } else {
        // Default to the first tab if no hash is present.
        $tabs.first().addClass('is-active');
        $panes.first().show();
      }

      // Handle tab clicks.
      $tabs.on('click', function (e) {
        e.preventDefault();
        var id = $(this).attr('href');
        switchTab(id);
        window.location.hash = id;
      });
    }
  };
})(jQuery, Drupal);
