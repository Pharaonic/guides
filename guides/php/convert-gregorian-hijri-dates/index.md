---
title: "How to Convert Between Gregorian and Hijri Dates in PHP"
slug: "convert-gregorian-hijri-dates"
description: "Convert Gregorian dates to Hijri and back in PHP with ext-intl's Umm al-Qura calendar, and learn when a tabular Hijri calendar is a day off."
category: "php"
tags:
  - php
  - dates
  - hijri
  - intl
  - calendars
related_packages:
  - php-hijri
published_at: "2026-11-20"
status: "published"
keywords:
  - php hijri date
  - convert gregorian to hijri php
  - umm al-qura php
  - intldateformatter islamic calendar
---

An app for a Saudi, Gulf or Muslim audience often has to show "1 Ramadan 1445" next to "11 March 2024", or schedule something for "1 Shawwal". PHP's `DateTime` only knows the Gregorian calendar, so you need something else to go between the two. This guide shows the native way with `ext-intl`, where it gets awkward, and the one question that decides which approach is right: which Hijri calendar do your users expect?

> **Quick Answer / TL;DR:**
> With `ext-intl`, format a date with the `en@calendar=islamic-umalqura` locale to get its Hijri date, and use `IntlCalendar` with the same locale to go back. That's the official Saudi calendar. Tabular calculations, used by many libraries, can be a day off from it.

## Which Hijri Calendar?

There isn't one Hijri calendar in software, there are several:

- **Umm al-Qura** is Saudi Arabia's official calendar, built from astronomical tables. ICU, which powers PHP's `intl` extension, calls it `islamic-umalqura`.
- **Tabular (civil)** calendars use a fixed 30-year cycle of 354- and 355-day years. ICU calls the common one `islamic-civil`. They're simple to compute but drift from Umm al-Qura by a day at some month boundaries.
- **Moon sighting** decides religious dates in many countries. No library can know those in advance; they're announced.

Here is the same set of Gregorian dates in each, from ICU 70 on PHP 8.5:

| Gregorian | Umm al-Qura | Tabular (civil) |
| --- | --- | --- |
| 2024-03-11 | 1445-09-01 | 1445-09-01 |
| 2025-03-01 | 1446-09-01 | 1446-09-01 |
| 2025-03-30 | 1446-10-01 | 1446-09-30 |
| 2026-11-20 | 1448-06-10 | 1448-06-09 |

On 30 March 2025, the day of Eid al-Fitr in Saudi Arabia, the tabular calendar still says 30 Ramadan. If your users check dates against the official Saudi calendar, use Umm al-Qura.

## The Native Approach: ext-intl

`IntlDateFormatter` formats a date in any calendar ICU supports; the calendar goes in the locale. Use the `TRADITIONAL` calendar type so the locale's calendar is used, not the Gregorian one:

```php
function toHijri(DateTimeInterface $date, string $locale = 'en'): string
{
    $formatter = new IntlDateFormatter(
        "{$locale}@calendar=islamic-umalqura",
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        $date->getTimezone(),
        IntlDateFormatter::TRADITIONAL,
        'd MMMM y',
    );

    return $formatter->format($date);
}

echo toHijri(new DateTimeImmutable('2025-03-30'));       // 1 Shawwal 1446
echo toHijri(new DateTimeImmutable('2025-03-30'), 'ar'); // ١ شوال ١٤٤٦
```

The pattern letters are ICU's, not PHP's `date()` ones: `d` is the day, `MMMM` the month name, `y` the year. Month names and digits come from the locale for free.

Going the other way, set the Hijri fields on an `IntlCalendar` in the same calendar and read the instant back. Months are zero-based, as in all of ICU:

```php
function fromHijri(int $year, int $month, int $day, string $timezone = 'UTC'): DateTimeImmutable
{
    $calendar = IntlCalendar::createInstance($timezone, 'en@calendar=islamic-umalqura');
    $calendar->clear();
    $calendar->set($year, $month - 1, $day);

    return DateTimeImmutable::createFromInterface($calendar->toDateTime());
}

echo fromHijri(1446, 10, 1)->format('Y-m-d l'); // 2025-03-30 Sunday
```

`IntlCalendar` also answers calendar questions that are hard to compute yourself, such as how many days a Hijri month has:

```php
$calendar = IntlCalendar::createInstance('UTC', 'en@calendar=islamic-umalqura');
$calendar->clear();
$calendar->set(1446, 8, 1); // Ramadan 1446 (month index 8)

echo $calendar->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH); // 29
```

## Where It Gets Awkward

The native approach is accurate, but it's low-level:

- **Two APIs and zero-based months.** Formatting uses `IntlDateFormatter`, arithmetic uses `IntlCalendar`, and `set(1446, 9, 1)` means Shawwal, not Ramadan. Wrap them once, as above, rather than at every call site.
- **No Carbon integration.** You format and convert by hand; nothing like `$date->toHijri()` exists.
- **The extension must be installed.** `intl` is common but optional, and its results depend on the ICU version it was built with. Check `INTL_ICU_VERSION` on every server.
- **Umm al-Qura has a range.** ICU's tables cover roughly 1300–1600 AH and fall back to a calculation outside it. That covers any date an application normally handles.

## Using a Package

If your project already uses Carbon and you want Hijri dates as Carbon methods, [Pharaonic Hijri](https://pharaonic.dev/packages/php/hijri) adds them with a mixin and doesn't need `intl`:

```php
use Carbon\Carbon;
use Pharaonic\Hijri\HijriCarbon;

Carbon::mixin(HijriCarbon::class);

Carbon::parse('2024-03-11')->toHijri()->isoFormat('dddd D MMMM YYYY'); // Monday 1 Ramadan 1445
Carbon::fromHijri(1445, 10, 1)->toDateString();                       // 2024-04-10
```

Be aware of what it computes: it uses the **tabular** Islamic calendar, the same one as ICU's `islamic-civil`, with a configurable day adjustment (default `-1`) to line results up with your region. On the table above it gives `1446-09-30` for 30 March 2025 and `1448-06-09` for 20 November 2026. An adjustment of `0` moves those two to the Umm al-Qura dates, but then 11 March 2024 becomes 2 Ramadan: no single offset makes a tabular calendar follow Umm al-Qura everywhere.

## Which One to Use

| You need | Use |
| --- | --- |
| Dates that match the official Saudi calendar | `ext-intl` with `islamic-umalqura` |
| Localized month names and digits in many languages | `ext-intl` |
| Hijri dates as Carbon methods, without `intl` | A tabular calendar such as Pharaonic Hijri, with an adjustment that suits your region |
| Religious dates decided by moon sighting | Neither alone: store the announced dates |

Whatever you choose, store Gregorian dates (or timestamps) in the database and convert for display. Hijri dates depend on the calendar, and a stored "1446-10-01" means a different day to each one.

## Conclusion

PHP can convert between Gregorian and Hijri dates without any package: `IntlDateFormatter` and `IntlCalendar` with `islamic-umalqura` give the official Saudi calendar in a dozen lines. Reach for a library when you want the Carbon API more than calendar-exact dates, and know which calendar it calculates. Sources: [IntlDateFormatter](https://www.php.net/manual/en/class.intldateformatter.php), [IntlCalendar](https://www.php.net/manual/en/class.intlcalendar.php), [ICU calendar types](https://unicode-org.github.io/icu/userguide/datetime/calendar/).
