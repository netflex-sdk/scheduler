<?php

namespace Netflex\Scheduler\Providers;

use Throwable;

use Netflex\Support\JWT;
use Netflex\Foundation\Variable;
use Netflex\Scheduler\SchedulerConnector;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Netflex\Scheduler\Scheduler;

class SchedulerServiceProvider extends ServiceProvider
{
  public function register()
  {
    //
  }

  public function boot()
  {
    $this->registerRoutes();
    $this->registerDriver();
  }

  public function registerDriver()
  {
    /** @var QueueManager */
    $manager = $this->app['queue'];

    $manager->addConnector('netflex', function () {
      return new SchedulerConnector;
    });
  }

  public function registerRoutes()
  {
    /** @var \Illuminate\Routing\Router */
    $router = $this->app['router'];

    $router->post('/.well-known/netflex/scheduler', function (Request $request) {
      return Scheduler::handle($request);
    })->name('netflex.queue.worker');
  }
}
