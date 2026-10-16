---
title: "How to Compare Nested Arrays in PHP: Beyond array_diff()"
slug: "compare-nested-arrays"
description: "Learn how to compare nested PHP arrays, report added, removed, and modified values at exact paths, and know when array_diff() is still enough."
category: "php"
tags:
  - php
  - arrays
  - diff
  - data-comparison
related_packages:
  - php-diff
published_at: "2026-10-16"
status: "published"
---

Comparing two arrays sounds like a solved problem: PHP ships `array_diff()` and a whole family of related functions. They work well for flat lists of values. Once your data is nested, such as a user profile with an address, an order with line items, or a decoded JSON payload, they stop answering the question you are actually asking: *what changed, and where?*

This guide starts with what the native functions do well, shows exactly where they break down, builds a small recursive comparison you can use today, and then covers the edge cases that decide whether a homemade solution is enough.

> **Quick Answer / TL;DR:**
> For flat PHP arrays, `array_diff()` is usually enough. For nested structures where you need added, removed, and modified values with their exact paths, use a recursive comparison or a dedicated diff abstraction.

## What array_diff() Does Well

[`array_diff()`](https://www.php.net/manual/en/function.array-diff.php) returns the values of the first array that do not appear in any of the others:

```php
$installed = ['php', 'laravel', 'pest'];
$required = ['php', 'pest'];

array_diff($installed, $required);
// [1 => 'laravel']
```

For a flat list of strings, this is exactly right. It is fast, built in, and easy to read. Its siblings cover the common variations:

| Function | Compares |
| --- | --- |
| `array_diff()` | Values only |
| `array_diff_key()` | Keys only |
| `array_diff_assoc()` | Keys and values together |
| `array_udiff()` / `array_diff_ukey()` | Values or keys, using your own callback |

If you only need to know which tags were removed from a list, stop here. You do not need anything else.

## Where It Breaks Down

Problems appear as soon as the data has structure.

### It Only Looks One Way

`array_diff()` answers "what is in the first array but not the second?" Nothing more. Values that were *added* are invisible:

```php
array_diff(['php', 'pest'], ['php', 'pest', 'pint']);
// [] — 'pint' was added, but the result is empty
```

To see both sides you have to call it twice, with the arguments swapped, and merge the results yourself.

### It Compares Values as Strings

Two values are considered equal when their string representations are equal (`(string) $a === (string) $b`). That hides real type changes:

```php
array_diff(['id' => 1, 'active' => true], ['id' => '1', 'active' => '1']);
// [] — the integer 1 and the string '1' look identical
```

`null` and `''` are also treated as equal. For audit logs or API payloads, a change from `1` to `"1"` can be exactly the bug you are looking for.

### It Does Not Look Inside Nested Arrays

This is the big one. Here a user moved from Cairo to Giza:

```php
$before = ['name' => 'Raggi', 'address' => ['city' => 'Cairo', 'zip' => '11511']];
$after = ['name' => 'Raggi', 'address' => ['city' => 'Giza', 'zip' => '11511']];

array_diff($before, $after);
// Warning: Array to string conversion (twice)
// []
```

Both nested arrays are converted to the string `"Array"`, so they compare as equal. You get two warnings and an empty result, and the change is lost. `array_diff_assoc()` behaves the same way. None of the native functions recurse.

## Three Questions, Not One

When you compare two versions of structured data, you usually want three separate answers:

- **Added**: a key exists in the new data but not in the old.
- **Removed**: a key existed in the old data but not in the new.
- **Modified**: a key exists in both, but its value is different.

Mixing them together, as a one-sided "difference" does, makes it impossible to say whether a value disappeared or was replaced.

## Why Paths Matter

In nested data, *where* a change happened is as important as *what* changed. "The city changed" is useful; "something inside `address` changed" is not, especially when `address` has ten keys.

A path describes the location of a value as a list of keys. In dot notation, `address.city` means `$data['address']['city']`, and `roles.1` means the second item of the `roles` list. Paths make a diff readable for humans and usable by code: you can check `if the path is billing.iban, alert finance` without walking the structure again.

## A Recursive Comparison

Here is a small function that walks both arrays, recurses into nested arrays, compares strictly, and records every change with its path:

```php
function diffArrays(array $before, array $after, string $path = ''): array
{
    $changes = [];

    foreach ($before as $key => $oldValue) {
        $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

        if (! array_key_exists($key, $after)) {
            $changes[] = ['type' => 'removed', 'path' => $keyPath, 'old' => $oldValue];

            continue;
        }

        $newValue = $after[$key];

        if (is_array($oldValue) && is_array($newValue)) {
            array_push($changes, ...diffArrays($oldValue, $newValue, $keyPath));
        } elseif ($oldValue !== $newValue) {
            $changes[] = ['type' => 'modified', 'path' => $keyPath, 'old' => $oldValue, 'new' => $newValue];
        }
    }

    foreach ($after as $key => $newValue) {
        if (! array_key_exists($key, $before)) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;
            $changes[] = ['type' => 'added', 'path' => $keyPath, 'new' => $newValue];
        }
    }

    return $changes;
}
```

Two details matter here:

- It uses [`array_key_exists()`](https://www.php.net/manual/en/function.array-key-exists.php), not `isset()`. `isset()` returns `false` for a key whose value is `null`, so a key set to `null` would be reported as removed.
- It uses `!==`, so `1` and `'1'` are different. See [comparison operators](https://www.php.net/manual/en/language.operators.comparison.php) for how loose comparison would treat them.

Using it on a slightly bigger example:

```php
$before = [
    'name' => 'Raggi',
    'address' => ['city' => 'Cairo', 'zip' => '11511'],
    'phone' => '0100',
    'roles' => ['admin'],
];

$after = [
    'name' => 'Raggi',
    'address' => ['city' => 'Giza', 'zip' => '11511'],
    'email' => 'raggi@example.com',
    'roles' => ['admin', 'editor'],
];

foreach (diffArrays($before, $after) as $change) {
    echo $change['type'], ' ', $change['path'], PHP_EOL;
}
```

```text
modified address.city
removed phone
added roles.1
added email
```

Every change is reported once, at its exact location, with its type. For many applications, such as comparing configuration arrays or showing what a form submission changed, this function is all you need.

## Edge Cases to Decide Up Front

The recursive function is short because it quietly makes decisions. Before relying on it, check that they match your data.

### Lists Are Compared by Position

The function treats `0, 1, 2…` keys like any other key. Inserting one item at the start of a list shifts every later item, so everything looks modified:

```php
diffArrays(['tags' => ['php', 'laravel']], ['tags' => ['go', 'php', 'laravel']]);
```

The function reports three changes:

```text
modified tags.0  ("php" -> "go")
modified tags.1  ("laravel" -> "php")
added tags.2     ("laravel")
```

The real change is a single insertion. Fixing this needs sequence alignment, the same idea `git diff` uses to align lines, which is considerably more code.

### Records Often Have an Identity

A list of order lines or users is rarely about positions. If two records swap places, nothing meaningful changed; if a record with `sku` `B-200` changed its quantity, you want to see that one field, wherever the record now sits. Comparing by position cannot express this. You need to match records by a key such as `id` or `sku`.

### Missing Is Not the Same as Null

`['note' => null]` and `[]` are different: in one the key exists, in the other it does not. Using `array_key_exists()` keeps them apart, and the reported change should say which case you are in. A `null` "old value" alone cannot.

### Strict or Loose Equality

The function uses strict comparison. That is usually right for change detection, but floats are awkward: `0.1 + 0.2 !== 0.3`. If your data contains computed floats, you may need a tolerance.

### Keys That Contain Dots

A key named `a.b` and the nested path `a` → `b` both produce the path string `a.b`, so two different changes become indistinguishable. A robust path format must escape the separator or store paths as lists of segments.

### Order of Keys in Maps

`['a' => 1, 'b' => 2] === ['b' => 2, 'a' => 1]` is `false`, because `===` on arrays also compares order. The recursive function compares maps key by key, so key order is correctly ignored, but be careful anywhere you shortcut with `$old === $new` on whole arrays.

### Objects, Dates, and Secrets

Real payloads often contain `DateTimeImmutable` values, `JsonSerializable` objects, or fields like `password` and `token` that must never end up in a log. Each needs a rule: compare dates as instants, serialize objects, and redact sensitive values before storing the diff.

## When a Dedicated Diff Abstraction Pays Off

Each edge case above is solvable in a few lines. Together they are not. Consider a dedicated library when you need several of these at once:

- lists that should be aligned, compared unordered, or matched by identity;
- results you query by path, such as "did `billing.iban` change?";
- consistent, serializable output for audit logs or webhooks;
- rules such as "ignore every `updated_at`" or "redact every `token`";
- the same behavior across many places in a codebase.

At that point you are maintaining a diff engine, and a tested one is cheaper than a growing helper function.

## Using pharaonic/php-diff

[PHP Diff](https://pharaonic.dev/packages/php/diff) is a dependency-free library from Pharaonic for exactly this problem. It supports PHP 8.0 and later; Composer installs the release that matches your PHP version:

```bash
composer require pharaonic/php-diff
```

The earlier example, with the same data:

```php
use Pharaonic\Diff\Diff;

$result = Diff::between($before, $after);

$result->wasModified('address.city'); // true
$result->wasAdded('email');           // true
$result->wasRemoved('phone');         // true
$result->hasChanged('name');          // false
```

Values are compared strictly, a missing key is reported differently from a `null` one, and keys containing dots are escaped in paths (`a\.b`). The result also reports every parent array above a change as an *aggregate* modification. Skip those when you only want the values that really changed:

```php
foreach ($result as $change) {
    if ($change->isAggregate()) {
        continue;
    }

    echo $change->type(), ' ', $change->path()->toDot(), PHP_EOL;
}
```

```text
modified address.city
added email
added roles.1
removed phone
```

### A Practical Example: Order Lines

Order lines are the case the recursive function handled badly. Here a line was reordered, one quantity changed, and a line was added:

```php
use Pharaonic\Diff\Diff;
use Pharaonic\Diff\DiffOptions;

$before = ['lines' => [
    ['sku' => 'A-100', 'qty' => 1],
    ['sku' => 'B-200', 'qty' => 2],
]];

$after = ['lines' => [
    ['sku' => 'B-200', 'qty' => 3],
    ['sku' => 'A-100', 'qty' => 1],
    ['sku' => 'C-300', 'qty' => 1],
]];

$options = DiffOptions::create()
    ->identify('lines', 'sku')
    ->ignore('lines.*.updated_at')
    ->redact('customer.card_number');

$result = Diff::between($before, $after, $options);

foreach ($result as $change) {
    if ($change->isAggregate()) {
        continue;
    }

    printf(
        "%s %s %s -> %s\n",
        $change->type(),
        $change->path()->toDot(),
        json_encode($change->oldValue()),
        json_encode($change->newValue()),
    );
}
```

```text
modified lines.0.qty 2 -> 3
added lines.2 null -> {"sku":"C-300","qty":1}
```

`identify('lines', 'sku')` matches records by their `sku`, so the reordering reports nothing, and the quantity change is reported for `B-200` even though it moved from index 1 to index 0. `$change->oldPath()` still returns `lines.1.qty` when you need the original position. `ignore()` and `redact()` take paths with `*` wildcards, so noisy or sensitive fields never reach your logs. `$result->toJson()` turns the whole result into a stable structure you can store.

The [full documentation](https://pharaonic.dev/packages/php/diff) covers the other list strategies, move detection, objects, and line-by-line text diffs.

## When to Use Which Approach

| Situation | Approach |
| --- | --- |
| Flat list of values, one direction | `array_diff()` |
| Flat map, keys and values | `array_diff_assoc()`, called in both directions |
| Small nested arrays, maps only, positions don't move | The recursive function above |
| Lists with insertions, records with IDs, ignored or redacted fields, audit logs | A dedicated library such as `pharaonic/php-diff` |

Native PHP is still the right choice when the data is flat, when you only need to know *whether* two arrays differ (`$before === $after`, keeping in mind that it also compares key order), or when adding a dependency is not justified for a single comparison.

## Summary

`array_diff()` compares flat values, one way, as strings. Nested data needs a recursive walk that compares strictly, uses `array_key_exists()`, and reports added, removed, and modified values with their paths. That walk fits in thirty lines until lists, identities, and sensitive fields enter the picture. When they do, reach for a dedicated diff library instead of growing your own.
