# Maintaining

## Branches

`main` is the only long-lived branch and is always publishable. Guides are not versioned like packages: there are no `8.N.x` or `N.x` lines. When a guide depends on a specific PHP, framework, or package version, say so in the text and in tags.

## Consumer Contract (pharaonic.dev)

pharaonic.dev can rely on these guarantees, all of them enforced by `composer validate-guides` on every pull request and push to `main`:

- Guides are exactly the files matching `guides/*/*/index.md`. Other paths under `guides/` are either `.gitkeep` files or `assets/` content.
- Each `index.md` begins with YAML front matter between `---` lines, then a Markdown body, encoded as UTF-8 with LF line endings and no BOM.
- Front matter conforms to `schema/guide.schema.json`. Unknown fields are rejected, so the consumer can map fields strictly.
- `category` equals the parent directory and `slug` equals the guide directory, so the public path is `/guides/{category}/{slug}` and is unique.
- Dates are `"YYYY-MM-DD"` strings, not YAML timestamps.
- Only `status: "published"` guides are public. Drafts may exist in `main` and must be filtered out in production.
- `image` and relative Markdown links and images point to existing files under the guide's own `assets/` directory. The consumer serves or copies that directory alongside the guide.
- Internal links use `/guides/{category}/{slug}` and resolve to a published guide.
- The body has no `#` heading. The consumer renders `title` as the page H1.
- The body contains no raw HTML. Render with HTML disabled or escaped (`league/commonmark` with `html_input: escape`).
- `related_packages` are package slugs (`php-diff`). The consumer maps them to package records and ignores slugs it does not know.

`.gitattributes` keeps `guides/`, `schema/`, `README.md`, and the license files in GitHub source archives and excludes only repository tooling.

Changing any of these guarantees is a breaking change for pharaonic.dev: coordinate the importer change first.

## Adding a Category

1. Add it to the `category` enum in `schema/guide.schema.json`.
2. Create `guides/{category}/.gitkeep`.
3. Add it to the issue form dropdown, `.github/labels.yml`, `README.md`, and `CONTRIBUTING.md`.
4. Make sure pharaonic.dev can render it before the first guide is merged.

## Editorial Review

Review a guide against the checklist in [CONTRIBUTING.md](CONTRIBUTING.md#pull-requests). Run the code examples yourself, especially when they demonstrate PHP or framework behavior. A correction to a published guide sets `updated_at` only when the change is meaningful to readers.

## Backlog

Write a guide only when there is real search intent and a clear problem it solves; do not publish thin pages to increase the count.

| Category | Slug | Related package |
| --- | --- | --- |
| php | `deterministic-data-fingerprints` | `php-fingerprint` |
| php | `signing-ordered-api-payloads` | `php-fingerprint` |
| php | `detecting-object-changes` | `php-diff` |
| php | `practical-native-enum-helpers` | `php-enum-extension` |
| php | `hijri-dates-in-php` | `php-hijri` |
| php | `creating-safe-url-slugs` | `php-slugify` |
| laravel | `reusable-model-slugs` | `laravel-sluggable` |
| laravel | `hijri-dates-in-laravel` | `laravel-hijri` |
| laravel | `filtering-eloquent-models` | — |

Future areas: package design, compatibility across PHP versions, testing, and API integration.
