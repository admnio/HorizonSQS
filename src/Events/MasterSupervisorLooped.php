<?php

namespace Admnio\Sunset\Events;

class MasterSupervisorLooped
{
    public function __construct(public readonly object $master) {}
}
