import type { PlaceholderConfig } from './types';

export {};

declare global {
  interface DrupalBehavior {
    attach: (context: HTMLElement | Document, settings?: DrupalSettings) => void;
    detach?: (context: HTMLElement | Document, settings?: DrupalSettings, trigger?: string) => void;
  }

  interface DrupalGlobal {
    behaviors: Record<string, DrupalBehavior>;
    url: (path: string) => string;
    t: (input: string, args?: Record<string, unknown>) => string;
  }

  interface DrupalSettings {
    makerspaceDashboardReact?: {
      placeholders?: Record<string, PlaceholderConfig & { reactId?: string }>; // reactId is stored on the DOM element.
    };
  }

  const Drupal: DrupalGlobal;
  const drupalSettings: DrupalSettings;
}
