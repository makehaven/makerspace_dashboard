import { useEffect, useMemo, useState } from 'react';
import { useChartData } from '../hooks/useChartData';
import type { PlaceholderConfig } from '../types';
import { ChartRenderer } from './ChartRenderer';

interface DashboardChartProps extends PlaceholderConfig {
  reactId: string;
}

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

export const DashboardChart = ({ sectionId, chartId, ranges, reactId }: DashboardChartProps) => {
  const [activeRange, setActiveRange] = useState<string | null>(ranges?.active ?? null);
  const { data, loading, error } = useChartData({ sectionId, chartId, range: activeRange });

  useEffect(() => {
    if (data?.range && data.range.active !== activeRange) {
      setActiveRange(data.range.active ?? null);
    }
  }, [data?.range?.active, activeRange]);

  const rangeOptions = useMemo(() => data?.range?.options ?? ranges?.options ?? {}, [data?.range?.options, ranges?.options]);
  const hasRangeControls = Object.keys(rangeOptions).length > 0;

  return (
    <div className="makerspace-dashboard-react-chart__inner" data-react-id={reactId}>
      {hasRangeControls && (
        <div className="makerspace-dashboard-react-chart__ranges" role="toolbar" aria-label={translate('Select time range')}>
          {Object.entries(rangeOptions).map(([key, config]) => (
            <button
              key={key}
              type="button"
              className={`makerspace-dashboard-react-chart__btn${activeRange === key ? ' makerspace-dashboard-react-chart__btn--active' : ''}`}
              disabled={loading && activeRange === key}
              aria-pressed={activeRange === key}
              onClick={() => setActiveRange(key)}
            >
              {config.label}
            </button>
          ))}
        </div>
      )}

      {loading && !data && (
        <div className="makerspace-dashboard-react-chart__status">{translate('Loading chart…')}</div>
      )}

      {error && (
        <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--error">
          {error.message}
        </div>
      )}

      {!error && data && <ChartRenderer visualization={data.visualization} />}
    </div>
  );
};
