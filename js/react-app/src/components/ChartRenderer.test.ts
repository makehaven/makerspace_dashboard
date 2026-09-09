import { describe, expect, it } from 'vitest';
import { ensureNumber, formatNumeric, hydrateCallbacks } from './ChartRenderer';

describe('ChartRenderer data contract', () => {
  it('normalizes numeric values without accepting missing data', () => {
    expect(ensureNumber('12.5')).toBe(12.5);
    expect(ensureNumber(0)).toBe(0);
    expect(ensureNumber(null)).toBeNull();
    expect(ensureNumber('not-a-number')).toBeNull();
  });

  it('formats currencies and percentages consistently', () => {
    expect(formatNumeric(1234.5, {
      format: 'currency',
      currency: 'USD',
      decimals: 2,
    })).toMatch(/1,234\.50/);
    expect(formatNumeric(87.25, { format: 'percent', decimals: 1 })).toBe('87.3%');
  });

  it('hydrates serialized callback definitions recursively', () => {
    const hydrated = hydrateCallbacks({
      plugins: {
        tooltip: {
          callbacks: {
            label: {
              __callback: 'series_value',
              options: { format: 'integer' },
            },
          },
        },
      },
    });

    const label = hydrated.plugins.tooltip.callbacks.label as unknown as (context: unknown) => string;
    expect(label({ raw: 42, dataset: { label: 'Members' } })).toBe('Members: 42');
  });
});
