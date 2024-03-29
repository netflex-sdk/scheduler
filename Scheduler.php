<?php

namespace Netflex\Scheduler;

use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use Netflex\API\Facades\API;
use Netflex\Scheduler\Contracts\HasJobLabel;
use Throwable;

class Scheduler implements Queue
{
  use InteractsWithTime;

  /**
   * The connection name for the queue.
   *
   * @var string
   */
  protected $connectionName;

  /**
   * The IoC container instance.
   *
   * @var \Illuminate\Container\Container
   */
  protected $container;

  /**
   * The create payload callbacks.
   *
   * @var callable[]
   */
  protected static $createPayloadCallbacks = [];

  /**
   *
   * Validates the request by checking the headers against the payload and processed time and check if the data lines up
   *
   *
   * @param Request $request
   * @return string
   */
  public static function validateRequest(Request $request): string
  {
    abort_unless($request->hasHeader('X-NF-JOB-ID'), 400, 'X-NF-JOB-ID header is missing');
    abort_unless($request->hasHeader('X-NF-JOB-PROCESSED-AT'), 400, 'X-NF-JOB-PROCESSED-AT header is missing');
    abort_unless($request->hasHeader('X-NF-DIGEST'), 400, 'X-NF-DIGEST header is missing');

    $privateKeys = self::getPrivateKeys();
    abort_unless(sizeof($privateKeys) > 0, 500, 'Need to have at least one possible key to validate against');

    $payload = $request->getContent();
    $jobId = $request->header('X-NF-JOB-ID');
    $processedAt = $request->header('X-NF-JOB-PROCESSED-AT');
    $digest = $request->header('X-NF-DIGEST');

    abort_if(cache()->has("scheduler-idempotency/$jobId:$processedAt"), 400, 'This request has already been received');
    cache()->put("scheduler-idempotency/$jobId:$processedAt", true, 3600);
    $valid = false;
    $hashContent = "$jobId:$processedAt:" . $payload;
    foreach ($privateKeys as $key) {
      $keyDigest = hash_hmac('sha512', $hashContent, $key);
      if ($keyDigest === $digest) {
        $valid = true;
        break;
      }
    }
    abort_unless($valid, 400, 'Digest does not match critical components');
    abort_unless(Carbon::parse($processedAt)->diffInMinutes() <= 5, 400, 'This request is too old, we won\'t process it');

    return $payload;
  }

  /**
   * Get the size of the queue.
   *
   * @param string|null $queue
   * @return int
   */
  public function size($queue = null)
  {
    return API::get("scheduler/queue/{$this->getConnectionName()}/size")->size;
  }

  /**
   * Push a new job onto the queue.
   *
   * @param string|object $job
   * @param mixed $data
   * @param string|null $queue
   * @return mixed
   */
  public function push($job, $data = '', $queue = null)
  {
    $payload = $this->createPayload($job, $this->getQueue($queue), $data);

    $name = $payload['displayName'] . ' (' . $payload['uuid'] . ')';

    if ($job instanceof HasJobLabel) {
      if ($label = $job->getJobLabel()) {
        $name = $label . ' (' . $payload['uuid'] . ')';
      }
    }

    $payload['name'] = $name;

    return $this->pushRaw($payload, $this->getQueue($queue), [
      'start' => Carbon::now()->toDateTimeString()
    ]);
  }

  protected function getQueue($queue = null)
  {
    return 'default';
  }

  /**
   * Push a new job onto the queue.
   *
   * @param string $queue
   * @param string|object $job
   * @param mixed $data
   * @return mixed
   */
  public function pushOn($queue, $job, $data = '')
  {
    return $this->push($job, $data, $queue);
  }

  /**
   * Push a raw payload onto the queue.
   *
   * @param object|array $payload
   * @param string|null $queue
   * @param array $options
   * @return mixed
   */
  public function pushRaw($payload, $queue = null, array $options = [])
  {
    $start = Carbon::parse($options['start'] ?? Carbon::now(), 'Europe/Oslo');
    $name = $payload['name'] ?? $payload['displayName'] . ' (' . $payload['uuid'] . ')';

    $url = route('netflex.queue.worker');
    if ($baseUri = Config::get(implode('.', ['queue', 'connections', $this->getConnectionName(), 'base_uri']))) {
      $url = rtrim($baseUri, "/") . route('netflex.queue.worker', null, false);
    }

    return API::post('scheduler/jobs', [
      'method' => 'post',
      'name' => $name,
      'url' => $url,
      'payload' => $payload,
      'start' => $start->toDateTimeString(),
      'enabled' => true
    ]);
  }

