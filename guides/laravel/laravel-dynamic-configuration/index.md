---
title: "Laravel Dynamic Configuration Without Redeploys: A Multi-Tenant Guide"
slug: "laravel-dynamic-configuration"
description: "Persist Laravel config overrides in the database, layer global and tenant scopes, and handle runtime loading, caching, and queues without redeploying."
category: "laravel"
tags:
  - laravel
  - configuration
  - multi-tenancy
  - eloquent
  - caching
  - queues
related_packages:
  - laravel-dynamic-config
published_at: "2026-11-04"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel dynamic configuration
  - laravel database configuration
  - laravel multi tenant config
  - laravel runtime configuration
  - laravel config cache
---

Your application serves three branches from one Laravel codebase. Each needs different `mail.from` values and external API options under `services.*`. Those configuration values need to vary by tenant while the application keeps reading them through Laravel's `config()` repository.

This guide builds that flow: save overrides, apply global → company → branch precedence, and keep ordinary `config()` calls throughout the application. It assumes existing `Company` and `Branch` models, a `Branch::company()` relationship, and an authenticated user's `branch` relationship.

> **Quick Answer:** Laravel can change configuration at runtime with `config(['key' => $value])`, but that change is not persistent. Persist configuration overrides in a database and load them into Laravel's config repository before consumers read them. For multiple tenants, explicitly load broader scopes first and narrower scopes last.

## The problem with configuration that lives only in files

Laravel's [configuration files](https://laravel.com/docs/13.x/configuration) and `.env` values are excellent for deployment-level defaults. They are versioned, predictable, and can be cached with `php artisan config:cache`.

Laravel already supports runtime changes without a package:

```php
config(['mail.from.address' => 'north@company.test']);

config('mail.from.address'); // north@company.test
```

That updates the current configuration repository; it does not persist the override for the next request.

File-based defaults apply to the application as a whole. When `mail.from.address` or `services.partner_api.options.timeout` must vary by company or branch, the application needs a way to resolve tenant-specific overrides at runtime. One approach is to store those overrides in a table and apply them to Laravel's config repository:

```php
$overrides = DB::table('branch_config_overrides')
    ->where('branch_id', $branch->getKey())
    ->pluck('value', 'key');

foreach ($overrides as $key => $jsonValue) {
    config()->set($key, json_decode($jsonValue, true, 512, JSON_THROW_ON_ERROR));
}
```

This example assumes an existing `branch_config_overrides` table with `branch_id`, `key`, and JSON-encoded `value` columns. A small loader can be enough, but the application must define its behavior:

- How do global, company, and branch values override one another?
- Do nested arrays merge, or does a new array erase existing options?
- How do you preserve booleans, integers, `null`, and lists?
- How does a configuration write invalidate a previously cached scope?
- How do queue workers avoid carrying one tenant's configuration into another job?

Laravel Dynamic Config addresses the persistence, scope-loading, merge, and cache-invalidation pieces while keeping the familiar Laravel config repository as the final destination.

| Concern | Custom database loader | Laravel Dynamic Config |
| --- | --- | --- |
| Persistence | You design the table and read/write layer | `Config::dynamic($scope)->set()` |
| Precedence | You implement global/company/branch ordering | You explicitly order `load()` calls |
| Nested values | You implement merge rules yourself | Associative arrays merge; lists replace |
| Caching | You design keys and invalidation | Scope-level caching and invalidation |
| Final consumption | Usually `config()` if you wire it correctly | Always Laravel's native `config()` |

The point isn't that custom code cannot do this. It's that configuration plumbing should be reliable, reusable infrastructure rather than a new maintenance task in every application.

## 1. Install the package

The examples here follow the **v13.x documentation**, which requires **Laravel 13 and PHP 8.3, 8.4, or 8.5**. If your application runs another Laravel major, check the package's [versioned documentation](https://pharaonic.dev/packages/laravel/dynamic-config/13.x) for the compatible release.

```bash
composer require pharaonic/laravel-dynamic-config
php artisan migrate
```

