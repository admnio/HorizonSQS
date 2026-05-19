<?php

namespace Admnio\Sunset\Contracts;

interface Terminable
{
    public function terminate(int $status = 0): void;
}
