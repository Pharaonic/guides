---
title: "How to Build a Social Engagement System in Laravel"
slug: "laravel-social-engagement"
description: "Design likes, follows, favorites, bookmarks, ratings, and reactions in Laravel with clear data rules, safe writes, and efficient Eloquent feeds."
category: "laravel"
tags:
  - laravel
  - eloquent
  - polymorphic-relations
  - social-engagement
  - reactions
  - bookmarks
  - ratings
  - data-integrity
related_packages:
  - laravel-likes
  - laravel-follows
  - laravel-favorites
  - laravel-bookmarks
  - laravel-ratings
  - laravel-reacts
published_at: "2026-11-14"
status: "published"
image: "assets/cover.webp"
keywords:
  - laravel social engagement system
  - laravel likes and follows
  - laravel favorites vs bookmarks
  - laravel polymorphic reactions
  - laravel emoji reactions
  - laravel ratings system
  - laravel bookmark metadata
  - laravel engagement database design
  - laravel reaction counts
  - laravel social feed performance
---

Your community app launches with a Like button. Six months later, an author can be followed, a product can be favorited and rated, a post can be saved with a private note, and a chat message can receive an emoji reaction. The team adds a table for each feature. Then the bugs arrive: double-clicks produce duplicate records, updating a bookmark loses its note, one user contributes several reactions to a count labeled *people*, and every feed card runs another handful of queries.

This is not mainly a question of which Eloquent trait to install. It is a **state-modeling problem**: each action needs an identity, a rule for repeat requests, a deletion policy, and a reliable way to present its state. We will build a small version in native Laravel, find its real boundaries, and then use six independent Pharaonic packages where they solve repetitive problems. The result is one coherent application, not six unrelated package tutorials.

> **Quick Answer / TL;DR:** Model likes, follows, favorites, bookmarks, ratings, and reactions according to their *different meanings*. Enforce uniqueness in the database, prefer explicit desired-state writes over blind toggles, and batch read-side counts for feeds. Native Eloquent is enough for simple, fixed pairs; reusable polymorphic packages become useful as actor types, target types, metadata, rating scales, and reaction rules multiply. Do not store the same Like in both Likes and Reactions without an intentional reason.

## Start with the Product Contract

Imagine a creator marketplace with `User`, `Post`, `Product`, and `ChatMessage` models. A sensible design might be:

| Interaction | Meaning in this application | Record identity | Second write |
| --- | --- | --- | --- |
| **Like** | Public approval of a post | Liker + target | Same like is a no-op |
| **Follow** | Follow a creator's updates | Follower + target | Same follow is a no-op |
| **Favorite** | Personal preference for a product | Favoriter + target | Same favorite is a no-op |
| **Bookmark** | Save a post with private context | Bookmarker + target | Update or replace its metadata |
| **Rating** | Assess a product on a scale | Rater + target | Update value or comment |
| **Reaction** | Express a specific response | Reactor + target + type, or one slot per target | Add, replace, or remove according to mode |

The names are product decisions, not universal laws. If your users cannot explain the difference between **Favorite** and **Bookmark**, there may be no need to expose both. In this example, Favorites have no additional data and Bookmarks can hold notes and priority.

The more dangerous overlap is **Like** versus a reaction named `like`. If both buttons mean the same thing on a `Post`, keeping both `likes` and `reactions` as sources of truth produces conflicting counts and notifications. Either make Likes the canonical post approval, remove `like` from the post reaction catalog, or use Reactions alone for that signal. We will use Likes for approval and Reactions for the other expressions.

Before choosing a schema, answer four questions for every action: **Who acts? What can receive it? What does a repeated request do? What exactly does a count mean?**

## Build the Native Laravel Baseline

