<?php

declare(strict_types=1);

namespace Softpack;

/**
 * A saved project: one WordPress install plus the server it is destined for.
 *
 * Projects live outside the WordPress root (under SOFTPACK_HOME, by default
 * ~/.softpack/projects) so the install stays pristine and so updating softpack
 * itself never disturbs them. Re-running a build reuses the project's insid,
 * which is what makes Softaculous treat the new archive as a newer backup of
 * the same installation rather than a different one.
 */
final class Project
{
    public const SCHEMA = 1;

    /** @var array<string,mixed> */
    private $data;

    /** @var string */
    private $file;

    /** @var string|null set for the duration of one build */
    private $buildName;

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(array $data, string $file)
    {
        $this->data = $data;
        $this->file = $file;
    }

    public static function home(): string
    {
        $env = getenv('SOFTPACK_HOME');
        if (is_string($env) && $env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = (string) getenv('USERPROFILE');
        }
        if ($home === '') {
            $home = sys_get_temp_dir();
        }
        return rtrim(str_replace('\\', '/', $home), '/') . '/.softpack';
    }

    public static function directory(): string
    {
        $dir = self::home() . '/projects';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new Failure("Cannot create the project directory: {$dir}");
        }
        return $dir;
    }

    /** @return string[] project slugs */
    public static function all(): array
    {
        $out = [];
        foreach ((array) glob(self::directory() . '/*.json') as $path) {
            $out[] = basename((string) $path, '.json');
        }
        sort($out);
        return $out;
    }

    public static function exists(string $slug): bool
    {
        return is_file(self::directory() . '/' . self::slug($slug) . '.json');
    }

    public static function load(string $slug): self
    {
        $file = self::directory() . '/' . self::slug($slug) . '.json';
        if (!is_file($file)) {
            throw new Failure("No project called '{$slug}'. Try: softpack list");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new Failure("Project file is not valid JSON: {$file}");
        }
        if (($data['schema'] ?? 0) > self::SCHEMA) {
            throw new Failure(
                "Project '{$slug}' was written by a newer softpack (schema {$data['schema']}). Update softpack."
            );
        }
        return new self($data, $file);
    }

    /**
     * Find the project whose wp_root matches this directory, if any.
     */
    public static function forRoot(string $root): ?self
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        foreach (self::all() as $slug) {
            $project = self::load($slug);
            if (rtrim((string) $project->get('wp_root'), '/') === $root) {
                return $project;
            }
        }
        return null;
    }

    public static function create(string $slug): self
    {
        $slug = self::slug($slug);
        return new self(
            [
                'schema'           => self::SCHEMA,
                'name'             => $slug,
                'created'          => gmdate('c'),
                'softpack_version' => SOFTPACK_VERSION,
                'builds'           => [],
            ],
            self::directory() . '/' . $slug . '.json'
        );
    }

    public static function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new Failure('That project name has no usable characters in it.');
        }
        return $slug;
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function target(): array
    {
        return (array) ($this->data['target'] ?? []);
    }

    /** @return array<string,mixed> */
    public function softaculous(): array
    {
        return (array) ($this->data['softaculous'] ?? []);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return string[] */
    public function excluded(): array
    {
        return array_values((array) ($this->data['exclude'] ?? []));
    }

    /**
     * The name shared by both halves of the pair, fixed for one build.
     */
    public function buildName(): string
    {
        if ($this->buildName === null) {
            $stamp = gmdate('Y-m-d_H-i-s', (int) $this->get('btime', time()));
            $this->buildName = 'wp.' . $this->softaculous()['insid'] . '.' . $stamp;
        }
        return $this->buildName;
    }

    public function startBuild(int $btime): void
    {
        $this->set('btime', $btime);
        $this->buildName = null;
    }

    public function recordBuild(string $name, int $size, string $directory): void
    {
        $builds   = (array) ($this->data['builds'] ?? []);
        $builds[] = [
            'name' => $name,
            'size' => $size,
            'dir'  => $directory,
            'at'   => gmdate('c'),
        ];
        // Keep the tail; the history is a convenience, not an archive.
        $this->data['builds'] = array_slice($builds, -20);
    }

    public function save(): void
    {
        $this->data['schema']           = self::SCHEMA;
        $this->data['updated']          = gmdate('c');
        $this->data['softpack_version'] = SOFTPACK_VERSION;

        $json = json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new Failure('Could not encode the project as JSON.');
        }
        if (@file_put_contents($this->file, $json . "\n") === false) {
            throw new Failure("Cannot write {$this->file}");
        }
        @chmod($this->file, 0600);   // it holds a database password
    }

    public function delete(): void
    {
        @unlink($this->file);
    }
}
