---
title: "How to Clone Eloquent Models with Nested Relationships Safely in Laravel"
slug: "clone-eloquent-models-with-relationships"
description: "Duplicate Eloquent models with nested relations while preserving shared records, rebuilding foreign keys, and avoiding partial or unsafe copies."
category: "laravel"
tags:
  - laravel
  - eloquent
  - model-replication
  - relationships
  - data-integrity
  - database-transactions
related_packages:
  - laravel-deep-replicate
published_at: "2026-11-12"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel replicate model with relationships
  - clone eloquent model with relations
  - laravel deep replicate
  - duplicate eloquent records
  - copy nested has-many relationships laravel
  - replicate belongs to many pivot laravel
  - laravel model clone transaction
  - clone eloquent graph safely
  - laravel replicate unique column
  - laravel deep replicate vs replicate
---

A customer clicks **Duplicate order** to prepare a new quote. The new order needs its own items and item options, but those items must still point to the *existing* products. Its customer remains the same, while payments, shipment history, and other activity must not be duplicated. If an insert fails halfway through, the application must not leave a half-built order behind.

This is a data-ownership problem, not simply a question of copying more rows. Laravel's `replicate()` handles one model well. The hard part is deciding which relationships own new records, which reference shared data, and which should not participate at all.

> **Quick Answer / TL;DR:** Use Eloquent's `replicate()` for a single record. For a small, fixed graph, clone each owned child inside `DB::transaction()` and reassign its foreign key to the new parent; leave shared foreign keys pointing to their original records. For larger selectable graphs, use explicit `copy` and `reuse` rules, with a preview and database constraints to verify the result.

## Draw the Ownership Boundary Before Copying Anything

Suppose an `Order` has the following relationships:

```text
Order
├── customer                 BelongsTo: shared customer
├── items                    HasMany: order-owned rows
│   ├── options              HasMany: item-owned rows
│   └── product              BelongsTo: shared catalog product
└── payments                 HasMany: historical financial activity
```

The intended result is **one new order, new items, new options, but no new customer, products, or payments**. The source order and all of its relationships stay unchanged.

A useful decision table is:

| Relation | Desired behavior | Reason |
| --- | --- | --- |
| `items`, `items.options` | Copy | Each new order needs independently editable rows. |
| `customer`, `items.product` | Reuse | Identity belongs to another domain record. |
| `payments` | Omit | Copying a historical payment could imply money moved when it did not. |

These decisions are application rules, not properties Eloquent can infer from relation types alone. A `BelongsTo` relation can represent either a shared catalog product or a parent record you actually want to clone. Think in terms of **ownership and identity** first, then choose the API.

## What Laravel's replicate() Actually Copies

Laravel provides a native model-level clone:

```php
use App\Models\Order;

$order = Order::query()->findOrFail($orderId);

$copy = $order->replicate();
$copy->status = 'draft';
$copy->save();
```

`replicate()` produces an unsaved model with copied attributes, excluding the original primary key and its standard managed timestamps. It is useful for duplicating a single address, draft, or configuration row. It does **not** create independent rows for the order's children. Saving the new `Order` leaves `items` and `items.options` in the database attached to the old order.

One subtlety: the native clone may carry already-loaded relation objects **in memory**. They are not fresh copies of those related database records. Clear them with `$copy->unsetRelations()` when you do not intend to use that inherited in-memory state. Nor is Eloquent's `push()` a general-purpose deep-cloning API; its job is to save a model and its loaded relationships, not allocate new identities and relink a graph.

See Laravel's [model replication reference](https://laravel.com/docs/13.x/eloquent#replicating-models) and [relationship persistence documentation](https://laravel.com/docs/13.x/eloquent-relationships#inserting-and-updating-related-models).

## Duplicate a Small Graph with Native Laravel

Assume integer-keyed Eloquent models with standard relations named `items`, `options`, `product`, and `customer`. The order has a **unique** `number` and a `status`; each item has `order_id`, `product_id`, and `state`; each option has `order_item_id`. Adapt these names to your schema.

You can solve this particular graph without installing anything:

