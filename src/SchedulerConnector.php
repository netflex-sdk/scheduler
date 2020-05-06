<?php

namespace Netflex\Scheduler;

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
    assert($config['url'] ?? false, 'url endpoint missing');
    return new Scheduler($config['url']);
  }
}
