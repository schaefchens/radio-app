<?php
declare(strict_types=1);

namespace Arche;

use Arche\Ai\Claude;
use Arche\Ai\OpenAi;
use Arche\Ai\OpenAiText;
use Arche\Ai\StubOpenAi;
use Arche\Ai\StubText;
use Arche\Ai\TextModel;
use Arche\Ai\Usage;
use Arche\Ai\Voice;
use Arche\Host\HostBreaks;
use Arche\Host\HostWriter;
use Arche\Identity\Identities;
use Arche\Identity\RateLimit;
use Arche\Jobs\Jobs;
use Arche\Jobs\Runner;
use Arche\Library\Library;
use Arche\Library\Media;
use Arche\Library\YouTube;
use Arche\Moderation\Moderator;
use Arche\Plan\Catalog;
use Arche\Plan\PlanResolver;
use Arche\Presence\Presence;
use Arche\Presence\Trends;
use Arche\Program\Committer;
use Arche\Program\Drafter;
use Arche\Program\Publisher;
use Arche\Program\Selector;
use Arche\Program\Timeline;
use Arche\Realtime\HetznerCloud;
use Arche\Realtime\Nodes;
use Arche\Realtime\Tokens;
use Arche\Realtime\WakeController;
use Arche\Submission\Submissions;
use Arche\Support\Budget;
use Arche\Support\Clock;
use Arche\Support\HttpClient;
use Arche\Support\Lock;
use Arche\Tick\Tick;

/**
 * The service container: one per request or tick. Services are built on first
 * use and live for the rest of the process; tests swap any of them via set().
 */
final class App
{
    /** @var array<string,object> */
    private array $services = [];
    public readonly Budget $budget;

    public function __construct(public readonly Config $config, public readonly Clock $clock, ?Store $store = null)
    {
        $this->budget = new Budget($config->int('TICK_BUDGET', 22));
        if ($store !== null) $this->services['store'] = $store;
    }

    public static function boot(): self
    {
        return new self(Config::load(), new Clock());
    }

    public function set(string $name, object $service): void
    {
        $this->services[$name] = $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $_type
     * @param \Closure():T $make
     * @return T
     */
    private function service(string $name, string $_type, \Closure $make): object
    {
        /** @var T */
        return $this->services[$name] ??= $make();
    }

    public function store(): Store
    {
        return $this->service('store', Store::class, function () {
            $store = new Store($this->config->dataDir . '/arche.sqlite', $this->config->bool('SQLITE_WAL', true));
            Schema::migrate($store, $this->clock->nowMs());
            return $store;
        });
    }

    public function lock(): Lock
    {
        return $this->service('lock', Lock::class, fn() => new Lock($this->config->dataDir . '/locks'));
    }

    public function http(): HttpClient
    {
        return $this->service('http', HttpClient::class, fn() => new HttpClient($this->budget));
    }

    public function usage(): Usage
    {
        return $this->service('usage', Usage::class, fn() => new Usage($this));
    }

    /** The model for the host's words and moderation: Claude or OpenAI (Config::textProvider). */
    public function text(): TextModel
    {
        return $this->service('text', TextModel::class, fn() => match ($this->config->textProvider()) {
            'stub' => new StubText($this),
            'openai' => new OpenAiText($this),
            // 'anthropic', and '' (no key: every call answers `no_key`)
            default => new Claude($this),
        });
    }

    public function openai(): OpenAi
    {
        return $this->service('openai', OpenAi::class, fn() => $this->config->stubAi() ? new StubOpenAi($this) : new OpenAi($this));
    }

    public function voice(): Voice
    {
        return $this->service('voice', Voice::class, fn() => new Voice($this));
    }

    public function media(): Media
    {
        return $this->service('media', Media::class, fn() => new Media($this));
    }

    public function catalog(): Catalog
    {
        return $this->service('catalog', Catalog::class, fn() => new Catalog($this));
    }

    public function resolver(): PlanResolver
    {
        return $this->service('resolver', PlanResolver::class, fn() => new PlanResolver($this));
    }

    public function library(): Library
    {
        return $this->service('library', Library::class, fn() => new Library($this));
    }

    public function youtube(): YouTube
    {
        return $this->service('youtube', YouTube::class, fn() => new YouTube($this));
    }

    public function timeline(): Timeline
    {
        return $this->service('timeline', Timeline::class, fn() => new Timeline($this));
    }

    public function selector(): Selector
    {
        return $this->service('selector', Selector::class, fn() => new Selector($this));
    }

    public function drafter(): Drafter
    {
        return $this->service('drafter', Drafter::class, fn() => new Drafter($this));
    }

    public function committer(): Committer
    {
        return $this->service('committer', Committer::class, fn() => new Committer($this));
    }

    public function publisher(): Publisher
    {
        return $this->service('publisher', Publisher::class, fn() => new Publisher($this));
    }

    public function hostBreaks(): HostBreaks
    {
        return $this->service('hostBreaks', HostBreaks::class, fn() => new HostBreaks($this));
    }

    public function hostWriter(): HostWriter
    {
        return $this->service('hostWriter', HostWriter::class, fn() => new HostWriter($this));
    }

    public function jobs(): Jobs
    {
        return $this->service('jobs', Jobs::class, fn() => new Jobs($this));
    }

    public function runner(): Runner
    {
        return $this->service('runner', Runner::class, fn() => new Runner($this));
    }

    public function tick(): Tick
    {
        return $this->service('tick', Tick::class, fn() => new Tick($this));
    }

    public function presence(): Presence
    {
        return $this->service('presence', Presence::class, fn() => new Presence($this));
    }

    public function trends(): Trends
    {
        return $this->service('trends', Trends::class, fn() => new Trends($this));
    }

    public function identities(): Identities
    {
        return $this->service('identities', Identities::class, fn() => new Identities($this));
    }

    public function rateLimit(): RateLimit
    {
        return $this->service('rateLimit', RateLimit::class, fn() => new RateLimit($this));
    }

    public function submissions(): Submissions
    {
        return $this->service('submissions', Submissions::class, fn() => new Submissions($this));
    }

    public function moderator(): Moderator
    {
        return $this->service('moderator', Moderator::class, fn() => new Moderator($this));
    }

    public function tokens(): Tokens
    {
        return $this->service('tokens', Tokens::class, fn() => new Tokens($this));
    }

    public function nodes(): Nodes
    {
        return $this->service('nodes', Nodes::class, fn() => new Nodes($this));
    }

    public function cloud(): HetznerCloud
    {
        return $this->service('cloud', HetznerCloud::class, fn() => new HetznerCloud($this));
    }

    public function wake(): WakeController
    {
        return $this->service('wake', WakeController::class, fn() => new WakeController($this));
    }

    /** Absolute path of a file under the web root. */
    public function publicPath(string $rel): string
    {
        return $this->config->publicDir . '/' . ltrim($rel, '/');
    }
}
