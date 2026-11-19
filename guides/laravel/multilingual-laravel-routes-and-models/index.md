---
title: "How to Build Multilingual Laravel Pages with Localized URLs and Translated Models"
slug: "multilingual-laravel-routes-and-models"
description: "Build multilingual Laravel pages with locale-safe URLs, Eloquent content translations, localized slugs, predictable fallbacks, and language links."
category: "laravel"
tags:
  - laravel
  - localization
  - eloquent
  - multilingual
  - i18n
  - localized-routes
  - translated-content
  - seo
related_packages:
  - laravel-localization
  - laravel-translatable
published_at: "2026-11-20"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel multilingual website
  - laravel localized routes and translated models
  - laravel translated slugs
  - laravel localization eloquent
  - laravel translation fallback
  - laravel localization api accept language
  - laravel hreflang language switcher
  - pharaonic laravel localization
  - pharaonic laravel translatable
  - laravel translation storage strategies
---

An Arabic visitor opens a product page. The URL is Arabic, the navigation is Arabic, but the product title is English. Then they click the language switcher, which preserves the Arabic product slug in an English URL and returns a 404. Meanwhile, an API consumer sends `Accept-Language: ar` and receives a cached English response.

All three failures can occur in an application that already uses Laravel's translation files correctly. **Choosing a request language, translating database content, and identifying a resource by a translated URL are separate problems.** Treating them as one switch is where multilingual applications become unreliable.

