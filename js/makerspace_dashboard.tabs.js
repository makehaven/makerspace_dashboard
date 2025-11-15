/**
 * @file
 * Keeps makerspace dashboard tab navigation in sync with hash-only panes.
 *
 * The dashboard now renders each section on its own route, but some admin
 * screens still expose anchor-based panes. This behavior limits itself to
 * local hash links so we do not interfere with route navigation.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.makerspaceDashboardTabs = {
    attach(context) {
      once('makerspace-dashboard-tabs', '.makerspace-dashboard-tabs', context).forEach((nav) => {
        const linkTargets = Array.from(nav.querySelectorAll('a[href^="#"]')).reduce((acc, link) => {
          const selector = link.getAttribute('href');
          if (!selector) {
            return acc;
          }
          const pane = document.querySelector(selector);
          if (pane instanceof HTMLElement) {
            acc.push([link, pane]);
          }
          return acc;
        }, /** @type {Array<[HTMLAnchorElement, HTMLElement]>} */ ([]));

        if (!linkTargets.length) {
          return;
        }

        const panes = linkTargets.map(([, pane]) => pane);

        const hideAll = () => {
          linkTargets.forEach(([link]) => link.classList.remove('is-active'));
          panes.forEach((pane) => pane.classList.add('visually-hidden'));
        };

        const activate = (link, pane) => {
          hideAll();
          link.classList.add('is-active');
          pane.classList.remove('visually-hidden');
        };

        linkTargets.forEach(([link, pane], index) => {
          link.addEventListener('click', (event) => {
            event.preventDefault();
            activate(link, pane);
            window.location.hash = link.getAttribute('href');
          });

          // Activate the first tab on initial load unless the hash matches.
          const hash = window.location.hash;
          if (hash && hash === link.getAttribute('href')) {
            activate(link, pane);
          }
          else if (!hash && index === 0) {
            activate(link, pane);
          }
        });
      });
    },
  };
})(Drupal, once);
