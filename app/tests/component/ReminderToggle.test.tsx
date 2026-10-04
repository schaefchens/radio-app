import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import '@/i18n';
import { ReminderToggle } from '@/components/schedule/ReminderToggle';
import { useReminders } from '@/store/reminders';

/**
 * Android 14+ holds a reminder back for up to an hour unless the app may use
 * "Alarms & reminders": with a reminder on, the toggle offers to allow it,
 * and only where that applies.
 */

const reminders = vi.hoisted(() => ({
  onTime: null as boolean | null,
  allowed: true as boolean | null,
}));

vi.mock('@/lib/reminders', () => ({
  toggleReminder: vi.fn(async () => 'on'),
  onTimeReminders: vi.fn(async () => reminders.onTime),
  allowOnTimeReminders: vi.fn(async () => reminders.allowed),
}));

beforeEach(() => {
  useReminders.setState({ list: [{ ch: 'main', p: 'gebet', since: 0 }], permission: 'granted' });
});

describe('on-time reminders', () => {
  it('Android without "Alarms & reminders": says so and opens the setting', async () => {
    reminders.onTime = false;
    const { allowOnTimeReminders } = await import('@/lib/reminders');
    render(<ReminderToggle channel="main" program="gebet" />);
    const allow = await screen.findByRole('button', { name: 'Allow on-time reminders' });
    expect(screen.getByText('Without “Alarms & reminders”, Android may deliver reminders late.')).toBeTruthy();
    fireEvent.click(allow);
    expect(allowOnTimeReminders).toHaveBeenCalledOnce();
    // Allowed: the offer goes.
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Allow on-time reminders' })).toBeNull());
  });

  it('allowed already, or not Android: nothing to offer', async () => {
    for (const onTime of [true, null]) {
      reminders.onTime = onTime;
      const { unmount } = render(<ReminderToggle channel="main" program="gebet" />);
      await screen.findByRole('button', { name: 'Reminder on' });
      await new Promise((r) => setTimeout(r, 0));
      expect(screen.queryByRole('button', { name: 'Allow on-time reminders' })).toBeNull();
      unmount();
    }
  });

  it('no reminder on: nothing to offer', async () => {
    reminders.onTime = false;
    useReminders.setState({ list: [] });
    render(<ReminderToggle channel="main" program="gebet" />);
    await screen.findByRole('button', { name: 'Remind me' });
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByRole('button', { name: 'Allow on-time reminders' })).toBeNull();
  });
});
