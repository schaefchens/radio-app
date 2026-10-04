import { lazy, Suspense, useEffect } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { AppShell } from '@/components/common/AppShell';
import { PlayerLayer } from '@/components/stage/PlayerLayer';
import { ErrorBoundary } from '@/components/common/ErrorBoundary';
import { HomePage } from '@/routes/HomePage';
import { bootRadio } from '@/lib/radio';
import { hideSplash } from '@/lib/native';

const SchedulePage = lazy(() => import('@/routes/SchedulePage').then((m) => ({ default: m.SchedulePage })));
const ChatPage = lazy(() => import('@/routes/ChatPage').then((m) => ({ default: m.ChatPage })));
const ProfilePage = lazy(() => import('@/routes/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SetupPage = lazy(() => import('@/routes/SetupPage').then((m) => ({ default: m.SetupPage })));
const ModRoutes = lazy(() => import('@/routes/mod/ModRoutes').then((m) => ({ default: m.ModRoutes })));
const AboutPage = lazy(() => import('@/routes/AboutPage').then((m) => ({ default: m.AboutPage })));
const DeleteAccountPage = lazy(() => import('@/routes/DeleteAccountPage').then((m) => ({ default: m.DeleteAccountPage })));

export function App() {
  useEffect(() => {
    void bootRadio();
    hideSplash();
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
          <Route path="about" element={<Lazy><AboutPage /></Lazy>} />
          {/* The legal pages must be reachable directly, by the names people type. */}
          <Route path="impressum" element={<Lazy><AboutPage section="impressum" /></Lazy>} />
          <Route path="datenschutz" element={<Lazy><AboutPage section="datenschutz" /></Lazy>} />
          <Route path="regeln" element={<Lazy><AboutPage section="regeln" /></Lazy>} />
          <Route path="rules" element={<Lazy><AboutPage section="regeln" /></Lazy>} />
          {/* Deleting an account without the app (Google Play asks for a web page). */}
          <Route path="konto-loeschen" element={<Lazy><DeleteAccountPage /></Lazy>} />
          <Route path="delete-account" element={<Lazy><DeleteAccountPage /></Lazy>} />
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
