---
title: "How to Build Inherited Model Settings in Laravel Without Fallback Bugs"
slug: "laravel-inherited-model-settings"
description: "Design Laravel settings that inherit across companies and branches, distinguish missing values from null, and prevent unsafe tenant overrides."
category: "laravel"
tags:
  - laravel
  - settings
  - multi-tenancy
  - eloquent
  - settings-inheritance
  - tenant-overrides
related_packages:
  - laravel-settings
published_at: "2026-11-10"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel model settings
  - laravel settings inheritance
  - laravel multi tenant settings
  - laravel company branch settings
  - laravel settings fallback chain
  - laravel tenant setting overrides
  - laravel settings null vs missing
  - laravel typed settings validation
  - laravel database settings
  - pharaonic laravel settings
---

A company changes the footer printed on its receipts. Most branches should use the new text, a few branches should retain their own text, and one branch should intentionally print **no footer at all**. An administrator also needs to change the minimum checkout amount without deploying the application.

The difficult part is not storing `receipt.footer = "Thank you"`. It is preserving **three different intentions**: inherit the parent value, override it, or explicitly suppress it. If your read logic treats a missing setting as `null`, a branch can unexpectedly inherit text it meant to hide. If it stores every effective value as a branch override, later company changes never reach branches that should inherit them.

This guide designs that behavior with native Laravel first, including its limits. Only then does it consider a dedicated settings package. The examples assume an application with saved `Company` and `Branch` Eloquent models and a `Branch::company()` relationship.

> **Quick Answer / TL;DR:** Model an override as the *presence of a stored key*, not merely a non-null value. Resolve branch → company → global explicitly; a missing key continues to the parent, while a stored `null` stops inheritance. For a few fixed fields, model columns can be enough. A reusable settings layer becomes useful when many models and keys need the same type, validation, and cache rules.

## First Decide Whether You Need a Settings System

Laravel already has three reasonable ways to represent a value, depending on who owns it and how often its shape changes.

| Requirement | Start with | Why |
| --- | --- | --- |
| One value per deployment | Laravel configuration | Files and `.env` are easy to review and deploy |
| A few known fields on a branch | Eloquent columns | Database constraints and normal model updates are explicit |
| A small group of preferences owned by one model | JSON-cast attribute | Keeps related preferences on the same record |
| Many keys across global, company, and branch scopes | An overrides table | Separates value inheritance from model schema |

Changing the live configuration repository is not persistent settings storage:

```php
config(['checkout.minimum_cents' => 7500]);

// Available through config() in this application runtime,
// but not stored for future requests.
```

Laravel's [`config()` and configuration cache](https://laravel.com/docs/13.x/configuration) solve a deployment and runtime configuration problem. Here we are modeling *editable business preferences* that are read deliberately for a particular company or branch. They should not automatically overwrite the application's configuration repository.

## The Simple Native Solution: Use Model Columns

If the only custom value is a checkout threshold, a nullable column may be the clearest design:

```php
// In a migration that modifies the branches table:
Schema::table('companies', function (Blueprint $table) {
    $table->unsignedInteger('minimum_order_cents')->nullable();
});

Schema::table('branches', function (Blueprint $table) {
    $table->unsignedInteger('minimum_order_cents')->nullable();
});
```

Use an integer number of minor currency units rather than a float for an amount that requires exact arithmetic. With a non-null global default and an optional company-level column, a branch can inherit through the null coalescing operator:

```php
$minimumCents = $branch->minimum_order_cents
    ?? $branch->company->minimum_order_cents
    ?? 10000;
```

For this field, `null` *means inherit*, and a checkout minimum cannot be explicitly disabled. That is a valid business rule. There is no reason to introduce an abstract settings table for it.

However, `receipt.footer` has a different requirement. A branch may intentionally suppress the footer. If `null` means inherit, you cannot also use it to mean “print nothing.” You need another state, such as a separate override flag, a mode column, or the presence of a row in another table.

**The important design decision is what `null` means for each field.** Make that decision before picking an API or package.

## Represent the Three States Explicitly

