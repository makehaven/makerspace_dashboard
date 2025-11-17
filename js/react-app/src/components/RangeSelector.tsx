import { useMemo } from 'react';
import type { RangeConfig } from '../types';

interface RangeSelectorProps {
  options: RangeConfig['options'];
  activeRange: string | null;
  pendingRange: string | null;
  onSelect(rangeKey: string): void;
  ariaLabel: string;
  controlId: string;
}

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

export const RangeSelector = ({ options, activeRange, pendingRange, onSelect, ariaLabel, controlId }: RangeSelectorProps) => {
  const entries = useMemo(() => Object.entries(options ?? {}), [options]);
  if (entries.length === 0) {
    return null;
  }

  const currentValue = pendingRange ?? activeRange ?? entries[0][0] ?? '';
  const legendId = `${controlId}-legend`;

  return (
    <fieldset className={`makerspace-dashboard-range-selector${pendingRange ? ' is-pending' : ''}`} aria-label={ariaLabel} aria-busy={pendingRange ? 'true' : undefined}>
      <legend id={legendId}>{translate('Date range')}</legend>
      <div className="makerspace-dashboard-range-selector__pill-group" role="radiogroup" aria-labelledby={legendId}>
        {entries.map(([key, config]) => {
          const isActive = currentValue === key;
          const isPending = pendingRange === key;
          const optionClasses = [
            'makerspace-dashboard-range-selector__pill',
            isActive ? 'is-active' : '',
            isPending ? 'is-loading' : '',
          ]
            .filter(Boolean)
            .join(' ');
          return (
            <label key={key} className={optionClasses}>
              <input
                type="radio"
                name={controlId}
                value={key}
                checked={isActive}
                onChange={() => onSelect(key)}
                disabled={Boolean(pendingRange)}
              />
              <span className="makerspace-dashboard-range-selector__pill-label">{config.label}</span>
            </label>
          );
        })}
      </div>
      {pendingRange && (
        <div className="makerspace-dashboard-range-selector__pending">
          <span aria-hidden="true" className="makerspace-dashboard-range-selector__spinner" />
          <span>{translate('Updating data…')}</span>
        </div>
      )}
    </fieldset>
  );
};
