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
      const defaultLocationsUrl = dashboardSettings.locations_url || null;
      once('makerspace-dashboard-location-map', '.makerspace-dashboard-location-map', context).forEach((mapElement) => {
        const wrapper = mapElement.closest('.makerspace-dashboard-location-map-wrapper');
        const locationsUrl = mapElement.getAttribute('data-locations-url') || defaultLocationsUrl;
        if (!locationsUrl) {
          renderNotice(mapElement, Drupal.t('Member location data is not available.'));
          return;
        }

        // Centered on New Haven, CT, or use data attributes
        const initLat = parseFloat(mapElement.getAttribute('data-lat')) || 41.3083;
        const initLon = parseFloat(mapElement.getAttribute('data-lon')) || -72.9279;
        const initZoom = parseInt(mapElement.getAttribute('data-zoom'), 10) || 11;
        const shouldFitBounds = mapElement.getAttribute('data-fit-bounds') !== 'false';

        const map = L.map(mapElement).setView([initLat, initLon], initZoom);

        // Fix for maps inside hidden details/tabs
        const details = mapElement.closest('details');
        if (details) {
          details.addEventListener('toggle', () => {
            if (details.open) {
              setTimeout(() => {
                map.invalidateSize();
              }, 200);
            }
          });
        }

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

            if (shouldFitBounds) {
              if (latLngs.length === 1) {
                map.setView(latLngs[0], 11);
              }
              else if (targetBounds && targetBounds.isValid()) {
                map.fitBounds(targetBounds.pad(0.25), {maxZoom: 16});
              }
            }

            const maxCount = positions.reduce((max, coords) => Math.max(max, coords.count || 1), 1);
            console.log('Building heatmap with', positions.length, 'points. Max intensity:', maxCount);

            const heatPoints = positions.map((coords) => {
              const normalized = (coords.count || 1) / maxCount;
              return [
                coords.lat,
                coords.lon,
                Math.max(0.2, Math.min(1, normalized)),
              ];
            });

            let heatLayer = null;
            if (typeof L.heatLayer === 'function') {
              heatLayer = L.heatLayer(heatPoints, {
                radius: 35,
                blur: 25,
                minOpacity: 0.4,
                maxZoom: 12,
                gradient: {
                  0.2: 'blue',
                  0.4: 'cyan',
                  0.6: 'lime',
                  0.8: 'yellow',
                  1.0: 'red'
                },
              });
              console.log('HeatLayer created successfully.');
            } else {
              console.warn('Leaflet.heat not loaded. Falling back to markers.');
            }

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

            // Check for initial view preference
            const markersBtn = wrapper.querySelector('[data-map-view="markers"]');
            const heatmapBtn = wrapper.querySelector('[data-map-view="heatmap"]');

            const showMarkers = () => {
              if (heatLayer) {
                map.removeLayer(heatLayer);
              }
              map.addLayer(markers);
              if (markersBtn) markersBtn.classList.add('active');
              if (heatmapBtn) heatmapBtn.classList.remove('active');
            };

            const showHeatmap = () => {
              if (!heatLayer) {
                return false;
              }
              map.removeLayer(markers);
              map.addLayer(heatLayer);
              if (heatmapBtn) heatmapBtn.classList.add('active');
              if (markersBtn) markersBtn.classList.remove('active');
              return true;
            };

            const hasPositiveSize = () => {
              const rect = mapElement.getBoundingClientRect();
              return rect.width > 0 && rect.height > 0;
            };

            const activateHeatmapWhenReady = (attempt = 0) => {
              if (!heatLayer) {
                showMarkers();
                return;
              }
              if (hasPositiveSize()) {
                if (!showHeatmap()) {
                  showMarkers();
                }
                return;
              }
              if (attempt >= 10) {
                console.warn('Heatmap could not determine map size, falling back to markers.');
                showMarkers();
                return;
              }
              setTimeout(() => activateHeatmapWhenReady(attempt + 1), 150);
            };

            let initialView = mapElement.getAttribute('data-initial-view') || 'markers';
            initialView = initialView.trim().toLowerCase();

            if (initialView === 'heatmap' && !heatLayer) {
              initialView = 'markers';
            }

            if (initialView === 'heatmap') {
              activateHeatmapWhenReady();
            }
            else {
              showMarkers();
            }

            const toggleButtons = wrapper.querySelectorAll('[data-map-view]');
            toggleButtons.forEach((button) => {
              button.addEventListener('click', (e) => {
                e.preventDefault();
                const view = (button.getAttribute('data-map-view') || '').trim().toLowerCase();

                if (view === 'heatmap' && !heatLayer) {
                  alert(Drupal.t('Heatmap library not loaded.'));
                  return;
                }

                if (view === 'heatmap') {
                  activateHeatmapWhenReady();
                }
                else {
                  showMarkers();
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
