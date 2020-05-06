<?php

namespace Netflex\Scheduler;

use Exception;
use Illuminate\Queue\Connectors\ConnectorInterface;

class SchedulerConnector implements ConnectorInterface
{
  /**
   * Establish a queue connection.
   *
   * @param  array  $config
   * @return \Illuminate\Contracts\Queue\Queue
   */
  public function connect(array $config)
  {
    if ($config['url'] ?? false) {
      return new Scheduler($config['url']);
    }

    throw new Exception('Queue URL not configured');
  }
}
