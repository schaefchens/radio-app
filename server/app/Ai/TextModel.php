<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * A model that answers with one JSON object of a given schema: the host's
 * words (role `host`) and moderation verdicts (role `moderation`). Claude or
 * OpenAI, whichever key the station has (Config::textProvider).
 *
 * Every failure is a TextResult without data, never an exception — except
 * BudgetExceeded, which is the tick's clock and not the job's fault (the job
 * runner retries it without counting an attempt). Callers fall back to
 * templates (host) or fail closed (moderation).
 */
interface TextModel
{
    /** 'anthropic', 'openai' or 'stub' — the host break's source and the logs. */
    public function provider(): string;

    /**
     * @param 'host'|'moderation' $role picks the model from the config
     * @param array<string,mixed> $schema JSON schema of the expected object
     */
    public function json(
        string $kind,
        string $role,
        string $system,
        string $user,
        array $schema,
        int $maxTokens = 4096,
        string $effort = 'low',
        int $timeout = 40,
    ): TextResult;
}
