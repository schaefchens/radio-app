import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';

/**
 * Small building blocks shared by the moderator pages (and the inline
 * confirmations elsewhere): no browser dialogs anywhere, since a native
 * confirm() blocks the page and every automated check driving it.
 */

export function Section({ title, actions, children, className }: { title: string; actions?: React.ReactNode; children: React.ReactNode; className?: string }) {
  return (
    <section className={clsx('card flex flex-col gap-3 p-4', className)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">{title}</h2>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {children}
    </section>
  );
}

export function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="flex min-w-0 flex-col">
      <span className="label">{label}</span>
      {children}
      {hint && <span className="mt-1 text-xs text-ink-faint">{hint}</span>}
    </label>
  );
}

export function Check({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <label className={clsx('flex items-center gap-2 text-sm', disabled ? 'text-ink-faint' : 'text-ink')}>
      <input type="checkbox" className="h-4 w-4 accent-brand" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  );
}

/** A button that asks once more, inline, before it acts. */
export function ConfirmButton({
  label,
  question,
  onConfirm,
  className = 'btn-ghost',
  disabled,
}: {
  label: React.ReactNode;
  question: string;
  onConfirm: () => void;
  className?: string;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const [asking, setAsking] = useState(false);
  if (!asking) {
    return (
      <button type="button" className={className} disabled={disabled} onClick={() => setAsking(true)}>
        {label}
      </button>
    );
  }
  return (
    <span className="inline-flex flex-wrap items-center gap-2 rounded-xl border border-heart/40 bg-heart/10 px-2 py-1 text-sm">
      <span className="text-ink">{question}</span>
      <button
        type="button"
        className="rounded-lg bg-heart px-2 py-1 text-xs font-semibold text-white"
        onClick={() => {
          setAsking(false);
          onConfirm();
        }}
      >
        {t('common.yes')}
      </button>
      <button type="button" className="rounded-lg px-2 py-1 text-xs text-ink-muted hover:text-ink" onClick={() => setAsking(false)}>
        {t('common.no')}
      </button>
    </span>
  );
}

/** Comma-separated tags edited as text, shown as chips. */
export function TagsInput({ value, onChange, placeholder }: { value: string[]; onChange: (v: string[]) => void; placeholder?: string }) {
  const [text, setText] = useState(value.join(', '));
  const [shown, setShown] = useState(value);
  if (shown !== value && shown.join(',') !== value.join(',')) {
    setShown(value);
    setText(value.join(', '));
  }
  const parse = (s: string): string[] =>
    [...new Set(s.split(',').map((x) => x.trim().toLowerCase()).filter(Boolean))].slice(0, 12);
  return (
    <div className="flex flex-col gap-1.5">
      <input
        className="field"
        value={text}
        placeholder={placeholder}
        onChange={(e) => {
          setText(e.target.value);
          const tags = parse(e.target.value);
          setShown(tags);
          onChange(tags);
        }}
      />
      {shown.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {shown.map((t) => (
            <span key={t} className="rounded-full bg-brand/15 px-2 py-0.5 text-xs text-brand-bright">
              {t}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}

export function Pill({ children, tone = 'default' }: { children: React.ReactNode; tone?: 'default' | 'good' | 'bad' | 'warn' }) {
  return (
    <span
      className={clsx(
        'inline-flex items-center rounded-full px-2 py-0.5 text-[0.7rem] font-semibold',
        tone === 'good' && 'bg-emerald-500/15 text-emerald-300',
        tone === 'bad' && 'bg-heart/15 text-heart',
        tone === 'warn' && 'bg-amber-500/15 text-amber-300',
        tone === 'default' && 'bg-night-line/20 text-ink-muted',
      )}
    >
      {children}
    </span>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'error' | 'ok'; children: React.ReactNode }) {
  return (
    <p
      role={tone === 'error' ? 'alert' : 'status'}
      className={clsx(
        'rounded-xl px-3 py-2 text-sm',
        tone === 'error' && 'border border-heart/40 bg-heart/10 text-heart',
        tone === 'ok' && 'border border-emerald-400/30 bg-emerald-500/10 text-emerald-300',
        tone === 'info' && 'border border-night-line/30 bg-night-deep/50 text-ink-muted',
      )}
    >
      {children}
    </p>
  );
}

export function Loading() {
  const { t } = useTranslation();
  return <p className="text-sm text-ink-muted">{t('mod.common.loading')}</p>;
}
