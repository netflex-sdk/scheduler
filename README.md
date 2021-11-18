# Scheduler

Use Laravels job dispatching with Netflex Scheduled Tasks API.

## Setup

```bash
composer require netflex/scheduler
```

## Configuration

.env:
```
QUEUE_CONNECTION=scheduler
```

config/queue.php:
```php
return [

  'default' => env('QUEUE_CONNECTION', 'scheduler'),

  'connections' => [
    'scheduler' => [
      'driver' => 'netflex',
      'url' => 'https://site.domain'
    ],
  ]
];
```

## Usage

Use it just like Laravels regular job dispathcer:

```php
dispatch(new App\Jobs\MyJob($arguments));
```

The scheduler connection will then serialize this job and schedule it for later processing.

## Quirks

The job wil never be executed at the exact time. It can be delayed several minutes (but usually never more than a minute).
So if your job should run at 00:00:00, you should schedule it at 23:59:00.
