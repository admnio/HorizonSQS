<?php

namespace Admnio\Sunset\Contracts;

interface SupervisorRepository
{
    public function names(): array;
    public function all(): array;
    public function find(string $name): ?array;
    public function get(array $names): array;
    public function longestActiveTimeout(): int;
    public function update(\Admnio\Sunset\Supervisor\Supervisor $supervisor): void;
    public function forget(array|string $names): void;
    public function flushExpired(): void;
}