  /**
   * Push a new job onto the queue after a delay.
   *
   * @param \DateTimeInterface|\DateInterval|int $delay
   * @param string|object $job
   * @param mixed $data
   * @param string|null $queue
   * @return mixed
   */
  public function later($delay, $job, $data = '', $queue = null)
  {
    $payload = $this->createPayload($job, $this->getQueue($queue), $data);
    $start = Carbon::createFromTimestamp($this->availableAt($delay))->toDateTimeString();

    return $this->pushRaw($payload, $this->getQueue($queue), [
      'start' => $start
    ]);
  }

  /**
   * Push a new job onto the queue after a delay.
   *
   * @param string $queue
   * @param \DateTimeInterface|\DateInterval|int $delay
   * @param string|object $job
   * @param mixed $data
   * @return mixed
   */
  public function laterOn($queue, $delay, $job, $data = '')
  {
    return $this->later($delay, $job, $data, $queue);
  }

  /**
   * Push an array of jobs onto the queue.
   *
   * @param array $jobs
   * @param mixed $data
   * @param string|null $queue
   * @return mixed
   */
  public function bulk($jobs, $data = '', $queue = null)
  {
    foreach ((array)$jobs as $job) {
      $this->push($job, $data, $queue);
    }
  }

  /**
   * Create a payload string from the given job and data.
   *
   * @param \Closure|string|object $job
   * @param string $queue
   * @param mixed $data
   * @return array
   *
   * @throws \Illuminate\Queue\InvalidPayloadException
   */
  protected function createPayload($job, $queue, $data = '')
  {
    if ($job instanceof Closure) {
      $job = CallQueuedClosure::create($job);
    }

    return $this->createPayloadArray($job, $queue, $data);
  }

  /**
   * Create a payload array from the given job and data.
   *
   * @param string|object $job
   * @param string $queue
   * @param mixed $data
   * @return array
   */
  protected function createPayloadArray($job, $queue, $data = '')
  {
    return is_object($job)
      ? $this->createObjectPayload($job, $queue)
      : $this->createStringPayload($job, $queue, $data);
  }

  /**
   * Create a payload for an object-based queue handler.
   *
   * @param object $job
   * @param string $queue
   * @return array
   */
  protected function createObjectPayload($job, $queue)
  {
    $payload = $this->withCreatePayloadHooks($queue, [
      'uuid' => (string)Str::uuid(),
      'displayName' => $this->getDisplayName($job),
      'job' => 'Illuminate\Queue\CallQueuedHandler@call',
      'maxTries' => $job->tries ?? null,
      'maxExceptions' => $job->maxExceptions ?? null,
      'delay' => $this->getJobRetryDelay($job),
      'timeout' => $job->timeout ?? null,
      'timeoutAt' => $this->getJobExpiration($job),
      'data' => [
        'commandName' => $job,
        'command' => $job,
      ],
    ]);

    return array_merge($payload, [
      'data' => [
        'commandName' => get_class($job),
        'command' => serialize(clone $job),
      ],
    ]);
  }

  /**
   * Get the display name for the given job.
   *
   * @param object $job
   * @return string
   */
  protected function getDisplayName($job)
  {
    return method_exists($job, 'displayName')
      ? $job->displayName() : get_class($job);
  }

  /**
   * Get the retry delay for an object-based queue handler.
   *
   * @param mixed $job
   * @return mixed
   */
  public function getJobRetryDelay($job)
  {
    if (!method_exists($job, 'retryAfter') && !isset($job->retryAfter)) {
      return;
    }

    $delay = $job->retryAfter ?? $job->retryAfter();

    return $delay instanceof DateTimeInterface
      ? $this->secondsUntil($delay) : $delay;
  }