The provider is discovered automatically and the migration creates a `dynamic_configs` table. Each row stores a dotted configuration key, its JSON-encoded value, and an optional identifier.

| identifier | key | value (conceptually) |
| --- | --- | --- |
| `NULL` | `mail.from.name` | `"Platform Team"` |
| `company-9` | `mail.from.address` | `"hello@company.test"` |
| `branch-12` | `mail.from.address` | `"north@company.test"` |

You don't need to publish a config file for the default setup. If you want a specific database connection or cache store, run:

```bash
php artisan vendor:publish --tag=dynamic-config-config
```

The published file lives at `config/pharaonic/dynamic-config.php`.

## 2. Persist the defaults and overrides

Suppose your existing `config/mail.php` contains:

```php
'from' => [
    'address' => 'noreply@platform.test',
    'name' => 'Platform',
],
```

Application code, a seeder, or an Artisan command can persist global config overrides:

```php
use Illuminate\Support\Facades\Config;

Config::dynamic()->set('mail.from.name', 'Platform Team');
```

A named identifier is another independent scope:

```php
Config::dynamic('company-9')->set(
    'mail.from.address',
    'hello@company.test'
);

Config::dynamic('branch-12')->set(
    'mail.from.address',
    'north@company.test'
);
```

`dynamic()` accepts **one scope at a time**. The global scope uses `null`; a named scope uses a non-empty string. The current package also accepts a **saved Eloquent model**, which we'll use shortly.

**`set()` persists a value; it doesn't immediately alter the current `config()` result**. Application code sees it after the relevant scope is loaded.

## 3. Load the scopes in the order you want them to win

This is the heart of the package:

```php
Config::dynamic()->load();
Config::dynamic('company-9')->load();
Config::dynamic('branch-12')->load();

config('mail.from.address'); // north@company.test
config('mail.from.name');    // Platform Team
```

Think of the result as a priority chain:

```text
config/*.php
    ↓
Global scope (identifier = NULL)
    ↓
Company scope (company-9)
    ↓
Branch scope (branch-12)
```

The last loaded scope wins **for keys it defines**. Keys it doesn't define keep the value from an earlier layer.

Here's the detail that makes the API predictable:

```php
Config::dynamic('branch-12')->load();
```

**This loads only `branch-12`.** It does **not** automatically load the global scope or guess the branch's parent company. If you need both, call `load()` twice.

That makes it possible to deliberately choose a different priority when your application needs one.

## 4. Make it Eloquent-native

The named scopes above illustrate the API. Keep using those strings if you choose that convention: `branch-12` and a model scope such as `branch:12` are separate scopes, with no automatic migration between them.

For the rest of this guide, use model scopes consistently for both writes and loads. In a SaaS application you already have `Company` and `Branch` models. The package supports them directly:

```php
$branch = Branch::findOrFail(12);

Config::dynamic($branch)->set(
    'mail.from.address',
    'north@company.test'
);

Config::dynamic($branch)->load();
```

For model scopes, the stored identifier is based on the model's morph class and key, such as `App\Models\Branch:12`. This avoids collisions between, say, `Company #12` and `Branch #12`.

**Production tip:** Use a morph map before storing model-scoped values, so a future PHP namespace refactor doesn't silently change the scope identity:

```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'company' => \App\Models\Company::class,
    'branch' => \App\Models\Branch::class,
]);
```

Now the model identifier can be `branch:12`. Register your morph map consistently during application boot and before writing these values. A model must already be saved to be used as a scope.

## 5. Load tenant configuration in middleware

Now let's replace the scattered conditionals with one predictable entry point. Our application has authenticated users, each assigned to a branch that belongs to a company.

**`app/Http/Middleware/LoadBranchConfiguration.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class LoadBranchConfiguration
{
    public function handle(Request $request, Closure $next): Response
    {
        Config::dynamic()->load();

        $branch = $request->user()?->branch;

        if ($branch) {
            if ($branch->company) {
                Config::dynamic($branch->company)->load();
            }

            Config::dynamic($branch)->load();
        }

        return $next($request);
    }
}
```

Apply it to authenticated routes **after authentication and tenant identification**:

