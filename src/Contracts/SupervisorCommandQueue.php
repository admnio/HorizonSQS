<?php

namespace Admnio\Sunset\Contracts;

interface SupervisorCommandQueue
{
    public function push(string $name, string $command, array $options = []): void;
    public function pending(string $name): array;
    public function flush(string $name): void;
}
