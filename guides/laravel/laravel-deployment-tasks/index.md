---
title: "How to Run One-Time and Repeatable Tasks After Laravel Deployment"
slug: "laravel-deployment-tasks"
description: "Run Laravel deployment tasks once or on every deploy, track execution, coordinate servers, and understand queued jobs and rollback with practical examples."
category: "laravel"
tags:
  - laravel
  - deployment
  - devops
  - artisan
  - queues
  - data-migrations
related_packages:
  - laravel-executor
published_at: "2026-10-29"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel post deployment tasks
  - laravel one time operation
  - laravel run code once after deploy
  - laravel deployment script
  - laravel data migration
  - laravel deploy commands
  - laravel executor
---

A release adds a column, backfills existing products, and rebuilds a search index. The next deployment should skip the backfill but still refresh each server's runtime state.

The code is usually straightforward. Remembering what ran, deciding what should run again, and coordinating servers is the harder part.

> **Quick Answer / TL;DR:**
> Use migrations for schema changes and Artisan commands or jobs for application work. When deployment needs execution history, track tasks explicitly: one-time tasks skip recorded successes, while repeatable tasks run whenever the deployment runner is called.

## Start with a Deployment Script

Laravel's [deployment tools](https://laravel.com/docs/13.x/deployment) and Artisan commands already cover many release steps:

```bash
set -e

php artisan migrate --force
php artisan app:backfill-ratings
php artisan optimize
```

Here, `app:backfill-ratings` is an application command you write. A small backfill might contain:

```php
use App\Models\Product;

Product::query()
    ->whereNull('rating_score')
    ->update(['rating_score' => 0]);
```

Assume the migration has already added a nullable `rating_score` column and zero is the intended initial value. Filtering missing values makes this update safe to repeat without overwriting existing scores.

For one small operation, an idempotent command can be enough. But leaving it in the deploy script runs it on every release; removing it makes the script stop describing that release's work. Multiple servers add another question: who executes the shared task?

A task ledger gives those decisions a permanent home in application code.

## Create a Tracked One-Time Task

