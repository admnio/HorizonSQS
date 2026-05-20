<?php

namespace Admnio\Sunset\Support;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Exceptions\InvalidConfigurationException;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class TransportRegistry
{
    /** @var array<string, Transport> */
    private array $transports = [];

    public function register(Transport $transport): void
    {
        $this->transports[$transport->name()] = $transport;
    }

    public function get(string $name): Transport
    {
        if (! isset($this->transports[$name])) {
            throw new InvalidConfigurationException(
                "Sunset: no transport registered for name '{$name}'."
            );
        }
        return $this->transports[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->transports);
    }
}
