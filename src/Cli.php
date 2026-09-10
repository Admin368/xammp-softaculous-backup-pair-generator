<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Terminal input and output. Colour is used only when the stream looks like a
 * terminal and NO_COLOR is unset.
 */
final class Cli
{
    /** @var bool */
    private static $colour;

    /** @var bool */
    public static $interactive = true;

    private static function colour(): bool
    {
        if (self::$colour === null) {
            self::$colour = getenv('NO_COLOR') === false
                && (function_exists('stream_isatty') ? @stream_isatty(STDOUT) : true);
        }
        return self::$colour;
    }

    private static function wrap(string $text, string $code): string
    {
        return self::colour() ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    public static function dim(string $s): string    { return self::wrap($s, '2'); }
    public static function bold(string $s): string   { return self::wrap($s, '1'); }
    public static function green(string $s): string  { return self::wrap($s, '32'); }
    public static function yellow(string $s): string { return self::wrap($s, '33'); }
    public static function red(string $s): string    { return self::wrap($s, '31'); }
    public static function cyan(string $s): string   { return self::wrap($s, '36'); }

    public static function out(string $line = ''): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    public static function heading(string $text): void
    {
        self::out();
        self::out(self::bold($text));
        self::out(self::dim(str_repeat('-', max(4, strlen($text)))));
    }

    public static function step(string $text): void
    {
        self::out('  ' . $text);
    }

    public static function ok(string $text): void
    {
        self::out('  ' . self::green('ok') . '   ' . $text);
    }

    public static function warn(string $text): void
    {
        self::out('  ' . self::yellow('warn') . ' ' . $text);
    }

    public static function fail(string $text): void
    {
        fwrite(STDERR, '  ' . self::red('FAIL') . ' ' . $text . PHP_EOL);
    }

    public static function info(string $label, string $value): void
    {
        self::out('  ' . self::dim(str_pad($label, 18)) . $value);
    }

    /**
     * Prompt for a value. Returns the default when the user just hits enter.
     */
    public static function ask(string $label, ?string $default = null, bool $allowEmpty = false): string
    {
        if (!self::$interactive) {
            if ($default === null && !$allowEmpty) {
                throw new Failure("'{$label}' has no value and --non-interactive was given.");
            }
            return (string) $default;
        }

        $suffix = $default !== null && $default !== ''
            ? ' ' . self::dim('[' . $default . ']')
            : '';

        while (true) {
            fwrite(STDOUT, '  ' . $label . $suffix . ': ');
            $line = fgets(STDIN);
            if ($line === false) {
                throw new Failure('Input stream closed.');
            }
            $line = trim($line);
            if ($line === '' && $default !== null) {
                return $default;
            }
            if ($line !== '' || $allowEmpty) {
                return $line;
            }
            self::warn('A value is required.');
        }
    }

    public static function confirm(string $label, bool $default = true): bool
    {
        if (!self::$interactive) {
            return $default;
        }
        $hint = $default ? 'Y/n' : 'y/N';
        while (true) {
            fwrite(STDOUT, '  ' . $label . ' ' . self::dim('[' . $hint . ']') . ': ');
            $line = fgets(STDIN);
            if ($line === false) {
                return $default;
            }
            $line = strtolower(trim($line));
            if ($line === '') {
                return $default;
            }
            if (in_array($line, ['y', 'yes'], true)) {
                return true;
            }
            if (in_array($line, ['n', 'no'], true)) {
                return false;
            }
        }
    }

    public static function bytes(int $n): string
    {
        if ($n >= 1073741824) { return sprintf('%.1f GB', $n / 1073741824); }
        if ($n >= 1048576)    { return sprintf('%.1f MB', $n / 1048576); }
        if ($n >= 1024)       { return sprintf('%.1f KB', $n / 1024); }
        return $n . ' B';
    }
}
