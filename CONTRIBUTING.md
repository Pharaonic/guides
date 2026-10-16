# Contributing

Thanks for helping improve Pharaonic Guides. Every guide here should answer a real developer question, accurately and without filler, before it mentions any package.

## Creating a Guide

1. Branch from `main`.
2. Create `guides/{category}/{slug}/index.md` from the [starter template](#starter-template).
3. Write the guide, verify every code example, and run the [validator](#validating-locally).
4. Open a pull request.

Unfinished work stays on its branch. Open the pull request when the guide is ready to publish, so `main` does not collect stale drafts.

## Directory Convention

```text
guides/{category}/{slug}/
├── index.md
└── assets/        # optional
```

- `category` is one of `php`, `laravel`, `livewire`, `filament`. It describes the ecosystem, not the article type: use tags for words like *tutorial* or *beginner*. A new category needs a schema change and maintainer agreement first.
- `slug` is lowercase kebab-case (`compare-nested-arrays`) and is the guide's permanent URL. Do not rename a published guide's directory.
- The directory holds only `index.md` and, optionally, `assets/`.

The public URL is `https://pharaonic.dev/guides/{category}/{slug}`.

## Front Matter

Every `index.md` starts with YAML front matter. [`schema/guide.schema.json`](schema/guide.schema.json) is the contract.

```yaml
---
title: "How to Compare Nested Arrays in PHP: Beyond array_diff()"
slug: "compare-nested-arrays"
description: "Learn how to compare nested PHP arrays and detect added, removed, and modified values beyond native array_diff()."
category: "php"
tags:
  - php
  - arrays
related_packages:
  - php-diff
published_at: "2026-10-16"
status: "published"
---
```

| Field | Required | Rules |
| --- | --- | --- |
| `title` | Yes | 10–120 characters. Describe the outcome a developer is searching for, such as "How to Compare Nested Arrays in PHP", not "PHP Diff Guide". |
| `slug` | Yes | Lowercase kebab-case. Must equal the directory name. |
| `description` | Yes | Plain text on one line, 50–300 characters; 120–160 is recommended. Say what the reader will learn. |
| `category` | Yes | Must equal the parent directory name. |
| `tags` | Yes | 1–10 unique lowercase kebab-case tags, such as `data-comparison`. |
| `published_at` | Yes | Quoted `"YYYY-MM-DD"`. |
| `status` | Yes | `draft` or `published`. |
| `updated_at` | No | Quoted `"YYYY-MM-DD"`, not earlier than `published_at`. |
| `related_packages` | No | Pharaonic package slugs such as `php-diff`, not Composer names such as `pharaonic/php-diff`. |
| `author` | No | Display name. |
| `image` | No | Cover image path relative to the guide directory, such as `assets/cover.webp`. The file must exist. |
| `keywords` | No | Up to 15 unique search phrases. |
| `canonical` | No | Only for content first published elsewhere. An `https://` URL. |

Always quote dates. Unquoted YAML dates are parsed as timestamps, and the validator rejects them. Fields that are not listed above are rejected; propose new metadata only when pharaonic.dev or the editorial process needs it.

### Status and Dates

- `draft`: structurally valid but not public. pharaonic.dev does not publish drafts in production.
- `published`: live once merged.

Set `published_at` to the publication date. Add or change `updated_at` only for meaningful content changes, such as corrected behavior, new sections, or updated versions. Typo fixes do not change it.

## Writing the Guide

The page title comes from front matter, so the body has no `#` heading. Start with an introduction, then use `##` sections without skipping levels.

A structure that works for most problem-solving guides (adapt it; don't force it):

1. Opening: the problem, in a few sentences.
2. The native or framework approach, and what it does well.
3. Where it breaks down, shown honestly.
4. A better approach, with practical code.
5. Using a Pharaonic package, only if it genuinely helps.
6. When to use which approach.
7. A short, practical conclusion.

Every guide should:

- answer a real question and stand alone without the package;
- use runnable, minimal examples that you have actually run;
- explain trade-offs, including when the native solution is enough;
- link to authoritative sources (php.net, laravel.com/docs, specification owners) for claims about language or framework behavior.

Avoid keyword stuffing, marketing language, copying package documentation, near-duplicate guides, and benchmarks or performance claims you cannot back with evidence.

### Quick Answer

When a guide answers a clear technical question, consider adding a Quick Answer right after the introduction and before the first `##` section, so a reader can get the answer in a few seconds:

```markdown
> **Quick Answer / TL;DR:**
> A concise, direct answer to the main technical question.
```

It is recommended where it helps, not required. Keep it to one to three short sentences that answer the reader's question directly, including when the native solution is enough. It is not a place for marketing copy or a package pitch, and it should not repeat the conclusion. Do not end lines with trailing spaces for a line break; the validator rejects trailing whitespace.

### Markdown

Use GitHub-flavored Markdown: headings, lists, links, tables, blockquotes, inline code, and fenced code blocks. Every fenced block needs a language identifier (`php`, `bash`, `text` for output). Never use screenshots of code.

Raw HTML is not supported. Scripts, iframes, and embeds are never allowed. Write generic types such as `Collection<int, User>` in backticks.

## Links

- **Other guides:** use the public path, such as `/guides/php/compare-nested-arrays`. The validator checks that the guide exists, and that a published guide never links to a draft.
- **Pharaonic packages:** link the package page on pharaonic.dev, such as `https://pharaonic.dev/packages/php/diff`. Link GitHub or Packagist only when the context needs it.
- **External sources:** prefer primary sources over copies. Use `https://`.

As a rough guide, link 0–3 related guides and 0–2 packages, and link only where it helps the reader.

## Assets

- Store images in the guide's `assets/` directory and reference them relatively: `![Diff flow](assets/flow.svg)`.
- Prefer SVG for diagrams and WebP for raster images. PNG, JPEG, and AVIF are accepted. Each file must be under 1 MiB.
- Always write alt text.
- Do not hotlink remote images or commit source design files.

## Validating Locally

Requires PHP 8.3+ and Composer:

```bash
composer install
composer validate-guides
```

Errors fail the build; warnings, such as a description outside 120–160 characters, are advice. Output names the file and, where possible, the line:

```text
guides/php/foo/index.md
ERROR: metadata.slug must match directory name "foo"
ERROR: line 42: fenced code block needs a language identifier (use "text" for plain output)
```

The validator checks:

1. front matter exists and is valid YAML;
2. metadata matches `schema/guide.schema.json`, including required fields, categories, statuses, and formats;
3. `category` and `slug` match the directory;
4. no two guides claim the same URL;
5. dates are real calendar dates and `updated_at` is not earlier than `published_at`;
6. the cover image and every relative link or image exist inside `assets/`, and assets have an allowed type and size;
7. `/guides/...` links resolve, and published guides do not link to drafts;
8. Markdown structure: no `#` heading, no skipped heading levels, closed code fences with languages, no trailing whitespace, no malformed or unsafe links, and no raw HTML.

## Pull Requests

Keep one guide, or one focused change, per pull request. Describe what the guide covers or what was corrected, and link sources for corrections. CI must pass.

Before requesting review, confirm that:

- the guide solves a real problem and its title matches a real search intent;
- every code example runs and native or framework behavior is described accurately;
- trade-offs are explained, and package references are relevant rather than forced;
- there are no unsupported claims or benchmarks;
- links are correct and sources are authoritative;
- the guide is meaningfully different from the package documentation;
- spelling and grammar have been reviewed.

## Starter Template

```markdown
---
title: "How to …"
slug: "your-guide-slug"
description: "Learn how to … (120–160 characters describing what the reader will learn)."
category: "php"
tags:
  - php
published_at: "YYYY-MM-DD"
status: "draft"
---

One or two paragraphs that state the problem and what the reader will be able to do afterwards.

## The Problem

…

## The Native Approach

…

## Where It Breaks Down

…

## A Better Approach

…

## When to Use Which Approach

…

## Summary

…
```

Change `status` to `"published"` and set `published_at` when the guide is ready.

## Security

Report malicious links, dangerous example code, or workflow vulnerabilities privately as described in [SECURITY.md](SECURITY.md), not in a public issue or pull request.
