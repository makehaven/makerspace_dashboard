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

function reviveFunctions<T>(input: T): T {
  if (Array.isArray(input)) {
    return input.map((value) => reviveFunctions(value)) as unknown as T;
  }
  if (input && typeof input === 'object') {
    return Object.entries(input as Record<string, unknown>).reduce<Record<string, unknown>>((acc, [key, value]) => {
      acc[key] = reviveFunctions(value);
      return acc;
    }, {}) as T;
  }
  if (typeof input === 'string') {
    const trimmed = input.trim();
    if (trimmed.startsWith('function') || trimmed.startsWith('(')) {
      try {
        // eslint-disable-next-line no-new-func
        const fn = new Function(`return (${input});`)();
        if (typeof fn === 'function') {
          return fn as unknown as T;
        }
      }
      catch (error) {
        console.warn('Failed to revive chart callback', error); // eslint-disable-line no-console
      }
    }
  }
  return input;
}

function renderChart(visualization: Extract<ChartVisualization, { type: 'chart' }>) {
  const chartType = visualization.chartType?.toLowerCase() ?? 'line';
  const options = reviveFunctions(visualization.options ?? {});
  const data = {
    ...(visualization.data as Record<string, unknown>),
  };

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
