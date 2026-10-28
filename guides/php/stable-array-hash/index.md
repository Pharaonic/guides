---
title: "How to Generate a Stable Hash for PHP Arrays"
slug: "stable-array-hash"
description: "Learn how to generate deterministic hashes for PHP arrays, normalize nested data, avoid key-order issues, and create reliable structured-data fingerprints."
category: "php"
tags:
  - php
  - hashing
  - fingerprint
  - arrays
  - json
  - data
related_packages:
  - php-fingerprint
published_at: "2026-10-28"
status: "published"
image: "assets/cover.webp"
keywords:
  - php array hash
  - stable hash php array
  - deterministic hash php
  - php array fingerprint
  - hash json encode php
  - php recursive array hash
  - deterministic json php
  - canonical json php
  - php object fingerprint
  - php payload hash
---

```php
$first = ['name' => 'Raggi', 'role' => 'developer'];
$second = ['role' => 'developer', 'name' => 'Raggi'];

hash('sha256', json_encode($first));
hash('sha256', json_encode($second));
// Different hashes.
```

These arrays can describe the same user, yet produce different cache keys or slip past duplicate detection. PHP keeps their insertion order, and JSON preserves that difference. The SHA-256 function is doing exactly what it should: hashing different bytes.

**A cryptographic hash is deterministic. Your input representation may not be.**

> **Quick Answer:** Recursively normalize map keys, preserve list order, and serialize with explicit type and error-handling rules before hashing. Plain JSON hashing is sufficient when your producer already controls the representation. The manual examples below target PHP 8.1+; the package introduced later currently requires PHP 8.5.

## The Naive Approach

This is concise, uses built-in functions, and has little code to maintain:

```php
$fingerprint = hash('sha256', json_encode($data));
```

It is valid when the exact serialized representation is what matters. In production, add `JSON_THROW_ON_ERROR` so encoding failures cannot silently become a hash of an unintended value.

The extra requirement for a stable PHP array hash is deciding which differences matter. A hash identifies bytes. A fingerprint is a compact identifier for content or state under your chosen normalization rules.

> Same bytes → same hash.
>
> Stable fingerprinting adds a requirement: same logical value → same canonical bytes → same hash.

Normalization converts equivalent values into a consistent representation. A canonical representation is the single representation you choose for those equivalent values. “Equivalent” is a domain rule: map order might be irrelevant while the order of workflow steps is essential.

## Key Order Changes the Hash

```php
$a = ['id' => 15, 'status' => 'active'];
$b = ['status' => 'active', 'id' => 15];

echo json_encode($a, JSON_THROW_ON_ERROR), PHP_EOL;
echo json_encode($b, JSON_THROW_ON_ERROR), PHP_EOL;

var_dump(
    hash('sha256', json_encode($a, JSON_THROW_ON_ERROR))
    === hash('sha256', json_encode($b, JSON_THROW_ON_ERROR))
);
```

```text
{"id":15,"status":"active"}
{"status":"active","id":15}
bool(false)
```

