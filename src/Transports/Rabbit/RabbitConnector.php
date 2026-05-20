<?php

namespace Admnio\Sunset\Transports\Rabbit;

use Admnio\Sunset\Support\TransportRegistry;
use Illuminate\Queue\Connectors\ConnectorInterface;

class RabbitConnector implements ConnectorInterface
{
    public function __construct(private TransportRegistry $transports)
    {
    }

    public function connect(array $config)
    {
        return $this->transports->get('rabbitmq')->connect($config);
    }
}
