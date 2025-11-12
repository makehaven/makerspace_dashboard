import { createRoot, Root } from 'react-dom/client';
import { DashboardChart } from './components/DashboardChart';
import type { PlaceholderConfig } from './types';

const roots = new Map<HTMLElement, Root>();

function getPlaceholderConfig(element: HTMLElement): (PlaceholderConfig & { reactId: string }) | null {
  const reactId = element.dataset.reactId;
  if (!reactId) {
    return null;
  }
  const placeholderSettings = drupalSettings?.makerspaceDashboardReact?.placeholders?.[reactId];
  if (!placeholderSettings) {
    return null;
  }
  return { ...placeholderSettings, reactId };
}

function mount(element: HTMLElement) {
  if (roots.has(element)) {
    return;
  }
  const config = getPlaceholderConfig(element);
  if (!config) {
    return;
  }
  const root = createRoot(element);
  root.render(<DashboardChart {...config} />);
  roots.set(element, root);
}

function unmount(element: HTMLElement) {
  const root = roots.get(element);
  if (!root) {
    return;
  }
  root.unmount();
  roots.delete(element);
}

const behavior: DrupalBehavior = {
  attach(context) {
    const scope = context instanceof HTMLElement ? context : document;
    const placeholders = scope.querySelectorAll<HTMLElement>('.makerspace-dashboard-react-chart');
    placeholders.forEach(mount);
  },
  detach(context) {
    const scope = context instanceof HTMLElement ? context : document;
    const placeholders = scope.querySelectorAll<HTMLElement>('.makerspace-dashboard-react-chart');
    placeholders.forEach(unmount);
  },
};

Drupal.behaviors = Drupal.behaviors || {};
Drupal.behaviors.makerspaceDashboardReact = behavior;
