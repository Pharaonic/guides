# Pharaonic Guides

Technical PHP ecosystem guides published on [pharaonic.dev](https://pharaonic.dev/guides).

This repository is the canonical source for Pharaonic's long-form educational content. Each guide solves a real developer problem on its own and recommends a Pharaonic package only where one genuinely helps.

## How Publishing Works

GitHub is the CMS. A guide is written on a branch, validated by CI, reviewed in a pull request, and merged into `main`. pharaonic.dev imports `main` and publishes every guide whose `status` is `published` at:

```text
https://pharaonic.dev/guides/{category}/{slug}
```

There is no application, database, or site generator in this repository. Rendering, search, and discovery belong to pharaonic.dev.

## Structure

```text
guides/
  php/
    compare-nested-arrays/
      index.md      # front matter + Markdown body
      assets/       # optional images (SVG, WebP)
  laravel/
  livewire/
  filament/
schema/
  guide.schema.json # front matter contract
scripts/
  validate-guides.php
```

## Validation

Requires PHP 8.3+ and Composer:

```bash
composer install
composer validate-guides
```

The validator checks front matter against the schema, directory/slug/category consistency, duplicate URLs, dates, assets, internal guide links, and Markdown structure. It exits non-zero on any error, and CI runs it on every pull request.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the guide format, writing standards, and a starter template. Report mistakes with the guide correction issue form, and security concerns through [SECURITY.md](SECURITY.md).

## License

Guide text and images are licensed under [Creative Commons Attribution 4.0 International (CC BY 4.0)](LICENSE). You may reuse and adapt them with attribution to Pharaonic and a link to the original guide.

Code, including the code examples inside guides and the validation tooling, is licensed under the [MIT License](LICENSE-CODE), so examples can be copied into your projects freely.