For a growing catalog of preferences, a row-per-override table makes the distinction natural:

| Branch row | Stored JSON value | Meaning |
| --- | --- | --- |
| No `receipt.footer` row | Not applicable | Inherit company or global footer |
| `receipt.footer` row | `"Branch welcome"` | Use this branch's text |
| `receipt.footer` row | `null` | Deliberately show no footer |

In this table, `null` in the last row means the JSON literal `null` is stored, **not** SQL `NULL`. That allows a database lookup to distinguish “there was no row” from “the row says null.”

Create a small custom table if this is the extent of your requirements:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preference_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 191);
            $table->string('key', 191);
            $table->longText('value'); // JSON-encoded, never SQL NULL
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_overrides');
    }
};
```

For this example, scopes are `global`, `company:12`, and `branch:47`. They are internal identifiers chosen by the application, not user input. The table stores only **explicit overrides**; it does not copy inherited defaults into every branch.

## Resolve a Setting Without Losing Its Source

A resolver should return more than a value when an administrator needs to understand or reset inheritance. It should preserve whether a row was found and which scope supplied it.

The following service needs no package-specific API. Save it as `app/Support/PreferenceResolver.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class PreferenceResolver
{
    /**
     * @param list<string> $scopes Most specific scope first.
     * @return array{found: bool, value: mixed, source: ?string}
     */
    public function resolve(string $key, array $scopes, mixed $default = null): array
    {
        $rows = DB::table('preference_overrides')
            ->where('key', $key)
            ->whereIn('scope', $scopes)
            ->pluck('value', 'scope');

        foreach ($scopes as $scope) {
            if ($rows->has($scope)) {
                return [
                    'found' => true,
                    'value' => json_decode($rows->get($scope), true, 512, JSON_THROW_ON_ERROR),
                    'source' => $scope,
                ];
            }
        }

        return ['found' => false, 'value' => $default, 'source' => null];
    }
}
```

Use it after loading and authorizing the branch. The ordered chain is deliberate: a branch wins over its company, and the company wins over the application-wide value.

```php
use App\Support\PreferenceResolver;

$chain = [
    'branch:'.$branch->getKey(),
    'company:'.$branch->company_id,
    'global',
];

$result = app(PreferenceResolver::class)->resolve(
    'receipt.footer',
    $chain,
    'Thank you for shopping',
);

$footer = $result['value'];
$owner = $result['source']; // Useful for an "Inherited from company" label.
```

This uses one query for all candidate scopes and checks *key presence*, not value truthiness. A stored `null`, `false`, `0`, or empty string can all be deliberate overrides. PHP's `??`, `empty()`, or a truthy check on the decoded value would erase some of these distinctions.

The resolver also has a predictable default: only when **no scope has the key** does it return the supplied fallback. Defaults are not automatically persisted, and a change to the company does not require rewriting every branch record.

## Writing an Override Is Different from Removing One

To suppress the company footer, persist the JSON literal `null` for the branch. For a simple native implementation, `upsert()` is preferable to a read-then-insert sequence when requests may race:

```php
use Illuminate\Support\Facades\DB;

$now = now();

