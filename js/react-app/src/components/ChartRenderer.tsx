import { memo } from 'react';
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Bar, Line, Pie } from 'react-chartjs-2';
import type { ChartVisualization } from '../types';

ChartJS.register(ArcElement, BarElement, CategoryScale, Filler, Legend, LineElement, LinearScale, PointElement, Tooltip);

interface ChartRendererProps {
  visualization: ChartVisualization;
}

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

type NumericFormat = 'integer' | 'decimal' | 'currency' | 'percent';

interface BaseFormatOptions {
  format?: NumericFormat;
  decimals?: number;
  prefix?: string;
  suffix?: string;
  currency?: string;
  showLabel?: boolean;
}

interface SeriesFormatOptions extends BaseFormatOptions {
  perAxis?: Record<string, BaseFormatOptions>;
  perDataset?: Record<string, BaseFormatOptions>;
}

type CallbackFactory = (options: Record<string, unknown>) => (...args: unknown[]) => unknown;

function ensureNumber(value: unknown): number | null {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return null;
  }
  return Number(value);
}

function extractContextValue(context: any): number | null {
  if (context?.raw !== undefined) {
    return ensureNumber(context.raw);
  }
  if (context?.value !== undefined) {
    return ensureNumber(context.value);
  }
  if (context?.parsed !== undefined && typeof context.parsed !== 'object') {
    return ensureNumber(context.parsed);
  }
  if (context?.parsed?.x !== undefined) {
    return ensureNumber(context.parsed.x);
  }
  if (context?.parsed?.y !== undefined) {
    return ensureNumber(context.parsed.y);
  }
  return null;
}

function formatNumeric(value: number, options: BaseFormatOptions): string {
  const decimals = typeof options.decimals === 'number' ? options.decimals : undefined;
  switch (options.format) {
    case 'currency': {
      const currency = typeof options.currency === 'string' ? options.currency : 'USD';
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        minimumFractionDigits: decimals ?? 0,
        maximumFractionDigits: decimals ?? 0,
      }).format(value);
    }
    case 'decimal':
      return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: decimals ?? 1,
        maximumFractionDigits: decimals ?? 1,
      }).format(value);
    case 'percent':
      return `${value.toFixed(decimals ?? 0)}%`;
    case 'integer':
    default:
      return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: decimals ?? 0,
      }).format(value);
  }
}

function buildValueString(value: number, options: BaseFormatOptions): string {
  const prefix = typeof options.prefix === 'string' ? options.prefix : '';
  const suffix = typeof options.suffix === 'string' && options.suffix !== '' ? ` ${options.suffix}` : '';
  return `${prefix}${formatNumeric(value, options)}${suffix}`.trim();
}

function resolveSeriesOptions(options: SeriesFormatOptions, context: any): BaseFormatOptions {
  const resolved: SeriesFormatOptions = { ...options };
  const axisId = context?.dataset?.yAxisID;
  if (axisId && resolved.perAxis?.[axisId]) {
    Object.assign(resolved, resolved.perAxis[axisId]);
  }
  const datasetIndex = typeof context?.datasetIndex === 'number' ? String(context.datasetIndex) : null;
  if (datasetIndex && resolved.perDataset?.[datasetIndex]) {
    Object.assign(resolved, resolved.perDataset[datasetIndex]);
  }
  return resolved;
}

const callbackFactories: Record<string, CallbackFactory> = {
  series_value: (optionsInput) => {
    const baseOptions = optionsInput as SeriesFormatOptions;
    return (context: any) => {
      const value = extractContextValue(context);
      if (value === null) {
        return '';
      }
      const options = resolveSeriesOptions(baseOptions, context);
      const showLabel = options.showLabel !== false;
      const label = showLabel ? context?.dataset?.label : '';
      const formatted = buildValueString(value, options);
      return label ? `${label}: ${formatted}` : formatted;
    };
  },
  value_format: (optionsInput) => {
    const options = optionsInput as BaseFormatOptions;
    return (value: unknown) => {
      const numeric = ensureNumber(value);
      if (numeric === null) {
        return '';
      }
      return buildValueString(numeric, options);
    };
  },
  dataset_share_percent: (optionsInput) => {
    const options = optionsInput as BaseFormatOptions;
    const decimals = typeof options.decimals === 'number' ? options.decimals : 1;
    const suffix = typeof options.suffix === 'string' ? options.suffix : '%';
    return (value: unknown, ctx: any) => {
      const dataset = ctx?.chart?.data?.datasets?.[ctx?.datasetIndex ?? 0];
      const data = Array.isArray(dataset?.data) ? dataset.data : [];
      const total = data.reduce((acc, current) => acc + (ensureNumber(current) ?? 0), 0);
      if (!total) {
        return `0${suffix}`;
      }
      const numeric = ensureNumber(value) ?? 0;
      const pct = (numeric / total) * 100;
      return `${pct.toFixed(decimals)}${suffix}`;
    };
  },
  tooltip_after_body_cohort: () => (items: any[]) => {
    if (!Array.isArray(items) || items.length === 0) {
      return [];
    }
    const index = items[0]?.dataIndex ?? 0;
    const datasets = items[0]?.chart?.data?.datasets ?? [];
    const active = ensureNumber(datasets[0]?.data?.[index]) ?? 0;
    const inactive = ensureNumber(datasets[1]?.data?.[index]) ?? 0;
    const total = active + inactive;
    return [
      `${translate('Total')}: ${formatNumeric(total, { format: 'integer' })}`,
      `${translate('Active')}: ${formatNumeric(active, { format: 'integer' })}`,
      `${translate('Inactive')}: ${formatNumeric(inactive, { format: 'integer' })}`,
    ];
  },
};

