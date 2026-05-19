<?php

namespace Admnio\Sunset\Contracts;

interface ProcessRepository
{
    public function allOrphans(string $master): array;
    public function orphaned(string $master, array $processIds): array;
    public function orphanedFor(string $master, int $seconds): array;
    public function forgetOrphans(string $master, array $processIds): void;
}