A [PHP array is an ordered map](https://www.php.net/manual/en/language.types.array.php). Associative arrays are not unordered in PHP. Here we deliberately define *logical equivalence* as matching keys and values regardless of insertion order. The serialized bytes still differ until we normalize them.

### Nested Arrays Need Recursive Normalization

```php
$first = ['user' => ['name' => 'Raggi', 'country' => 'EG']];
$second = ['user' => ['country' => 'EG', 'name' => 'Raggi']];

ksort($first);
ksort($second);
// The nested 'user' maps still have different key orders.
```

Sorting just the outer map cannot fix a PHP recursive array hash. Every nested map needs the same rule, including maps inside lists.

## Lists and Maps Need Different Rules

These lists usually represent different values:

```php
['php', 'laravel', 'livewire'];
['livewire', 'laravel', 'php'];
```

Sorting every array would erase that distinction. Normalize map key order; preserve list element order unless the business explicitly treats a list as an unordered collection.

[`array_is_list()`](https://www.php.net/manual/en/function.array-is-list.php), available since PHP 8.1, recognizes consecutive integer keys starting at zero, in that order. An empty PHP array is a list. A sparse array such as `[0 => 'php', 2 => 'laravel']` is a map under this policy. Call `array_values()` beforehand only when discarding those keys is intentional.

## Build a Recursive Normalizer

This normalizer accepts arrays, nulls, booleans, integers, finite floats, and UTF-8 strings. It rejects input objects and resources instead of guessing how to represent them. The returned objects are internal map containers for JSON, not support for arbitrary object inputs.

```php
function normalizeForHash(mixed $value, int $depth = 0): mixed
{
    if (is_array($value)) {
        if ($depth >= 128) {
            throw new InvalidArgumentException('Maximum array depth exceeded.');
        }

        $isList = array_is_list($value);

        if (! $isList) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = normalizeForHash($item, $depth + 1);
        }

        return $isList ? $value : (object) $value;
    }

    if (is_float($value)) {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Non-finite floats are unsupported.');
        }

        // This policy treats -0.0 and 0.0 as equivalent.
        return $value == 0.0 ? 0.0 : $value;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
        return $value;
    }

    throw new InvalidArgumentException('Only arrays and JSON scalar values are supported.');
}
```

`SORT_STRING` gives map keys a fixed string comparison rather than PHP's mixed-type default comparison. Classifying the container *before* sorting matters: `[1 => 'b', 0 => 'a']` starts as a map but becomes list-shaped after sorting. Casting normalized maps to objects keeps JSON from silently changing that map into a list.

PHP already converts some numeric string keys to integer keys when constructing arrays. No normalizer can recover a distinction PHP has already discarded. This policy also treats `[]` as an empty list; use a different input model if empty maps must be representable.

The depth guard bounds recursion, including self-referencing arrays. UTF-8 validity is checked by JSON encoding in the next step.

## A Reusable Manual Fingerprint Function

Put the normalizer and this function in an application helper loaded by Composer:

```php
function stableFingerprint(mixed $value): string
{
    if (ini_get('serialize_precision') !== '-1') {
        throw new LogicException('Set serialize_precision=-1 for this fingerprint policy.');
    }

    $normalized = normalizeForHash($value);
    $json = json_encode(
        $normalized,
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
    );

    return hash('sha256', $json);
}
```

`JSON_THROW_ON_ERROR` turns invalid UTF-8 or an encoding depth failure into a `JsonException`. Do not suppress that exception or hash an empty fallback. `JSON_PRESERVE_ZERO_FRACTION` keeps the integer `1` separate from the float `1.0`. No unescaped-slash flag is needed: escaped slashes are deterministic too.

The precision check enforces the expected PHP configuration rather than changing global settings inside a helper. Keep the PHP runtime, flags, input types, and normalization policy consistent between producers. This is an application-specific deterministic JSON strategy, **not an implementation of a canonical JSON standard** such as [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785).

```text
PHP value → Normalize → Canonical representation → Serialize → Hash → Fingerprint
```

For persisted identifiers, version your policy, for example with a `manual-v1:` storage prefix. Changing normalization rules changes identifiers even if the data has not changed.

## Edge Cases That Define Equivalence

### Scalars and Type Sensitivity

The manual function keeps all of these apart:

```php
$values = [1, '1', true, 'true', null, '', 1.0];
$hashes = array_map('stableFingerprint', $values);

var_dump(count(array_unique($hashes)) === count($values));
// bool(true)
```

Whether `1` and `1.0` should match is a domain decision. Here they differ. A quantity API might normalize both to integers before hashing; a typed configuration might need to preserve the distinction. Avoid coercing numeric identifiers such as `'0015'` without a deliberate rule.

### Floats, Infinities, and Large Integers

`0.1 + 0.2` and `0.3` are different binary floating-point values. The manual helper does not round them. Use explicit decimal strings or integer minor units for monetary values, or define rounding at the domain boundary.

The [JSON encoder](https://www.php.net/manual/en/function.json-encode.php) requires UTF-8 strings, and its float output depends on `serialize_precision`. It cannot encode `INF`, `-INF`, or `NAN` as JSON numbers; our normalizer rejects these first. Preserve identifiers larger than `PHP_INT_MAX` as strings, including when decoding incoming JSON with `JSON_BIGINT_AS_STRING`. Precision lost during parsing cannot be restored by hashing.

Even with controlled settings, a homemade JSON policy should be tested across the runtimes where you exchange fingerprints. Deterministic hashing requires deterministic serialization, not just a fixed algorithm name.

### Objects Need an Explicit Projection

For a `User` object, are you identifying its instance, public properties, database attributes, class, or selected fields? These are different questions.

```php
$userData = ['id' => 42, 'email' => 'user@example.com'];
$fingerprint = stableFingerprint($userData);
```

Build that projection deliberately. Blindly hashing `serialize($object)` couples identifiers to class names, property visibility, serialization hooks, and implementation changes. The manual helper rejects objects, including dates and enums, until you convert them to supported data.

### Dates and Enums

Custom normalization could convert a date to a UTC timestamp with microseconds and an enum to a class-qualified case name. Another application might use a backed enum value or preserve the timezone. Choose those semantics explicitly; the package below has its own typed rules and does not simply turn every structured value into a string.

### Missing Keys and Nulls

```php
stableFingerprint(['name' => 'Raggi'])
    !== stableFingerprint(['name' => 'Raggi', 'email' => null]);
// true
```

An omitted field and an explicit null are structurally different. For PATCH requests, null can mean “clear this value” while omission means “leave it alone.” Keep nulls for payload comparison, configuration fingerprints, and cache keys unless your business rules explicitly equate these cases.

## Practical Uses for the Manual Function

### Duplicate API Payloads

```php
$fingerprint = stableFingerprint($payload);

if (ProcessedRequest::where('fingerprint', $fingerprint)->exists()) {
    return;
}
```

`ProcessedRequest` is an application-defined Laravel model. Without normalization, reordered map keys can give an equivalent request a new fingerprint and bypass this lookup.

This illustrates lookup only. Two concurrent requests can both pass `exists()`. Production deduplication needs a unique database constraint and an atomic claim; the complete example later shows one approach. Define whether repeated identical content should count as a duplicate at all: two legitimate purchases can contain the same items.

### Stable Cache Keys

```php
$key = 'products:manual-v1:'.stableFingerprint([
    'filters' => $filters,
    'sort' => $sort,
]);
```

Equivalent filters built from `?status=active&country=EG` or the reversed parameter order should share a key when order has no meaning. Normalize request types first: query strings contain strings, so `'1'` and `1` remain different unless converted. Include tenant, permissions, locale, and other response inputs when they affect the cached result.

### Change Detection

```php
$oldData = ['mail' => ['host' => 'smtp.example.com', 'port' => 587]];
$newData = ['mail' => ['port' => 587, 'host' => 'smtp.example.com']];

$before = stableFingerprint($oldData);
$after = stableFingerprint($newData);

if ($before !== $after) {
    // A value or meaningful ordering changed under this policy.
}
// Reordering the mail map alone does not enter the branch.
```

A fingerprint detects change; it does not explain it. For added, removed, and modified paths, use the techniques in [comparing nested PHP arrays](/guides/php/compare-nested-arrays), including PHP Diff where appropriate.

## A Content Hash Is Not Authentication

> A plain SHA-256 fingerprint does not prove who sent a payload. Anyone who can modify the content can calculate its new hash.

For authenticated messages, use a shared-secret construction such as [`hash_hmac()`](https://www.php.net/manual/en/function.hash-hmac.php), or the provider's specified signature scheme. Verify exactly the bytes or ordered fields required by that protocol; normalizing a webhook body can invalidate its signature. This guide uses content fingerprints for equivalence, not sender authentication.

## Test the Normalization Contract

These Pest tests assume the two manual helper functions are loaded by the test bootstrap. They test the policy, not particular digest literals:

```php
it('ignores map insertion order', function () {
    expect(stableFingerprint(['id' => 15, 'status' => 'active']))
        ->toBe(stableFingerprint(['status' => 'active', 'id' => 15]));
});

it('normalizes nested maps inside lists', function () {
    $a = ['items' => [['meta' => ['b' => 2, 'a' => 1]]]];
    $b = ['items' => [['meta' => ['a' => 1, 'b' => 2]]]];

    expect(stableFingerprint($a))->toBe(stableFingerprint($b));
});

it('normalizes deeply nested data', function () {
    $a = ['b' => 2, 'a' => 1];
    $b = ['a' => 1, 'b' => 2];

    for ($level = 0; $level < 32; $level++) {
        $a = ['child' => $a];
        $b = ['child' => $b];
    }

    expect(stableFingerprint($a))->toBe(stableFingerprint($b));
});

it('preserves list order', function () {
    expect(stableFingerprint(['php', 'laravel']))
        ->not->toBe(stableFingerprint(['laravel', 'php']));
});

it('preserves scalar types and null keys', function () {
    expect(stableFingerprint(1))->not->toBe(stableFingerprint('1'))
        ->and(stableFingerprint(1))->not->toBe(stableFingerprint(1.0))
        ->and(stableFingerprint([]))->not->toBe(stableFingerprint(['email' => null]));
});

it('keeps numeric maps distinct from lists', function () {
    expect(stableFingerprint([1 => 'b', 0 => 'a']))
        ->not->toBe(stableFingerprint(['a', 'b']));
});

it('rejects values outside the manual scope', function () {
    expect(fn () => stableFingerprint(INF))->toThrow(InvalidArgumentException::class)
        ->and(fn () => stableFingerprint(new stdClass()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => stableFingerprint("\xB1"))->toThrow(JsonException::class);
});
```

Also test your maximum depth, recursive arrays, empty containers, numeric keys, and representative production payloads before persisting these hashes.

## Using pharaonic/php-fingerprint

[PHP Fingerprint](https://pharaonic.dev/packages/php/fingerprint) centralizes normalization and hashing. The current source inspected for this guide is release 8.5.0: Composer requires **PHP `>=8.5 <8.6` and `ext-mbstring`**. Do not assume the current release runs on every PHP 8.x version. Unicode normalization rules additionally need `ext-intl` or `symfony/polyfill-intl-normalizer`.

```bash
composer require pharaonic/php-fingerprint
```

The actual entry point is `Pharaonic\Fingerprint\Fingerprint`; there is no global fingerprint helper or Laravel facade registered by this package.

```php
use Pharaonic\Fingerprint\Fingerprint;

$a = ['id' => 15, 'status' => 'active'];
$b = ['status' => 'active', 'id' => 15];

$hash = Fingerprint::make($a);

var_dump($hash === Fingerprint::make($b)); // bool(true)
var_dump(Fingerprint::verify($b, $hash));   // bool(true)
```

`make()` returns lowercase hexadecimal SHA-256 by default. `verify()` compares the calculated digest with an expected digest in constant time. Verification of an unkeyed content hash still does not authenticate its sender.

### Nested Maps and Ordered Lists

```php
use Pharaonic\Fingerprint\Fingerprint;

$data = [
    'filters' => ['country' => 'EG', 'status' => 'active'],
    'page' => 1,
];

$reordered = [
    'page' => 1,
    'filters' => ['status' => 'active', 'country' => 'EG'],
];

var_dump(Fingerprint::make($data) === Fingerprint::make($reordered));
// bool(true)

var_dump(
    Fingerprint::make(['php', 'laravel', 'livewire'])
    === Fingerprint::make(['livewire', 'laravel', 'php'])
);
// bool(false)
```

Maps are canonicalized recursively by sorting their typed key tokens with binary string comparison. Lists preserve order and duplicates by default. Their classification uses `array_is_list()` on the input container, so sorting does not accidentally turn a numeric map into a list.

### What the Package Actually Normalizes

The [canonical rules and implementation](https://github.com/Pharaonic/php-fingerprint/tree/8.5.0/src) define a versioned, type-tagged, length-framed byte format, currently `v1`, rather than canonical JSON. Package hashes will not match the manual JSON helper, even for simple arrays. This is a separate fingerprint policy.

| Value | Default behavior |
| --- | --- |
| Null, bool, int, string | Distinct type tokens; null fields retained |
| `1`, `'1'`, `1.0`, `true` | Four different fingerprints |
| Finite floats | Exact IEEE-754 bits, independent of JSON precision settings |
| `-0.0`, `0.0` | Same fingerprint |
| `INF`, `-INF` | Supported, distinct float values |
| `NAN` | Rejected with `UnsupportedValueException` |
| Strings | Raw bytes preserved; no automatic trim, case folding, or Unicode normalization |
| Arrays | Recursive map normalization; list order preserved |
| `stdClass` | Public properties as a map; an empty object differs from `[]` |
| `JsonSerializable` | Recursively normalizes its returned value |
| `Traversable` except generators | Iterates into array data; duplicate keys and invalid key types rejected |
| `DateTimeInterface` | UTC instant with microseconds, using a date token distinct from strings |
| `UnitEnum` / `BackedEnum` | Enum class, case name, and backed value (or null for pure cases) |

Date objects representing the same instant match across timezones. `keepTimezone()` instead includes local time and timezone name; `datePrecision('second')` can truncate microseconds. Enum cases differ from their names or backed values as plain scalars, and equally backed cases from different enum classes differ. `enumsByName()` and `enumsByValue()` opt into conversion; the latter rejects pure enum cases.

Ordinary objects are rejected even if they expose public properties. `Stringable` needs an explicit `normalize()` callback. Private and protected state is not inspected. Resources, closures, and generators are unsupported as structured values. `Fingerprint::file()` and `Fingerprint::stream()` are separate APIs for hashing raw bytes, not normalization of resources inside arrays.

Circular object references raise `CircularReferenceException`; the default maximum nesting depth is 512, configurable through `maxDepth()`. Excessively deep values, including self-referencing arrays, raise `MaximumDepthExceededException`. Configuration errors use `ConfigurationException`; unavailable Unicode normalization support raises `MissingExtensionException`. Do not replace failures with an empty fingerprint.

### Configure Only the Equivalence You Need

```php
use Pharaonic\Fingerprint\Fingerprint;

$payload = [
    'updated_at' => '2026-10-28T10:30:00+00:00',
    'customer' => ['email' => ' USER@example.com '],
    'tags' => ['php', 'laravel'],
];

$hash = Fingerprint::for($payload)
    ->ignore('updated_at')
    ->at('customer.email')->trim()->lower()
    ->at('tags')->unordered()
    ->make();
```

The builder is immutable: use the value returned by each method. `at()` scopes subsequent value rules to a dot path; `*` matches one path segment. Here only `tags` becomes unordered. It keeps duplicates unless `unique()` is explicitly requested. Avoid a global `unordered()` rule when nested list order matters.

Other verified options include `only()`, `ignoreNulls()`, `normalizeNumericStrings()`, `normalizeBooleans()`, `precision()`, and class/interface `normalize()` callbacks. These change what counts as equivalent; they are not required for basic stable hashing. Large numeric strings should generally stay strings: opting into numeric normalization can convert overflowing integers to floats.

`algorithm()` accepts algorithms available through `hash_algos()`. SHA-256 remains a sensible content-hash default. Output can be changed with `binary()`, `base64()`, or `base64Url()`. `hmac()` is available for a shared canonical-message contract, and the separate `signature()->fields()` API handles protocol-ordered fields. Neither should replace the serialization rules specified by an external provider.

For debugging, `Fingerprint::for($payload)->result()` exposes `hash()`, `canonical()`, `normalized()`, and `version()`. Canonical bytes and normalized values can contain sensitive input; handle them with the same care as the payload. There is no Laravel service provider or framework dependency in the inspected Composer configuration.

## Manual vs Pharaonic

| Responsibility | Manual helper above | php-fingerprint |
| --- | --- | --- |
| Runtime | PHP 8.1+, controlled JSON settings | Current release: PHP 8.5, mbstring |
| Recursive map ordering | `ksort(SORT_STRING)` and map containers | Typed keys sorted by canonical token bytes |
| Lists | Order preserved | Order preserved; optional scoped ordering and uniqueness |
| Serialization | Application-specific JSON | Versioned typed canonical format |
| Scalars | JSON types, finite floats, UTF-8 strings | Typed scalars, byte strings, infinities; rejects NAN |
| Objects, dates, enums | Explicit projection required | Defined supported types and custom normalizers |
| Filtering | Add and test your own policy | Path rules, selection, and opt-in null filtering |
| Hash generation | Fixed SHA-256 | Configurable algorithm and encoding; optional HMAC |
| Failure behavior | Helper exceptions and `JsonException` | Package exceptions with path-aware diagnostics |
| Digest compatibility | Its own policy | Different canonical bytes and hashes |

### What the Package Saves You from Maintaining

You no longer maintain recursive traversal, type framing, binary map-key ordering, map/list classification, date and enum encoding, object conversion rules, depth and cycle checks, and digest encoding in each application helper. Its tests cover those shared rules, including golden canonical vectors and normalization invariants.

You still own the business definition of equivalence, safe input parsing, field selection, deterministic custom callbacks, storage versioning, and concurrency. Pin and test package upgrades when fingerprints are persisted or shared between services. Replacing the manual helper requires a migration or a new key namespace; the two formats are not interchangeable.

## When hash(json_encode()) Is Perfectly Fine

```php
$fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
```

This is a valid choice when exact serialized form matters, key order is controlled or canonicalized by the producer, or a simple structure stays inside one controlled code path. You might deliberately want reordered keys to count as different content.

Use a reusable normalizer when several callers need the same definition of logical data equivalence. A dependency is not necessary for every hash.

## When Not to Use Fingerprints

Content fingerprints do not replace:

- Password hashing: use [`password_hash()` and `password_verify()`](https://www.php.net/manual/en/ref.password.php).
- Cryptographic signatures, message authentication, or encryption.
- Human-readable diffs explaining which fields changed.
- Database uniqueness rules and appropriate unique indexes.
- Random identifiers for separate events that happen to contain identical data.

Hashes have a finite output space, so collisions are possible. Use SHA-256 with a suitable application policy; do not describe a fingerprint as mathematical proof of equality. Keep explicit business keys when they define identity more accurately than content does.

## Complete Example: API Request Deduplication

Suppose this endpoint intentionally treats identical order content from the same account as a duplicate. If clients can place the same order twice legitimately, use a client-supplied idempotency key instead.

```php
use Pharaonic\Fingerprint\Fingerprint;

$payload = [
    'customer' => [
        'id' => 42,
        'email' => 'user@example.com',
    ],
    'items' => [
        ['id' => 10, 'quantity' => 2],
        ['id' => 18, 'quantity' => 1],
    ],
];

// 1. Naive: map insertion order changes this digest.
$naive = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

// 2. Manual: load normalizeForHash() and stableFingerprint() from above.
$manual = stableFingerprint($payload);

// 3. Package: normalized maps, ordered lists, typed canonical format.
$package = Fingerprint::make($payload);

$reordered = [
    'items' => [
        ['quantity' => 2, 'id' => 10],
        ['quantity' => 1, 'id' => 18],
    ],
    'customer' => ['email' => 'user@example.com', 'id' => 42],
];

var_dump($naive === hash('sha256', json_encode($reordered, JSON_THROW_ON_ERROR)));
var_dump($manual === stableFingerprint($reordered));
var_dump($package === Fingerprint::make($reordered));
```

```text
bool(false)
bool(true)
bool(true)
```

Neither stable approach sorts the `items` list. Reversing those two items changes both fingerprints. If item order has no meaning in your domain, define and test that as a separate policy.

For a Laravel application, the following PostgreSQL example atomically claims a fingerprint and creates an order in the same transaction. It assumes an application-owned `processed_requests` table with `account_id`, `operation`, and `fingerprint` columns, a unique index on all three, and timestamps with database defaults. The `orders` table has `account_id` and a JSON `payload` column.

```php
use Illuminate\Support\Facades\DB;
use Pharaonic\Fingerprint\Fingerprint;

// Use an authenticated account ID, not a value trusted from the payload.
$accountId = 7;
$operation = 'create-order:fp-v1';
$fingerprint = Fingerprint::make($payload);

$created = DB::transaction(function () use ($accountId, $operation, $fingerprint, $payload): bool {
    $claimed = DB::selectOne(
        'INSERT INTO processed_requests (account_id, operation, fingerprint)
         VALUES (?, ?, ?)
         ON CONFLICT (account_id, operation, fingerprint) DO NOTHING
         RETURNING fingerprint',
        [$accountId, $operation, $fingerprint]
    );

    if ($claimed === null) {
        return false;
    }

    DB::table('orders')->insert([
        'account_id' => $accountId,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);

    return true;
});
```

The unique index arbitrates concurrent requests. A failed order insert rolls back the claim so the request can be retried. Keep external effects such as payment calls out of this transaction; use a transactional outbox or the provider's idempotency mechanism. Store and return the original response if your API promises replayable responses, and define a retention window for deduplication records.

Hash functions are deterministic. Stable fingerprints require a deterministic representation of the data first. Maintain that contract in a scoped helper or centralize it with [PHP Fingerprint](https://pharaonic.dev/packages/php/fingerprint); check the [source and tests](https://github.com/Pharaonic/php-fingerprint) when choosing or upgrading the policy.
