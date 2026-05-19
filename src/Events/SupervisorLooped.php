<?php

namespace Admnio\Sunset\Events;

class SupervisorLooped
{
    public function __construct(public readonly object $supervisor) {}
}