DB::table('preference_overrides')->upsert([
    [
        'scope' => 'branch:'.$branch->getKey(),
        'key' => 'receipt.footer',
        'value' => json_encode(null, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ],
], ['scope', 'key'], ['value', 'updated_at']);
```

If the branch administrator chooses **Use company default**, delete that row instead:

```php
DB::table('preference_overrides')
    ->where('scope', 'branch:'.$branch->getKey())
    ->where('key', 'receipt.footer')
    ->delete();
```

That action is not equivalent to storing `null`. Deleting the row enables inheritance again; storing `null` explicitly blocks it. Give these actions distinct labels in the admin interface.

The unique index prevents duplicate keys within a scope. Laravel's [upsert documentation](https://laravel.com/docs/13.x/queries#upserts) explains the database-specific behavior and index requirements. This solves duplicate insertion, not every race in a larger read-modify-write workflow.

## Where a Small Custom Implementation Starts to Cost More

The native resolver is short because it deliberately omits the policies that a reusable settings system eventually needs:

- **Type stability.** What prevents one request from writing integer `7500` and another from writing string `"7500"` for the same key? JSON preserves types, but does not enforce a consistent one across writes.
- **Validation across entry points.** A controller may validate `minimum_order_cents`, while a queue job, import script, or Artisan command writes a negative value. The invariant belongs in a shared write boundary.
- **Override metadata.** Admin screens need to show the effective value, whether it is inherited, and what happens when an override is removed.
- **Concurrency and cache invalidation.** An `upsert()` guards a unique key, but caching must invalidate the affected scope after a committed write, including when the write happens inside an outer transaction.
- **Model identity and lifecycle.** Manually constructed `company:12` strings need consistent rules across models, morph aliases, deletion, and data migrations.
- **Tenant authorization.** Knowing a scope identifier is not proof that the current user can edit it. Authorization must happen before either read or write.

Do not hide all of these behind a generic `getSetting()` helper and assume the complexity is gone. A tiny, single-model preference table might never need most of them. But once several teams are reimplementing the same resolver, validator, writer, and cache policy, a reusable component becomes worthwhile.

A useful rule is to keep the business schema explicit and the override engine generic. The engine should not decide who is authorized, what “minimum order” means, or whether a footer can be disabled.

## When Pharaonic Laravel Settings Fits

[Pharaonic Laravel Settings](https://pharaonic.dev/packages/laravel/settings) packages the reusable parts: an Eloquent `Setting` model, global/named/model scopes, typed values, optional definitions, explicit fallback order, and per-scope caching. It does **not** discover a company's branches or authorize a request automatically.

The current **13.0.0** release targets Laravel 13 and PHP 8.3–8.5. Earlier Laravel major versions have their own release lines. In a compatible application:

```bash
composer require pharaonic/laravel-settings
php artisan migrate
php artisan vendor:publish --tag=settings-config
```

The interesting part is not the number of helper methods. It is that an important key can have the same contract no matter which scope writes it. For the example above, define two different kinds of preferences in `config/pharaonic/settings.php`:

```php
// Entries inside the returned config array:
'definitions' => [
    'checkout.minimum_cents' => [
        'type' => 'integer',
        'nullable' => false,
        'default' => 10000,
        'rules' => ['min:0'],
    ],
    'receipt.footer' => [
        'type' => 'string',
        'nullable' => true,
        'default' => 'Thank you for shopping',
    ],
],
```

This says that a checkout minimum is a non-negative integer and that the footer may deliberately be null. The default is used only when there is no stored value in the selected fallback chain; it is not a row copied to every model.

Add `Pharaonic\Laravel\Settings\Concerns\HasSettings` to your saved `Company` and `Branch` models. With `$branch` and `$company` already resolved and authorized, the same inheritance rule becomes:

```php
$minimumCents = settings($branch)
    ->fallbackTo($company)
    ->fallbackToGlobal()
    ->getValue('checkout.minimum_cents');

$footer = settings($branch)
    ->fallbackTo($company)
    ->fallbackToGlobal()
    ->getValue('receipt.footer');
```

The two distinct admin actions remain straightforward:

```php
$branch->setSetting('receipt.footer', null); // Explicitly suppress footer.
$branch->forgetSetting('receipt.footer');    // Go back to inheriting.
```

To see why a branch displays a particular value, use the scoped builder's `explainValue('receipt.footer')` result, which includes the source scope and whether the value was inherited. To edit a stored value, `setSetting()` writes immediately; there is no separate model `save()` step.

**A type is not merely a serialization hint.** New keys infer their type from their first non-null value unless one is declared; later writes cannot silently change that stored type. The default casting policy is `strict`. The optional `safe` policy accepts only selected unambiguous conversions, rather than PHP's loose casts. Change a stored type intentionally with `retypeValue()` after reviewing the data.

These are reasons to adopt the package when the same invariants recur across models. They are not reasons to replace a perfectly clear nullable column with a settings row.

## Keep the Critical Boundaries in Your Application

Even with a settings package, three responsibilities remain yours.

**Authorization:** Route-model bind and authorize the actual branch before reading or writing preferences. An identifier such as `company:12` is a locator, not a security boundary. For a form, use Laravel's policies and [request validation](https://laravel.com/docs/13.x/validation) before allowing changes.

```php
// Inside a controller action that has resolved $branch:
Gate::authorize('update', $branch);

$validated = $request->validate([
    'minimum_cents' => ['required', 'integer', 'min:0'],
]);

$branch->setSetting('checkout.minimum_cents', (int) $validated['minimum_cents']);
```

The request validation defines the form's accepted input; the package definition protects the stored setting against writes from other parts of the application. Keep exact monetary amounts in integer minor units rather than floats.

**Consistency:** When several fields must change together, use a transaction on the relevant database connection. The package's `setMany()` stores values for one scope atomically and invalidates its scope cache after the outer transaction commits. If settings use a *different* database connection from the business records, a transaction on the default connection does not automatically cover both.

**Operations:** A model's settings are not an Eloquent relation. `HasSettings::settings()` returns a scoped builder, so `with('settings')` is not supported. Deleting a model does not purge its settings by default; force-delete purging is configurable, but bulk query deletes bypass model events. Shared cache storage matters when several servers serve the same tenants. Direct SQL writes bypass the package's type checks and cache invalidation.

Do not store API secrets as ordinary settings: the current release does not support encrypted definitions and rejects that option rather than writing purportedly encrypted values as plaintext.

## Test Intent, Not Just Returned Values

The important regression test is a state transition: **inherit → override → suppress → inherit again**. With `Company` and `Branch` factories, the `HasSettings` trait on both models, and the `receipt.footer` definition above, a Pest test can exercise it:

```php
<?php

use App\Models\Branch;
use App\Models\Company;

it('distinguishes a missing footer from an explicitly hidden one', function () {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();

    $company->setSetting('receipt.footer', 'Company footer');

    $read = fn () => settings($branch)
        ->fallbackTo($company)
        ->fallbackToGlobal()
        ->getValue('receipt.footer');

    expect($read())->toBe('Company footer');

    $branch->setSetting('receipt.footer', 'Branch footer');
    expect($read())->toBe('Branch footer');

    $branch->setSetting('receipt.footer', null);
    expect($read())->toBeNull();

    $branch->forgetSetting('receipt.footer');
    expect($read())->toBe('Company footer');
});
```

This example assumes the app's factories and `Branch::company()` relation already exist. Add separate tests for an invalid checkout amount, writes of the wrong PHP type, unauthorized scopes, and rollback behavior. If you keep the native resolver instead, run the same behavioral test against it. The contract matters more than the implementation chosen.

## Don't Confuse Model Settings with Dynamic Laravel Configuration

There is an important boundary between these two use cases:

- **Model preferences:** Code intentionally asks what *this branch* should do. Read through a settings resolver or scoped settings model. Do not change global `config()` state for another tenant's benefit.
- **Runtime framework configuration:** An integration must continue using `config('mail.from.address')` or `config('services.partner.timeout')`, with database-backed overrides injected into Laravel's configuration repository. That is a different loading and lifecycle problem, covered in [Laravel Dynamic Configuration Without Redeploys](/guides/laravel/laravel-dynamic-configuration).

Conflating the two can cause cross-tenant behavior in queues or long-running workers. Pick a boundary based on how the consuming code reads the value, not on which package has the most helpers.

## Choose the Smallest Design That Preserves Intent

If you have two known preferences and no inheritance, ordinary model fields are probably the right solution. If you have a few tenant-specific settings, a JSON attribute or a small overrides table may be enough. When keys multiply across models, values need stable types, and several services depend on the same fallback rules, a dedicated settings model can eliminate duplicated infrastructure.

Whichever route you choose, keep the essential rule visible in code and in the admin interface: **missing means inherit; stored null means an intentional value**. That distinction prevents more real-world configuration bugs than any particular convenience method.
