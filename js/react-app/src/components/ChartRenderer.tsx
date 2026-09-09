import { memo, useMemo } from 'react';
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
import type { ChartData, ChartOptions } from 'chart.js';
import { FunnelChart } from './FunnelChart';

ChartJS.register(ArcElement, BarElement, CategoryScale, Filler, Legend, LineElement, LinearScale, PointElement, Tooltip);

interface ChartRendererProps {
  visualization: ChartVisualization;
}

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

export type NumericFormat = 'integer' | 'decimal' | 'currency' | 'percent';

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

type CallbackFactory = (options: Record<string, unknown>) => (...args: never[]) => unknown;
type UnknownRecord = Record<string, unknown>;

function asRecord(value: unknown): UnknownRecord {
  return value !== null && typeof value === 'object' ? value as UnknownRecord : {};
}

export function ensureNumber(value: unknown): number | null {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return null;
  }
  return Number(value);
}

function extractContextValue(contextInput: unknown): number | null {
  const context = asRecord(contextInput);
  if (context.raw !== undefined) {
    return ensureNumber(context.raw);
  }
  if (context?.value !== undefined) {
    return ensureNumber(context.value);
  }
  if (context?.parsed !== undefined && typeof context.parsed !== 'object') {
    return ensureNumber(context.parsed);
  }
  const parsed = asRecord(context.parsed);
  if (parsed.x !== undefined) {
    return ensureNumber(parsed.x);
  }
  if (parsed.y !== undefined) {
    return ensureNumber(parsed.y);
  }
  return null;
}