```php
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// $order is an existing, persisted Order model.
$newOrder = DB::transaction(function () use ($order): Order {
    $copy = $order->replicate()->unsetRelations();
    $copy->number = 'DRAFT-'.Str::upper(Str::random(16));
    $copy->status = 'draft';
    $copy->save();

    foreach ($order->items()->with('options')->get() as $item) {
        $newItem = $item->replicate()->unsetRelations();
        $newItem->order_id = $copy->getKey();
        $newItem->state = 'pending';
        $newItem->save();

        foreach ($item->options as $option) {
            $newOption = $option->replicate()->unsetRelations();
            $newOption->order_item_id = $newItem->getKey();
            $newOption->save();
        }
    }

    return $copy;
});
```

Notice what the code **does not** change: `customer_id` on the order and `product_id` on each item. Their original values still identify shared records. `payments` are never traversed, so no new payment rows are created. `with('options')` prevents an extra option query for every item, while `DB::transaction()` rolls back the database inserts together if an exception occurs.

The generated order number still needs a database unique constraint; random generation reduces collisions but does not replace the constraint. Also verify the unique fields and observers on child models before copying them. Laravel documents the [database transaction behavior](https://laravel.com/docs/13.x/database#database-transactions).

For one fixed parent-child structure, this is often the clearest solution. You can see precisely which rows are created and where each foreign key changes.

## Where Hand-Written Graph Copying Becomes Fragile

Now the product manager asks for a second duplication mode: optionally include only active items, copy each item's discounts, keep shared tags with their pivot metadata, and support an article-like polymorphic attachment elsewhere in the product.

The problem stops being the number of `foreach` loops. You now need to maintain several invariants:

- **Identity:** if two selected relation paths reach the same database row, do you create one copy or two?
- **Dependency order:** a copied `BelongsTo` parent must exist before its child can reference its new key.
- **Pivots:** a new association needs a new pivot row, but the related record may need to remain shared. Some pivot tables also carry extra columns and unique constraints.
- **Safety:** each copy needs its own unique business identifiers, and all database writes must succeed or fail together.
- **Selection:** a constrained eager load, a global scope, and a filtered query are not equivalent sources of rows.

A dedicated application service can manage all of this. However, when several features need configurable graph duplication, it is reasonable to reuse a graph replication engine instead of maintaining separate traversal and identity-map implementations.

## Express the Copy Policy with Laravel Deep Replicate

[Pharaonic Laravel Deep Replicate](https://pharaonic.dev/packages/laravel/deep-replicate) exposes explicit path rules rather than silently walking every relationship. The examples below use **13.0.0**, released for Laravel 13 with PHP 8.3–8.5. The project also publishes separate release lines for earlier supported Laravel majors; select the line compatible with your application.

```bash
composer require pharaonic/laravel-deep-replicate
```

Its service provider and facade are auto-discovered. No package migration is required. For the same order example:

```php
use Illuminate\Support\Str;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;

$result = DeepReplicate::for($order)
    ->copy('items.options')
    ->reuse('items.product', 'customer')
    ->with(['status' => 'draft'])
    ->generate('number', fn () => 'DRAFT-'.Str::upper(Str::random(16)))
    ->configure('items', fn (RelationRule $rule) => $rule->with([
        'state' => 'pending',
    ]))
    ->replicate();

$newOrder = $result->model();
```

`items.options` implies copying its `items` parent. Both paths create new records; the engine links each copied option to its copied item. `reuse('items.product', 'customer')` retains existing products and customer records. An unselected `payments` relation remains unvisited.

The output is a **persisted** root model, not an unsaved Eloquent clone. The whole selected graph is planned, written, and linked in one database transaction. Unlike a method that recursively follows whatever happens to be loaded, the selected paths describe the graph intentionally.

You can also add `Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates` to the `Order` model and start with `$order->deepReplicate('items.options')`. That method returns the same builder; Laravel's original `replicate()` remains unchanged. A deep-replication operation **requires at least one `copy` or `reuse` path**; for root-only copying, use Eloquent's native method. A builder can be previewed repeatedly before execution but can execute `replicate()` only once.

### Copy, reuse, and skip are not interchangeable

| Strategy | What happens | Common use |
| --- | --- | --- |
| `copy('items')` | New related model rows with new identities | Owned order lines |
| `reuse('customer')` | Link to the same related row | Existing customer |
| `reuse('tags')` | Keep the related tags, create new pivot rows | Shared taxonomy |
| `skip('payments')` | Do not traverse or relink that path | Historical activity |

**Important:** `skip()` does not clear a foreign key already stored on the model being copied. An unselected or skipped `BelongsTo` relation can continue pointing to the original parent. This is often desirable for a customer or product, but dangerous for tenant boundaries or workflow ownership. If you want a different value for an *unselected* key, set it deliberately with `with()`, after authorization and validation.

The engine rejects `reuse()` on `HasOne`, `HasMany`, `MorphOne`, and `MorphMany` because reassigning those children would take them away from the original parent. Through relations (`HasOneThrough`, `HasManyThrough`) and custom relation classes are not traversed for copying. Those limits matter when designing your path policy.

## Keep Shared Many-to-Many Records Without Losing Pivot Data

Suppose orders have a `tags()` `BelongsToMany` relation with an extra `approved_at` pivot column. Duplicating a tag record would be wrong, but losing tag-specific pivot data might also be wrong.

```php
use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;

$result = DeepReplicate::for($order)
    ->copy('items.options')
    ->reuse('tags')
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)))
    ->transformPivot('tags', function (
        array $attributes,
        ?Model $sourcePivot,
        ReplicationContext $context
    ): array {
        $attributes['approved_at'] = null;

        return $attributes;
    })
    ->replicate();
```

This creates **new pivot rows pointing to the existing tags**. The original pivots and tag models remain unchanged. The transform resets the approval field for the new association. The package copies pivot attributes by default, including fields not configured with `withPivot()` when it queries the database itself; it does not collapse duplicate pivot rows with `sync()`.

Be careful with independent unique columns on pivot tables. A copied token or external reference may violate its unique constraint unless you replace it in `transformPivot()`. For a many-to-many path, `copy('tags')` has a different meaning: create new tag models as well as new pivot links. See Laravel's [many-to-many relationship documentation](https://laravel.com/docs/13.x/eloquent-relationships#many-to-many-relationships).

## Control Which Source Rows Are Used

A previously eager-loaded relationship may contain only a subset. A user interface could have loaded the five newest items, but the duplication action should usually consider **all** items. Deep Replicate queries selected relationships afresh by default, honoring their relation constraints and global scopes.

When duplication rules require a subset, specify the constraint explicitly:

```php
$result = DeepReplicate::for($order)
    ->copy('items.options')
    ->filter('items', fn ($query) => $query->where('state', 'active'))
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)))
    ->replicate();
```

When a precisely constrained, already-loaded snapshot is itself the intended input, use `usingLoaded()` instead. Every requested path must be loaded on all relevant source models:

```php
$order->load('items.options');

$result = DeepReplicate::for($order)
    ->copy('items.options')
    ->usingLoaded('items', 'items.options')
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)))
    ->replicate();
```

Do not combine `filter()` and `usingLoaded()` on the same path; the builder rejects that conflict. And remember that a loaded pivot only carries columns that were loaded. If those rows require additional pivot columns, eager-load them with `withPivot()` or let the engine query the source relations itself.

## Preview the Graph and Enforce Operational Limits

A destructive database operation deserves a cheap way to inspect its *scope*, even if the plan itself is read-only. Build the rule set once, inspect it, then replicate it:

```php
$builder = DeepReplicate::for($order)
    ->copy('items.options')
    ->reuse('items.product', 'customer')
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)));

$plan = $builder->dryRun();

if ($plan->estimatedModels() > 3000 || $plan->estimatedPivotRows() > 5000) {
    throw new DomainException('This order is too large to duplicate.');
}

foreach ($plan->warnings() as $warning) {
    logger()->warning('Replication plan warning', [
        'code' => $warning->code(),
        'path' => $warning->path(),
        'message' => $warning->message(),
    ]);
}

$result = $builder
    ->maxModels(3000)
    ->maxPivotRows(5000)
    ->replicate();

$result->stats()->copiedModels();
$result->stats()->reusedModels();
$result->stats()->pivotRowsCreated();
```

`dryRun()` issues planning queries but writes no database rows and does not run model events, generators, transforms, or lifecycle callbacks. Its estimates can become stale if source data changes; `replicate()` plans again within its transaction. The limits are checked before inserts, so you should keep them on the actual operation even after previewing.

The defaults are a maximum relation depth of **10**, **10,000** planned models (copied and reused), **50,000** pivot rows, and a relation/pivot chunk size of **500**. The engine still holds the planned graph in memory and inserts copied models individually. For very large datasets, a streaming, domain-specific batch job can be more appropriate.

## Validate Business Rules the Engine Cannot Know

A graph engine can preserve database relationships, but it cannot decide whether a business operation is appropriate. Production duplication needs a few deliberate checks.

### Unique business identifiers

Primary keys are regenerated; unique slugs, invoice numbers, SKUs, or external IDs are **not** automatically suffixed. Use `generate()` or `configure()` for relevant copied paths, and enforce uniqueness in the database. Do not retry arbitrary replication callbacks after a unique failure unless you have designed them to be safe to run again.

### Tenant and authorization boundaries

Global scopes stay active by default, but a scope is not a substitute for an authorization check. Verify that the actor can read the source and create the copy in the target context. A copied root may retain `tenant_id` or `customer_id` if those fields are unselected; a copied child's tenant key may also need an explicit policy. The `withoutGlobalScopes()` option removes **all** relation-query global scopes, including tenant and soft-delete scopes, so it should never be the default workaround for missing children.

### Transactions, model events, and external effects

Replication runs in one transaction on the source model's database connection; it rejects graphs spanning different connections. Eloquent events run unless `withoutEvents()` is requested. Database rollback cannot undo email, HTTP requests, file copies, or other external work started by observers. Schedule those effects after commit, or use a transactional outbox.

For example:

```php
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;

DeepReplicate::for($order)
    ->copy('items.options')
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)))
    ->afterCommit(function (ReplicationResult $result): void {
        // Dispatch your application job only after the outer transaction commits.
        \App\Jobs\IndexClonedOrder::dispatch($result->model()->getKey());
    })
    ->replicate();
```

The job shown is an **application-owned job** you would implement. The package does not copy uploaded files or cloud objects when a copied model contains their paths. It does not make external side effects transactional.

### Cycles and mixed relation graphs

The engine deduplicates copied records by identity across selected paths and can resolve some foreign-key cycles by temporarily inserting a nullable link and updating it later. A non-nullable cycle that cannot be broken safely fails and rolls back. `MorphTo` and many polymorphic relation types are supported, but through relations and arbitrary custom relation classes are not. Test your specific graph, especially if a polymorphic path reaches models with different relation definitions.

## Test the Data Invariants, Not Just the Return Value

For an order seeded with at least one item and its options, the result should prove that new and shared identities were handled differently. In a PHPUnit feature test, after creating the fixture `$order`:

```php
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;

$oldItem = $order->items()->firstOrFail();
$oldProductId = $oldItem->product_id;
$oldOptionCount = $oldItem->options()->count();

$result = DeepReplicate::for($order)
    ->copy('items.options')
    ->reuse('items.product', 'customer')
    ->generate('number', fn () => 'DRAFT-'.bin2hex(random_bytes(8)))
    ->replicate();

$copy = $result->model();
$newItem = $result->map()->get($oldItem);

$this->assertNotEquals($order->getKey(), $copy->getKey());
$this->assertNotEquals($oldItem->getKey(), $newItem->getKey());
$this->assertEquals($oldProductId, $newItem->product_id);
$this->assertEquals($order->customer_id, $copy->customer_id);
$this->assertSame($oldOptionCount, $newItem->options()->count());
$this->assertSame(0, $copy->payments()->count());
```

The final assertion assumes your `Order` model has a `payments()` relationship. Add separate failure-path tests for uniqueness violations, observer exceptions, tenant-scoped data, and pivot constraints. In each test, assert that **no partial copy was committed** and that the source still references its original children.

## When to Use Each Approach

| Situation | Prefer |
| --- | --- |
| Clone only a single model with adjusted attributes | Native `replicate()`. |
| Copy a fixed one- or two-level graph in one feature | A small, explicit `DB::transaction()` service. |
| Repeatedly duplicate configurable nested graphs with mixed shared and owned relations | Deep Replicate with explicit path policies. |
| Rebuild domain data using bespoke pricing, inventory, payments, or other irreversible rules | A dedicated domain workflow; reuse the package only for the safe subgraph, if useful. |
| Copy millions of records, cross-database data, or binary assets | A specialized migration/batch/export process, not a single in-memory graph replication. |

The goal is not to make `replicate()` recursive by default. It is to preserve the meaning of each relationship as new identities are allocated. Start with native Eloquent when its explicitness is an advantage; introduce a graph engine when the same ownership and linking problems recur across features.

For the complete option reference and support matrix, consult the [package documentation](https://pharaonic.dev/packages/laravel/deep-replicate) and the [13.x source and tests](https://github.com/Pharaonic/laravel-deep-replicate/tree/13.x).
