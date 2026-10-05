/**
 * OpenAI's built-in voices (Host\Hosts::OPENAI_VOICES); tts-1 and tts-1-hd
 * lack ballad, marin and cedar. On its own: the i18n key test reads it
 * without the rest of /mod's API code.
 */
export const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'] as const;