  /**
   * Get the expiration timestamp for an object-based queue handler.
   *
   * @param mixed $job
   * @return mixed
   */
  public function getJobExpiration($job)
  {
    if (!method_exists($job, 'retryUntil') && !isset($job->timeoutAt)) {
      return;
    }

    $expiration = $job->timeoutAt ?? $job->retryUntil();

    return $expiration instanceof DateTimeInterface
      ? $expiration->getTimestamp() : $expiration;
  }

  /**
   * Create a typical, string based queue payload array.
   *
   * @param string $job
   * @param string $queue
   * @param mixed $data
   * @return array
   */
  protected function createStringPayload($job, $queue, $data)
  {
    return $this->withCreatePayloadHooks($queue, [
      'uuid' => (string)Str::uuid(),
      'displayName' => is_string($job) ? explode('@', $job)[0] : null,
      'job' => $job,
      'maxTries' => null,
      'maxExceptions' => null,
      'delay' => null,
      'timeout' => null,
      'data' => $data,
    ]);
  }

  /**
   * Register a callback to be executed when creating job payloads.
   *
   * @param callable $callback
   * @return void
   */
  public static function createPayloadUsing($callback)
  {
    if (is_null($callback)) {
      static::$createPayloadCallbacks = [];
    } else {
      static::$createPayloadCallbacks[] = $callback;
    }
  }

  /**
   * Create the given payload using any registered payload hooks.
   *
   * @param string $queue
   * @param array $payload
   * @return array
   */
  protected function withCreatePayloadHooks($queue, array $payload)
  {
    if (!empty(static::$createPayloadCallbacks)) {
      foreach (static::$createPayloadCallbacks as $callback) {
        $payload = array_merge($payload, call_user_func(
          $callback,
          $this->getConnectionName(),
          $queue,
          $payload
        ));
      }
    }

    return $payload;
  }


  private ?Job $nextJob;

  /**
   *
   * Sets the job that will be returned from pop
   *
   * Since the handle method will only run a single time per request, we're only letting you store a single
   * job.
   *
   * @param Job $job
   * @return void
   */
  public function nextJob(Job $job)
  {
    $this->nextJob = $job;
  }

  /**
   * Pop the next job off of the queue.
   *
   * @param string|null $queue
   * @return \Illuminate\Contracts\Queue\Job|null
   */
  public function pop($queue = null)
  {
    $job = $this->nextJob;
    $this->nextJob = null;
    return $job;
  }

  /**
   * Get the connection name for the queue.
   *
   * @return string
   */
  public function getConnectionName()
  {
    return $this->connectionName;
  }

  /**
   * Set the connection name for the queue.
   *
   * @param string $name
   * @return $this
   */
  public function setConnectionName($name)
  {
    $this->connectionName = $name;
    return $this;
  }

  public function setContainer(Container $container)
  {
    $this->container = $container;
    return $this;
  }

  private static function getPrivateKeys(string $key = 'publicKey'): array
  {
    return array_values(array_filter([
      config("api.$key"),
      ...collect(config('api.connections', []))
        ->pluck($key)
        ->toArray()
    ]));
  }

  public static function handle(Request $request)
  {

    $payload = self::validateRequest($request);

    $uuid = data_get(json_decode($payload), 'uuid', 'unknown');
    try {
      set_time_limit(3600);

      /** @var Scheduler $scheduler */
      $scheduler = app(QueueManager::class)->connection('scheduler');

      $job = new SyncJob(
        app(\Illuminate\Contracts\Container\Container::class),
        $payload,
        $scheduler->getConnectionName(),
        $scheduler->getQueue()
      );

      $scheduler->nextJob($job);

      /** @var Worker $queue */
      $queue = app('queue.worker');
      $queue->runNextJob($scheduler->getConnectionName(), $scheduler->getQueue(), (new WorkerOptions('default', 0, 512, 3600)));

      return [
        'uuid' => $uuid,
        'success' => true
      ];

    } catch (Throwable $e) {
      if (App::environment() === 'local')
        throw $e;
      return response()->json([
        'uuid' => $uuid,
        'success' => false,
        'error' => $e->getMessage()
      ], 500);
    }
  }
}
