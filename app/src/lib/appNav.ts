/**
 * Navigation from outside React (a tapped reminder): the router's navigate,
 * handed in by AppShell. A call before it is there waits for it.
 */
let navigate: ((path: string) => void) | null = null;
let waiting: string | null = null;

export function setNavigator(fn: (path: string) => void): () => void {
  navigate = fn;
  if (waiting !== null) {
    const path = waiting;
    waiting = null;
    if (window.location.pathname !== path) fn(path);
  }
  return () => {
    if (navigate === fn) navigate = null;
  };
}

export function navigateTo(path: string): void {
  if (!navigate) waiting = path;
  else if (window.location.pathname !== path) navigate(path);
}
