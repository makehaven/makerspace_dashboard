import { describe, expect, it } from 'vitest';
import { chartTable } from './ChartDataTable';

describe('Chart data table', () => {
  it('preserves zero and missing values in the same labeled series as CSV', () => {
    expect(chartTable({ type: 'chart', library: 'chartjs', chartType: 'bar', data: {
      labels: ['January', 'February'], datasets: [{ label: 'Members', data: [0, null] }, { label: 'Visits', data: [2, 4] }],
    } })).toEqual({ header: ['Label', 'Members', 'Visits'], rows: [['January', '0', '2'], ['February', '', '4']] });
  });

  it('does not misrepresent structured scatter points as scalar rows', () => {
    expect(chartTable({ type: 'chart', library: 'chartjs', chartType: 'scatter', data: {
      labels: ['January'], datasets: [{ data: [{ x: 1, y: 2 }] }],
    } })).toBeNull();
  });
});
