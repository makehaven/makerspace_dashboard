import { useEffect, useState } from 'react';
import type { ChartDefinition } from '../types';

interface UseChartDataArgs {
  sectionId: string;
  chartId: string;
  range?: string | null;
}

interface ChartDataState {
  data: ChartDefinition | null;
  loading: boolean;
  error: Error | null;
}

export function useChartData({ sectionId, chartId, range }: UseChartDataArgs): ChartDataState {
  const [state, setState] = useState<ChartDataState>({ data: null, loading: true, error: null });

  useEffect(() => {
    const controller = new AbortController();
    setState((prev) => ({ ...prev, loading: true, error: null }));

    const baseUrl = Drupal.url(`makerspace-dashboard/api/chart/${sectionId}/${chartId}`);
    const url = new URL(baseUrl, window.location.origin);
    if (range) {
      url.searchParams.set('range', range);
    }
    url.searchParams.set('_cb', Date.now().toString());

    fetch(url.toString(), { signal: controller.signal, headers: { Accept: 'application/json' } })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`Unable to load chart (HTTP ${response.status})`);
        }
        return response.json();
      })
      .then((payload: ChartDefinition) => {
        if (!controller.signal.aborted) {
          setState({ data: payload, loading: false, error: null });
        }
      })
      .catch((error: Error) => {
        if (controller.signal.aborted) {
          return;
        }
        setState({ data: null, loading: false, error });
      });

    return () => controller.abort();
  }, [sectionId, chartId, range]);

  return state;
}
