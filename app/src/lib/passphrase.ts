import { generateMnemonic, mnemonicToSeedSync, validateMnemonic } from '@scure/bip39';
import { wordlist } from '@scure/bip39/wordlists/english.js';

/**
 * The optional 12-word passphrase (BIP39, 128 bits, always generated — never
 * chosen, which is what makes a deterministic lookup safe). The words never
 * leave the device: the BIP39 seed (PBKDF2-SHA512) yields a credential id and
 * secret, as in bible-assistant, and the server keeps only an HMAC of the id
 * and an Argon2id hash of the secret. There is no recovery by design.
 */

export function normalize(words: string): string {
  return words.trim().toLowerCase().replace(/\s+/g, ' ');
}

export function generatePassphrase(): string {
  return generateMnemonic(wordlist, 128);
}

export function validPassphrase(words: string): boolean {
  const n = normalize(words);
  return n.split(' ').length === 12 && validateMnemonic(n, wordlist);
}

function hex(b: Uint8Array): string {
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}

function uuidV8(bytes: Uint8Array): string {
  const b = new Uint8Array(bytes.slice(0, 16));
  b[6] = (b[6]! & 0x0f) | 0x80;
  b[8] = (b[8]! & 0x3f) | 0x80;
  const h = hex(b);
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20, 32)}`;
}

export function deriveCredential(words: string): { credId: string; credSecret: string } {
  const seed = mnemonicToSeedSync(normalize(words));
  return { credId: uuidV8(seed.slice(0, 16)), credSecret: hex(seed.slice(16, 48)) };
}

export const WORDS = wordlist;
