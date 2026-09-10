<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Works out which files go into the archive and writes softperms.txt.
 *
 * Nothing is copied: the archive is built straight from the WordPress root,
 * with the handful of generated files supplied from a small staging folder.
 */
final class Payload
{
    /** Never archived, whatever the project says. */
    private const ALWAYS_SKIP = [
        '.git', '.svn', '.hg', '.idea', '.vscode',
        'node_modules', '.DS_Store', 'Thumbs.db', 'desktop.ini',
        'error_log', 'debug.log',
    ];

    /** Supplied from staging rather than copied from the install. */
    public const GENERATED = ['wp-config.php', '.htaccess'];

    /** @var string */
    private $root;

    /** @var string[] relative POSIX paths, e.g. "wp-content/uploads/elementor/css" */
    private $excluded;

    /**
     * @param string[] $excluded
     */
    public function __construct(string $root, array $excluded)
    {
        $this->root     = rtrim(str_replace('\\', '/', $root), '/');
        $this->excluded = array_values(array_filter(array_map(
            static function (string $p): string {
                return trim(str_replace('\\', '/', $p), '/');
            },
            $excluded
        )));
    }

    /**
     * Top-level entries to hand to tar, in the install's own order.
     *
     * @return string[]
     */
    public function topLevel(): array
    {
        $out = [];
        foreach ((array) scandir($this->root) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, self::GENERATED, true)) {
                continue;   // staging supplies these
            }
            if ($this->isExcluded($entry)) {
                continue;
            }
            $out[] = $entry;
        }
        sort($out);
        return $out;
    }

    /**
     * Nested exclusions that tar has to be told about explicitly, because
     * their parent directory is still being archived.
     *
     * @return string[]
     */
    public function nestedExcludes(): array
    {
        $top    = [];
        foreach ((array) scandir($this->root) as $entry) {
            $top[$entry] = true;
        }

        $out = [];
        foreach (array_merge($this->excluded, self::ALWAYS_SKIP) as $path) {
            if (strpos($path, '/') !== false) {
                $out[] = $path;          // definitely nested
            } elseif (!isset($top[$path])) {
                $out[] = $path;          // a bare name that may occur anywhere
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Every path that will end up in the archive, relative and POSIX-slashed.
     * Directories come before their contents.
     *
     * @return array<int,array{path:string,dir:bool}>
     */
    public function walk(): array
    {
        $out = [];
        $this->walkInto('', $out);
        return $out;
    }

    /**
     * @param array<int,array{path:string,dir:bool}> $out
     */
    private function walkInto(string $relative, array &$out): void
    {
        $absolute = $relative === '' ? $this->root : $this->root . '/' . $relative;
        $entries  = @scandir($absolute);
        if ($entries === false) {
            return;
        }
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = $relative === '' ? $entry : $relative . '/' . $entry;

            if ($relative === '' && in_array($entry, self::GENERATED, true)) {
                continue;
            }
            if ($this->isExcluded($rel) || in_array($entry, self::ALWAYS_SKIP, true)) {
                continue;
            }

            $isDir = is_dir($absolute . '/' . $entry);
            $out[] = ['path' => $rel, 'dir' => $isDir];

            if ($isDir && !is_link($absolute . '/' . $entry)) {
                $this->walkInto($rel, $out);
            }
        }
    }

    /**
     * softperms.txt: "<relative path> <octal>", install root first.
     *
     * Softaculous lists softver.txt, cgi-bin, .htaccess and wp-config.php but
     * not softsql.sql, softperms.txt or the metadata file - those are written
     * after it is generated. That is reproduced here.
     *
     * @param string[] $staged extra top-level entries coming from staging
     */
    public function writePerms(string $destination, array $staged): int
    {
        $fh = fopen($destination, 'wb');
        if ($fh === false) {
            throw new Failure("Cannot write {$destination}");
        }

        fwrite($fh, "/ 0750\n");
        $count = 0;

        foreach ($staged as $entry => $isDir) {
            fwrite($fh, $entry . ' ' . ($isDir ? '0755' : '0644') . "\n");
            $count++;
        }
        foreach ($this->walk() as $item) {
            fwrite($fh, $item['path'] . ' ' . ($item['dir'] ? '0755' : '0644') . "\n");
            $count++;
        }

        fclose($fh);
        return $count;
    }

    private function isExcluded(string $relative): bool
    {
        foreach ($this->excluded as $pattern) {
            if ($relative === $pattern || strpos($relative, $pattern . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}
