import { useState } from 'react';

export type Sheet = 'song' | 'record' | 'prayer';
export type RecordKind = 'story' | 'prayer';

export interface SubmitSheetsState {
  open: Sheet | null;
  recordKind: RecordKind;
  show: (sheet: Sheet, kind?: RecordKind) => void;
  close: () => void;
}

/**
 * Which submission sheet is open. The page holds it: it shows the tiles twice
 * (desktop and phone layout) but must mount each sheet once — a closed sheet
 * stays in the DOM, and two copies would share their form ids.
 */
export function useSubmitSheets(): SubmitSheetsState {
  const [open, setOpen] = useState<Sheet | null>(null);
  const [recordKind, setRecordKind] = useState<RecordKind>('story');
  return {
    open,
    recordKind,
    show: (sheet, kind) => {
      if (kind) setRecordKind(kind);
      setOpen(sheet);
    },
    close: () => setOpen(null),
  };
}
