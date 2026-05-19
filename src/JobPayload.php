<?php

namespace Admnio\Sunset;

use ArrayAccess;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Arr;
// NOTE: `Laravel\Horizon\Tags` and `Laravel\Horizon\Contracts\Silenced` are
// intentionally imported here for internal helper-method use. They are NOT
// part of Sunset's public API surface. When v1.0.0 drops the laravel/horizon
// dep, port `Tags::for()` into Admnio\Sunset\Tags and define our own Silenced
// marker interface in Admnio\Sunset\Contracts.
use Laravel\Horizon\Contracts\Silenced;
use Laravel\Horizon\Tags;

class JobPayload implements ArrayAccess
{
    public string $value;
    public array $decoded;

    public function __construct(string $value)
    {
        $this->value = $value;
        $this->decoded = json_decode($value, true) ?? [];
    }

    public function id(): string
    {
        return (string) ($this->decoded['uuid'] ?? $this->decoded['id'] ?? '');
    }

    public function tags(): array
    {
        return Arr::get($this->decoded, 'tags', []);
    }

    public function isRetry(): bool
    {
        return isset($this->decoded['retry_of']);
    }

    public function retryOf(): ?string
    {
        return $this->decoded['retry_of'] ?? null;
    }

    public function isSilenced(): bool
    {
        return (bool) ($this->decoded['silenced'] ?? false);
    }

    public function prepare(mixed $job = null): self
    {
        return $this->set([
            'type' => $this->determineType($job),
            'tags' => $tags = $this->determineTags($job),
            'silenced' => $this->shouldBeSilenced($job, $tags),
            'pushedAt' => str_replace(',', '.', microtime(true)),
        ]);
    }

    protected function determineType(mixed $job): string
    {
        return match (true) {
            $job instanceof BroadcastEvent => 'broadcast',
            $job instanceof CallQueuedListener => 'event',
            $job instanceof SendQueuedMailable => 'mail',
            $job instanceof SendQueuedNotifications => 'notification',
            default => 'job',
        };
    }

    protected function determineTags(mixed $job): array
    {
        return array_merge(
            $this->decoded['tags'] ?? [],
            ! $job || is_string($job) ? [] : Tags::for($job)
        );
    }

    protected function shouldBeSilenced(mixed $job, array $tags = []): bool
    {
        if (! $job) {
            return false;
        }

        $underlying = $this->underlyingJob($job);
        $jobClass = is_string($underlying) ? $underlying : get_class($underlying);

        return in_array($jobClass, config('horizon.silenced', []))
            || is_a($jobClass, Silenced::class, true)
            || count(array_intersect($tags, config('horizon.silenced_tags', []))) > 0;
    }

    protected function underlyingJob(mixed $job): mixed
    {
        return match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof CallQueuedListener => $job->class,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            default => $job,
        };
    }

    public function set(array $values): self
    {
        $this->decoded = array_merge($this->decoded, $values);
        $this->value = json_encode($this->decoded);
        return $this;
    }

    public function commandName(): ?string
    {
        return Arr::get($this->decoded, 'data.commandName');
    }

    public function displayName(): ?string
    {
        return Arr::get($this->decoded, 'displayName');
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->decoded);
    }

    public function offsetGet($offset): mixed
    {
        return $this->decoded[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->decoded[$offset] = $value;
        $this->value = json_encode($this->decoded);
    }

    public function offsetUnset($offset): void
    {
        unset($this->decoded[$offset]);
        $this->value = json_encode($this->decoded);
    }
}
