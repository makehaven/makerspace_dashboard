/**
 * @file
 * Adds interactive time-range controls to Makerspace dashboard charts.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Fetches updated markup for a given chart and replaces the container.
   *
   * @param {HTMLElement} container
   *   The chart wrapper element (.makerspace-dashboard-range-chart).
   * @param {string} section
   *   Section machine name.
   * @param {string} chart
   *   Chart identifier within the section.
   * @param {string} range
   *   Selected time range key.
   * @param {HTMLElement} trigger
   *   The button that initiated the update.
   */
  function refreshChart(container, section, chart, range, trigger) {
    var url = Drupal.url('makerspace-dashboard/chart/' + section + '/' + chart + '/' + range);
    var buttons = container.querySelectorAll('.makerspace-dashboard-range-controls button');

    container.classList.add('is-loading');
    trigger.setAttribute('aria-busy', 'true');
    buttons.forEach(function (button) {
      button.disabled = true;
    });

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.markup) {
          throw new Error('Invalid response payload.');
        }

        // Merge drupalSettings from the response.
        if (payload.drupalSettings) {
          if (window.jQuery) {
            window.jQuery.extend(true, drupalSettings, payload.drupalSettings);
          } else {
            Object.assign(drupalSettings, payload.drupalSettings);
          }
        }

        // Load any declared libraries.
        var libraryPromises = [];
        var requiresGoogleLibrary = false;
        if (Array.isArray(payload.libraries)) {
          payload.libraries.forEach(function (library) {
            var parts = library.split('/');
            if (parts.length === 2 && typeof Drupal.loadLibrary === 'function') {
              libraryPromises.push(Drupal.loadLibrary(parts[0], parts[1]));
              if (library === 'charts_google/google') {
                requiresGoogleLibrary = true;
              }
            }
          });
        }

        return Promise.all(libraryPromises).catch(function () {
          // Ignore library load errors; charts may already be available.
        }).then(function () {
          if (requiresGoogleLibrary) {
            return new Promise(function (resolve) {
              var retries = 0;
              var maxRetries = 20;
              var delay = 75;
              function checkGoogleReady() {
                if (window.google && window.google.charts && window.google.visualization) {
                  resolve();
                  return;
                }
                retries++;
                if (retries >= maxRetries) {
                  resolve();
                  return;
                }
                setTimeout(checkGoogleReady, delay);
              }
              checkGoogleReady();
            });
          }
          return undefined;
        }).then(function () {
          var wrapper = document.createElement('div');
          wrapper.innerHTML = payload.markup;
          var replacement = wrapper.firstElementChild;
          if (!replacement) {
            throw new Error('Replacement markup missing root element.');
          }

          Drupal.detachBehaviors(container);
          container.parentNode.replaceChild(replacement, container);
          Drupal.attachBehaviors(replacement, drupalSettings);

          // Ensure focus is returned to the active button for accessibility.
          var activeButton = replacement.querySelector('.makerspace-dashboard-range-controls button.is-active');
          if (activeButton) {
            activeButton.focus();
          }
        });
      })
      .catch(function (error) {
        // eslint-disable-next-line no-console
        console.error('Dashboard range update failed:', error);
        window.alert(Drupal.t('Unable to update chart for the selected time range.'));
        // Reactivate the previous button state.
        var active = container.querySelector('.makerspace-dashboard-range-controls button.is-active');
        if (active) {
          active.disabled = false;
        }
      })
      .finally(function () {
        trigger.removeAttribute('aria-busy');
        container.classList.remove('is-loading');
        buttons.forEach(function (button) {
          button.disabled = false;
        });
      });
  }

  /**
   * Ensures the Charts Google behavior waits for the Google loader.
   */
  function patchGoogleChartsBehavior() {
    if (!Drupal.behaviors || !Drupal.behaviors.chartsGooglecharts) {
      return;
    }
    var original = Drupal.behaviors.chartsGooglecharts.attach;
    if (typeof original !== 'function' || original.__makerspacePatched) {
      return;
    }

    var patched = function (context, settings) {
      var self = this;
      var args = arguments;

      function invokeOriginal() {
        try {
          original.apply(self, args);
        } catch (error) {
          // eslint-disable-next-line no-console
          console.error('Google charts attach failed:', error);
        }
      }

      if (typeof window.google === 'undefined' || typeof window.google.charts === 'undefined') {
        if (typeof Drupal.loadLibrary === 'function') {
          Drupal.loadLibrary('charts_google', 'google')
            .catch(function () {
              // Ignore load errors; detach gracefully.
            })
            .then(function () {
              if (window.google && window.google.charts) {
                invokeOriginal();
              }
            });
        }
        return;
      }

      invokeOriginal();
    };

    patched.__makerspacePatched = true;
    Drupal.behaviors.chartsGooglecharts.attach = patched;
  }

  patchGoogleChartsBehavior();

  Drupal.behaviors.makerspaceDashboardRanges = {
    attach: function (context) {
      once('makerspaceDashboardRanges', '.makerspace-dashboard-range-chart', context).forEach(function (container) {
        patchGoogleChartsBehavior();
        var section = container.getAttribute('data-section');
        var chart = container.getAttribute('data-chart-id');
        if (!section || !chart) {
          return;
        }

        var buttons = container.querySelectorAll('.makerspace-dashboard-range-controls button');
        buttons.forEach(function (button) {
          button.addEventListener('click', function (event) {
            event.preventDefault();
            var range = button.getAttribute('data-range');
            var current = container.getAttribute('data-active-range');
            if (!range || range === current || button.disabled) {
              return;
            }

            refreshChart(container, section, chart, range, button);
          });
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
