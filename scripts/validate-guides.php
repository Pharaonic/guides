<?php

declare(strict_types=1);

/*
 * Validates every guide in this repository.
 *
 *   php scripts/validate-guides.php [repository-root]
 *
 * Exits with 1 when any error is found. Warnings are reported but never fail.
 * The checks are listed in CONTRIBUTING.md.
 */

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

final class GuideValidator
{
    private const PUBLIC_HOST = 'https://pharaonic.dev';

    private const ASSET_EXTENSIONS = ['svg', 'webp', 'png', 'jpg', 'jpeg', 'avif'];

    private const MAX_ASSET_BYTES = 1024 * 1024;

    /** @var array<string, list<array{level: string, line: ?int, message: string}>> */
    private array $issues = [];

    /** @var array<string, array{file: string, category: mixed, slug: mixed, status: mixed, body: string, bodyLine: int}> */
    private array $guides = [];

    /** @var array<string, mixed> Guide directories ("php/compare-nested-arrays") and their status, for link checks. */
    private array $directories = [];

    private object $schema;

    /** @var list<string> */
    private array $categories;

    public function __construct(private readonly string $root)
    {
        $schema = json_decode((string) file_get_contents($root.'/schema/guide.schema.json'), false, 512, JSON_THROW_ON_ERROR);
        $this->schema = $schema;
        $this->categories = $schema->properties->category->enum;
    }

    public function run(): int
    {
        $this->checkLayout();

        foreach (glob($this->root.'/guides/*/*/index.md') ?: [] as $path) {
            $this->checkGuide($path);
        }

        $this->checkDuplicates();

        foreach ($this->guides as $file => $guide) {
            $this->checkMarkdown($file, $guide);
        }

        return $this->report();
    }

    private function checkLayout(): void
    {
        if (! is_dir($this->root.'/guides')) {
            $this->error('guides', 'directory is missing');

            return;
        }

        foreach (new DirectoryIterator($this->root.'/guides') as $category) {
            if ($category->isDot()) {
                continue;
            }

            $name = $category->getFilename();
            $relative = 'guides/'.$name;

            if (! $category->isDir()) {
                $this->error($relative, 'only category directories belong in guides/');

                continue;
            }

            if (! in_array($name, $this->categories, true)) {
                $this->error($relative, sprintf('unknown category "%s"; allowed: %s (add new ones to the schema first)', $name, implode(', ', $this->categories)));

                continue;
            }

            foreach (new DirectoryIterator($category->getPathname()) as $guide) {
                if ($guide->isDot() || $guide->getFilename() === '.gitkeep') {
                    continue;
                }

                $guideRelative = $relative.'/'.$guide->getFilename();

                if (! $guide->isDir()) {
                    $this->error($guideRelative, 'each guide lives in its own directory: guides/{category}/{slug}/index.md');

                    continue;
                }

                $this->checkGuideDirectory($guide->getPathname(), $guideRelative);
            }
        }
    }

    private function checkGuideDirectory(string $directory, string $relative): void
    {
        if (is_file($directory.'/index.md')) {
            $this->directories[substr($relative, strlen('guides/'))] = null;
        } else {
            $this->error($relative, 'index.md is missing');
        }

        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $name = $entry->getFilename();

            if ($name === 'index.md' || ($name === 'assets' && $entry->isDir())) {
                continue;
            }

            $this->error($relative.'/'.$name, 'a guide directory holds only index.md and an optional assets/ directory');
        }

        if (! is_dir($directory.'/assets')) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory.'/assets', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $assetRelative = $relative.'/'.substr($file->getPathname(), strlen($directory) + 1);