export function formatNumeric(value: number, options: BaseFormatOptions): string {
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

function resolveSeriesOptions(options: SeriesFormatOptions, contextInput: unknown): BaseFormatOptions {
  const context = asRecord(contextInput);
  const dataset = asRecord(context.dataset);
  const resolved: SeriesFormatOptions = { ...options };
  const axisId = typeof dataset.yAxisID === 'string' ? dataset.yAxisID : '';
  if (axisId && resolved.perAxis?.[axisId]) {
    Object.assign(resolved, resolved.perAxis[axisId]);
  }
  const datasetIndex = typeof context.datasetIndex === 'number' ? String(context.datasetIndex) : null;
  if (datasetIndex && resolved.perDataset?.[datasetIndex]) {
    Object.assign(resolved, resolved.perDataset[datasetIndex]);
  }
  return resolved;
}

const datasetMembersCountFactory = () => (contextInput: unknown) => {
  const context = asRecord(contextInput);
  const dataset = asRecord(context.dataset);
  const datasetCounts = Array.isArray(dataset.makerspaceCounts)
    ? dataset.makerspaceCounts
    : [];
  const index = typeof context.dataIndex === 'number' ? context.dataIndex : 0;
  const members = ensureNumber(datasetCounts[index]);
  if (members === null) {
    return '';
  }
  return `${translate('Members')}: ${formatNumeric(members, { format: 'integer' })}`;
};

const callbackFactories: Record<string, CallbackFactory> = {
  series_value: (optionsInput) => {
    const baseOptions = optionsInput as SeriesFormatOptions;
    return (contextInput: unknown) => {
      const context = asRecord(contextInput);
      const dataset = asRecord(context.dataset);
      const value = extractContextValue(context);
      if (value === null) {
        return '';
      }
      const options = resolveSeriesOptions(baseOptions, context);
      const showLabel = options.showLabel !== false;
      const label = showLabel && typeof dataset.label === 'string' ? dataset.label : '';
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
    return (value: unknown, contextInput: unknown) => {
      const context = asRecord(contextInput);
      const chart = asRecord(context.chart);
      const chartData = asRecord(chart.data);
      const datasets = Array.isArray(chartData.datasets) ? chartData.datasets : [];
      const datasetIndex = typeof context.datasetIndex === 'number' ? context.datasetIndex : 0;
      const dataset = asRecord(datasets[datasetIndex]);
      const data = Array.isArray(dataset.data) ? dataset.data : [];
      const total = data.reduce<number>((acc, current) => acc + (ensureNumber(current) ?? 0), 0);
      if (!total) {
        return `0${suffix}`;
      }
      const numeric = ensureNumber(value) ?? 0;
      const pct = (numeric / total) * 100;
      return `${pct.toFixed(decimals)}${suffix}`;
    };
  },
  tooltip_after_body_cohort: () => (itemsInput: unknown) => {
    const items = Array.isArray(itemsInput) ? itemsInput.map(asRecord) : [];
    if (!Array.isArray(items) || items.length === 0) {
      return [];
    }
    const index = typeof items[0].dataIndex === 'number' ? items[0].dataIndex : 0;
    const chart = asRecord(items[0].chart);
    const chartData = asRecord(chart.data);
    const datasets = Array.isArray(chartData.datasets) ? chartData.datasets.map(asRecord) : [];
    const activeData = Array.isArray(datasets[0]?.data) ? datasets[0].data : [];
    const inactiveData = Array.isArray(datasets[1]?.data) ? datasets[1].data : [];
    const active = ensureNumber(activeData[index]) ?? 0;
    const inactive = ensureNumber(inactiveData[index]) ?? 0;
    const total = active + inactive;
    return [
      `${translate('Total')}: ${formatNumeric(total, { format: 'integer' })}`,
      `${translate('Active')}: ${formatNumeric(active, { format: 'integer' })}`,
      `${translate('Inactive')}: ${formatNumeric(inactive, { format: 'integer' })}`,
    ];
  },
  dataset_members_count: datasetMembersCountFactory,
  payment_mix_members_count: datasetMembersCountFactory,
  dataset_total_members: () => (itemsInput: unknown) => {
    const items = Array.isArray(itemsInput) ? itemsInput.map(asRecord) : [];
    if (!Array.isArray(items) || items.length === 0) {
      return '';
    }
    const index = typeof items[0]?.dataIndex === 'number' ? items[0].dataIndex : null;
    const datasetIndex = typeof items[0]?.datasetIndex === 'number' ? items[0].datasetIndex : null;
    const chart = asRecord(items[0].chart);
    const chartData = asRecord(chart.data);
    const datasets = chartData.datasets;
    if (index === null || datasetIndex === null || !Array.isArray(datasets)) {
      return '';
    }
    const dataset = asRecord(datasets[datasetIndex]);
    const memberTotals = Array.isArray(dataset.makerspaceMembers)
      ? dataset.makerspaceMembers
      : [];
    const totalMembers = ensureNumber(memberTotals[index]);
    if (totalMembers === null) {
      return '';
    }
    return `${translate('Total members in range')}: ${formatNumeric(totalMembers, { format: 'integer' })}`;
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

export function hydrateCallbacks<T>(input: T): T {
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

function renderChart(
  chartTypeInput: string | undefined,
  data: Record<string, unknown>,
  options: Record<string, unknown>,
) {
  const chartType = chartTypeInput?.toLowerCase() ?? 'line';

  switch (chartType) {
    case 'bar':
      return <Bar data={data as unknown as ChartData<'bar'>} options={options as ChartOptions<'bar'>} />;
    case 'pie':
    case 'doughnut':
      return <Pie data={data as unknown as ChartData<'pie'>} options={options as ChartOptions<'pie'>} />;
    default:
      return <Line data={data as unknown as ChartData<'line'>} options={options as ChartOptions<'line'>} />;
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
  const chartConfig = useMemo(() => {
    if (visualization.type === 'chart' && visualization.library === 'chartjs' && visualization.data) {
      return {
        data: hydrateCallbacks(visualization.data as Record<string, unknown>),
        options: hydrateCallbacks(visualization.options ?? {}),
      };
    }
    return null;
  }, [visualization]);

  if (visualization.type === 'chart' && visualization.library === 'chartjs') {
    if (!visualization.data) {
      return <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">{translate('No data available.')}</div>;
    }
    return (
      <div className="makerspace-dashboard-react-chart__canvas">
        {chartConfig && renderChart(visualization.chartType, chartConfig.data, chartConfig.options)}
      </div>
    );
  }

  if (visualization.type === 'funnel') {
    if (!visualization.stages?.length) {
      return <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">{translate('No data available.')}</div>;
    }
    return (
      <div className="makerspace-dashboard-react-chart__canvas makerspace-dashboard-react-chart__canvas--funnel">
        <FunnelChart stages={visualization.stages} options={visualization.options} />
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
