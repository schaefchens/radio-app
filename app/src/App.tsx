import { lazy, Suspense, useEffect } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { AppShell } from '@/components/common/AppShell';
import { PlayerLayer } from '@/components/stage/PlayerLayer';
import { ErrorBoundary } from '@/components/common/ErrorBoundary';
import { HomePage } from '@/routes/HomePage';
import { bootRadio } from '@/lib/radio';

const SchedulePage = lazy(() => import('@/routes/SchedulePage').then((m) => ({ default: m.SchedulePage })));
const ChatPage = lazy(() => import('@/routes/ChatPage').then((m) => ({ default: m.ChatPage })));
const ProfilePage = lazy(() => import('@/routes/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SetupPage = lazy(() => import('@/routes/SetupPage').then((m) => ({ default: m.SetupPage })));
const ModRoutes = lazy(() => import('@/routes/mod/ModRoutes').then((m) => ({ default: m.ModRoutes })));

export function App() {
  useEffect(() => {
    void bootRadio();
  }, []);
  return (
    <BrowserRouter>
      <PlayerLayer />
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<ErrorBoundary><HomePage /></ErrorBoundary>} />
          <Route path="schedule" element={<Lazy><SchedulePage /></Lazy>} />
          <Route path="chat" element={<Lazy><ChatPage /></Lazy>} />
          <Route path="profile" element={<Lazy><ProfilePage /></Lazy>} />
          <Route path="setup" element={<Lazy><SetupPage /></Lazy>} />
          <Route path="mod/*" element={<Lazy><ModRoutes /></Lazy>} />
          <Route path="*" element={<ErrorBoundary><HomePage /></ErrorBoundary>} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

function Lazy({ children }: { children: React.ReactNode }) {
  return (
    <ErrorBoundary>
      <Suspense fallback={<div className="p-6 text-sm text-ink-muted">…</div>}>{children}</Suspense>
    </ErrorBoundary>
  );
}
