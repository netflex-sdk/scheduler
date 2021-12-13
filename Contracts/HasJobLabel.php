<?php

namespace Netflex\Scheduler\Contracts;

interface HasJobLabel
{
    /**
     * @return string
     */
    public function getJobLabel(): string;
}