[Pharaonic Laravel Executor](https://pharaonic.dev/packages/laravel/executor) stores deployment tasks in timestamped PHP files and records successful runs in the database.

The examples below use the Laravel 13 package line, which requires PHP 8.3–8.5. Check the package's requirements if your application uses an older Laravel release.

```bash
composer require pharaonic/laravel-executor
php artisan migrate
php artisan make:executor BackfillRatings
```

The package loads its table migration automatically. Replace the generated file's contents in `executors/` with:

```php
<?php

use App\Models\Product;
use Pharaonic\Laravel\Executor\Classes\Executor;
use Pharaonic\Laravel\Executor\Enums\ExecutorType;

return new class () extends Executor {
    public $type = ExecutorType::ONCE;

    public $tags = ['ratings'];

    public function up(): void
    {
        Product::query()
            ->whereNull('rating_score')
            ->update(['rating_score' => 0]);
    }

    public function down(): void
    {
        // Original values are not retained; use a corrective task if needed.
    }
};
```

Run it and inspect the result:

```bash
php artisan execute
php artisan execute:status
```

Run `execute` again: the recorded `ONCE` task is skipped. Keep its timestamped filename stable; it identifies the task.

**Recorded once does not mean arbitrary side effects can happen exactly once.** If work succeeds but the process stops before saving its record, a retry can repeat that work. Keep tasks idempotent, as the filtered update above is.

## Repeat Work on Every Deployment

Create another executor:

```bash
php artisan make:executor OptimizeRuntime
```

Replace its contents with:

```php
<?php

use Pharaonic\Laravel\Executor\Classes\Executor;
use Pharaonic\Laravel\Executor\Enums\ExecutorType;

return new class () extends Executor {
    public $type = ExecutorType::ALWAYS;

    public function up(): void
    {
        if ($this->command('optimize') !== 0) {
            throw new RuntimeException('Application optimization failed.');
        }
    }

    public function down(): void
    {
        if ($this->command('optimize:clear') !== 0) {
            throw new RuntimeException('Clearing optimization failed.');
        }
    }
};
```

`ALWAYS` means every invocation of `execute`, so call the runner during each deployment. `$this->command()` returns an exit code; throwing on failure prevents the executor from being recorded as successful.

Your deployment steps now become:

```bash
set -e

php artisan migrate --force
php artisan execute
```

Keep the other steps your application needs, including restarting long-running workers when deploying new code.

## Queue Expensive Work Without Blocking the Release

Inside an executor's `up()`, dispatch an existing application job:

```php
$this->job(\App\Jobs\RebuildSearchIndex::class)
    ->onQueue('search');
```

The job must implement Laravel's `ShouldQueue` contract to be queued, and a worker must consume that queue using an asynchronous connection. See [Laravel's queue documentation](https://laravel.com/docs/13.x/queues).

The executor records successful **dispatch**, not successful completion of the background job. Job failures and retries remain the queue's responsibility. If the release requires the rebuilt index immediately, dispatching it alone is insufficient.

## Coordinate Multiple Servers

Servers must share the executors database and a cache store that supports shared locks. For example, with Redis configured:

```dotenv
EXECUTOR_LOCK_STORE=redis
EXECUTOR_LOCK_SECONDS=600
```

`execute` takes a cache lock before reading execution history. The next server waits, then skips `ONCE` tasks already recorded by the first. Use the same cache namespace on every server and set the lock duration longer than the whole execution run; an expired lock can allow overlap.

For work limited to particular machines, add this property to an executor:

```php
public $servers = ['10.0.0.11', '10.0.0.12'];
```

`ONCE` still means once across the shared database, on the first eligible server. Use `ALWAYS` and invoke `execute` on each eligible server when every machine needs its own work.

## Select Tasks and Roll Back Deliberately

Tags let you run a smaller group:

```bash
php artisan execute --tags=ratings
```

Tasks first executed in the same invocation share a batch. Existing `ALWAYS` tasks retain their original batch while their execution count increases.

```bash
php artisan execute:rollback
php artisan execute:rollback --steps=3
```

These commands call `down()` for the selected batches and delete their records, allowing those tasks to run again. They do not undo queued jobs or automatically reverse data changes. Our backfill leaves `down()` empty because clearing every score would also destroy unrelated values.

Rollback runs on the machine where you invoke it, including tasks targeted to other servers. Choose that machine deliberately and review the batch before running it.

## How Does It Compare with One-Time Operations?

[One-Time Operations for Laravel](https://github.com/TimoKoerber/laravel-one-time-operations) is an established alternative with substantially broader adoption, as its [Packagist statistics](https://packagist.org/packages/timokoerber/laravel-one-time-operations) show. It already supports tags, synchronous or queued operations, status inspection, and command isolation.

The useful distinction is how each package models deployment work:

| Requirement | One-Time Operations | Pharaonic Executor |
| --- | --- | --- |
| Track completed one-time work | Recorded operations | `ExecutorType::ONCE` |
| Run work on every runner invocation | Documented model centers on one-time operations | `ExecutorType::ALWAYS` |
| Background work | Operations can themselves run asynchronously | Executors dispatch application jobs with `$this->job()` |
| Select groups | `--tag` | `--tags` |
| Coordinate runners | Opt-in `--isolated` | Lock taken by `execute` with a supporting cache store |
| Reverse a batch | No documented batch rollback command | `down()` and `execute:rollback` |
| Limit work to server IPs | No documented server-list property | `$servers` |

This compares documented workflows, not what custom PHP code could implement. Command isolation also does not by itself guarantee exactly-once background processing.

Choose One-Time Operations when its one-time, optionally asynchronous operation model matches your release process. Choose Executor when one-time and repeatable work, explicit reversals, and server targeting belong in the same workflow.

For a handful of harmless, repeatable commands, a deployment script remains a good choice. Add tracked tasks when execution history starts affecting whether a release is correct. The [Executor documentation](https://pharaonic.dev/packages/laravel/executor) covers configuration and the full command reference.
