<?php

declare(strict_types=1);

namespace Softpack;

/**
 * URL rewriting that survives PHP serialisation.
 *
 * WordPress - and Elementor especially - stores absolute URLs inside
 * serialised arrays, where every string carries its own byte length
 * ("s:25:\"http://localhost/legacy\""). A plain str_replace that changes the
 * length silently corrupts the record, so serialised values are unpacked,
 * rewritten and repacked instead.
 *
 * A value is only transformed when serialize(unserialize($v)) === $v, i.e. the
 * round trip is provably lossless. Anything else is left alone and reported,
 * which is the honest outcome: a warning beats a corrupted post_meta row.
 */
final class Replacer
{
    /** @var string[] */
    private $from = [];

    /** @var string[] */
    private $to = [];

    /** @var int */
    public $replacements = 0;

    /** @var int */
    public $rowsTouched = 0;

    /** @var string[] */
    public $skipped = [];

    /**
     * @param array<int,array{0:string,1:string}> $pairs
     */
    public function __construct(array $pairs)
    {
        foreach ($pairs as $pair) {
            $this->from[] = $pair[0];
            $this->to[]   = $pair[1];
        }
        if ($this->from === []) {
            throw new Failure('Replacer needs at least one search/replace pair.');
        }
    }

    /**
     * Build the pairs needed to move a site from one URL to another, covering
     * both the plain form and the escaped-slash form that Elementor writes
     * into its JSON payloads.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function urlPairs(string $oldUrl, string $newUrl): array
    {
        $oldUrl = rtrim($oldUrl, '/');
        $newUrl = rtrim($newUrl, '/');

        $escape = static function (string $url): string {
            return str_replace('/', '\\/', $url);
        };

        $pairs = [[$oldUrl, $newUrl]];

        $oldEsc = $escape($oldUrl);
        $newEsc = $escape($newUrl);
        if ($oldEsc !== $oldUrl) {
            $pairs[] = [$oldEsc, $newEsc];
        }

        // Bare host swap catches stray references that carry no scheme.
        $oldHost = (string) parse_url($oldUrl, PHP_URL_HOST);
        $newHost = (string) parse_url($newUrl, PHP_URL_HOST);
        $oldPath = trim((string) parse_url($oldUrl, PHP_URL_PATH), '/');
        if ($oldHost !== '' && $newHost !== '' && $oldHost !== $newHost && $oldPath === '') {
            $pairs[] = ['//' . $oldHost, '//' . $newHost];
        }

        return $pairs;
    }

    /**
     * Rewrite one column value. $context is only used in warnings.
     */
    public function value(?string $value, string $context = ''): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (!$this->contains($value)) {
            return $value;
        }

        $before = $this->replacements;

        if (self::isSerialized($value)) {
            $rebuilt = $this->serialized($value, $context);
            if ($rebuilt !== null) {
                if ($this->replacements > $before) {
                    $this->rowsTouched++;
                }
                return $rebuilt;
            }
            // Not safely round-trippable; leave it exactly as it was.
            $this->skipped[] = $context;
            return $value;
        }

        $out = $this->plain($value);
        if ($this->replacements > $before) {
            $this->rowsTouched++;
        }
        return $out;
    }

    /**
     * Count occurrences without rewriting - used by the verifier.
     */
    public function contains(string $value): bool
    {
        foreach ($this->from as $needle) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function plain(string $value): string
    {
        foreach ($this->from as $i => $needle) {
            $n = substr_count($value, $needle);
            if ($n > 0) {
                $this->replacements += $n;
                $value = str_replace($needle, $this->to[$i], $value);
            }
        }
        return $value;
    }

    /**
     * @return string|null null when the value cannot be safely rewritten
     */
    private function serialized(string $value, string $context): ?string
    {
        $data = @unserialize($value, ['allowed_classes' => true]);
        if ($data === false && $value !== 'b:0;') {
            return null;
        }
        // Provable losslessness: if we cannot reproduce the input byte for
        // byte, we have no business writing a replacement back.
        if (serialize($data) !== $value) {
            return null;
        }

        return serialize($this->walk($data));
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function walk($data)
    {
        if (is_string($data)) {
            if (!$this->contains($data)) {
                return $data;
            }
            // A string inside a serialised structure may itself be serialised.
            if (self::isSerialized($data)) {
                $inner = @unserialize($data, ['allowed_classes' => true]);
                if (($inner !== false || $data === 'b:0;') && serialize($inner) === $data) {
                    return serialize($this->walk($inner));
                }
            }
            return $this->plain($data);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $item) {
                $newKey = is_string($key) ? $this->walk($key) : $key;
                $out[$newKey] = $this->walk($item);
            }
            return $out;
        }

        if (is_object($data)) {
            foreach (get_object_vars($data) as $prop => $item) {
                $data->$prop = $this->walk($item);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Is this string a PHP serialisation? Mirrors WordPress' is_serialized().
     */
    public static function isSerialized(string $data): bool
    {
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4 || $data[1] !== ':') {
            return false;
        }
        $end = substr($data, -1);
        if ($end !== ';' && $end !== '}') {
            return false;
        }
        return in_array($data[0], ['s', 'a', 'O', 'b', 'i', 'd'], true);
    }
}