This guide builds a small English/Arabic product catalogue. We start with Laravel itself, preserve its useful primitives, then connect [Pharaonic Laravel Localization](https://pharaonic.dev/packages/laravel/localization) and [Pharaonic Laravel Translatable](https://pharaonic.dev/packages/laravel/translatable) at the boundaries where they replace repeated application-specific logic.

> **Quick Answer / TL;DR:** Use Laravel's `app.locale` for the active language, `app.fallback_locale` for missing display text, and `lang/` files for interface strings. A localized website also needs URL routing and locale-aware model lookup; database content needs explicit per-locale storage. The two Pharaonic packages address those different concerns, but the model must explicitly connect translated slugs to localized route binding.

## Define the Language Contract Before Writing Routes

Our catalogue needs two web URLs for a product, a stable API URL, and a deliberate rule for missing translations.

| Concern | English | Arabic | Owner |
| --- | --- | --- | --- |
| Website index | `/products` | `/ar/المنتجات` | Localized routing |
| Product URL | `/products/light-lens` | `/ar/المنتجات/عدسة-خفيفة` | Routing + translated slug |
| Product name | `Light Lens` | `عدسة خفيفة` | Eloquent content |
| Interface label | `Add to cart` | `أضف إلى السلة` | Laravel language files |
| API index | `/api/products` | Same URL, negotiated response | HTTP locale selection |

The unprefixed URL is always English in this design. A visitor's cookie or browser setting must **not** silently change `/products` to Arabic: that URL has one canonical language. An API response can vary by `Accept-Language` because its URL intentionally does not encode a locale.

Also distinguish two forms of fallback. A missing **product name** may reasonably display the English name. A missing **Arabic slug** must not silently generate a supposedly Arabic URL containing the English slug. Content fallback and URL identity have different correctness requirements.

## Build the Native Laravel Baseline

Laravel already solves interface text and per-request application locale. In `.env`, set:

```dotenv
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```

Create ordinary `lang/en/catalog.php` and `lang/ar/catalog.php` files (run `php artisan lang:publish` first if your application has no `lang/` directory):

```php
<?php

// lang/en/catalog.php
return ['add_to_cart' => 'Add to cart'];
```

```php
<?php

// lang/ar/catalog.php
return ['add_to_cart' => 'أضف إلى السلة'];
```

Blade can now render `{{ __('catalog.add_to_cart') }}` in the active locale. Laravel also lets you call `App::setLocale('ar')` during a request. Neither feature stores an Arabic product name or creates `/ar/المنتجات` automatically. These are separate jobs; see the [Laravel localization documentation](https://laravel.com/docs/13.x/localization).

### Native database translations

A normal Eloquent design can use a `products` table and a `product_translations` table keyed by `(product_id, locale)`:

```php
<?php

// database/migrations/xxxx_xx_xx_create_catalogue_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
    }
};
```

The first unique index prevents duplicate translations for a product and locale. The second prevents two products from claiming the same slug in one locale. Both translated fields are nullable so partially translated records can exist; later, the model marks fields as *required for editorial completeness*, not as database `NOT NULL` constraints. It assumes slugs are unique within the entire catalogue; tenant-scoped applications should put their tenant identity into the uniqueness model as well.

```php
<?php

// app/Models/Product.php: native baseline only
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['is_published'];

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }
}
```

```php
<?php

// app/Models/ProductTranslation.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    protected $fillable = ['product_id', 'locale', 'name', 'slug', 'description'];
}
```

A controller can query the requested translation and then try the fallback locale:

```php
<?php

use App\Models\Product;

$product = Product::findOrFail($id);
$requested = app()->getLocale();

$translation = $product->translations()->where('locale', $requested)->first();
$fallback = $requested === config('app.fallback_locale')
    ? null
    : $product->translations()->where('locale', config('app.fallback_locale'))->first();

$name = $translation?->name ?? $fallback?->name;
```

This is legitimate Eloquent, not a defective workaround. For two languages and a few pages, you can stop here. But every controller now has to settle missing values, eager-loading, translated slugs, strict URL lookup, language-switch links, and index queries. The native solution becomes costly **when the same rules have to be repeated consistently**, not merely because the application supports multiple languages.

## Connect URL Localization to Eloquent Translation

The package implementation replaces the native model logic while retaining the same table layout:

```bash
composer require pharaonic/laravel-localization pharaonic/laravel-translatable
php artisan vendor:publish --tag=localization-config
php artisan vendor:publish --tag=translatable-config
```

In the published `config/pharaonic/localization.php`, change `supported` to `['en', 'ar']`; keep the provided `path` and `resolvers` defaults. The important settings are:

```php
<?php

// Relevant entries in config/pharaonic/localization.php
return [
    'supported' => ['en', 'ar'],
    'path' => [
        'hide_default' => true,
        'canonical_redirect' => true,
        'prefixes' => [],
    ],
    'resolvers' => [
        'web' => ['user', 'session', 'cookie', 'header'],
        'api' => ['user', 'header'],
    ],
    'session' => ['key' => 'locale'],
    'cookie' => [
        'key' => 'locale',
        'minutes' => 60 * 24 * 365,
        'same_site' => 'lax',
        'secure' => null,
    ],
    'header' => ['name' => 'Accept-Language'],
    'locales' => [],
];
```

The default and fallback locale still come from **Laravel**, not duplicate configuration keys. In `config/translatable.php`, the default strategy is `env('TRANSLATABLE_STRATEGY', 'table')`. We use the default `table` strategy for product content because it suits several locales and ordinary relational constraints.

### Keep URL segments separate from model slugs

The string `products` is part of the route; the slug identifies one product. Translate the former with a language file, not an Eloquent field:

```php
<?php

// lang/ar/routes.php
return ['products' => 'المنتجات'];
```

```php
<?php

// routes/web.php
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::localized(function (): void {
    Route::get('/products', [ProductController::class, 'index'])
        ->name('products.index');

    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->name('products.show');
});
```

This registers real routes in both languages: `/products/{product}` and `/ar/المنتجات/{product}`. With the default-prefix policy above, GET `/en/products` redirects to `/products`; it does not negotiate a different language. Unlike runtime route rewriting, the registration is compatible with `route:cache` when the actions use controllers. A change to supported locales or translated segments requires a route-cache rebuild.

### Make translated fields the model's content API

Replace the native `Product` model shown earlier with the following **table-strategy** implementation. Keep `ProductTranslation` and the migration as written above:

```php
<?php

// app/Models/Product.php: package implementation
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Localization\Contracts\HasLocalizedRouteKey;
use Pharaonic\Laravel\Translatable\Contracts\TableTranslatable;
use Pharaonic\Laravel\Translatable\Translatable;

class Product extends Model implements TableTranslatable, HasLocalizedRouteKey
{
    use Translatable;

    protected $fillable = ['is_published'];

    public function translatable(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true],
            'slug' => ['type' => 'string', 'required' => true, 'fallback' => false],
            'description' => ['type' => 'string'],
        ];
    }

    public function getLocalizedRouteKey(string $locale): ?string
    {
        $slug = $this->getTranslation('slug', $locale);

        return $slug === null || trim($slug) === '' ? null : $slug;
    }

    public function scopeWithLocalizedUrl(Builder $query, string $locale): void
    {
        $query->whereHas('translations', function (Builder $translations) use ($locale): void {
            $translations->where('locale', $locale)
                ->whereNotNull('slug')
                ->whereRaw('TRIM(slug) <> ?', ['']);
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (locale()->mode() !== 'path') {
            // Keep normal ID/key binding for non-localized API and other routes.
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()
            ->whereTranslation('slug', (string) $value, app()->getLocale())
            ->first();
    }
}
```

That last method is **application integration code**, not an automatic feature supplied by either package. `laravel-localization` sets the route locale before Laravel substitutes route parameters; `laravel-translatable` exposes the exact localized slug and a locale-aware query. The model combines them. The explicit `mode() !== 'path'` branch matters: without it, overriding binding to look up slugs could accidentally break API routes that still identify products by ID.

Renamed slugs need a separate slug-history/redirect policy. Cross-language matches would weaken the URL contract.

`withLocalizedUrl()` excludes null, empty, and space-only slugs before pagination. `getLocalizedRouteKey()` also rejects blank values when building language links. Validate all new slugs at the write boundary, as shown below; these guards also handle existing blank records.

A controller and two small views complete the web example:

```php
<?php

// app/Http/Controllers/ProductController.php
namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->where('is_published', true)
            ->withLocalizedUrl(app()->getLocale())
            ->withTranslations([app()->getLocale(), config('app.fallback_locale')])
            ->paginate(20);

        return view('products.index', compact('products'));
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_published, 404);

        return view('products.show', compact('product'));
    }
}
```

```blade
<!-- resources/views/products/index.blade.php -->
@foreach ($products as $product)
    <a href="{{ locale()->route('products.show', ['product' => $product]) }}">
        {{ $product->name }}
    </a>
@endforeach
{{ $products->links() }}
```

```blade
<!-- resources/views/products/show.blade.php -->
<!doctype html>
<html lang="{{ locale()->normalize(locale()->current()) }}" dir="{{ locale()->direction() }}">
<head>
    <link rel="canonical" href="{{ locale()->alternates()[locale()->current()] ?? url()->current() }}">
    @foreach (locale()->hreflangs() as $language => $url)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">
    @endforeach
</head>
<body>
    <h1>{{ $product->name }}</h1>
    <p>{{ $product->description }}</p>
</body>
</html>
```

The show view owns canonical and alternate tags here. In a larger application, move that head block into the shared layout or pass its URL map to your SEO layer.

### Save values once, then let the current locale select them

```php
<?php

use App\Models\Product;
use Illuminate\Support\Facades\Validator;

$translations = [
    'en' => [
        'name' => 'Light Lens',
        'slug' => 'light-lens',
        'description' => 'A compact travel lens.',
    ],
    'ar' => [
        'name' => 'عدسة خفيفة',
        'slug' => 'عدسة-خفيفة',
        'description' => 'عدسة صغيرة للسفر.',
    ],
];

foreach ($translations as &$translation) {
    $slug = $translation['slug'] ?? null;
    if (is_string($slug)) {
        $translation['slug'] = trim($slug) === '' ? null : trim($slug);
    }
}
unset($translation);

Validator::make($translations, [
    '*.slug' => ['nullable', 'string', 'max:255', 'regex:/\A[\p{L}\p{M}\p{N}]+(?:-[\p{L}\p{M}\p{N}]+)*\z/u'],
])->validate();

$product = Product::create(['is_published' => true]);
$product->fillTranslations($translations)->save();

$product->getTranslation('name', 'ar'); // 'عدسة خفيفة'
$product->getTranslation('slug', 'en'); // 'light-lens'
```

Normalize blank slugs to `null` and validate their format on every write path, including imports and admin forms. This rule allows Unicode letters, combining marks, numbers, and separating hyphens; the database index enforces per-locale uniqueness. A partial translation may omit its slug.

In a view, `$product->name` reads the **current request locale**, with display fallback. `getTranslation('name', 'ar')` reads the **exact stored value**.

To inspect what users actually see rather than guessing which locale supplied it:

```php
<?php

$resolved = $product->resolveTranslation('name', 'ar');

$resolved->value;           // Displayed value.
$resolved->resolvedLocale;  // Locale that supplied that value.
$resolved->isFallback;      // Whether a fallback was used.
$product->missingTranslations('ar'); // Required fields still missing.
```

`hasCompleteTranslation('ar')` checks the fields marked `required`; it does not validate URL syntax or require every optional field to be translated.

## Generate Correct Language Links, Not Just Language Flags

For this product, the logical route name is `products.show` in both languages. Let Localization choose the concrete route and ask the model for its locale-specific key:

```php
<?php

locale()->route('products.show', ['product' => $product], 'en');
// https://example.com/products/light-lens

locale()->route('products.show', ['product' => $product], 'ar');
// https://example.com/ar/المنتجات/عدسة-خفيفة (URL-encoded on the wire)
```

For the **current page**, `switchUrl()` preserves route parameters and the query string; `alternates()` returns query-free variants; `hreflangs()` also adds `x-default` for the default-locale URL.

```blade
<!-- resources/views/partials/language-switcher.blade.php -->
<nav aria-label="Language">
    @foreach (locale()->supported() as $code)
        @if ($url = locale()->switchUrl($code))
            <a href="{{ $url }}"
               lang="{{ locale()->normalize($code) }}"
               hreflang="{{ locale()->normalize($code) }}"
               @if ($code === locale()->current()) aria-current="true" @endif>
                {{ locale()->nativeName($code) }}
            </a>
        @endif
    @endforeach
</nav>
```

If a product has no usable Arabic slug, `switchUrl('ar')` returns `null` and `alternates()` omits Arabic. The switcher and the head tags therefore advertise only available variants.

There is no automatic remembered-language redirect on a path-localized website. To store a preference after a deliberate language choice, use `locale()->remember($code, 'session')` or `remember($code, 'cookie')` from an application-owned, CSRF-protected route. Then redirect to a URL generated for the chosen locale. A saved preference never overrides an explicit `/ar/...` URL.

## Keep APIs on a Negotiated Locale Contract

The API keeps one URL per resource. Ensure `routes/api.php` is registered through `withRouting(api: ...)` in `bootstrap/app.php`; use Laravel's `php artisan install:api` if you also need its Sanctum setup. The `localization` middleware uses the `api` resolver profile, initially `['user', 'header']`, without redirecting:

```php
<?php

// routes/api.php
use App\Http\Controllers\ApiProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('localization')->group(function (): void {
    Route::get('/products', [ApiProductController::class, 'index']);
    Route::get('/products/{product}', [ApiProductController::class, 'show']);
});
```

```php
<?php

// app/Http/Controllers/ApiProductController.php
namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ApiProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('is_published', true)
            ->withTranslations([app()->getLocale(), config('app.fallback_locale')])
            ->orderBy('id')
            ->paginate(20)
            ->through(fn (Product $product) => $this->productData($product));

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_published, 404);
        $product->load('translations');

        return response()->json($this->productData($product));
    }

    private function productData(Product $product): array
    {
        return [
            'id' => $product->getKey(),
            'name' => $product->name,
            'name_locale' => $product->resolveTranslation('name')->resolvedLocale,
        ];
    }
}
```

The index returns a paginator with products in `data` and pagination metadata alongside it. Both endpoints hide unpublished products; the detail endpoint binds `{product}` by ID.

With `Accept-Language: ar-EG,ar;q=0.9,en;q=0.8` and no higher-priority user preference, `ar` is selected from the supported list. The middleware emits `Content-Language`, adds `Vary: Accept-Language` when the header resolver was read, and marks relevant user/session/cookie-selected cacheable responses private. Your own **custom resolver** must also set appropriate cache headers if it reads tenant or personal data. Avoid public full-response caching across languages unless the cache key explicitly distinguishes the relevant locale and variation policy.

For authenticated users, the built-in `user` resolver reads `preferredLocale()` from a model implementing Laravel's `HasLocalePreference`; it does **not** infer a column magically. An API that authenticates tokens should put authentication middleware before `localization`, for example `['auth:sanctum', 'localization']` when Sanctum is actually installed. Sessions and encrypted cookies belong in a `web` middleware context, not a default stateless API context.

## Choose Translation Storage by Query Shape

The example used the default **table** strategy, not because it is universally faster, but because translated slugs benefit from an ordinary `(locale, slug)` unique index and because content may grow to more locales. Translatable exposes three explicit strategies with a shared model API:

| Strategy | Storage shape | Useful when | Trade-off |
| --- | --- | --- | --- |
| `table` | `product_translations` rows | Many locales, full-text content, ordinary relational constraints | Extra relation; eager-load it for lists |
| `json` | `products.name` JSON object by locale | Few translated fields, variable locale set | Queries/indexes are database-specific in cost and design |
| `columns` | `name_en`, `name_ar` | Small, fixed locale set and ordinary column indexes | Adding a locale requires a schema migration |

A model can explicitly implement **one** marker interface: `TableTranslatable`, `JsonTranslatable`, or `ColumnsTranslatable`. Without a marker it inherits `translatable.strategy` (default: `table`). The default is application-wide, but the marker makes an individual model's choice explicit. There are no shared-table, hybrid, or per-field storage strategies in this API.

For example, an independently managed tag with a JSON column named `name` might implement `JsonTranslatable`, while a fixed-locale lookup table could implement `ColumnsTranslatable`. **Do not change a model's strategy without moving the data.** The supported workflow is `translatable:inspect`, `translatable:make-schema`, `translatable:migrate` (dry-run first), `translatable:verify`, then switching the marker. Use a real migration review and backup before applying it.

### Query and loading consequences

A product index in the active locale should eager-load the table translations instead of reading them lazily per row:

```php
<?php

$products = Product::query()
    ->where('is_published', true)
    ->withLocalizedUrl(app()->getLocale())
    ->withTranslations([app()->getLocale(), config('app.fallback_locale')])
    ->orderByTranslation('name', 'asc', app()->getLocale())
    ->paginate(20);

$untranslated = Product::query()
    ->whereMissingTranslation('ar', 'name')
    ->get();
```

The same translation query API works across the three strategies, but SQL and indexing costs differ. Check query plans on your database, especially for large catalogues and fallback sorting. `withLocalizedUrl()` is an application scope specific to this guide's table strategy.

## Test the Invariants That Usually Break

These feature tests use the migration, package model, controllers, registered web/API routes, and views above. Keep `APP_LOCALE=en` and `APP_FALLBACK_LOCALE=en` in the test environment, with `supported` set to `['en', 'ar']` before application boot.

```php
<?php

// tests/Feature/MultilingualCatalogueTest.php
namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultilingualCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $arabic = [], bool $published = true): Product
    {
        $product = Product::create(['is_published' => $published]);
        $product->fillTranslations([
            'en' => ['name' => 'Light Lens', 'slug' => 'light-lens'],
            'ar' => $arabic,
        ])->save();

        return $product;
    }

    public function test_arabic_url_resolves_only_the_arabic_slug(): void
    {
        $product = $this->product(['name' => 'عدسة خفيفة', 'slug' => 'عدسة-خفيفة']);

        $this->get(locale()->route('products.show', ['product' => $product], 'ar'))
            ->assertOk()
            ->assertSee('عدسة خفيفة');

        $this->get('/ar/المنتجات/light-lens')->assertNotFound();
    }

    public function test_missing_arabic_slug_is_not_a_language_alternate(): void
    {
        $this->product(['name' => 'عدسة خفيفة']);

        $this->get('/products/light-lens')
            ->assertOk()
            ->assertSee('rel="canonical"', false)
            ->assertDontSee('hreflang="ar"', false);

        $this->get('/ar/المنتجات')->assertOk()->assertDontSee('عدسة خفيفة');
    }

    public function test_blank_slugs_are_excluded_from_lists_and_alternates(): void
    {
        // Deliberately bypass input validation to simulate legacy records.
        foreach (['', '   '] as $slug) {
            $product = $this->product(['name' => 'Blank slug product', 'slug' => $slug]);

            $this->get('/ar/المنتجات')
                ->assertOk()
                ->assertDontSee('Blank slug product');

            $this->get('/products/light-lens')
                ->assertOk()
                ->assertDontSee('hreflang="ar"', false);

            $product->delete();
        }
    }

    public function test_display_name_falls_back_without_changing_the_slug(): void
    {
        $product = $this->product(['slug' => 'عدسة-خفيفة']);
        $resolved = $product->resolveTranslation('name', 'ar');

        $this->assertSame('Light Lens', $resolved->value);
        $this->assertSame('en', $resolved->resolvedLocale);
        $this->assertTrue($resolved->isFallback);
        $this->assertNull($product->getTranslation('name', 'ar'));

        $this->get('/ar/المنتجات/عدسة-خفيفة')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertSee('Light Lens');
    }

    public function test_arabic_cookie_does_not_change_an_unprefixed_page(): void
    {
        $this->product(['name' => 'عدسة خفيفة', 'slug' => 'عدسة-خفيفة']);

        $this->withCookie('locale', 'ar')->get('/products')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('Light Lens')
            ->assertDontSee('عدسة خفيفة');
    }

    public function test_default_prefix_redirects_to_the_canonical_url(): void
    {
        $this->get('/en/products')->assertStatus(301)->assertRedirect('/products');
    }

    public function test_api_negotiates_language_and_declares_cache_variation(): void
    {
        $this->product(['name' => 'عدسة خفيفة']);

        $response = $this->withHeaders(['Accept-Language' => 'ar-EG,ar;q=0.9'])
            ->getJson('/api/products')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.0.name', 'عدسة خفيفة')
            ->assertJsonPath('data.0.name_locale', 'ar');

        $this->assertContains('Accept-Language', $response->baseResponse->getVary());
        $this->assertTrue($response->baseResponse->headers->hasCacheControlDirective('private'));
    }

    public function test_api_keeps_id_binding_and_reports_display_fallback(): void
    {
        $product = $this->product();

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('id', $product->getKey())
            ->assertJsonPath('name', 'Light Lens')
            ->assertJsonPath('name_locale', 'en');

        $this->getJson('/api/products/light-lens')->assertNotFound();
    }

    public function test_unpublished_products_are_hidden_on_web_and_api(): void
    {
        $product = $this->product(['name' => 'عدسة خفيفة', 'slug' => 'عدسة-خفيفة'], false);

        $this->get('/products')->assertOk()->assertDontSee('Light Lens');
        $this->get('/products/light-lens')->assertNotFound();
        $this->get('/ar/المنتجات/عدسة-خفيفة')->assertNotFound();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products/'.$product->getKey())->assertNotFound();
    }

    public function test_api_paginates_published_products(): void
    {
        for ($index = 1; $index <= 21; $index++) {
            $product = Product::create(['is_published' => true]);
            $product->setTranslation('name', 'en', 'Lens '.$index)->save();
        }

        $this->getJson('/api/products')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 21)
            ->assertJsonPath('last_page', 2);

        $this->getJson('/api/products?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('current_page', 2);
    }
}
```

Run the suite, then rebuild the route cache and run it again to check route registration as well as request behavior:

```bash
php artisan test --filter=MultilingualCatalogueTest
php artisan route:cache
php artisan test --filter=MultilingualCatalogueTest
php artisan route:clear
```

These checks cover the default private JSON responses. If your application enables shared caching or adds custom resolvers, also test its public-to-private transitions and cache keys with authenticated user preferences.

## When You Do Not Need Both Packages

If you only translate navigation, validation messages, and static copy, **Laravel's `lang/` files are enough**. If users switch a simple internal dashboard between two languages, a small middleware that calls `App::setLocale()` may be all you need. If you only translate model fields in a single-language URL scheme, a storage strategy or a simple Eloquent relationship may be enough without specialized routing.

Use both packages when **public URLs carry a stable language**, **products have independently translated content and slugs**, and **the same locale and fallback rules must remain consistent across controllers, SEO links, and API responses**. They still do not provide a translation editor, machine translation, a slug-history service, or an automatic integration between the two packages. Those belong to your application if your product needs them.

The useful architecture is not "install localization twice." It is one authoritative locale for the current request, one explicit place to store each translated field, and a strict distinction between **content that may fall back** and **URLs that must identify exactly one localized resource**.

## References

- [Eloquent relationships](https://laravel.com/docs/13.x/eloquent-relationships).
- [Laravel Localization Package](https://pharaonic.dev/packages/laravel/localization).
- [Laravel Translatable Package](https://pharaonic.dev/packages/laravel/translatable).
