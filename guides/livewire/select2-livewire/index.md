---
title: "How to Use Select2 with Livewire 4"
slug: "select2-livewire"
description: "Learn how to integrate Select2 with Livewire 4, synchronize values, handle wire:ignore, dynamic options, and avoid common DOM update problems."
category: "livewire"
tags:
  - livewire
  - livewire-4
  - select2
  - laravel
  - javascript
  - forms
related_packages:
  - livewire-select2
published_at: "2026-10-27"
status: "published"
image: "assets/cover.webp"
keywords:
  - select2 livewire 4
  - livewire select2
  - select2 with livewire
  - livewire wire ignore select2
  - livewire dynamic select2
  - select2 wire:model
---

Select2 enhances a native select with JavaScript-managed markup. Livewire updates the same page through DOM morphing. Without coordination, the widget can disappear after a request, show an old selection, or keep options that the server has already replaced. Repeated initialization can also leave duplicate handlers.

This guide builds a manual integration first, including initial values, resets, and dependent Country → City fields. Then it replaces the integration code with Pharaonic's current Livewire Select2 package.

> **Quick Answer / TL;DR:**
> Use `wire:ignore` to protect Select2's DOM, then explicitly synchronize the property and widget in both directions. For dependent selects, replace a keyed ignored block when its parent changes, or update its options yourself.

## Start with a Normal Livewire Select

Assume an existing Laravel application with Livewire 4, `Country` and `City` models, and integer primary keys. Each city belongs to a country through `country_id`.

In `app/Livewire/ProfileForm.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Country;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProfileForm extends Component
{
    public ?string $countryId = null;

    public function render(): View
    {
        return view('livewire.profile-form', [
            'countries' => Country::query()->orderBy('name')->get(),
        ]);
    }
}
```

In `resources/views/livewire/profile-form.blade.php`:

```blade
<div>
    <label for="country">Country</label>
    <select id="country" wire:model="countryId">
        <option value="">Choose a country</option>
        @foreach ($countries as $country)
            <option value="{{ $country->id }}">{{ $country->name }}</option>
        @endforeach
    </select>
</div>
```

This works without custom JavaScript. Plain `wire:model` sends the selection with the next request. Use `wire:model.live` when choosing a country must immediately load cities.

IDs are nullable strings here because HTML option values and Select2 selections are strings. Validate them on the server; cast after validation if your application needs integers.

## Add Select2 Normally

Load jQuery, Select2's JavaScript, and Select2's stylesheet once in the layout. Expose the same jQuery instance as `window.jQuery`; load the plugin against that instance. Keep these assets available before initializing a field.

The simplest initialization is:

```javascript
window.jQuery('#country').select2({ width: '100%' })
```

That enhances the field, but it does not provide a Livewire integration. Select2's jQuery `change` event does not automatically behave like the native event Livewire expects. On subsequent requests, Livewire can also morph markup that Select2 still owns.

## What wire:ignore Actually Solves

Protect the plugin's entire DOM subtree, including the container Select2 inserts beside the select:

```blade
<div wire:ignore>
    <select id="country">
        <option value="">Choose a country</option>
        <option value="1">Egypt</option>
    </select>
</div>
```

> **`wire:ignore` protects the DOM. It does not synchronize the value with Livewire.**

It also prevents Livewire from updating the options inside that block. Keep validation messages outside it. See Livewire's [wire:ignore reference](https://livewire.laravel.com/docs/4.x/wire-ignore).

## Build the Manual Integration

Use an Alpine data component for each field. Livewire already includes Alpine. Its `init()` and `destroy()` methods give the widget a lifetime tied to its own DOM block, including when a keyed block is replaced.

Put this in `resources/js/select2-field.js`. Load it once through your application's JavaScript entry point, before Livewire starts Alpine. Do not place it in a component view that executes again on every visit.

```javascript
document.addEventListener('alpine:init', () => {
    Alpine.data('manualSelect2', (property) => {
        let $select
        let stopWatching

        const display = (value) => {
            $select.val(value == null ? '' : String(value))
                .trigger('change.select2')
        }

        return {
            init() {
                $select = window.jQuery(this.$refs.input)

                if (!$select.data('select2')) {
                    $select.select2({ width: '100%' })
                }

                display(this.$wire.$get(property))

                $select.off('.manualSelect2').on('change.manualSelect2', () => {
                    const value = $select.val() ?? ''
                    const current = this.$wire.$get(property) ?? ''

                    if (String(current) !== value) {
                        this.$wire.$set(property, value, true).catch(console.error)
                    }
                })

                stopWatching = this.$wire.$watch(property, display)
            },

            destroy() {
                stopWatching?.()
                $select?.off('.manualSelect2')

                if ($select?.data('select2')) {
                    $select.select2('destroy')
                }
            },
        }
    })
})
```

