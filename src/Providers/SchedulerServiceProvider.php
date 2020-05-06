<?php

namespace Netflex\Scheduler\Providers;

use Throwable;

use Netflex\Support\JWT;
use Netflex\Foundation\Variable;
use Netflex\Scheduler\SchedulerConnector;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class SchedulerServiceProvider extends ServiceProvider
{
  public function register()
  {
    //
  }

  public function boot()
  {
    $this->registerDriver();
    $this->registerRoutes();
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
    /** @var Router */
    $router = $this->app['router'];

    $router->post('/.well-known/netflex/scheduler', function (Request $request) {
      $token = $request->get('token');
      if ($payload = JWT::decodeAndVerify($token, Variable::get('netflex_api'))) {
        try {
          $job = unserialize($payload->data->command);
          if (is_object($job) && method_exists($job, 'handle')) {
            return $job->handle;
          }
        } catch (Throwable $e) {
          return;
        }
      }
    });
  }
}