Start with a deliberately narrow requirement: only `User` models interact with `Post` models, using integer IDs. Laravel migrations, [many-to-many relationships](https://laravel.com/docs/13.x/eloquent-relationships#many-to-many-relationships), and [query builder writes](https://laravel.com/docs/13.x/queries) already solve much of this.

### One like per user and post

Create a migration for the initial Like feature:

```php title="database/migrations/xxxx_xx_xx_create_post_likes_table.php"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
```

A normal Eloquent relation exposes the likers:

```php title="app/Models/Post.php (native baseline)"
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Post extends Model
{
    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_likes')
            ->withTimestamps();
    }
}
```

An explicit *make liked* operation is more predictable than a toggle:

```php
use Illuminate\Support\Facades\DB;

$created = DB::table('post_likes')->insertOrIgnore([
    'user_id' => $user->getKey(),
    'post_id' => $post->getKey(),
    'created_at' => now(),
    'updated_at' => now(),
]) === 1;
```

`$created` reports whether this call inserted a row. A second identical request should leave the pair liked, not undo the first request. Crucially, the **unique index**, not an application-side `exists()` check, prevents two concurrent requests from inserting the same pair.

`insertOrIgnore()` is a convenient *small example*, not a blanket recommendation: some drivers can suppress errors beyond duplicate keys, and MySQL can bypass strict-mode checks. For sensitive writes, handle the relevant unique-constraint error explicitly. For a fixed user-to-post Like, the native approach is perfectly reasonable.

### The first expansion: bookmarks with metadata

Now users want to save posts with a note. Add a separate table instead of overloading `post_likes` with another meaning:

```php title="database/migrations/xxxx_xx_xx_create_post_bookmarks_table.php"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->json('meta');
            $table->timestamps();
            $table->unique(['user_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_bookmarks');
    }
};
```

A native upsert can replace the full JSON object for the same pair:

```php
use Illuminate\Support\Facades\DB;

$meta = ['note' => 'Read before Friday', 'priority' => 2];
$now = now();

DB::table('post_bookmarks')->upsert([
    [
        'user_id' => $user->getKey(),
        'post_id' => $post->getKey(),
        'meta' => json_encode((object) $meta, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ],
], ['user_id', 'post_id'], ['meta', 'updated_at']);
```

This is a valid **replace**, not a merge. But it still leaves product decisions to you: how to reject top-level JSON lists, distinguish an omitted metadata argument from an empty object, prevent identical updates from changing `updated_at`, validate nested values, and return a consistent read type. On MySQL and MariaDB, `upsert()` uses the table's actual primary and unique indexes to detect conflicts, regardless of the `uniqueBy` argument. The migration's unique pair is therefore important.

### The next expansion: reactions with different cardinality

A multi-reaction post can use another native table:

```php title="database/migrations/xxxx_xx_xx_create_post_reactions_table.php"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type_key', 128);
            $table->timestamps();
            $table->unique(['user_id', 'post_id', 'type_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_reactions');
    }
};
```

The triple unique index permits one user to react with `love` *and* `wow`, but not `love` twice. A chat UI that permits **only one reaction per user** has a different invariant. Adding `unique(user_id, post_id)` prevents multiple reactions for **every** post, so it cannot coexist with the intended multiple-mode behavior on that same table.

One native workaround is to serialize writes to the parent and reconcile the actor's rows inside a transaction. For example, this operation enforces single mode for a post, assuming **every writer for that post uses the same locking discipline**:

```php
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function setSingleReaction(User $user, Post $post, string $type): bool
{
    return DB::transaction(function () use ($user, $post, $type) {
        Post::query()->whereKey($post->getKey())
            ->lockForUpdate()->firstOrFail();

        $pair = DB::table('post_reactions')
            ->where('user_id', $user->getKey())
            ->where('post_id', $post->getKey());

        $current = (clone $pair)->pluck('type_key')->all();

        if ($current === [$type]) {
            return false;
        }

        (clone $pair)->delete();

        DB::table('post_reactions')->insert([
            'user_id' => $user->getKey(),
            'post_id' => $post->getKey(),
            'type_key' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }, 3);
}
```

This is not a free abstraction. Locking the parent can serialize **unrelated users** reacting to a popular post, a new type still needs authorization and catalog validation, and SQLite locking behavior differs from row-locking engines. Mixing this function with code that writes reactions without the lock breaks its guarantee. You should test the pattern on your actual database before adopting it. If the product only needs one reaction mode and a few predefined types, a dedicated native schema remains a good option.

### What actually becomes difficult

None of this means Eloquent has failed. The work grows because the product has changed:

- The same action must work with `Post`, `Product`, `User`, and `ChatMessage`, including actors other than users. A single pair of foreign keys is no longer sufficient; [polymorphic relationships](https://laravel.com/docs/13.x/eloquent-relationships#polymorphic-relationships) introduce morph types, aliases, and deletion decisions.
- The write contract varies: boolean creation for Likes, a mutable JSON object for Bookmarks, a scale-constrained numeric value for Ratings, and single/multiple modes for Reactions. A generic `toggle()` repository erases those differences.
- Counting is not the same as presenting. A feed needs counts, the current viewer's selection, and available reaction choices, without loading every child row or triggering an N+1 query.
- A unique constraint prevents duplicate identities, but it does not validate authorization, prevent spam, coordinate notifications, or make an arbitrary multi-step workflow atomic.

These are legitimate reasons to encapsulate each behavior. They are **not** reasons to replace every Eloquent relation in a small application.

## Introduce the Packages at the Persistence Boundary

Pharaonic's packages provide six **independent** polymorphic implementations. There is no requirement to install them together and no unified `Engagement` model that silently mixes their data.

| Domain responsibility | Composer package | Actor trait | Target trait |
| --- | --- | --- | --- |
| Like a target | `pharaonic/laravel-likes` | `CanLike` | `HasLikes` |
| Follow a target | `pharaonic/laravel-follows` | `CanFollow` | `HasFollowers` |
| Favorite a target | `pharaonic/laravel-favorites` | `CanFavorite` | `HasFavorites` |
| Bookmark a target | `pharaonic/laravel-bookmarks` | `CanBookmark` | `HasBookmarks` |
| Rate a target | `pharaonic/laravel-ratings` | `CanRate` | `HasRatings` |
| React to a target | `pharaonic/laravel-reacts` | `CanReact` | `HasReactions` |

The examples below were checked against the repositories' `develop` branches on November 14, 2026. Those branches target Laravel 13 and PHP 8.3–8.5 (Reactions additionally requires `mbstring`). **Check the compatible published tag of each package** before installation: a development branch is not a promise about an existing stable release. Do not change global Composer minimum-stability to work around a missing tag.

For a project that deliberately needs all six, the normal Composer and migration workflow is:

```bash
composer require pharaonic/laravel-likes pharaonic/laravel-follows \
    pharaonic/laravel-favorites pharaonic/laravel-bookmarks \
    pharaonic/laravel-ratings pharaonic/laravel-reacts

php artisan migrate
php artisan vendor:publish --tag=reacts-config
```

Run the combined Composer command only after verifying that compatible releases resolve in your project. Each package auto-discovers its service provider and loads its migration. Publishing the reaction configuration is necessary for our custom reaction groups; most other configuration can remain at its default. If the application already has native interaction tables, **migrate existing data deliberately** before switching the source of truth. Installing a package does not import or reconcile your old rows.

## Give Models Only the Behaviors They Need

For this application, the user acts on several targets and can be followed by other users. A post can be liked, bookmarked, and reacted to. Products can be favorited and rated. Chat messages can receive reactions:

```php title="app/Models/User.php"
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Bookmarks\Concerns\CanBookmark;
use Pharaonic\Laravel\Favorites\Concerns\CanFavorite;
use Pharaonic\Laravel\Follows\Concerns\CanFollow;
use Pharaonic\Laravel\Follows\Concerns\HasFollowers;
use Pharaonic\Laravel\Likes\Concerns\CanLike;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;
use Pharaonic\Laravel\Reacts\Concerns\CanReact;

class User extends Authenticatable
{
    use CanBookmark, CanFavorite, CanFollow, HasFollowers;
    use CanLike, CanRate, CanReact;
}
```

```php title="app/Models/Post.php (package implementation)"
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Bookmarks\Concerns\HasBookmarks;
use Pharaonic\Laravel\Likes\Concerns\HasLikes;
use Pharaonic\Laravel\Reacts\Concerns\HasReactions;
use Pharaonic\Laravel\Reacts\Contracts\ResolvesReactionGroup;

class Post extends Model implements ResolvesReactionGroup
{
    use HasBookmarks, HasLikes, HasReactions;

    public function reactionGroup(): string
    {
        return 'posts';
    }
}
```

```php title="app/Models/Product.php"
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Favorites\Concerns\HasFavorites;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

class Product extends Model
{
    use HasFavorites, HasRatings;
}
```

```php title="app/Models/ChatMessage.php"
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Reacts\Concerns\HasReactions;
use Pharaonic\Laravel\Reacts\Contracts\ResolvesReactionGroup;
use Pharaonic\Laravel\Reacts\Contracts\ResolvesReactionMode;

class ChatMessage extends Model implements ResolvesReactionGroup, ResolvesReactionMode
{
    use HasReactions;

    public function reactionGroup(): string
    {
        return 'chat';
    }

    public function reactionMode(): string
    {
        return 'single';
    }
}
```

These snippets assume conventional persisted models, a `posts.title` column, `posts.is_published`, and `users.is_banned` for the HTTP example below. They do not replace your application's existing fields, relations, casts, or migrations. In particular, the **native baseline tables** and the **package tables** are alternative implementations, not tables you should write to simultaneously.

The actor/target split is meaningful. Calling `$viewer->follow($author)` is natural, while the author exposes `followers()`. The `User` model needs `HasFollowers` only because users can be followed; products do not need it.

## Make Each Write Mean Exactly One Thing

Start with the simple, idempotent pairs:

```php
$viewer->like($post);                 // true only when created
$viewer->like($post);                 // false; the post remains liked
$viewer->hasLiked($post);             // true
$viewer->unlike($post);               // true only when removed

$viewer->follow($author);             // true only when created
$viewer->isFollowing($author);        // true
$viewer->unfollow($author);           // true only when removed

$viewer->favorite($product);         // true only when created
$viewer->hasFavorited($product);      // true
$viewer->unfavorite($product);       // true only when removed
```

`like()`, `follow()`, and `favorite()` report **whether a record was created**, not the current selection state. Their matching toggle methods report the resulting state. Prefer an explicit `PUT` to add and `DELETE` to remove when requests may be retried, because two toggles can cancel each other.

### Bookmarks: omitting metadata is not clearing metadata

A bookmark is a model with a read-only `BookmarkMeta` object:

```php
$bookmark = $viewer->bookmark($post, [
    'note' => 'Read this weekend',
    'priority' => 2,
]);

$bookmark->meta->note;                 // 'Read this weekend'
$bookmark->meta->get('priority', 0);   // 2

$viewer->bookmark($post, ['priority' => 3]); // Replaces the entire object.
$viewer->bookmark($post);                   // Keeps the existing metadata.
$viewer->bookmark($post, []);               // Clears it to {}.
```

The top level must be a JSON object, not a list or scalar. Supplying an object equal to the stored metadata is a no-op. The value object is immutable: to change only the priority without losing another field, copy the current object first:

```php
$meta = $viewer->bookmarkOf($post)->meta->toObject();
$meta->priority = 4;

$viewer->bookmark($post, $meta);
```

This is where a seemingly harmless `PATCH` endpoint becomes dangerous. A patch containing only `priority` **does not mean merge** to `bookmark()`. Either construct the full replacement server-side or make the endpoint a `PUT` whose contract explicitly replaces the complete metadata object.

### Ratings: an updated opinion is still one rating

```php
$result = $viewer->rate($product, 4, 'Useful product');
$result->created();     // true
$result->current();     // 4

$result = $viewer->rate($product, 5);  // Update value; keep old comment.
$result->updated();     // true
$result->previous();    // 4

$viewer->rate($product, 5)->unchanged(); // true
$viewer->rate($product, 5, null);        // Explicitly clear the comment.

$product->averageRating();       // float or null
$product->ratingsCount();        // number of individual ratings
$product->ratingDistribution();  // stored value => count
```

The default scale is `1` to `5` in whole-number steps. A rated model can define a different scale, for example `['min' => 0, 'max' => 10, 'step' => 0.5]`, using `ratingScale()`. A value such as `8.75` is then invalid. Changing the scale does not rewrite historical ratings; migration of those values is your responsibility.

A rating comment is not automatically a review platform. Purchase verification, abuse reports, moderation, reply threads, and display policy still belong to the application.

### Reactions: vocabulary and cardinality are separate

Publish and edit the reaction config at `config/pharaonic/reacts.php`. The following are the **relevant entries within the existing returned array**, not a replacement for its other settings:

```php title="config/pharaonic/reacts.php (selected entries)"
'definitions' => [
    'love' => ['label' => 'Love', 'emoji' => '❤️'],
    'laugh' => ['label' => 'Laugh', 'emoji' => '😂'],
    'wow' => ['label' => 'Wow', 'emoji' => '😮'],
    'fire' => ['label' => 'Fire', 'emoji' => '🔥'],
],
'default_group' => 'posts',
'groups' => [
    'posts' => ['types' => ['love', 'laugh', 'wow']],
    'chat' => ['types' => ['love', 'laugh', 'fire'], 'free_form' => true],
],
'mode' => 'multiple',
```

There is deliberately **no `like` reaction** on posts. The Post trait chooses `posts`, and ChatMessage chooses `chat` and overrides the global mode to `single`:

```php
$viewer->react($post, 'love');          // Added.
$viewer->react($post, 'laugh');         // Also added: post is multiple-mode.
$viewer->react($post, 'laugh');         // false: unchanged.

$viewer->react($chatMessage, 'love');   // Added.
$viewer->react($chatMessage, 'fire');   // Replaces love, not a second row.
$viewer->react($chatMessage, '🚀');     // Replaces fire; chat allows free-form emoji.

$viewer->hasReacted($post, 'love');     // true
$post->reactionCounts();               // count per type, not per unique user
```

The mode is a property of the **reactable**. `multiple` permits one row per reactor, target, and type; `single` keeps one type per reactor and target. A free-form reaction must be one valid emoji sequence, not arbitrary text. The documented emoji parser requires PCRE2 10.40 or later; registered reaction keys can still work where that parser is unavailable.

Removing a type from a group does not delete old rows. Switching a target from `multiple` to `single` does not eagerly rewrite historical rows; the next write reconciles that actor-target pair. If historical data must comply immediately, migrate it. See the package's [reaction configuration and modes](https://pharaonic.dev/packages/laravel/reacts).

## Walk a Real Request from HTTP to the Feed

The packages handle persistence, not your routes, authorization, or UI. Here is one connected slice of the marketplace: a visitor sees a post, signs in, Likes it, bookmarks it, and chooses a reaction. The examples use **web routes with Blade forms**, so mutations redirect back to the page. JSON APIs can use the same domain operations and return a JSON representation instead.

### Authorize the action once, at the application boundary

Assume posts have an `is_published` boolean and users have an `is_banned` boolean. Define the actual business rule using Laravel's [policy conventions](https://laravel.com/docs/13.x/authorization#creating-policies):

```php title="app/Policies/PostPolicy.php"
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function interact(User $user, Post $post): bool
    {
        return (bool) $post->is_published && ! $user->is_banned;
    }
}
```

Laravel can discover `PostPolicy` by convention; register it explicitly if your app has changed its policy mapping. Extend the rule for ownership, blocked accounts, private groups, and tenant boundaries. Disabling a button in HTML is **not** a substitute for this check.

### Use explicit endpoints and preserve bookmark semantics

```php title="routes/web.php"
<?php

use App\Http\Controllers\PostEngagementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::put('/posts/{post}/like', [PostEngagementController::class, 'like'])
        ->name('posts.like');
    Route::delete('/posts/{post}/like', [PostEngagementController::class, 'unlike'])
        ->name('posts.unlike');

    Route::put('/posts/{post}/bookmark', [PostEngagementController::class, 'bookmark'])
        ->name('posts.bookmark');
    Route::delete('/posts/{post}/bookmark', [PostEngagementController::class, 'unbookmark'])
        ->name('posts.unbookmark');

    Route::post('/posts/{post}/reactions', [PostEngagementController::class, 'react'])
        ->name('posts.react');
    Route::delete('/posts/{post}/reactions', [PostEngagementController::class, 'unreact'])
        ->name('posts.unreact');
});
```

```php title="app/Http/Controllers/PostEngagementController.php"
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pharaonic\Laravel\Reacts\Exceptions\InvalidReactionType;
use Pharaonic\Laravel\Reacts\Exceptions\ReactionNotAllowed;

class PostEngagementController extends Controller
{
    public function like(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);
        $request->user()->like($post);

        return back();
    }

    public function unlike(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);
        $request->user()->unlike($post);

        return back();
    }

    public function bookmark(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);

        // This PUT replaces the entire bookmark metadata object.
        // Empty input intentionally clears metadata to {}.
        $meta = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        $request->user()->bookmark($post, $meta);

        return back();
    }

    public function unbookmark(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);
        $request->user()->unbookmark($post);

        return back();
    }

    public function react(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);
        $type = $request->validate([
            'type' => ['required', 'string', 'max:128'],
        ])['type'];

        try {
            $request->user()->react($post, $type);
        } catch (InvalidReactionType $exception) {
            throw ValidationException::withMessages([
                'type' => $exception->getMessage(),
            ]);
        } catch (ReactionNotAllowed $exception) {
            abort(403);
        }

        return back();
    }

    public function unreact(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('interact', $post);
        $type = $request->validate([
            'type' => ['required', 'string', 'max:128'],
        ])['type'];

        try {
            $request->user()->unreact($post, $type);
        } catch (InvalidReactionType $exception) {
            throw ValidationException::withMessages([
                'type' => $exception->getMessage(),
            ]);
        }

        return back();
    }
}
```

These endpoint rules are deliberately specific. `PUT /bookmark` sends the **complete** `note`/`priority` object, not a patch. A production edit form must submit all metadata keys it wants to preserve; a missing key is deleted. The app restricts accepted fields and sizes before the package enforces the JSON-object shape. `POST /reactions` adds the selected type and `DELETE` removes it; neither silently toggles on retry. The package's optional `ReactionPolicy` can reject additional reaction-specific rules, but it does not replace the Laravel policy.

### Load the feed without N+1 queries

Do not call `hasLiked()`, `hasBookmarked()`, or `reactionCounts()` once per card. Load SQL aggregates and viewer flags in the post query, then ask `ReactionView` for one batch:

```php title="app/Http/Controllers/PostFeedController.php"
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pharaonic\Laravel\Reacts\Support\ReactionView;

class PostFeedController extends Controller
{
    public function index(Request $request): View
    {
        $viewer = $request->user();

        $query = Post::query()
            ->where('is_published', true)
            ->withCount(['likes', 'bookmarks']);

        if ($viewer !== null) {
            $query->withExists([
                'likes as is_liked' => fn (Builder $likes) => $likes->whereLiker($viewer),
            ]);
            $query->withBookmarkedBy($viewer);
        }

        $posts = $query->latest()->paginate(15);
        $reactionViews = ReactionView::forMany($posts->items(), $viewer);

        return view('posts.index', compact('posts', 'reactionViews'));
    }
}
```

`withCount()` adds SQL subqueries for counts, and `withExists()` adds a viewer-specific boolean flag instead of running an extra query for each post. `withBookmarkedBy()` adds `is_bookmarked` via an `EXISTS` expression. `ReactionView::forMany()` computes the reaction counts and viewer selections for the page in two reaction queries. Its items are keyed by model identity, so retrieve them with `get($post)`.

For the example page, register the read route as well:

```php title="routes/web.php (add alongside the write routes)"
use App\Http\Controllers\PostFeedController;

Route::get('/posts', [PostFeedController::class, 'index'])->name('posts.index');
```

### Render the actual persisted selection

This minimal Blade view makes the *read model* and the HTTP write contract meet. The post is public, but forms are available only to an authenticated viewer:

```blade title="resources/views/posts/index.blade.php"
@foreach ($posts as $post)
    @php($reactionView = $reactionViews->get($post))

    <article>
        <h2>{{ $post->title }}</h2>
        <p>{{ $post->likes_count }} likes · {{ $post->bookmarks_count }} saves</p>
        <p>{{ $reactionView['total'] }} reaction selections</p>

        @auth
            <form method="POST"
                  action="{{ route((bool) $post->is_liked ? 'posts.unlike' : 'posts.like', $post) }}">
                @csrf
                @method((bool) $post->is_liked ? 'DELETE' : 'PUT')
                <button type="submit" aria-pressed="{{ (bool) $post->is_liked ? 'true' : 'false' }}">
                    {{ (bool) $post->is_liked ? 'Unlike' : 'Like' }}
                </button>
            </form>

            <form method="POST"
                  action="{{ route((bool) $post->is_bookmarked ? 'posts.unbookmark' : 'posts.bookmark', $post) }}">
                @csrf
                @method((bool) $post->is_bookmarked ? 'DELETE' : 'PUT')
                <button type="submit">
                    {{ (bool) $post->is_bookmarked ? 'Remove bookmark' : 'Save for later' }}
                </button>
            </form>
        @endauth

        @foreach ($reactionView['choices'] as $choice)
            @auth
                @if ($choice['selectable'])
                    <form method="POST"
                          action="{{ route($choice['selected'] ? 'posts.unreact' : 'posts.react', $post) }}">
                        @csrf
                        @if ($choice['selected'])
                            @method('DELETE')
                        @endif
                        <input type="hidden" name="type" value="{{ $choice['key'] }}">
                        <button type="submit" aria-pressed="{{ $choice['selected'] ? 'true' : 'false' }}">
                            {{ $choice['emoji'] ?: $choice['label'] }}
                            {{ $choice['count'] }}
                        </button>
                    </form>
                @endif
            @else
                <span>{{ $choice['emoji'] ?: $choice['label'] }}: {{ $choice['count'] }}</span>
            @endauth
        @endforeach

        @foreach ($reactionView['used_other'] as $other)
            <span>{{ $other['emoji'] ?: $other['label'] }}: {{ $other['count'] }}</span>
        @endforeach
    </article>
@endforeach

@error('type')
    <p role="alert">{{ $message }}</p>
@enderror

{{ $posts->links() }}
```

The form to save a bookmark intentionally sends **no metadata**, which creates `{}`. A separate edit page can submit both `note` and `priority` to the same `PUT` route; when changing only one value, initialize the form from the complete stored `BookmarkMeta` so the other is not dropped. The view renders registered reaction choices; a free-form emoji picker is an additional application UI, not something the package inserts automatically.

In multiple mode, `reactionView['total']` counts **reaction rows**, not unique users. `selectable` says the type belongs to the configured catalog, **not** that the user passed the authorization policy. The server checks permissions on every write, and Blade escapes displayed values.

## Bring Follows, Favorites, and Ratings into the Same Product

The same actor can follow a creator and favorite a product while rating it. These are three distinct records, not different names for a Like:

```php
$viewer->follow($creator);
$viewer->isFollowing($creator);        // bool

$viewer->favorite($product);
$viewer->hasFavorited($product);      // bool

$result = $viewer->rate($product, 5, 'Accurate description');
$result->status();                     // 'created', 'updated', or 'unchanged'
```

For a product listing, aggregate in SQL rather than calling `averageRating()` and `hasFavorited()` in a loop:

```php
$products = Product::query()
    ->withCount(['favorites', 'ratings'])
    ->withAvg('ratings', 'value')
    ->paginate(20);

// Each product has favorites_count, ratings_count, ratings_avg_value.
// Cast and round the database average for presentation.
```

This uses Laravel's [relationship aggregates](https://laravel.com/docs/13.x/eloquent-relationships#aggregating-related-models). It does not automatically include whether *this viewer* favorited each product: use a filtered `withExists()` on `favorites` or a batched lookup, not a query per item. The same principle applies to followers lists and read indicators elsewhere in the application.

One design decision deserves its own rule: a Follow is an ongoing subscription, not a notification delivery promise. The package stores the relationship; your app decides which updates generate notifications, who is permitted to follow whom, and whether private accounts require approval.

## Operational Rules the Packages Cannot Decide for You

**Concurrency and idempotency.** The packages enforce their documented uniqueness rules and handle common concurrent writes. That does not make your whole API atomic. An app that charges money, emits an external notification, and updates a feed counter in the same controller needs its own transaction and after-commit side-effect strategy. A repeated `PUT` to create a Like is safer than a repeated toggle. Test concurrency with multiple real database connections, not only sequential method calls.

**Morph identities and migrations.** These packages store morph types and model keys as strings to support integer, UUID, and ULID models. Changing the [morph map](https://laravel.com/docs/13.x/eloquent-relationships#custom-polymorphic-types) after writing rows requires migrating those stored aliases. Do not assume all six packages have identical `model`, `table`, or `connection` configuration: read each package's actual published config before customizing it.

**Soft deletes and bulk deletion.** Package defaults clean up interactions when the relevant model is permanently deleted, while soft deletes generally preserve the relationship for restoration. Reactions also provide a `preserve_on_soft_delete` setting. Database-level bulk deletes do not fire ordinary Eloquent model events. Privacy erasure, cross-table cleanup, and retention rules still need explicit application design.

**Ratings and historical data.** `averageRating()` returns `null` with no ratings, not a zero-star assessment. Changing a model's rating scale does not convert stored rows. A direct Eloquent model save validates a new rating value, but raw SQL or mass query updates bypass model hooks; keep migration scripts and bulk imports honest about their responsibility.

**Bookmark metadata.** Metadata is replaced as a whole. Reject unexpected keys and oversized notes at the HTTP boundary. Do not store secrets inside a saved bookmark simply because its interface is private. If you need to filter millions of bookmarks by a JSON property, consider database-specific indexing instead of assuming a generic JSON column is efficient.

**Reaction catalog changes.** Groups govern *new* choices. Old reactions remain until deliberately removed or migrated. A change from `multiple` to `single` may temporarily leave historical multi-reaction rows until pairs are reconciled. Optional `ReactionPolicy` rules are not a substitute for request authorization, nor are simple row-count quotas guaranteed atomic under concurrency.

**Notifications and analytics.** Likes, Follows, Favorites, Bookmarks, Ratings, and Reactions are independent event sources. There is no shared notification engine, feed-ranking algorithm, spam prevention, or automatic Like-to-Reaction synchronization. Subscribe only to changes that have product meaning, dispatch external work after commit, and make consumers safe to retry.

## Test the Domain Invariants, Not Just Return Values

The package test suites cover their storage behavior. Your application must verify the **composition**: one source of truth, authorization, metadata contracts, modes, and read-side consistency. The following examples assume Pest, conventional model factories, published configuration with the groups shown above, and the `PostPolicy` and routes from the example.

```php title="tests/Feature/SocialEngagementTest.php"
<?php

use App\Models\ChatMessage;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Pharaonic\Laravel\Reacts\Exceptions\InvalidReactionType;

it('keeps exactly one like for repeated desired-state writes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    expect($user->like($post))->toBeTrue();
    expect($user->like($post))->toBeFalse();
    expect($post->likes()->count())->toBe(1);
});

it('replaces bookmark metadata without keeping absent keys', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    $user->bookmark($post, ['note' => 'Later', 'priority' => 1]);
    $bookmark = $user->bookmark($post, ['priority' => 2]);

    expect($bookmark->meta->get('priority'))->toBe(2);
    expect($bookmark->meta->has('note'))->toBeFalse();
    expect($post->bookmarks()->count())->toBe(1);
});

it('keeps one rating while the opinion changes', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    expect($user->rate($product, 3)->created())->toBeTrue();
    expect($user->rate($product, 5)->updated())->toBeTrue();
    expect($user->rate($product, 5)->unchanged())->toBeTrue();
    expect($product->ratingsCount())->toBe(1);
    expect($product->averageRating())->toBe(5.0);
});

it('keeps multiple post types but replaces a single chat reaction', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    $message = ChatMessage::factory()->create();

    $user->react($post, 'love');
    $user->react($post, 'laugh');
    expect($post->reactions()->count())->toBe(2);

    $user->react($message, 'love');
    $user->react($message, 'fire');
    expect($message->reactions()->count())->toBe(1);
    expect($user->hasReacted($message, 'love'))->toBeFalse();
    expect($user->hasReacted($message, 'fire'))->toBeTrue();
});

it('rejects a registered reaction that is not in the target group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    expect(fn () => $user->react($post, 'fire'))
        ->toThrow(InvalidReactionType::class);
});

it('refuses to mutate an unpublished post through HTTP', function () {
    $user = User::factory()->create(['is_banned' => false]);
    $post = Post::factory()->create(['is_published' => false]);

    $this->actingAs($user)
        ->put(route('posts.like', $post))
        ->assertForbidden();

    expect($post->likes()->count())->toBe(0);
});
```

Add a test for bookmark metadata omitted versus explicitly empty, one for guest reaction selections, and one that verifies the page renders flags without fetching a full relation for every card. For concurrency, run two independent workers against the same MySQL, PostgreSQL, or other production database and assert one unique pair survives. Sequential Pest tests cannot establish that guarantee. For production-sensitive performance, count real SQL queries on a 1-post and 20-post feed and inspect your database's query plan instead of claiming a benchmark from code inspection.

The examples above are **integration examples**, not a claim that this specific application test suite was executed here. Their factories, columns, and database must be supplied by your project. Package methods, model contracts, and reaction view shape were checked against the published source; they do not substitute for your application's CI.

## Decide When Not to Use a Package

A single `post_likes` pivot with an index and a pair of routes may be simpler than installing any package. Keep native Laravel if one actor type, one target type, and one fixed state rule satisfy the product. Do not install Favorites and Bookmarks if the interface has only one kind of saved item. Choose Reactions **instead of** a second Like system if the thumbs-up is simply one reaction type. Do not introduce Ratings when your product has no meaningful, comparable numeric assessment.

Use the packages when you genuinely need polymorphic actors and targets, reusable read/write behavior, JSON-object bookmarks, scale-aware ratings, or configurable reaction catalogs and modes. Retain the application rules where they belong: policies, HTTP semantics, moderation, notifications, visibility, and cross-feature consistency.

The final test is simple: after a second click, a retry, a model deletion, and a 20-card feed render, can you predict **which row exists, why it exists, and how the UI knows**? If you can, the architecture is doing its job.

For implementation details beyond this application example, see the current package documentation for [Likes](https://pharaonic.dev/packages/laravel/likes), [Follows](https://pharaonic.dev/packages/laravel/follows), [Favorites](https://pharaonic.dev/packages/laravel/favorites), [Bookmarks](https://pharaonic.dev/packages/laravel/bookmarks), [Ratings](https://pharaonic.dev/packages/laravel/ratings), and [Reactions](https://pharaonic.dev/packages/laravel/reacts). Laravel's primary references are [Eloquent relationships](https://laravel.com/docs/13.x/eloquent-relationships), [query builder](https://laravel.com/docs/13.x/queries), and [authorization](https://laravel.com/docs/13.x/authorization).
