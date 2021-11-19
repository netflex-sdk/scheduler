# Netflex Scheduler

<a href="https://packagist.org/packages/netflex/scheduler"><img src="https://img.shields.io/packagist/v/netflex/scheduler?label=stable" alt="Stable version"></a>
<a href="https://github.com/netflex-sdk/framework/actions/workflows/split_monorepo.yaml"><img src="https://github.com/netflex-sdk/framework/actions/workflows/split_monorepo.yaml/badge.svg" alt="Build status"></a>
<a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/github/license/netflex-sdk/log.svg" alt="License: MIT"></a>
<a href="https://github.com/netflex-sdk/sdk/graphs/contributors"><img src="https://img.shields.io/github/contributors/netflex-sdk/sdk.svg?color=green" alt="Contributors"></a>
<a href="https://packagist.org/packages/netflex/scheduler/stats"><img src="https://img.shields.io/packagist/dm/netflex/scheduler" alt="Downloads"></a>

[READ ONLY] Subtree split of the Netflex Scheduler component (see [netflex/framework](https://github.con/netflex-sdk/framework))

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
