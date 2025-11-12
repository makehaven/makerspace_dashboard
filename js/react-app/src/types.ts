export interface RangeOption {
  label: string;
}

export interface RangeConfig {
  active: string | null;
  options: Record<string, RangeOption>;
}

export interface PlaceholderConfig {
  sectionId: string;
  chartId: string;
  ranges?: RangeConfig;
}

export type ChartVisualization =
  | {
      type: 'chart';
      library: 'chartjs';
      chartType: string;
      data: Record<string, unknown>;
      options?: Record<string, unknown>;
    }
  | {
      type: 'table';
      header: string[];
      rows: string[][];
      empty?: string | null;
    }
  | {
      type: 'markup';
      markup: string;
    }
  | {
      type: 'container';
      attributes?: Record<string, unknown>;
      children: Record<string, ChartVisualization>;
    }
  | {
      type: 'unknown';
    };

export interface ChartDefinition {
  sectionId: string;
  chartId: string;
  title: string;
  description: string;
  notes: string[];
  downloadUrl: string;
  range?: RangeConfig | null;
  visualization: ChartVisualization;
}
