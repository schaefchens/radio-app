/**
 * The lines a protocol 1 voice worker's config.toml needs for this station:
 * its address and the key, which /mod shows only once (worker/README.md).
 */
export function configSnippet(key: string, origin: string): string {
  const local = /^https?:\/\/(localhost|127\.|\[::1\])/.test(origin);
  return `[[stations]]\nname = "${local ? 'ARCHE dev' : 'ARCHE'}"\nurl = "${origin}"\nkey = "${key}"\n`;
}

/** Where a herde worker reaches this station (protocol 2). */
export function projectAddress(origin: string): string {
  return `${origin.replace(/\/+$/, '')}/api/worker/v2`;
}

/** herde's image for an NVIDIA GPU (github.com/schaefchens/herde): speech, which is what the station asks of a computer. */
export const HERDE_IMAGE = 'ghcr.io/schaefchens/herde:cuda';

/** What a computer with herde installed (a Mac) runs to join with an invite: herde trades the code for its key once. */
export function joinCommand(code: string, origin: string): string {
  return `herde join ${projectAddress(origin)} ${code}`;
}

/**
 * The same with Docker (Linux, or Windows through Docker Desktop): one line each, so they paste into a shell
 * and into PowerShell alike. The first joins (the key goes into the volume `herde`), the second runs the worker,
 * now and after every restart, as a plain user on a read-only file system; its status page stays on that computer.
 */
export function dockerCommands(code: string, origin: string): [string, string] {
  return [
    `docker run --rm -v herde:/config ${HERDE_IMAGE} join ${projectAddress(origin)} ${code}`,
    `docker run -d --name herde --restart unless-stopped --gpus all --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges -v herde:/config -v herde-cache:/cache -p 127.0.0.1:8737:8737 ${HERDE_IMAGE}`,
  ];
}
