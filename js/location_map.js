/**
 * @file
 * Initializes the member location map.
 */

(function (Drupal, once, L) {
  'use strict';

  const renderNotice = (element, message, mapInstance) => {
    if (mapInstance && typeof mapInstance.remove === 'function') {
      mapInstance.remove();
    }
    element.classList.add('makerspace-dashboard-location-map--empty');
    element.textContent = '';
    const notice = document.createElement('p');
    notice.className = 'makerspace-dashboard-location-notice';
    notice.textContent = message;
    element.appendChild(notice);
  };

  Drupal.behaviors.makerspaceDashboardLocationMap = {
    attach(context, settings) {
      if (typeof L === 'undefined') {
        return;
      }

      const dashboardSettings = (settings && settings.makerspace_dashboard) ? settings.makerspace_dashboard : {};
      const locationsUrl = dashboardSettings.locations_url || null;
      once('makerspace-dashboard-location-map', '#member-location-map', context).forEach((mapElement) => {
        if (!locationsUrl) {
          renderNotice(mapElement, Drupal.t('Member location data is not available.'));
          return;
        }

        // Centered on New Haven, CT.
        const map = L.map(mapElement).setView([41.3083, -72.9279], 8);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        fetch(locationsUrl, { credentials: 'same-origin' })
          .then((response) => {
            if (!response.ok) {
              throw new Error(response.statusText || 'Request failed');
            }
            return response.json();
          })
          .then((data) => {
            if (!Array.isArray(data) || data.length === 0) {
              renderNotice(mapElement, Drupal.t('No member location data is available yet.'), map);
              return;
            }

            const heatPoints = data
              .map((coords) => [parseFloat(coords.lat), parseFloat(coords.lon), 0.6])
              .filter((coords) => Number.isFinite(coords[0]) && Number.isFinite(coords[1]));

            if (!heatPoints.length) {
              renderNotice(mapElement, Drupal.t('No member location data is available yet.'), map);
              return;
            }

            L.heatLayer(heatPoints, { radius: 28, blur: 18, minOpacity: 0.35 }).addTo(map);
          })
          .catch((error) => {
            console.error('Error fetching or processing location data:', error);
            renderNotice(mapElement, Drupal.t('Could not load member location data.'), map);
          });
      });
    },
  };

}(Drupal, once, L));
