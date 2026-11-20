---
title: "How to Show Human-Readable File Sizes in PHP and Laravel"
slug: "human-readable-file-sizes"
description: "Turn byte counts into sizes like 1.5 MB in PHP and Laravel, choose between decimal KB and binary KiB, and avoid the rounding bug in the usual snippet."
category: "php"
tags:
  - php
  - laravel
  - formatting
  - file-sizes
related_packages:
  - php-readable
published_at: "2026-11-20"
status: "published"
image: "assets/cover.webp"
keywords:
  - php human readable file size
  - php format bytes
  - laravel number filesize
  - kb vs kib
---

`filesize()` returns bytes, and nobody wants to read "1048576" next to a download link. Turning bytes into "1 MB" looks like a five-line function, but there are two decisions hidden in it: whether a kilobyte is 1,000 or 1,024 bytes, and what happens when rounding reaches the next unit. This guide covers both, in plain PHP and in Laravel.

> **Quick Answer / TL;DR:**
> Divide by 1,000 for KB, MB, GB (what macOS and storage vendors show) or by 1,024 and label the result KiB, MiB, GiB. Round, then move to the next unit if the rounded value reached it. Laravel's `Number::fileSize()` divides by 1,024 but labels the result KB.

## KB or KiB?

Two systems are in use, and they differ by 2.4% at KB and 7.4% at GB:

| Unit | Bytes | Used by |
| --- | --- | --- |
| kilobyte (KB) | 1,000 | SI prefixes; macOS Finder, disk and network vendors |
| kibibyte (KiB) | 1,024 | IEC binary prefixes; Linux tools with `-h`, memory |

The IEC defined KiB, MiB and GiB so that "KB" could keep meaning 1,000 bytes ([NIST: prefixes for binary multiples](https://physics.nist.gov/cuu/Units/binary.html)). Windows Explorer still divides by 1,024 and writes "KB". Pick one system, and label it correctly: a "1.5 MB" upload limit that's really 1.5 MiB rejects files your users think are allowed.

## The Native Approach

PHP has no built-in byte formatter, so most projects carry a helper like this:

```php
function humanFileSize(int $bytes, int $decimals = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = $bytes > 0 ? min((int) floor(log($bytes, 1000)), count($units) - 1) : 0;

    return round($bytes / 1000 ** $power, $decimals) . ' ' . $units[$power];
}

echo humanFileSize(1500);       // 1.5 KB
echo humanFileSize(1048576);    // 1 MB
echo humanFileSize(1500000000); // 1.5 GB
```

It's decimal, short, and right most of the time.

## Where It Breaks Down

The unit is chosen before rounding, so a value just under the next unit rounds up to a number that should have been the next unit:

```php
echo humanFileSize(999999); // 1000 KB
```

The fix is to round first and step up when the rounded value reaches the base. The other gaps are smaller: negative sizes (a difference between two files) make `log()` fail, the result isn't localized ("1,5 KB" in German), and there's no way back from "10 MB" to bytes for a config value.

## A Better Native Version

Round, then move up a unit when rounding reached it, and keep the sign:

```php
function humanFileSize(int $bytes, int $decimals = 1, bool $binary = false): string
{
    $base = $binary ? 1024 : 1000;
    $units = $binary ? ['B', 'KiB', 'MiB', 'GiB', 'TiB'] : ['B', 'KB', 'MB', 'GB', 'TB'];
    $absolute = abs($bytes);
    $unit = 0;

    while ($unit < count($units) - 1 && $absolute >= $base ** ($unit + 1)) {
        $unit++;
    }

    $value = round($absolute / $base ** $unit, $decimals);

    if ($value >= $base && $unit < count($units) - 1) {
        $value = round($absolute / $base ** ++$unit, $decimals);
    }

    return ($bytes < 0 ? '-' : '') . $value . ' ' . $units[$unit];
}

echo humanFileSize(999999);             // 1 MB
echo humanFileSize(1536, binary: true); // 1.5 KiB
echo humanFileSize(-2500);              // -2.5 KB
```

For localized decimals, format `$value` with `NumberFormatter` from `ext-intl` instead of concatenating it.

## In Laravel

Laravel 11 and later ship [`Number::fileSize()`](https://laravel.com/docs/13.x/helpers#method-number-file-size):

```php
use Illuminate\Support\Number;

echo Number::fileSize(1048576);   // 1 MB
echo Number::fileSize(1536, 2);   // 1.50 KB
echo Number::fileSize(1500);      // 1 KB
```

Know what it does before showing it to users. It divides by 1,024 but labels the result KB, MB, GB. Its default precision is 0, so 1,500 bytes shows as "1 KB" and 1,536 as "2 KB". It switches unit once the value passes 0.9 of the next one, so 999 bytes is "1 KB". For a dashboard that's fine; for an upload limit or a billing page, pass a precision and say which unit you mean.

## Using a Package

[Pharaonic Readable](https://pharaonic.dev/packages/php/readable) formats both systems with the rounding rule above, localizes through `ext-intl` when you pass a locale, and parses sizes back into bytes:

```php
use Pharaonic\Readable\Bytes;

echo Bytes::format(1500);                 // 1.5 KB
echo Bytes::format(999999);               // 1 MB
echo Bytes::format(1536, binary: true);   // 1.5 KiB

Bytes::parse('10 MB');   // 10000000
Bytes::parse('1.5 KiB'); // 1536
```

It's worth it when sizes appear in several places, when you need both directions (a "max upload: 10 MB" setting that code compares against bytes), or when you show sizes in more than one language. For one label on one page, the native function is enough.

## Which One to Use

| You need | Use |
| --- | --- |
| A size label in plain PHP | The native function above (round, then step up) |
| A quick size in a Laravel view where the exact unit doesn't matter | `Number::fileSize()` with a precision |
| Decimal and binary units, localized output, or parsing sizes back | A formatter such as Pharaonic Readable |

## Conclusion

Formatting bytes is easy once two choices are explicit: decimal KB or binary KiB, and rounding before choosing the unit. Write them into one helper, label the unit honestly, and test the boundary values (999, 1000, 999999) once. Sources: [PHP filesize()](https://www.php.net/manual/en/function.filesize.php), [NIST binary prefixes](https://physics.nist.gov/cuu/Units/binary.html), [Laravel Number::fileSize()](https://laravel.com/docs/13.x/helpers#method-number-file-size).
