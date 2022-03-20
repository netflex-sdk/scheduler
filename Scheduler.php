<?php

namespace Netflex\Scheduler;

use Closure;
use Throwable;
use DateTimeInterface;

use Carbon\Carbon;

use Netflex\API\Facades\API;
use Netflex\Foundation\Variable;
use Netflex\Support\JWT;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use Netflex\Scheduler\Contracts\HasJobLabel;

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
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        return API::get("scheduler/queue/{$this->getConnectionName()}/size")->size;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
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
     * @param  string  $queue
     * @param  string|object  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function pushOn($queue, $job, $data = '')
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  object|array  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $timeout = Config::get(implode('.', ['queue', 'connections', $this->getConnectionName(), 'timeout']), 3600);
        $token = JWT::create($payload, Variable::get('netflex_api'), $timeout);

        $name = $payload['name'] ?? $payload['displayName'] . ' (' . $payload['uuid'] . ')';

        return API::post('scheduler/jobs', [
            'method' => 'post',
            'name' => $name,
            'url' =>  route('netflex.queue.worker'),
            'payload' => ['task' => $token],
            'start' => $options['start'] ?? Carbon::now()->toDateTimeString(),
            'enabled' => true
        ]);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
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
     * @param  string  $queue
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @return mixed
     */
    public function laterOn($queue, $delay, $job, $data = '')
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * @param  array  $jobs
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ((array) $jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    /**
     * Create a payload string from the given job and data.
     *
     * @param  \Closure|string|object  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return string
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
     * @param  string|object  $job
     * @param  string  $queue
     * @param  mixed  $data
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
     * @param  object  $job
     * @param  string  $queue
     * @return array
     */
    protected function createObjectPayload($job, $queue)
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
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
     * @param  object  $job
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
     * @param  mixed  $job
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
     * @param  mixed  $job
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
     * @param  string  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return array
     */
    protected function createStringPayload($job, $queue, $data)
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Str::uuid(),
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
     * @param  callable  $callback
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
     * @param  string  $queue
     * @param  array  $payload
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

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        return null;
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
     * @param  string  $name
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

    public static function handle(Request $request)
    {
        if ($data = $request->get('task')) {
            if ($jwt = JWT::decodeAndVerify($data, Variable::get('netflex_api'))) {
                if ($task = $jwt->data) {
                    try {
                        if ($job = unserialize($task->command)) {
                            set_time_limit(3600);
                            return [
                                'uuid' => $jwt->uuid,
                                'success' => ((int) $job->handle()) === 0
                            ];
                        }
                        abort(400);
                    } catch (Throwable $e) {
                        return response()->json([
                            'uuid' => $jwt->uuid ?? null,
                            'success' => false,
                            'error' => $e->getMessage()
                        ], 500);
                    }
                }
            }
        }

        abort(400);
    }
}
