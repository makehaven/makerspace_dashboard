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
        const wrapper = mapElement.closest('.makerspace-dashboard-location-map-wrapper');
        if (!locationsUrl) {
          renderNotice(mapElement, Drupal.t('Member location data is not available.'));
          return;
        }

        // Centered on New Haven, CT.
        const map = L.map(mapElement).setView([41.3083, -72.9279], 8);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
          attribution: '&copy; <a href="https://carto.com/attributions">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
          subdomains: 'abcd',
        }).addTo(map);

        fetch(locationsUrl, { credentials: 'same-origin' })
          .then((response) => {
            if (!response.ok) {
              throw new Error(response.statusText || 'Request failed');
            }
            return response.json();
          })
          .then((data) => {
            if (!data || !Array.isArray(data.locations) || data.locations.length === 0) {
              renderNotice(mapElement, Drupal.t('No member location data is available yet.'), map);
              return;
            }

            const positions = data.locations
              .map((coords) => ({
                lat: Number.parseFloat(coords.lat),
                lon: Number.parseFloat(coords.lon),
                count: Number.parseFloat(coords.count || 1),
              }))
              .filter((coords) => Number.isFinite(coords.lat) && Number.isFinite(coords.lon));

            if (!positions.length) {
              renderNotice(mapElement, Drupal.t('No member location data is available yet.'), map);
              return;
            }

            const infoEl = document.createElement('div');
            infoEl.className = 'makerspace-dashboard-location-map-info';

            if (data.mappable_count && data.total_count) {
              const mappableCount = Number.parseInt(data.mappable_count, 10);
              const totalCount = Number.parseInt(data.total_count, 10);
              if (!Number.isNaN(mappableCount) && !Number.isNaN(totalCount)) {
                const percent = totalCount > 0 ? ((mappableCount / totalCount) * 100).toFixed(1) : 0;
                const countEl = document.createElement('p');
                countEl.className = 'makerspace-dashboard-location-map-count';
                countEl.innerHTML = Drupal.t('Showing @mappable of @total members with valid locations (@percent%).', {
                  '@mappable': mappableCount,
                  '@total': totalCount,
                  '@percent': percent,
                });
                infoEl.appendChild(countEl);
              }
            }

            const jitterEl = document.createElement('p');
            jitterEl.className = 'makerspace-dashboard-location-map-jitter';
            jitterEl.textContent = Drupal.t('To protect member privacy, a small amount of random "jitter" has been added to each location marker, so they do not represent exact addresses.');
            infoEl.appendChild(jitterEl);
            wrapper.appendChild(infoEl);

            const latLngs = positions.map((coords) => [coords.lat, coords.lon]);
            const bounds = L.latLngBounds(latLngs);
            const focusBounds = L.latLngBounds([40.8, -74.2], [42.4, -71.0]);
            let targetBounds = bounds;
            if (focusBounds.overlaps(bounds)) {
              const south = Math.max(bounds.getSouth(), focusBounds.getSouth());
              const west = Math.max(bounds.getWest(), focusBounds.getWest());
              const north = Math.min(bounds.getNorth(), focusBounds.getNorth());
              const east = Math.min(bounds.getEast(), focusBounds.getEast());
              if (south <= north && west <= east) {
                targetBounds = L.latLngBounds([south, west], [north, east]);
              }
            }

            if (latLngs.length === 1) {
              map.setView(latLngs[0], 11);
            }
            else if (targetBounds && targetBounds.isValid()) {
              map.fitBounds(targetBounds.pad(0.25));
            }

            const maxCount = positions.reduce((max, coords) => Math.max(max, coords.count || 1), 1);
            const heatPoints = positions.map((coords) => {
              const normalized = (coords.count || 1) / maxCount;
              return [
                coords.lat,
                coords.lon,
                Math.max(0.2, Math.min(1, normalized)),
              ];
            });

            const heatLayer = L.heatLayer(heatPoints, {
              radius: 38,
              blur: 28,
              minOpacity: 0.3,
              gradient: {
                0.0: '#d0f4f7',
                0.3: '#80deea',
                0.5: '#4dd0e1',
                0.7: '#ffb74d',
                1.0: '#f4511e',
              },
            });

            const markers = L.layerGroup();
            positions.forEach((coords) => {
              const weight = Math.max(1, coords.count || 1);
              const marker = L.circleMarker([coords.lat, coords.lon], {
                radius: Math.min(20, 6 + Math.sqrt(weight) * 3),
                color: '#f57c00',
                fillColor: '#ff9800',
                fillOpacity: 0.85,
                weight: 2,
              });
              marker.bindTooltip(Drupal.t('@count member(s)', { '@count': weight }), {
                permanent: false,
                direction: 'top',
              });
              marker.addTo(markers);
            });

            markers.addTo(map);

            const toggleButtons = wrapper.querySelectorAll('[data-map-view]');
            toggleButtons.forEach((button) => {
              button.addEventListener('click', (e) => {
                e.preventDefault();
                const view = button.getAttribute('data-map-view');

                toggleButtons.forEach((btn) => btn.classList.remove('active'));
                button.classList.add('active');

                if (view === 'heatmap') {
                  map.removeLayer(markers);
                  map.addLayer(heatLayer);
                } else {
                  map.removeLayer(heatLayer);
                  map.addLayer(markers);
                }
              });
            });
          })
          .catch((error) => {
            console.error('Error fetching or processing location data:', error);
            renderNotice(mapElement, Drupal.t('Could not load member location data.'), map);
          });
      });
    },
  };

}(Drupal, once, L));