Use it in the view:

```blade
<div>
    <label>Country</label>
    <div wire:ignore x-data="manualSelect2('countryId')">
        <select x-ref="input" aria-label="Country">
            <option value="">Choose a country</option>
            @foreach ($countries as $country)
                <option value="{{ $country->id }}">{{ $country->name }}</option>
            @endforeach
        </select>
    </div>
</div>
```

This helper owns the binding, so the manual select has no `wire:model`. It uses the closest component's `$wire` through Alpine; it needs no global field ID. The `true` argument sends changes immediately, which the dependent example needs.

The watcher returns a disposer. Call it when the field disappears, even when the Livewire component itself remains mounted. Remove only your own namespaced handlers, then destroy the widget. The initialization guard and namespaced binding prevent repeated setup from stacking handlers.

Server-to-widget updates trigger only `change.select2`, so they refresh Select2 without re-entering the handler that sends changes to Livewire. These APIs are available in the [Livewire 4 JavaScript API](https://livewire.laravel.com/docs/4.x/javascript).

## Initial Values: Three States Must Agree

For an edit form, initialize the property before rendering, for example:

```php
public ?string $countryId = '5';
```

There are three separate states: the Livewire property, the native select's value, and Select2's displayed selection. The helper reads the first, sets the second with `.val()`, and refreshes the third with `change.select2`.

The option with value `5` must already exist. Setting a value does not fetch a missing option or its label. With remote data, add the selected option first, as described in Select2's [programmatic selection documentation](https://select2.org/programmatic-control/add-select-clear-items/).

## Reset the Property and the Widget

With the original default of `null`, reset the field on the server:

```php
public function resetForm(): void
{
    $this->reset('countryId');
}
```

The manual `$watch` callback then updates the ignored select and its visible widget. `wire:ignore` alone would leave the old selection visible.

`reset()` restores the property's declared default. If you declare `'5'` as its default, resetting restores `'5'`; explicitly assign `null` when you mean to clear it.

## Dynamic Options: Country → City

Changing a country changes both the city's available options and its selected value:

```php
public ?string $countryId = null;

public ?string $cityId = null;

public function updatedCountryId(): void
{
    $this->cityId = null;
}
```

Query cities for the current country in `render()`. This avoids keeping a second public copy of the database results. The complete component appears below.

For the manual integration, key the ignored city block by country:

```blade
<div wire:ignore wire:key="manual-city-{{ $countryId ?: 'none' }}"
    x-data="manualSelect2('cityId')">
    <select x-ref="input" aria-label="City">
        <option value="">Choose a city</option>
        @foreach ($cities as $city)
            <option value="{{ $city->id }}">{{ $city->name }}</option>
        @endforeach
    </select>
</div>
```

When the country changes, Livewire replaces the keyed block. Alpine disposes the old watcher, removes its handler, destroys Select2, and initializes the new field with the new options. Unrelated renders keep the existing widget.

If options change while the country stays the same, include an options revision in the key too. A stable key cannot refresh an ignored option list.

For small client-owned lists, you can instead replace native options with `new Option(...)` and notify Select2 without destroying it. Close an open dropdown before replacing its options. With server-rendered Blade options, keyed replacement keeps the server as the single source for option markup and limits recreation to actual parent changes.

## Where the Manual Work Accumulates

The reusable helper keeps the manual approach reasonable. You still own initialization, the ignored boundary, both directions of state synchronization, initial values, reset behavior, keyed option replacement, watcher disposal, listener removal, and widget destruction.

Country, City, Language, and Timezone can all reuse the helper; four copied scripts are unnecessary. But the helper is now an integration layer your application must maintain and verify after framework or plugin upgrades.

## Introduce Pharaonic Livewire Select2

The current [Livewire Select2 package](https://pharaonic.dev/packages/livewire/select2) provides that integration layer through Livewire Assistant. Its 4.x source requires **Livewire 4.3.4 or later in the 4.x line**. Earlier package documentation may describe a different API.

```bash
composer require pharaonic/livewire-select2
```

Keep loading jQuery, Select2, and Select2's CSS yourself. Add this once in the layout:

```blade
@livewireScripts
@livewireSelect2Scripts
```

The directive includes the Assistant runtime and Select2 integration scripts. No Node build is required for the package itself. Do not load its scripts separately again on every field.

The examples below were checked against package **4.0.0**, Livewire **4.4.8**, and Select2 **4.1.0**. They match the `resources/js` implementation and dependent-select browser fixtures in the package's [4.x source](https://github.com/Pharaonic/livewire-select2/tree/4.x).

## The Same Country Field, with Less Integration Code

### Manual Version

```blade
<div wire:ignore x-data="manualSelect2('countryId')">
    <select x-ref="input" aria-label="Country">
        <option value="">Choose a country</option>
        @foreach ($countries as $country)
            <option value="{{ $country->id }}">{{ $country->name }}</option>
        @endforeach
    </select>
</div>
```

This also requires the JavaScript helper shown earlier.

### Pharaonic Version

```blade
<div wire:ignore>
    <select data-pharaonic="select2" wire:model.live="countryId"
        data-options='{"width":"100%"}' aria-label="Country">
        <option value="">Choose a country</option>
        @foreach ($countries as $country)
            <option value="{{ $country->id }}">{{ $country->name }}</option>
        @endforeach
    </select>
</div>
```

Remove the manual helper from package-managed fields. The package initializes Select2, reads the initial property value, writes user changes, watches property updates, and cleans up the widget and its handlers when removed. You still choose where `wire:ignore` belongs.

Plain `wire:model` defers the value until the next request. Use `.live` for immediate dependent updates. `data-property="countryId"` is also supported, takes precedence over `wire:model`, and always sends immediately. The binding accepts dot paths such as `address.countryId`.

## Dynamic Country → City with Pharaonic

Keep the same properties, `updatedCountryId()`, and city query. Replace the helper attributes with package attributes, retaining the key:

```blade
<div wire:ignore wire:key="package-city-{{ $countryId ?: 'none' }}">
    <select data-pharaonic="select2" wire:model.live="cityId"
        data-options='{"width":"100%"}' aria-label="City">
        <option value="">Choose a city</option>
        @foreach ($cities as $city)
            <option value="{{ $city->id }}">{{ $city->name }}</option>
        @endforeach
    </select>
</div>
```

The package tears down the removed widget and binds its replacement. You maintain the dependency and key; it maintains the plugin lifecycle.

Alternatively, omit `wire:ignore` on the dynamic field. Livewire then morphs the options, and the package rebuilds the widget after morphing. This avoids a changing key but recreates Select2 on component renders and closes an open dropdown. The keyed ignored approach avoids that on unrelated requests.

Neither approach observes arbitrary database changes. The component must render fresh options, and an ignored field must receive a changed key when its list changes.

## Several Fields Do Not Need Several Scripts

The manual helper is reusable through `x-data="manualSelect2('language')"` and `x-data="manualSelect2('timezone')"`. Package-managed fields share the same runtime without that application helper:

```blade
<div>
    <div wire:ignore>
        <select data-pharaonic="select2" wire:model="language" aria-label="Language">
            <option value="en">English</option>
            <option value="ar">Arabic</option>
        </select>
    </div>
    <div wire:ignore>
        <select data-pharaonic="select2" wire:model="timezone" aria-label="Timezone">
            <option value="Africa/Cairo">Cairo</option>
            <option value="UTC">UTC</option>
        </select>
    </div>
</div>
```

Declare matching component properties, for example `public string $language = 'en';` and `public string $timezone = 'Africa/Cairo';`. Add these alongside Country and City without extra initialization calls. Repeated rows need stable, unique `wire:key` values; use a record's identity rather than its loop position.

## Multiple Selection

The package supports a native `multiple` select bound to an array:

```php
public array $tagIds = [];
```

```blade
<div wire:ignore>
    <select multiple data-pharaonic="select2" wire:model="tagIds" aria-label="Tags">
        @foreach ($tags as $tag)
            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
        @endforeach
    </select>
</div>
```

Pass `$tags` from the component. A selection produces an array of strings, such as `['3', '8']`; clearing produces `[]`. Initial integer values are converted to strings for display. `data-tags` additionally permits creating free-text options, so do not enable it for a restricted list of database IDs.

The earlier manual helper handles single values only. To support multiple values manually, normalize values to arrays, compare their contents, and apply the array with `.val(values).trigger('change.select2')`.

## Validate the Property on the Server

Select2 provides the UI. Laravel and Livewire still validate the submitted property:

```php
use Livewire\Attributes\Validate;

#[Validate('required|exists:countries,id')]
public ?string $countryId = null;

#[Validate(['tagIds' => 'array', 'tagIds.*' => 'integer|distinct|exists:tags,id'])]
public array $tagIds = [];
```

Call `$this->validate()` before saving. An `array` rule alone does not validate the IDs inside it. For dependent fields, also check that the city belongs to the chosen country; the full example uses a constrained `Rule::exists` for this.

Render `@error` messages outside ignored blocks so Livewire can update them.

## Select2 Inside a Modal

Select2 normally attaches its dropdown to the document body. Modal focus handling and stacking can interfere with that dropdown. In a manual integration, pass the modal as `dropdownParent`:

```javascript
window.jQuery('#country').select2({
    dropdownParent: window.jQuery('#user-modal'),
})
```

With the package, set `data-parent="#user-modal"` on the select. An empty `data-parent` finds the closest `.modal`, `dialog`, or `[role="dialog"]`:

```blade
<div role="dialog" id="user-modal">
    <div wire:ignore>
        <select data-pharaonic="select2" wire:model="countryId" data-parent
            aria-label="Country">
            <option value="">Choose a country</option>
            <option value="1">Egypt</option>
        </select>
    </div>
</div>
```

This is Select2 dropdown placement and focus behavior, rather than a Livewire state issue. See Select2's [modal troubleshooting](https://select2.org/troubleshooting/common-problems).

## Common Mistakes

| Mistake | Result | Fix |
| --- | --- | --- |
| Add only `wire:ignore` | Protected markup, unsynchronized state | Bind user changes and watch property changes |
| Initialize after every request | Repeated setup and possible orphaned markup | Mount once per field lifetime; destroy on removal |
| Register `change` repeatedly | Duplicate updates or requests | Namespace and remove your handlers |
| Replace ignored options on the server | Old options remain visible | Replace a keyed block or manage options explicitly |
| Call `.val()` without notifying Select2 | Native value changes but displayed label stays old | Trigger `change.select2` for display-only synchronization |
| Change only the widget | Server still holds another value | Write to the Livewire property |
| Change only the server property | Ignored UI displays the previous value | Watch the property and refresh the widget |
| Use `.change` expecting an immediate request | Dependent options wait for another action | Use `wire:model.live` on Livewire 4 |
| Copy old `message.processed` or `emit` examples | Lifecycle code targets an older Livewire version | Use current component APIs and scoped cleanup |

Do not combine the manual helper with the package on one select. With package-managed `wire:change`, prefer an `updatedCountryId()` hook for dependent state: the package dispatches a native change for `wire:change`, which additional jQuery change listeners can also receive.

## Manual vs Pharaonic

| Responsibility | Manual helper | Current Pharaonic package |
| --- | --- | --- |
| Load jQuery, Select2, and CSS | Application | Application |
| Initialize Select2 | Helper | Package |
| Add `wire:ignore` | Blade | Blade; optional with package morph rebuilding |
| Write choices to Livewire | `$wire.$set` | Package binding |
| Display initial values and server resets | `$get` and `$watch` | Package binding |
| Remove handlers and destroy widgets | Alpine `destroy()` | Assistant lifecycle and package teardown |
| Dependent options | Server query plus keyed replacement | Same query and key, or package morph rebuilding |
| Several fields | Reuse application helper | Reuse package attributes |
| Multiple values | Extend helper for arrays | Built in |
| Validation and dependency rules | Application | Application |

## Full Manual Example

This complete Country → City form uses the single-select helper above. Load that helper once before Alpine starts, with jQuery, Select2, and its CSS already available. Mount the component with `<livewire:profile-form />`.

In `app/Livewire/ProfileForm.php`:

```php
<?php

namespace App\Livewire;

use App\Models\City;
use App\Models\Country;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ProfileForm extends Component
{
    public ?string $countryId = null;

    public ?string $cityId = null;

    public bool $submitted = false;

    public function updatedCountryId(): void
    {
        $this->cityId = null;
        $this->submitted = false;
        $this->resetValidation('cityId');
    }

    protected function rules(): array
    {
        return [
            'countryId' => ['required', 'integer', 'exists:countries,id'],
            'cityId' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('country_id', $this->countryId),
            ],
        ];
    }

    public function submit(): void
    {
        $this->validate();

        // Persist the validated IDs using your application's authorization rules.
        $this->submitted = true;
    }

    public function resetForm(): void
    {
        $this->reset('countryId', 'cityId', 'submitted');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.profile-form', [
            'countries' => Country::query()->orderBy('name')->get(),
            'cities' => City::query()
                ->where('country_id', $this->countryId)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
```

In `resources/views/livewire/profile-form.blade.php`:

```blade
<form wire:submit="submit">
    <div wire:ignore x-data="manualSelect2('countryId')">
        <select x-ref="input" aria-label="Country">
            <option value="">Choose a country</option>
            @foreach ($countries as $country)
                <option value="{{ $country->id }}">{{ $country->name }}</option>
            @endforeach
        </select>
    </div>
    @error('countryId') <p role="alert">{{ $message }}</p> @enderror

    <div wire:ignore wire:key="manual-city-{{ $countryId ?: 'none' }}"
        x-data="manualSelect2('cityId')">
        <select x-ref="input" aria-label="City">
            <option value="">Choose a city</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </select>
    </div>
    @error('cityId') <p role="alert">{{ $message }}</p> @enderror

    <button type="submit" wire:loading.attr="disabled">Submit</button>
    <button type="button" wire:click="resetForm" wire:loading.attr="disabled">Reset</button>
    @if ($submitted)
        <p role="status">Selection validated.</p>
    @endif
</form>
```

The server queries options; the changing key replaces the city's ignored subtree. The helper initializes the new instance and cleans up the old one. A reset changes the country key and clears both values; resetting only the city is handled by its watcher.

For an edit form, load both property values in `mount()` and ensure the initial city query contains the selected city. Do not call `updatedCountryId()` while loading those initial values, since it deliberately clears the city.

## Full Pharaonic Example

Use **the exact same `ProfileForm` PHP component above**: the properties, validation, country hook, query, submit action, and reset action are unchanged. Load `@livewireSelect2Scripts` once in the layout and use this complete replacement view:

```blade
<form wire:submit="submit">
    <div wire:ignore>
        <select data-pharaonic="select2" wire:model.live="countryId"
            data-options='{"width":"100%"}' aria-label="Country">
            <option value="">Choose a country</option>
            @foreach ($countries as $country)
                <option value="{{ $country->id }}">{{ $country->name }}</option>
            @endforeach
        </select>
    </div>
    @error('countryId') <p role="alert">{{ $message }}</p> @enderror

    <div wire:ignore wire:key="package-city-{{ $countryId ?: 'none' }}">
        <select data-pharaonic="select2" wire:model.live="cityId"
            data-options='{"width":"100%"}' aria-label="City">
            <option value="">Choose a city</option>
            @foreach ($cities as $city)
                <option value="{{ $city->id }}">{{ $city->name }}</option>
            @endforeach
        </select>
    </div>
    @error('cityId') <p role="alert">{{ $message }}</p> @enderror

    <button type="submit" wire:loading.attr="disabled">Submit</button>
    <button type="button" wire:click="resetForm" wire:loading.attr="disabled">Reset</button>
    @if ($submitted)
        <p role="status">Selection validated.</p>
    @endif
</form>
```

There is no component-specific JavaScript. Both forms implement the same behavior; the package owns the Select2/Livewire integration layer in the second form. Keep your server queries, validation, authorization, and dependent-field rules.

## What the Package Saves You from Writing

For these examples, remove the application's `manualSelect2` helper entirely. The package supplies:

- Select2 initialization for marked fields, including fields added after a Livewire update.
- Initial property-to-widget synchronization and subsequent server-to-widget updates, including resets.
- The change handler that writes values using the binding's immediate or deferred behavior.
- Namespaced event cleanup and widget destruction when fields disappear.
- Integration with Livewire Assistant for component ownership, morph updates, and navigation cleanup.
- Array normalization for multiple selects and optional creation of missing free-text tag options.

It does not load the Select2 dependencies, fetch your city records, validate IDs, invent missing labels for ordinary selections, or decide when an ignored option list needs a new key. Outside `wire:ignore`, rebuilding on renders is a deliberate trade-off, not a promise that the widget remains open.

The package cleans up widgets before `wire:navigate` leaves a page and mounts them again when a page is shown. Its runtime scripts use `data-navigate-once`; your dependency assets should also follow your application's navigation loading strategy.

## Choosing an Integration

Livewire owns application state; Select2 owns its JavaScript UI. The manual helper is a reasonable choice for a small application that already maintains similar Alpine adapters. Pharaonic replaces that adapter when you want the shared lifecycle and binding behavior maintained in a package.

Use the [package documentation](https://pharaonic.dev/packages/livewire/select2) for configuration and the [GitHub source](https://github.com/Pharaonic/livewire-select2) to check version-specific behavior. In either approach, keep dependent queries and validation in the Livewire component.