```php
use App\Http\Middleware\LoadBranchConfiguration;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', LoadBranchConfiguration::class])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class);
    });
```

From inside your controllers, mailables, or other application code:

```php
$sender = config('mail.from.address');
```

That is still Laravel's own configuration API. The package is only responsible for applying overrides *before* those consumers read them.

> **Security boundary:** Identify the branch through authenticated, authorized application state, not an unchecked request parameter such as `?branch_id=12`. Dynamic scopes isolate stored keys; they are not a substitute for tenant authorization.

## 6. Update or remove a persisted config override

Configuration writes can run in a provisioning command or another authorized application flow. For example, using the same saved branch model as the middleware:

```php
use App\Models\Branch;
use Illuminate\Support\Facades\Config;

$branch = Branch::findOrFail(12);

Config::dynamic($branch)->set(
    'mail.from.address',
    'north@company.test'
);

Config::dynamic($branch)->set(
    'services.partner_api.options.timeout',
    20
);
```

These calls persist two configuration overrides and invalidate that scope's cache. They do not mutate the current config repository. On the next request, the middleware applies global, company, and branch scopes before application code reads:

```php
config('mail.from.address');                    // north@company.test
config('services.partner_api.options.timeout'); // 20
```

No config-file change or redeployment is required. Keep the config keys under application control and validate incoming values before persisting them. Your mail provider must also permit the configured sender address or domain.

To remove the branch's sender override and inherit the company/global value again:

```php
Config::dynamic($branch)->forget('mail.from.address');
```

`forget()` removes the stored override from **that scope only**. It does not erase Laravel's base config or other scopes. If the old override is already loaded into the current request, it remains there; the inherited value becomes visible in a fresh request or job with the scopes loaded again. Calling `load()` again only applies stored overrides; it does not reset the repository to its file-based defaults.

## 7. Nested configuration without accidental data loss

A tenant override for one timeout should preserve unrelated service configuration. Dynamic Config applies these rules:

| Incoming value | Behavior on `load()` |
| --- | --- |
| Scalar or `null` | Replaces the target |
| Associative array | Recursively merges with an existing array |
| Sequential list (`0, 1, 2, …`) | Replaces the existing list |
| Empty array `[]` | Replaces the target with an empty array |
| Dotted key | Updates that leaf without deleting its siblings |

Here, a list means consecutive integer keys starting at zero. Sparse numeric keys count as an associative array. Prefer storing a configuration subtree either as a parent array or as dotted leaves; mixing both is harder to manage, and deleting a parent row does not delete independently stored child rows.

Suppose your application starts with:

```php
// config/services.php
'partner_api' => [
    'endpoint' => 'https://api.example.test',
    'options' => [
        'timeout' => 5,
        'retries' => 2,
    ],
],
```

A company can change just one nested option:

```php
Config::dynamic($company)->set('services.partner_api', [
    'options' => [
        'timeout' => 15,
    ],
]);

Config::dynamic($company)->load();

config('services.partner_api');
```

The resolved result keeps the endpoint and retries but updates the timeout:

```php
[
    'endpoint' => 'https://api.example.test',
    'options' => [
        'timeout' => 15,
        'retries' => 2,
    ],
]
```

You can also override just `services.partner_api.options.timeout` using a dotted key.

## 8. Stored values are not the same as resolved values

Two similar-looking calls answer two different questions:

```php
// What did this branch explicitly save?
Config::dynamic($branch)->get('mail.from.address');

// What configuration is Laravel using after all loaded scopes?
config('mail.from.address');
```

A scope's `get()`, `has()`, and `all()` inspect **only its own stored rows**. They don't fall back to the base config or to other scopes.

```php
$scope = Config::dynamic($branch);

$scope->get('mail.from.address', 'fallback@example.test');
$scope->has('mail.from.address');
$scope->all(); // Flat dotted-key array
```

A stored `null` is still a stored value: `has()` returns `true` even when `get()` returns `null`. This matters when `null` is an intentional override, not the absence of one.

## 9. What about performance and `config:cache`?

Dynamic Config caches the stored values **per scope**. `set()` and `forget()` invalidate the affected scope's cache entry rather than flushing the whole application cache.

