/**
 * OpenAI's built-in voices (Host\Hosts::OPENAI_VOICES); tts-1 and tts-1-hd
 * lack ballad, marin and cedar. On its own: the i18n key test reads it
 * without the rest of /mod's API code.
 */
export const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'] as const;

/**
 * Qwen3-TTS CustomVoice's presets (worker/arche_worker/engine.py VOICES):
 * listed while no computer is online to report them, so a host on our own
 * computers can be set up — or changed — with the Mac asleep.
 */
export const QWEN_VOICES = ['Ryan', 'Aiden', 'Vivian', 'Serena', 'Uncle_Fu', 'Dylan', 'Eric', 'Ono_Anna', 'Sohee'] as const;
