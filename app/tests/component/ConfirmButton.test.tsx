import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import '@/i18n';
import { ConfirmButton } from '@/components/mod/ui';

describe('ConfirmButton', () => {
  it('asks inline before acting, and "no" cancels', () => {
    const onConfirm = vi.fn();
    render(<ConfirmButton label="Delete" question="Really?" onConfirm={onConfirm} />);
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByText('Really?')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'No' }));
    expect(onConfirm).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    fireEvent.click(screen.getByRole('button', { name: 'Yes' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Delete' })).toBeTruthy();
  });
});
