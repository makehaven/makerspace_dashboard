type NumericFormat = 'integer' | 'decimal' | 'currency' | 'percent';

const defaultPalette = ['#1d4ed8', '#0f766e', '#f97316', '#7c3aed', '#0891b2', '#ca8a04'];

const formatNumeric = (value: number, format: NumericFormat = 'integer', decimals?: number) => {
  switch (format) {
    case 'currency':
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: decimals ?? 0,
        maximumFractionDigits: decimals ?? 0,
      }).format(value);
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
};

const translate = (text: string) => (typeof Drupal?.t === 'function' ? Drupal.t(text) : text);

export interface FunnelStage {
  label: string;
  value: number;
  helper?: string;
}

export interface FunnelOptions {
  showValues?: boolean;
  format?: NumericFormat;
  decimals?: number;
}

interface FunnelChartProps {
  stages: FunnelStage[];
  options?: FunnelOptions;
}

export const FunnelChart = ({ stages, options }: FunnelChartProps) => {
  const sanitizedStages = stages.map((stage) => ({
    ...stage,
    value: typeof stage.value === 'number' ? stage.value : Number(stage.value) || 0,
  }));
  const maxValue = sanitizedStages.reduce((acc, stage) => Math.max(acc, stage.value), 0);
  if (!maxValue) {
    return (
      <div className="makerspace-dashboard-react-chart__status makerspace-dashboard-react-chart__status--empty">
        {translate('No data available.')}
      </div>
    );
  }

  const minWidth = 8;

  return (
    <div className="makerspace-funnel">
      {sanitizedStages.map((stage, index) => {
        const widthPercent = Math.max((stage.value / maxValue) * 100, minWidth);
        const color = defaultPalette[index % defaultPalette.length];
        return (
          <div key={`${stage.label}-${index}`} className="makerspace-funnel__stage">
            <div
              className="makerspace-funnel__bar"
              style={{
                width: `${widthPercent}%`,
                background: `linear-gradient(90deg, ${color}, ${color}dd)`,
              }}
            >
              <div className="makerspace-funnel__bar-label">
                <span>{stage.label}</span>
                {options?.showValues !== false && (
                  <span className="makerspace-funnel__value">
                    {formatNumeric(stage.value, options?.format ?? 'integer', options?.decimals)}
                  </span>
                )}
              </div>
            </div>
            {stage.helper && <div className="makerspace-funnel__helper">{stage.helper}</div>}
          </div>
        );
      })}
    </div>
  );
};
