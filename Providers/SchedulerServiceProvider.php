<?php

namespace Netflex\Scheduler\Providers;

use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Netflex\Scheduler\Scheduler;
use Netflex\Scheduler\SchedulerConnector;

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
    /** @var QueueManager $manager */
    $manager = $this->app['queue'];

    $manager->addConnector('netflex', function () {
      return new SchedulerConnector;
    });
  }

  public function registerRoutes()
  {
    /** @var Router $router */
    $router = $this->app['router'];

    $router->post('/.well-known/netflex/scheduler', function (Request $request) {
      return Scheduler::handle($request);
    })->name('netflex.queue.worker');
  }
}
