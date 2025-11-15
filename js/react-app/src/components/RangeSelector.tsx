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
  const selectId = `${controlId}-select`;
  const legendId = `${controlId}-legend`;

  return (
    <fieldset className={`makerspace-dashboard-range-selector${pendingRange ? ' is-pending' : ''}`} aria-label={ariaLabel} aria-busy={pendingRange ? 'true' : undefined}>
      <legend id={legendId}>{translate('Date range')}</legend>
      <div className="makerspace-dashboard-range-selector__options" role="radiogroup" aria-labelledby={legendId}>
        {entries.map(([key, config]) => {
          const isPending = pendingRange === key;
          return (
            <label key={key} className="makerspace-dashboard-range-selector__option">
              <input
                type="radio"
                name={controlId}
                value={key}
                checked={currentValue === key}
                onChange={() => onSelect(key)}
                disabled={isPending}
              />
              <span className="makerspace-dashboard-range-selector__label">
                <span className="makerspace-dashboard-range-selector__title">{config.label}</span>
                <span className="makerspace-dashboard-range-selector__hint">{key.toUpperCase()}</span>
              </span>
            </label>
          );
        })}
      </div>
      <div className="makerspace-dashboard-range-selector__dropdown">
        <label htmlFor={selectId}>{translate('Jump to range')}</label>
        <div className="makerspace-dashboard-range-selector__select-wrapper">
          <select id={selectId} value={currentValue} onChange={(event) => onSelect(event.target.value)} disabled={Boolean(pendingRange)}>
            {entries.map(([key, config]) => (
              <option key={key} value={key}>
                {config.label}
              </option>
            ))}
          </select>
          {pendingRange && <span className="makerspace-dashboard-range-selector__spinner" aria-hidden="true" />}
        </div>
      </div>
    </fieldset>
  );
};
