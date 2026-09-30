/**
 * How many messages the phone carousel shows: as many rows (at most three,
 * at least one) as fit between the pinned player and the unfolded dock.
 * `available` is that height, `chrome` the panel's own heading, padding and
 * dots, `row` the tallest message.
 */
export function fitMessages(available: number, chrome: number, row: number): number {
  let used = chrome;
  let count = 0;
  for (let i = 0; i < 3; i++) {
    if (i > 0 && used + row > available) break;
    used += row;
    count++;
  }
  return count;
}
