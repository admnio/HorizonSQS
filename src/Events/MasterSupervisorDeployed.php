<?php

namespace Admnio\Sunset\Events;

class MasterSupervisorDeployed
{
    public function __construct(public readonly string $master) {}
}
