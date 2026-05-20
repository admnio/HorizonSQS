<?php

namespace Admnio\Sunset\Transports\Sqs;

use Admnio\Sunset\Support\TransportRegistry;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SqsConnector implements ConnectorInterface
{
    public function __construct(private TransportRegistry $transports)
    {
    }

    public function connect(array $config)
    {
        return $this->transports->get('sqs')->connect($config);
    }
}
