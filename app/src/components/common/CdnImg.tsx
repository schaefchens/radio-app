import { useState } from 'react';
import { cdnUrl } from '@/lib/cdn';

/** One of our images (/media/…) from the CDN, or from the site if the edge cannot deliver it. */
export function CdnImg({ src, className }: { src: string; className?: string }) {
  const [failed, setFailed] = useState<string | null>(null);
  const url = failed === src ? src : cdnUrl(src);
  return <img src={url} alt="" className={className} onError={() => url !== src && setFailed(src)} />;
}
