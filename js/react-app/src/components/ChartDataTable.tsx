import type { ChartVisualization } from '../types';

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

/** The same labeled scalar series exported by the CSV controller. */
export function chartTable(visualization: ChartVisualization): { header: string[]; rows: string[][] } | null {
  if (visualization.type !== 'chart') return null;
  const { labels, datasets } = visualization.data;
  if (!Array.isArray(labels) || !Array.isArray(datasets) || !labels.length || !datasets.length) return null;
  const scalar = (value: unknown) => value == null || ['string', 'number', 'boolean'].includes(typeof value);
  if (!labels.every(scalar) || !datasets.every((series) => Array.isArray(series.data) && series.data.every(scalar))) return null;
  return {
    header: ['Label', ...datasets.map((series) => String(series.label ?? 'Series'))],
    rows: labels.map((label, index) => [String(label ?? ''), ...datasets.map((series) => String(series.data[index] ?? ''))]),
  };
}

export function ChartDataTable({ visualization, title }: { visualization: ChartVisualization; title: string }) {
  const table = chartTable(visualization);
  if (!table) return null;
  return (
    <details className="makerspace-dashboard-data-table">
      <summary>{translate('View data table')}</summary>
      <div className="makerspace-dashboard-data-table__scroll" role="region" aria-label={title} tabIndex={0}>
        <table>
          <caption>{title}</caption>
          <thead><tr>{table.header.map((label, index) => <th key={index} scope="col">{label}</th>)}</tr></thead>
          <tbody>{table.rows.map((row, index) => <tr key={index}>{row.map((value, column) => column === 0
            ? <th key={column} scope="row">{value}</th>
            : <td key={column}>{value}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </details>
  );
}
