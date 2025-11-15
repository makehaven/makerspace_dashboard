import { useCallback, useEffect, useMemo, useState } from 'react';
import { useChartData } from '../hooks/useChartData';
import type { PlaceholderConfig } from '../types';
import { ChartRenderer } from './ChartRenderer';
import { RangeSelector } from './RangeSelector';

interface DashboardChartProps extends PlaceholderConfig {
  reactId: string;
}

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

export const DashboardChart = ({ sectionId, chartId, ranges, reactId }: DashboardChartProps) => {
  const defaultRange = ranges?.active ?? null;
  const [selectedRange, setSelectedRange] = useState<string | null>(defaultRange);
  const requestRange = selectedRange ?? defaultRange ?? null;
  const { data, loading, error } = useChartData({ sectionId, chartId, range: requestRange });

  useEffect(() => {
    if (selectedRange || !data?.range?.active) {
      return;
    }
    setSelectedRange(data.range.active);
  }, [data?.range?.active, selectedRange]);

  const rangeOptions = useMemo(() => data?.range?.options ?? ranges?.options ?? {}, [data?.range?.options, ranges?.options]);
  const hasRangeControls = Object.keys(rangeOptions).length > 0;
  const lastServerRange = data?.range?.active ?? defaultRange;
  const pendingRange = loading && selectedRange && selectedRange !== lastServerRange ? selectedRange : null;

  const handleRangeSelect = useCallback(
    (key: string) => {
      if (key === selectedRange) {
        return;
      }
      setSelectedRange(key);
    },
    [selectedRange],
  );

  return (
    <div className="makerspace-dashboard-react-chart__inner" data-react-id={reactId}>
      {hasRangeControls && (
        <RangeSelector
          options={rangeOptions}
          activeRange={selectedRange ?? defaultRange}
          pendingRange={pendingRange}
          ariaLabel={translate('Select time range')}
          onSelect={handleRangeSelect}
          controlId={`${reactId}-range`}
        />
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
