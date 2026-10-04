import { beforeEach, describe, expect, it } from 'vitest';
import { act, render } from '@testing-library/react';
import { useState } from 'react';
import '@/i18n';
import { BottomSheet } from '@/components/common/BottomSheet';
import { closeTop, openCount, resetBackStack } from '@/lib/backStack';
import { useStage } from '@/store/stage';

function TwoSheets({ closed }: { closed: string[] }) {
  const [first, setFirst] = useState(true);
  const [second, setSecond] = useState(true);
  const close = (name: string, set: (open: boolean) => void) => () => {
    closed.push(name);
    set(false);
  };
  return (
    <>
      <BottomSheet open={first} title="First" onClose={close('first', setFirst)}>
        <p>one</p>
      </BottomSheet>
      <BottomSheet open={second} title="Second" onClose={close('second', setSecond)}>
        <p>two</p>
      </BottomSheet>
    </>
  );
}

beforeEach(() => resetBackStack());

describe("Android's back button and the sheets", () => {
  it('closes the newest sheet first, and the player stays paused until both are gone', () => {
    const closed: string[] = [];
    render(<TwoSheets closed={closed} />);
    expect(openCount()).toBe(2);
    expect(useStage.getState().overlays).toBe(2);

    act(() => void closeTop());
    expect(closed).toEqual(['second']);
    expect(useStage.getState().overlays).toBe(1);

    act(() => void closeTop());
    expect(closed).toEqual(['second', 'first']);
    expect(useStage.getState().overlays).toBe(0);
    expect(openCount()).toBe(0);
  });
});
