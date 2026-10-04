import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import type { Block } from '@/content/legal';

/** Headed paragraphs of a legal text (the station page, the rules). */
export function Blocks({ blocks }: { blocks: Block[] }) {
  return (
    <div className="flex flex-col gap-4">
      {blocks.map((b) => (
        <div key={b.h}>
          <h3 className="font-semibold text-ink">{b.h}</h3>
          {b.p.split('\n').map((line, i) => (
            <p key={i} className="mt-1 text-sm leading-relaxed text-ink-muted">
              <Linked text={line} />
            </p>
          ))}
        </div>
      ))}
    </div>
  );
}

// A URL runs to the next space or ")"; an address ends at its last domain label.
const LINK = /(https?:\/\/[^\s)]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)/g;
// Our own pages open in place: in the store apps a new window on the same
// host would load the whole app again.
const OWN = /^https:\/\/radio\.schaefchens\.de(\/[^\s]*)?$/;

/** URLs and e-mail addresses in a legal text as links (nothing else is markup). */
function Linked({ text }: { text: string }) {
  const parts = text.split(LINK);
  return (
    <>
      {parts.map((part, i) => {
        if (i % 2 === 0) return <Fragment key={i}>{part}</Fragment>;
        const own = OWN.exec(part);
        if (own) {
          return (
            <Link key={i} to={own[1] ?? '/'} className="text-accent underline">
              {part}
            </Link>
          );
        }
        const href = part.startsWith('http') ? part : `mailto:${part}`;
        return (
          <a key={i} href={href} className="text-accent underline" target={href.startsWith('http') ? '_blank' : undefined} rel="noreferrer">
            {part}
          </a>
        );
      })}
    </>
  );
}
