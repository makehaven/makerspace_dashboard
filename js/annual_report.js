(function (Drupal, once) {
  const TARGET_HEADING = 'Visit Frequency Distribution (1 Year)';
  const START_RGB = [139, 25, 25];
  const END_RGB = [209, 213, 219];

  function blendColor(ratio) {
    const clamped = Math.max(0, Math.min(1, ratio || 0));
    const channels = START_RGB.map((start, index) => {
      const end = END_RGB[index];
      return Math.round(start + (end - start) * clamped)
        .toString(16)
        .padStart(2, '0');
    });
    return `#${channels.join('')}`;
  }

  function extractValues(card) {
    const chartEl = card.querySelector('[data-chart]');
    if (!chartEl) {
      return null;
    }
    try {
      const parsed = JSON.parse(chartEl.getAttribute('data-chart'));
      const rows = Array.isArray(parsed?.data) ? parsed.data.slice(1) : [];
      const values = rows.map((row) => Number(row[1]) || 0);
      return values.length ? values : null;
    }
    catch (e) {
      return null;
    }
  }

  function buildPalette(values) {
    const max = Math.max(...values, 0);
    if (!max) {
      return values.map(() => '#d1d5db');
    }
    return values.map((value) => {
      const ratio = max > 0 ? value / max : 0;
      return blendColor(1 - ratio);
    });
  }

  function paintChart(card, colors) {
    const slices = card.querySelectorAll('svg path[stroke="#ffffff"][fill]');
    if (!slices.length) {
      return false;
    }
    slices.forEach((path, index) => {
      if (colors[index]) {
        path.setAttribute('fill', colors[index]);
      }
    });

    const legendDots = card.querySelectorAll('svg circle');
    legendDots.forEach((circle, index) => {
      if (colors[index]) {
        circle.setAttribute('fill', colors[index]);
        circle.setAttribute('stroke', colors[index]);
      }
    });
    return true;
  }

  function paintWithRetry(card, colors, attempt = 0) {
    if (paintChart(card, colors)) {
      return;
    }
    if (attempt < 10) {
      setTimeout(() => paintWithRetry(card, colors, attempt + 1), 250);
    }
  }

  Drupal.behaviors.makerspaceDashboardVisitFrequencyColors = {
    attach(context) {
      once('makerspace-visit-frequency', '.annual-report-card', context).forEach((card) => {
        const heading = card.querySelector('h3');
        if (!heading || heading.textContent.trim() !== TARGET_HEADING) {
          return;
        }

        const values = extractValues(card);
        if (!values) {
          return;
        }

        const colors = buildPalette(values);
        paintWithRetry(card, colors);

        const observer = new MutationObserver(() => {
          paintWithRetry(card, colors);
        });
        observer.observe(card, { childList: true, subtree: true });
      });
    },
  };
})(Drupal, once);
