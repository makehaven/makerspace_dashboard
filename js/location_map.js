/**
 * @file
 * Initializes the member location map.
 */

(function (Drupal, L) {
  'use strict';

  Drupal.behaviors.makerspaceDashboardLocationMap = {
    attach: function (context, settings) {
      const mapElement = context.querySelector('#member-location-map');
      if (!mapElement || mapElement.classList.contains('processed')) {
        return;
      }
      mapElement.classList.add('processed');

      // Initialize the map.
      const map = L.map(mapElement).setView([42.3601, -71.0589], 8); // Centered on Boston, MA.

      // Add a tile layer from OpenStreetMap.
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      // Fetch the pre-geocoded location data.
      fetch(settings.makerspace_dashboard.locations_url)
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (Array.isArray(data) && data.length > 0) {
            // Add a default intensity value to each point, which resolved the
            // rendering issue in the minimal test case.
            const heatPoints = data.map(coords => [coords.lat, coords.lon, 0.5]);
            L.heatLayer(heatPoints, {radius: 25}).addTo(map);
          }
        })
        .catch(error => {
          console.error('Error fetching or processing location data:', error);
          mapElement.innerHTML = '<p>Could not load member location data.</p>';
        });
    }
  };

}(Drupal, L));
