---
title: "How to Use Tagify with Livewire 4 Without Manual Synchronization"
slug: "tagify-livewire"
description: "Integrate Tagify with Livewire 4, keep client and server state synchronized, and replace fragile lifecycle code with a focused integration."
category: "livewire"
tags:
  - livewire
  - livewire-4
  - tagify
  - laravel
  - javascript
  - forms
related_packages:
  - livewire-tagify
published_at: "2026-10-29"
status: "published"
image: "assets/cover.webp"
keywords:
  - tagify livewire 4
  - livewire tagify
  - tagify wire model
  - tagify with livewire
  - livewire tags input
  - tagify wire ignore
---

Adding a tag works. Then you clear the field on the server—and the old tags stay on screen.

That is the integration problem: **Tagify owns the widget; Livewire owns the data.** Initializing the input connects neither direction automatically. Let's build that connection, then see what Pharaonic Livewire Tagify removes from your application code.

> **Quick Answer:** `wire:ignore` protects Tagify's markup, but you still need two-way value synchronization and cleanup. Pharaonic Livewire Tagify handles those jobs through `wire:model`, with `data-direct` for plain arrays.

## Start with One Skills Field

Assume an existing Laravel application with Livewire 4.3.4 or later in the 4.x line, the minimum required by the package below.

Install [Tagify](https://github.com/yairEO/tagify):

```bash
npm install @yaireo/tagify
```

In `resources/js/app.js`:

```javascript
import Tagify from '@yaireo/tagify'
import '@yaireo/tagify/dist/tagify.css'

window.Tagify = Tagify
```

Load this entry with `@vite('resources/js/app.js')` in your layout. Run `npm run dev` locally or `npm run build` for deployment. Load Livewire once; it already includes Alpine.

Use this component in `app/Livewire/ProfileForm.php`:

```php
<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProfileForm extends Component
{
    public array $skills = ['php'];

    public function clearSkills(): void
    {
        $this->skills = [];
    }

    public function render(): View
    {
        return view('livewire.profile-form');
    }
}
```

The goal is simple: show `php` initially, accept new tags, and clear the widget when `clearSkills()` runs.

## Protecting the DOM Is Only Half the Job

Tagify creates markup around the original input. Wrapping it in [wire:ignore](https://livewire.laravel.com/docs/4.x/wire-ignore) prevents Livewire from morphing that markup.

But an ignored field does not receive new values from Blade after each request. You still need:

- **Widget → Livewire:** convert tag objects into the array your component expects.
- **Livewire → widget:** display the initial value and later server changes.
- **Cleanup:** remove the watcher and destroy Tagify when the field disappears.

A change listener alone covers only the first direction.

## The Manual Solution

Add this helper to `app.js`, after the imports and before Livewire starts Alpine:

```javascript
document.addEventListener('alpine:init', () => {
    Alpine.data('manualTagify', (property) => {
        let tagify
        let stopWatching

        const values = () => tagify.getCleanValue().map(tag => tag.value)
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)

        const display = (value) => {
            const next = value ?? []

            if (!same(values(), next)) {
                tagify.loadOriginalValues(JSON.stringify(
                    next.map(value => ({ value }))
                ))
            }
        }

        return {
            init() {
                tagify = new window.Tagify(this.$refs.input)
                display(this.$wire.$get(property))

                tagify.on('change', () => {
                    const next = values()

                    if (!same(next, this.$wire.$get(property))) {
                        this.$wire.$set(property, next, false).catch(console.error)
                    }
                })

                stopWatching = this.$wire.$watch(property, display)
            },

            destroy() {
                stopWatching?.()
                tagify?.destroy()
            },
        }
    })
})
```

In `resources/views/livewire/profile-form.blade.php`:

```blade
<div>
    <label for="skills">Skills</label>
    <div wire:ignore x-data="manualTagify('skills')">
        <input id="skills" x-ref="input">
    </div>
    <button type="button" wire:click="clearSkills">Clear</button>
</div>
```

Render `<livewire:profile-form />` in a Blade page. The helper reads the initial array, updates Livewire's client state when tags change, and watches for property updates. `false` defers sending user changes until the next request.

`loadOriginalValues()` updates Tagify without firing its change event. The equality check skips redundant reloads, and Alpine's `destroy()` disposes the watcher and widget. There is no `wire:model`: this helper owns the binding. See the [Livewire JavaScript API](https://livewire.laravel.com/docs/4.x/javascript) for the `$wire` methods.

## Where the Maintenance Starts

This is a reusable helper, so several fields do not require several copied scripts. For a small application with plain string tags, it may be enough.

You still own value conversion, both synchronization directions, watcher disposal, and widget teardown. Add richer tag objects or different Tagify modes, and the helper needs more decisions. Framework upgrades mean checking that bridge again.

That is the work a focused integration package can take over.

## The Same Field with Pharaonic

Install [Pharaonic Livewire Tagify](https://pharaonic.dev/packages/livewire/tagify):

```bash
composer require pharaonic/livewire-tagify
```

Keep Tagify's JavaScript and CSS. Add the integration scripts once in your layout:

```blade
@livewireScripts
@livewireTagifyScripts
```

If Livewire is already loaded automatically, add only `@livewireTagifyScripts`.

Keep the PHP component and replace the view with:

```blade
<div>
    <label for="skills">Skills</label>
    <div wire:ignore>
        <input id="skills" data-pharaonic="tagify"
            wire:model="skills" data-direct>
    </div>
    <button type="button" wire:click="clearSkills">Clear</button>
</div>
```

Remove the manual helper if nothing else uses it. The package handles initialization, value synchronization, and cleanup. `data-direct` keeps the property as `['php', 'laravel']` rather than an array of tag objects.

Plain `wire:model` sends changes with the next request. Use `wire:model.live` when a tag change should immediately update server-dependent UI, following [Livewire's binding semantics](https://livewire.laravel.com/docs/4.x/wire-model).

Try Clear: the server assigns `[]`, and Tagify clears too. That second direction is what a quick initialization snippet misses.

## Add Options, Keep the Same Binding

For suggestions and a tag limit, extend the input:

```blade
<div wire:ignore>
    <input data-pharaonic="tagify" wire:model="skills" data-direct
        data-suggest data-max="8" aria-label="Skills"
        data-suggest-list='["php","laravel","livewire","filament"]'>
</div>
```

Suggestions offer choices without restricting input to the list. Validate `skills` and `skills.*` on the server too; a widget limit is not server validation. Keep validation messages outside `wire:ignore`.

For other Tagify settings, use `data-options` as JSON. Without `data-direct`, tags mode binds tag objects; select mode binds one object or value, and mix mode binds a string. Match your PHP property to the mode. The [package documentation](https://pharaonic.dev/packages/livewire/tagify) covers those details.

Keep the manual helper when you want a small integration you control. Choose the package when several forms need the same bridge. Either way, test both directions: add a tag in the browser, then change the property on the server.
