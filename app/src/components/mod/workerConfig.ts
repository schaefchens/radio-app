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

/**
 * herde's images with speech, which is what the station asks of a computer (github.com/schaefchens/herde):
 * one for an NVIDIA GPU, one for an AMD GPU through ROCm (Linux only: Docker on Windows passes NVIDIA alone).
 */
export const HERDE_IMAGES = { nvidia: 'ghcr.io/schaefchens/herde:cuda', amd: 'ghcr.io/schaefchens/herde:rocm' } as const;
export type Gpu = keyof typeof HERDE_IMAGES;
export const GPUS = Object.keys(HERDE_IMAGES) as Gpu[];

/**
 * How the worker reaches the GPU. An AMD GPU is two devices, opened through the groups video and render,
 * whose ids differ from one Linux to the next: the shell looks them up (Linux only, as the image is).
 */
const GPU_ACCESS: Record<Gpu, string> = {
  nvidia: '--gpus all',
  amd: '--device /dev/kfd --device /dev/dri --group-add $(getent group video | cut -d: -f3) --group-add $(getent group render | cut -d: -f3)',
};

/** What a computer with herde installed (a Mac) runs to join with an invite: herde trades the code for its key once. */
export function joinCommand(code: string, origin: string): string {
  return `herde join ${projectAddress(origin)} ${code}`;
}

/**
 * The same with Docker (Linux, or Windows through Docker Desktop with an NVIDIA GPU): one line each, so
 * NVIDIA's paste into a shell and into PowerShell alike. The first joins (the key goes into the volume `herde`),
 * the second runs the worker, now and after every restart, as a plain user on a read-only file system; its
 * status page stays on that computer.
 */
export function dockerCommands(code: string, origin: string, gpu: Gpu = 'nvidia'): [string, string] {
  const image = HERDE_IMAGES[gpu];
  return [
    `docker run --rm -v herde:/config ${image} join ${projectAddress(origin)} ${code}`,
    `docker run -d --name herde --restart unless-stopped ${GPU_ACCESS[gpu]} --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges -v herde:/config -v herde-cache:/cache -p 127.0.0.1:8737:8737 ${image}`,
  ];
}
