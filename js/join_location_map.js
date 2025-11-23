/**
 * @file
 * Reusable Leaflet map for dataset filters (quarters, demographics, etc).
 */

(function (Drupal, once, L) {
  'use strict';

  Drupal.behaviors.makerspaceLocationFilterMaps = {
    attach: function (context, settings) {
      if (typeof L === 'undefined') {
        return;
      }
      var configs = (settings && settings.makerspace_dashboard && settings.makerspace_dashboard.location_filter_maps) || {};
      Object.keys(configs).forEach(function (mapId) {
        once('makerspace-filter-map-' + mapId, '[data-join-map-canvas="' + mapId + '"]', context).forEach(function (mapElement) {
          initializeFilterMap(mapElement, configs[mapId], context, mapId);
        });
      });
    },
  };

  function initializeFilterMap(mapElement, mapSettings, context, mapId) {
    if (!mapSettings || !mapSettings.apiUrl) {
      return;
    }

    var wrapper = mapElement.closest('.join-location-map') || context;
    var summary = wrapper ? wrapper.querySelector('[data-join-map-summary="' + mapId + '"]') : null;
    var toggleButton = wrapper ? wrapper.querySelector('[data-join-map-toggle="' + mapId + '"]') : null;
    var buttons = wrapper
      ? Array.prototype.slice.call(wrapper.querySelectorAll('[data-join-map-button="' + mapId + '"]'))
      : [];

    var filterLookup = {};
    (mapSettings.filters || []).forEach(function (filter) {
      filterLookup[filter.value] = filter;
    });

    var defaultView = { coords: [41.3083, -72.9279], zoom: 8 };
    var map = L.map(mapElement).setView(defaultView.coords, defaultView.zoom);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; <a href="https://carto.com/attributions">CARTO</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      subdomains: 'abcd',
    }).addTo(map);

    var datasetCache = {};
    var activeLayers = new Map();
    var pendingRequests = new Map();
    var idleSummary = mapSettings.emptySummary || Drupal.t('Select one or more filters to visualize.');

    function showSummary(markup) {
      if (!summary) {
        return;
      }
      summary.innerHTML = '<p>' + markup + '</p>';
    }
    showSummary(idleSummary);

    function refreshMapBounds() {
      var allLatLngs = [];
      activeLayers.forEach(function (info) {
        allLatLngs = allLatLngs.concat(info.latLngs);
      });
      if (!allLatLngs.length) {
        map.setView(defaultView.coords, defaultView.zoom);
        return;
      }
      map.fitBounds(L.latLngBounds(allLatLngs).pad(0.25));
    }

    function updateSummary() {
      if (!summary) {
        return;
      }
      if (activeLayers.size === 0) {
        showSummary(idleSummary);
        return;
      }
      var lines = [];
      activeLayers.forEach(function (info) {
        lines.push(Drupal.t('@label: @mapped mapped / @total profiles', {
          '@label': info.label,
          '@mapped': info.mapped,
          '@total': info.total,
        }));
      });
      showSummary(lines.join('<br>'));
    }

    function removeLayer(filterValue) {
      var entry = activeLayers.get(filterValue);
      if (!entry) {
        return;
      }
      map.removeLayer(entry.layer);
      activeLayers.delete(filterValue);
      updateSummary();
      refreshMapBounds();
    }

    function addLayer(filterValue) {
      var cacheEntry = datasetCache[filterValue];
      if (!cacheEntry) {
        return;
      }
      var payload = cacheEntry.payload;
      var filter = cacheEntry.filter;
      var positions = (payload.locations || []).map(function (coords) {
        return {
          lat: Number.parseFloat(coords.lat),
          lon: Number.parseFloat(coords.lon),
          count: Number.parseFloat(coords.count || 1),
        };
      }).filter(function (coords) {
        return Number.isFinite(coords.lat) && Number.isFinite(coords.lon);
      });

      if (!positions.length) {
        if (summary && activeLayers.size === 0) {
          showSummary(Drupal.t('No location data was available for that filter.'));
        }
        return;
      }

      var layer = L.layerGroup();
      var latLngs = [];
      positions.forEach(function (coords) {
        latLngs.push([coords.lat, coords.lon]);
        var marker = L.circleMarker([coords.lat, coords.lon], {
          radius: Math.min(20, 6 + Math.sqrt(Math.max(1, coords.count || 1)) * 3),
          color: filter.color || '#ff9800',
          fillColor: filter.color || '#ff9800',
          fillOpacity: 0.9,
          weight: 2,
        });
        marker.bindTooltip(Drupal.t('@count member(s)', { '@count': Math.max(1, coords.count || 1) }), { direction: 'top' });
        marker.addTo(layer);
      });

      map.addLayer(layer);
      activeLayers.set(filterValue, {
        layer: layer,
        latLngs: latLngs,
        label: filter.label || filter.value,
        mapped: payload.mappable_count || positions.length,
        total: payload.total_count || payload.mappable_count || positions.length,
      });
      updateSummary();
      refreshMapBounds();
    }

    function fetchFilter(filterValue, filter, button) {
      var params = filter.params || {};
      var requestKey = filterValue + ':' + Object.values(params).join('|');
      pendingRequests.set(filterValue, requestKey);
      showSummary(Drupal.t('Loading @label data…', { '@label': filter.label || filter.value }));

      var search = new URLSearchParams(params);
      return fetch(mapSettings.apiUrl + '?' + search.toString(), { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error(response.statusText || 'Request failed');
          }
          return response.json();
        })
        .then(function (payload) {
          if (pendingRequests.get(filterValue) !== requestKey) {
            return;
          }
          datasetCache[filterValue] = { payload: payload, filter: filter };
          addLayer(filterValue);
          pendingRequests.delete(filterValue);
        })
        .catch(function (error) {
          console.error('Unable to load location data:', error);
          pendingRequests.delete(filterValue);
          setButtonActive(button, false);
          if (activeLayers.size === 0) {
            showSummary(Drupal.t('Unable to load data for that filter.'));
          }
        });
    }

    function setButtonActive(button, isActive) {
      if (!button) {
        return;
      }
      if (isActive) {
        button.classList.add('active');
        var color = button.getAttribute('data-color');
        if (color) {
          button.style.backgroundColor = color;
          button.style.borderColor = color;
          button.style.color = '#fff';
        }
      }
      else {
        button.classList.remove('active');
        button.style.backgroundColor = '';
        button.style.borderColor = '';
        button.style.color = '';
      }
    }

    function toggleFilter(filterValue, button) {
      if (!filterValue) {
        return;
      }
      if (activeLayers.has(filterValue)) {
        removeLayer(filterValue);
        setButtonActive(button, false);
        return;
      }

      var filter = filterLookup[filterValue] || {
        value: filterValue,
        label: button ? button.textContent.trim() : filterValue,
        color: button ? button.getAttribute('data-color') : '#ff9800',
        params: {},
      };
      setButtonActive(button, true);

      if (datasetCache[filterValue]) {
        addLayer(filterValue);
        return;
      }
      fetchFilter(filterValue, filter, button);
    }

    buttons.forEach(function (button) {
      var filterValue = button.getAttribute('data-filter-value') || button.getAttribute('data-quarter-value');
      var color = button.getAttribute('data-color');
      if (color) {
        button.style.borderColor = color;
      }
      button.addEventListener('click', function (event) {
        event.preventDefault();
        toggleFilter(filterValue, button);
      });
    });

    var defaultFilterValue = mapSettings.defaultFilter ||
      (mapSettings.filters && mapSettings.filters[0] ? mapSettings.filters[0].value : null);
    if (defaultFilterValue) {
      var defaultButton = buttons.find(function (button) {
        var value = button.getAttribute('data-filter-value') || button.getAttribute('data-quarter-value');
        return value === defaultFilterValue;
      });
      toggleFilter(defaultFilterValue, defaultButton || null);
    }

    if (toggleButton) {
      toggleButton.addEventListener('click', function (event) {
        event.preventDefault();
        wrapper.classList.toggle('is-expanded');
        var isExpanded = wrapper.classList.contains('is-expanded');
        toggleButton.textContent = isExpanded
          ? toggleButton.getAttribute('data-expanded-label')
          : toggleButton.getAttribute('data-collapsed-label');
        setTimeout(function () {
          map.invalidateSize();
          refreshMapBounds();
        }, 200);
      });
    }
  }

}(Drupal, once, L));