            if (! in_array(strtolower($file->getExtension()), self::ASSET_EXTENSIONS, true)) {
                $this->error($assetRelative, sprintf('unsupported asset type; allowed: %s', implode(', ', self::ASSET_EXTENSIONS)));
            } elseif ($file->getSize() > self::MAX_ASSET_BYTES) {
                $this->error($assetRelative, sprintf('asset is %s KiB; optimize it below %d KiB', number_format($file->getSize() / 1024), self::MAX_ASSET_BYTES / 1024));
            }
        }
    }

    private function checkGuide(string $path): void
    {
        $file = $this->relative($path);
        $directory = basename(dirname($path));
        $category = basename(dirname($path, 2));
        $contents = (string) file_get_contents($path);

        if (str_starts_with($contents, "\u{FEFF}")) {
            $this->error($file, 'remove the UTF-8 byte order mark');
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\r")) {
            $this->error($file, 'use LF line endings');
            $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        }

        if (! preg_match('/\A---\n(.*?)\n---(?:\n|\z)/s', $contents, $match)) {
            $this->error($file, 'file must start with YAML front matter between two "---" lines', 1);

            return;
        }

        $frontMatter = $match[1];
        $body = substr($contents, strlen($match[0]));
        $bodyLine = substr_count($match[0], "\n") + 1;

        try {
            $metadata = Yaml::parse($frontMatter);
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();
            // The parser counts lines from the start of the front matter, after the opening "---".
            $message = preg_replace('/ at line \d+/', '', $exception->getMessage()) ?? $exception->getMessage();
            $this->error($file, 'invalid YAML: '.$message, $line > 0 ? $line + 1 : null);

            return;
        }

        if (! is_array($metadata) || array_is_list($metadata)) {
            $this->error($file, 'front matter must be a YAML mapping of fields', 2);

            return;
        }

        $this->directories[$category.'/'.$directory] = $metadata['status'] ?? null;

        $this->checkSchema($file, $metadata);

        $slug = $metadata['slug'] ?? null;

        if (($metadata['category'] ?? null) !== $category) {
            $this->error($file, sprintf('metadata.category must match directory category "%s"', $category));
        }

        if ($slug !== $directory) {
            $this->error($file, sprintf('metadata.slug must match directory name "%s"', $directory));
        }

        $this->checkDates($file, $metadata);

        if (is_string($metadata['image'] ?? null) && ! is_file(dirname($path).'/'.$metadata['image'])) {
            $this->error($file, sprintf('metadata.image "%s" does not exist', $metadata['image']));
        }

        if (is_string($metadata['description'] ?? null)) {
            $length = mb_strlen($metadata['description']);

            if ($length < 120 || $length > 160) {
                $this->warning($file, sprintf('metadata.description is %d characters; 120-160 is recommended', $length));
            }

            if (preg_match('/[`<>]|\*\*|\]\(/', $metadata['description'])) {
                $this->warning($file, 'metadata.description should be plain text, without Markdown or HTML');
            }
        }

        if (trim($body) === '') {
            $this->error($file, 'guide body is empty');
        }

        $this->guides[$file] = [
            'file' => $path,
            'category' => $metadata['category'] ?? null,
            'slug' => $slug,
            'status' => $metadata['status'] ?? null,
            'body' => $body,
            'bodyLine' => $bodyLine,
        ];
    }

    /**
     * @param array<mixed> $metadata
     */
    private function checkSchema(string $file, array $metadata): void
    {
        // Unquoted YAML dates are parsed as timestamps; explain that instead of a type error.
        $reported = [];

        foreach (['published_at', 'updated_at'] as $field) {
            if (is_int($metadata[$field] ?? null)) {
                $this->error($file, sprintf('metadata.%s must be a quoted date string, e.g. %s: "%s"', $field, $field, gmdate('Y-m-d', $metadata[$field])));
                $reported[] = 'metadata.'.$field;
            }
        }

        $data = json_decode((string) json_encode($metadata), false);
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $result = $validator->validate($data, $this->schema);

        if ($result->isValid()) {
            return;
        }

        $errors = (new ErrorFormatter())->format(
            $result->error(),
            true,
            null,
            fn (ValidationError $error): string => implode('.', ['metadata', ...$error->data()->fullPath()]),
        );

        foreach ($errors as $key => $messages) {
            if (in_array($key, $reported, true)) {
                continue;
            }

            foreach (array_unique($messages) as $message) {
                $this->error($file, $key.': '.$message);
            }
        }
    }

    /**
     * @param array<mixed> $metadata
     */
    private function checkDates(string $file, array $metadata): void
    {
        $dates = [];

        foreach (['published_at', 'updated_at'] as $field) {
            $value = $metadata[$field] ?? null;

            if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
                continue;
            }

            if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $this->error($file, sprintf('metadata.%s "%s" is not a real calendar date', $field, $value));

                continue;
            }

            $dates[$field] = $value;
        }

        if (isset($dates['published_at'], $dates['updated_at']) && $dates['updated_at'] < $dates['published_at']) {
            $this->error($file, 'metadata.updated_at must not be earlier than metadata.published_at');
        }
    }

    private function checkDuplicates(): void
    {
        $urls = [];

        foreach ($this->guides as $file => $guide) {
            if (is_string($guide['category']) && is_string($guide['slug'])) {
                $urls[$this->guideUrl($guide['category'], $guide['slug'])][] = $file;
            }
        }

        foreach ($urls as $url => $files) {
            if (count($files) < 2) {
                continue;
            }

            foreach ($files as $file) {
                $others = implode(', ', array_diff($files, [$file]));
                $this->error($file, sprintf('duplicate slug within category: public URL %s is also claimed by %s', $url, $others));
            }
        }
    }

    /**
     * @param array{file: string, category: mixed, slug: mixed, status: mixed, body: string, bodyLine: int} $guide
     */
    private function checkMarkdown(string $file, array $guide): void
    {
        $lines = explode("\n", $guide['body']);
        $fence = null;
        $fenceLine = 0;
        $previousLevel = 1;
        $headings = [];
        $references = [];
        $usedReferences = [];
        $prose = [];

        foreach ($lines as $index => $line) {
            $number = $guide['bodyLine'] + $index;

            if (preg_match('/[ \t]+$/', $line)) {
                $this->error($file, 'trailing whitespace', $number);
            }

            if ($fence !== null) {
                if (preg_match('/^\s*('.preg_quote($fence[0], '/').'{'.strlen($fence).',})\s*$/', $line)) {
                    $fence = null;
                }

                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $match)) {
                $fence = $match[1];
                $fenceLine = $number;
                $info = trim($match[2]);

                if ($info === '') {
                    $this->error($file, 'fenced code block needs a language identifier (use "text" for plain output)', $number);
                } elseif ($fence[0] === '`' && str_contains($info, '`')) {
                    $this->error($file, 'malformed code fence', $number);
                }

                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t#]*$/', $line, $match)) {
                $level = strlen($match[1]);
                $text = trim($match[2] ?? '');

                if ($level === 1) {
                    $this->error($file, 'do not use "#" headings; the title comes from front matter, so start sections at "##"', $number);
                } elseif ($level > $previousLevel + 1) {
                    $this->error($file, sprintf('heading level jumps from h%d to h%d', $previousLevel, $level), $number);
                }

                if ($text === '') {
                    $this->error($file, 'empty heading', $number);
                } elseif (isset($headings[$level.$text])) {
                    $this->warning($file, sprintf('duplicate heading "%s" (also on line %d); anchors will collide', $text, $headings[$level.$text]), $number);
                } else {
                    $headings[$level.$text] = $number;
                }

                $previousLevel = max($level, 2);
            } elseif (preg_match('/^#{1,6}[^#\s]/', $line)) {
                $this->warning($file, 'add a space after "#" if this is meant to be a heading', $number);
            }

            if (preg_match('/^ {0,3}\[([^\]]+)\]:\s*(\S+)/', $line, $match)) {
                $references[strtolower($match[1])] = true;
                $this->checkLinkTarget($file, $guide, $match[2], false, $number);

                continue;
            }

            $prose[$number] = $line;
        }

        if ($fence !== null) {
            $this->error($file, 'fenced code block is never closed', $fenceLine);
        }

        foreach ($prose as $number => $line) {
            // Inline code may contain anything; keep a placeholder so `code` link text still counts as text.
            $text = preg_replace('/(`+)(?:(?!\1).)+?\1/', 'code', $line) ?? $line;

            if (preg_match('/<(script|iframe|style|object|embed|form|input)\b/i', $text, $match)) {
                $this->error($file, sprintf('unsafe HTML <%s> is not allowed in guides', strtolower($match[1])), $number);
            } elseif (preg_match('/<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^>]*)?\/?>/', $text, $match)) {
                $this->error($file, sprintf('raw HTML %s is not supported; use Markdown, or wrap code in backticks', $match[0]), $number);
            }

            if (preg_match('/\]\(\s*\)/', $text)) {
                $this->error($file, 'link has an empty target', $number);
            }

            if (preg_match('/\]\([^)]*$/', $text)) {
                $this->error($file, 'link target is not closed with ")"', $number);
            }

            if (preg_match('/\]\s+\((?:https?:|\/|\.)/', $text)) {
                $this->error($file, 'remove the space between "]" and "(" in the link', $number);
            }

            preg_match_all('/(!?)\[((?:[^\[\]]|\[[^\]]*\])*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $text, $links, PREG_SET_ORDER);

            foreach ($links as $link) {
                if ($link[1] === '!' && trim($link[2]) === '') {
                    $this->warning($file, 'image has no alt text', $number);
                }

                if ($link[1] !== '!' && trim($link[2]) === '') {
                    $this->error($file, 'link has no text', $number);
                }

                $this->checkLinkTarget($file, $guide, $link[3], $link[1] === '!', $number);
            }

            preg_match_all('/\[([^\[\]]+)\]\[([^\[\]]*)\]/', $text, $refs, PREG_SET_ORDER);

            foreach ($refs as $ref) {
                $usedReferences[strtolower($ref[2] === '' ? $ref[1] : $ref[2])] = $number;
            }
        }

        foreach ($usedReferences as $reference => $number) {
            if (! isset($references[$reference])) {
                $this->error($file, sprintf('reference link [%s] has no definition', $reference), $number);
            }
        }
    }

    /**
     * @param array{file: string, category: mixed, slug: mixed, status: mixed, body: string, bodyLine: int} $guide
     */
    private function checkLinkTarget(string $file, array $guide, string $target, bool $image, int $number): void
    {
        if (preg_match('/^(javascript|vbscript|data|file):/i', $target)) {
            $this->error($file, sprintf('"%s" links are not allowed', strtolower(strtok($target, ':') ?: '')), $number);

            return;
        }

        if (str_starts_with($target, self::PUBLIC_HOST.'/guides')) {
            $target = substr($target, strlen(self::PUBLIC_HOST));
        }

        if ($image) {
            if (preg_match('/^[a-z][a-z0-9+.-]*:|^\/\//i', $target)) {
                $this->error($file, 'store images in the guide\'s assets/ directory instead of linking remote images', $number);

                return;
            }

            if (! str_starts_with(ltrim($target, './'), 'assets/')) {
                $this->error($file, sprintf('image "%s" must live in the guide\'s assets/ directory', $target), $number);

                return;
            }
        }

        if (str_starts_with($target, 'http://')) {
            $this->warning($file, sprintf('use https for %s', $target), $number);

            return;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:|^\/\/|^#/i', $target)) {
            return;
        }

        $path = (string) preg_replace('/[?#].*$/', '', $target);

        if (str_starts_with($path, '/guides')) {
            $this->checkGuideLink($file, $guide, $path, $number);

            return;
        }

        if (str_starts_with($path, '/')) {
            return;
        }

        $normalized = preg_replace('#^(\./)+#', '', $path) ?? $path;

        if ($normalized === '' || str_contains($normalized, '..') || ! str_starts_with($normalized, 'assets/')) {
            $this->error($file, sprintf('relative link "%s" must point into this guide\'s assets/; link other guides as /guides/{category}/{slug}', $target), $number);

            return;
        }

        if (! is_file(dirname($guide['file']).'/'.rawurldecode($normalized))) {
            $this->error($file, sprintf('"%s" does not exist', $target), $number);
        }
    }

    /**
     * @param array{file: string, category: mixed, slug: mixed, status: mixed, body: string, bodyLine: int} $guide
     */
    private function checkGuideLink(string $file, array $guide, string $path, int $number): void
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $segment): bool => $segment !== ''));

        if ($segments === ['guides'] || (count($segments) === 2 && in_array($segments[1], $this->categories, true))) {
            return;
        }

        if (count($segments) !== 3) {
            $this->error($file, sprintf('"%s" is not a guide URL; use /guides/{category}/{slug}', $path), $number);

            return;
        }

        $key = $segments[1].'/'.$segments[2];

        if (! array_key_exists($key, $this->directories)) {
            $this->error($file, sprintf('broken internal guide link %s', $path), $number);

            return;
        }

        if ($guide['status'] === 'published' && $this->directories[$key] !== 'published') {
            $this->error($file, sprintf('links to %s, which is not a published guide', $path), $number);
        }
    }

    private function guideUrl(string $category, string $slug): string
    {
        return '/guides/'.$category.'/'.$slug;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root) + 1);
    }

    private function error(string $file, string $message, ?int $line = null): void
    {
        $this->issues[$file][] = ['level' => 'ERROR', 'line' => $line, 'message' => $message];
    }

    private function warning(string $file, string $message, ?int $line = null): void
    {
        $this->issues[$file][] = ['level' => 'WARNING', 'line' => $line, 'message' => $message];
    }

    private function report(): int
    {
        $errors = 0;
        $warnings = 0;
        $annotate = getenv('GITHUB_ACTIONS') === 'true';

        ksort($this->issues);

        foreach ($this->issues as $file => $issues) {
            usort($issues, fn (array $a, array $b): int => [$a['line'] ?? 0, $a['level']] <=> [$b['line'] ?? 0, $b['level']]);

            echo $file, PHP_EOL;

            foreach ($issues as $issue) {
                $location = $issue['line'] !== null ? 'line '.$issue['line'].': ' : '';
                echo $issue['level'], ': ', $location, $issue['message'], PHP_EOL;

                if ($annotate) {
                    printf(
                        "::%s file=%s%s::%s\n",
                        $issue['level'] === 'ERROR' ? 'error' : 'warning',
                        $file,
                        $issue['line'] !== null ? ',line='.$issue['line'] : '',
                        str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $issue['message']),
                    );
                }

                $issue['level'] === 'ERROR' ? $errors++ : $warnings++;
            }

            echo PHP_EOL;
        }

        printf(
            "%d guide%s checked: %d error%s, %d warning%s.\n",
            count($this->guides),
            count($this->guides) === 1 ? '' : 's',
            $errors,
            $errors === 1 ? '' : 's',
            $warnings,
            $warnings === 1 ? '' : 's',
        );

        return $errors > 0 ? 1 : 0;
    }
}

$root = realpath($argv[1] ?? dirname(__DIR__));

if ($root === false || ! is_file($root.'/schema/guide.schema.json')) {
    fwrite(STDERR, "Repository root with schema/guide.schema.json not found.\n");
    exit(2);
}

exit((new GuideValidator($root))->run());