function hydrateLegacyFunction(source: string): (() => unknown) | null {
  const trimmed = source.trim();
  if (!trimmed.startsWith('function') && !trimmed.startsWith('(')) {
    return null;
  }
  try {
    // eslint-disable-next-line no-new-func
    const fn = new Function(`return (${source});`)();
    return typeof fn === 'function' ? fn : null;
  }
  catch (error) {
    console.warn('Failed to revive legacy chart callback', error); // eslint-disable-line no-console
  }
  return null;
}

function hydrateCallbacks<T>(input: T): T {
  if (Array.isArray(input)) {
    return input.map((value) => hydrateCallbacks(value)) as unknown as T;
  }
  if (input && typeof input === 'object') {
    if ('__callback' in (input as Record<string, unknown>)) {
      const definition = input as { __callback?: string; options?: Record<string, unknown> };
      const factory = definition.__callback ? callbackFactories[definition.__callback] : undefined;
      if (factory) {
        return factory(definition.options ?? {}) as unknown as T;
      }
      return input;
    }
    const entries = Object.entries(input as Record<string, unknown>);
    return entries.reduce<Record<string, unknown>>((acc, [key, value]) => {
      acc[key] = hydrateCallbacks(value);
      return acc;
    }, {}) as T;
  }
  if (typeof input === 'string') {
    const revived = hydrateLegacyFunction(input);
    if (revived) {
      return revived as unknown as T;
    }
  }
  return input;
}

function renderChart(visualization: Extract<ChartVisualization, { type: 'chart' }>) {
  const chartType = visualization.chartType?.toLowerCase() ?? 'line';
  const options = hydrateCallbacks(visualization.options ?? {});
  const data = hydrateCallbacks(visualization.data as Record<string, unknown>);

  switch (chartType) {
    case 'bar':
      return <Bar data={data} options={options} />;
    case 'pie':
    case 'doughnut':
      return <Pie data={data} options={options} />;
    default:
      return <Line data={data} options={options} />;
  }
}

function getContainerClassNames(attributes?: Record<string, unknown>): string {
  const classes = ['makerspace-dashboard-react-chart__children'];
  const classList = Array.isArray(attributes?.class)
    ? (attributes?.class as unknown[])
    : typeof attributes?.class === 'string'
      ? (attributes?.class as string).split(' ')
      : [];
  if (classList.includes('pie-chart-pair-container')) {
    classes.push('makerspace-dashboard-react-chart__children--two-up');
  }
  return classes.join(' ');
}

const ChartRendererComponent = ({ visualization }: ChartRendererProps) => {
  if (visualization.type === 'chart' && visualization.library === 'chartjs') {
    if (!visualization.data) {
      return <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">{translate('No data available.')}</div>;
    }
    return (
      <div className="makerspace-dashboard-react-chart__canvas">
        {renderChart(visualization)}
      </div>
    );
  }

  if (visualization.type === 'table') {
    if (!visualization.rows?.length) {
      return <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">{visualization.empty ?? translate('No data available.')}</div>;
    }
    return (
      <table className="makerspace-dashboard-react-chart__table">
        <thead>
          <tr>
            {visualization.header.map((cell) => (
              <th key={cell}>{cell}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {visualization.rows.map((row, index) => (
            <tr key={`${row[0]}-${index}`}>
              {row.map((cell, cellIndex) => (
                <td key={`${index}-${cellIndex}`}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    );
  }

  if (visualization.type === 'markup') {
    return <div dangerouslySetInnerHTML={{ __html: visualization.markup }} />;
  }

  if (visualization.type === 'container') {
    const childrenEntries = Object.entries(visualization.children ?? {});
    if (!childrenEntries.length) {
      return null;
    }
    return (
      <div className={getContainerClassNames(visualization.attributes)}>
        {childrenEntries.map(([key, child]) => (
          <ChartRendererComponent key={key} visualization={child} />
        ))}
      </div>
    );
  }

  return <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">{translate('Unsupported chart type.')}</div>;
};

export const ChartRenderer = memo(ChartRendererComponent);