Once you call `load()`, normal `config()` calls read Laravel's in-memory config repository. They do not run another database query for each access.

The package works alongside `php artisan config:cache`: Laravel's generated cache file stores the file-based configuration, while dynamic overrides are applied to runtime memory. They're never written into the generated config cache file.

For multi-server deployments, use a **shared cache backend**, such as Redis, when you want invalidations from one server to become visible to the others:

```dotenv
DYNAMIC_CONFIG_CACHE_STORE=redis
```

If your application stores the scopes in a central database, configure that too:

```dotenv
DYNAMIC_CONFIG_CONNECTION=central
```

The connection must exist in your application's database configuration. With file-based or in-process caches across multiple servers, use a finite TTL or another deliberate consistency strategy.

**Two operational details worth remembering:**

1. If a database client modifies the `dynamic_configs` table directly, it bypasses the package's automatic cache invalidation. Prefer `set()` and `forget()` for writes.
2. Loading a new config value doesn't rebuild an already-resolved service. Load scopes before using a mailer, client, or other service that reads configuration at initialization time.

## 10. Queues and Octane: avoid cross-tenant surprises

HTTP requests under normal PHP-FPM get a fresh application lifecycle. **Queue workers are different:** a worker can process many jobs with the same in-memory config repository.

If one job loads one branch and the next handles another, a key missing from the second branch does not clear the first branch's override. [Laravel job middleware](https://laravel.com/docs/13.x/queues#job-middleware) can wrap each job in a snapshot and restore:

```php
namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\Config;

class LoadBranchConfiguration
{
    public function handle(object $job, callable $next): void
    {
        $repository = config();
        $original = $repository->all();

        try {
            Config::dynamic()->load();

            if ($job->branch->company) {
                Config::dynamic($job->branch->company)->load();
            }

            Config::dynamic($job->branch)->load();

            $next($job);
        } finally {
            foreach (array_diff_key($repository->all(), $original) as $key => $value) {
                unset($repository[$key]);
            }

            $repository->set($original);
        }
    }
}
```

Attach it to a queued job that has a saved, authorized `Branch` model in its public `$branch` property:

```php
public function middleware(): array
{
    return [new \App\Jobs\Middleware\LoadBranchConfiguration];
}
```

The snapshot must be taken before tenant overrides are applied. Every tenant-aware job must follow the same lifecycle. Restoration also runs when loading a scope or executing the job throws. Newly introduced top-level keys become `null` under Laravel's config repository; previous top-level values are restored.

**Configuration restoration does not reset service instances.** A mailer or API client cached by a manager or singleton can retain the previous tenant's configuration. Use an explicit per-job client or the service's supported purge/rebuild API as part of the job lifecycle, including cleanup on failure. Test two different tenants in the same worker before relying on runtime configuration for that service.

Laravel Octane provides a request-scoped copy of the config repository, and the package uses that request's repository. Load the relevant scopes in request middleware as usual. Either way, don't assume an already-constructed service will automatically refresh after an override.

## When should you use Dynamic Config?

**Good fits:**

- SaaS applications where each company or branch has its own configuration overrides
- Application-wide feature switches used through `config()`
- Tenant-specific external service options
- Persisted config overrides that must take effect on subsequent requests without a deployment

**Not a replacement for:**

- Normal relational application data, such as orders, invoices, or user profiles
- Tenant authorization or access control
- A dedicated secret manager when you need specialized secret storage, rotation, and auditing
- A complete feature-flag platform with experiments, percentage rollouts, and targeting rules

If you need a normal CRUD record, make a normal model. If you need to override something your application already reads through `config()`, Dynamic Config is a natural fit.

Choose the smallest solution that fits: file configuration for deployment defaults, an application-owned loader for a few config overrides, or scoped persistence when several tenants need the same layering rules. Keep writes authorized, load scopes before consumers run, and treat long-running service instances as part of the tenant lifecycle.

See the [Dynamic Config documentation](https://pharaonic.dev/packages/laravel/dynamic-config/13.x) for the complete API.
