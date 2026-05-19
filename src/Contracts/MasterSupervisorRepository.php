<?php

namespace Admnio\Sunset\Contracts;

interface MasterSupervisorRepository
{
    public function names(): array;
    public function all(): array;
    public function find(string $name): ?array;
    public function get(array $names): array;
    public function update(\Admnio\Sunset\Supervisor\MasterSupervisor $master): void;
    public function forget(string $name): void;
    public function flushExpired(): void;
}
