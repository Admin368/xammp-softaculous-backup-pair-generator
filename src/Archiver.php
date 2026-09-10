<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Builds the .tar and then the .tar.gz.
 *
 * tar is required because a stock WordPress + Elementor install has several
 * hundred paths over tar's 100-character limit, and the archive has to use the
 * GNU long-name extension for those. Compression falls back through 7-Zip,
 * gzip and finally a small pure-PHP gzip writer, so no external compressor is
 * strictly needed.
 */
final class Archiver
{
    /** @var string|null */
    private static $tarBinary;

    /** @var bool GNU tar needs --force-local or it reads "C:/x" as a host spec */
    private static $tarIsGnu = false;

    public static function tarBinary(): string
    {
        if (self::$tarBinary === null) {
            // Windows ships bsdtar in System32, which handles drive letters
            // natively. Preferring it avoids the GNU host-spec problem when
            // softpack is run from Git Bash.
            $found = self::locate(['tar', 'bsdtar'], [
                'C:/Windows/System32/tar.exe',
            ]);
            if ($found === null) {
                throw new Failure(
                    "softpack needs 'tar' on PATH to build the archive.\n"
                    . '  Windows 10/11 and every Linux ship it; check with: tar --version'
                );
            }
            self::$tarBinary = $found;

            $version        = self::run($found, ['--version']);
            self::$tarIsGnu = stripos($version['out'], 'GNU tar') !== false;
        }
        return self::$tarBinary;
    }

    /**
     * Options every tar invocation needs on this platform.
     *
     * @return string[]
     */
    private static function tarFlags(): array
    {
        self::tarBinary();
        return self::$tarIsGnu ? ['--force-local'] : [];
    }

    /**
     * @param string[] $entries       top-level names inside $sourceRoot
     * @param string[] $excludes      relative patterns to skip
     * @param string[] $stagedEntries names inside $stagingDir
     */
    public static function tar(
        string $destination,
        string $sourceRoot,
        array $entries,
        array $excludes,
        string $stagingDir,
        array $stagedEntries
    ): void {
        if (is_file($destination)) {
            @unlink($destination);
        }

        $args = array_merge(['-c', '-f', self::path($destination)], self::tarFlags());
        foreach ($excludes as $pattern) {
            $args[] = '--exclude=' . $pattern;
        }
        // Two source directories in one pass: the install, then the generated
        // files. tar applies -C in order as it walks the operand list.
        $args[] = '-C';
        $args[] = self::path($sourceRoot);
        foreach ($entries as $entry) {
            $args[] = $entry;
        }
        $args[] = '-C';
        $args[] = self::path($stagingDir);
        foreach ($stagedEntries as $entry) {
            $args[] = $entry;
        }

        $result = self::run(self::tarBinary(), $args);
        if ($result['code'] !== 0) {
            throw new Failure("tar failed (exit {$result['code']}):\n  " . trim($result['err']));
        }
        if (!is_file($destination)) {
            throw new Failure('tar reported success but produced no archive.');
        }
    }

    /**
     * @return string[] entry names as actually stored in the archive
     */
    public static function listTar(string $archive): array
    {
        $result = self::run(
            self::tarBinary(),
            array_merge(['-t', '-f', self::path($archive)], self::tarFlags())
        );
        if ($result['code'] !== 0) {
            throw new Failure('Could not list the archive: ' . trim($result['err']));
        }
        $lines = preg_split('/\r?\n/', trim($result['out'])) ?: [];
        return array_values(array_filter($lines, static function (string $l): bool {
            return $l !== '';
        }));
    }

    /**
     * gzip $source to $source.gz, storing the original filename in the header
     * the way a real Softaculous backup does.
     *
     * @return string the method used, for reporting
     */
    public static function gzip(string $source, string $destination): string
    {
        if (is_file($destination)) {
            @unlink($destination);
        }

        $sevenZip = self::locate(['7z', '7za'], [
            'C:/Program Files/7-Zip/7z.exe',
            'C:/Program Files (x86)/7-Zip/7z.exe',
        ]);
        if ($sevenZip !== null) {
            $result = self::run($sevenZip, [
                'a', '-tgzip', '-mx=6', '-bso0', '-bsp0',
                self::path($destination), self::path($source),
            ]);
            if ($result['code'] === 0 && is_file($destination)) {
                return '7-Zip';
            }
        }

        self::gzipWithPhp($source, $destination);
        return 'php-zlib';
    }

    /**
     * Stream a gzip member by hand: header with FNAME, raw deflate body,
     * CRC32 and size trailer. Avoids holding the archive in memory.
     */
    private static function gzipWithPhp(string $source, string $destination): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new Failure("Cannot read {$source}");
        }
        $out = fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);
            throw new Failure("Cannot write {$destination}");
        }

        $name = basename($source);
        // magic, deflate, FNAME flag, mtime, no extra flags, unknown OS
        fwrite($out, "\x1f\x8b\x08\x08" . pack('V', time()) . "\x00\xff");
        fwrite($out, $name . "\x00");

        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        $crc     = hash_init('crc32b');
        $size    = 0;

        while (!feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $size += strlen($chunk);
            hash_update($crc, $chunk);
            fwrite($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
        }
        fwrite($out, deflate_add($deflate, '', ZLIB_FINISH));

        // CRC32 comes out big-endian from hash(); gzip wants little-endian.
        $raw = hash_final($crc, true);
        fwrite($out, strrev($raw));
        fwrite($out, pack('V', $size % 4294967296));

        fclose($in);
        fclose($out);
    }

    public static function verifyGzip(string $archive): bool
    {
        $fh = @gzopen($archive, 'rb');
        if ($fh === false) {
            return false;
        }
        while (!gzeof($fh)) {
            if (gzread($fh, 1 << 20) === false) {
                gzclose($fh);
                return false;
            }
        }
        gzclose($fh);
        return true;
    }

    /**
     * @param string[] $names
     * @param string[] $absoluteCandidates
     */
    private static function locate(array $names, array $absoluteCandidates): ?string
    {
        foreach ($absoluteCandidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $isWindows = stripos(PHP_OS_FAMILY, 'win') === 0;
        $probe     = $isWindows ? 'where' : 'command -v';
        foreach ($names as $name) {
            $out  = [];
            $code = 0;
            @exec($probe . ' ' . escapeshellarg($name) . ' 2>' . ($isWindows ? 'NUL' : '/dev/null'), $out, $code);
            if ($code === 0 && $out !== []) {
                return trim($out[0]);
            }
        }
        return null;
    }

    /**
     * Run an external command and collect its output.
     *
     * stdout and stderr go to temporary files rather than pipes. With pipes,
     * a child that writes more to stderr than the pipe buffer holds blocks
     * forever while the parent is still blocked reading stdout - a deadlock
     * that shows up as a build that simply stops, using no CPU. stdin is
     * bound to the null device so an unexpected prompt fails fast instead of
     * hanging on an inherited handle.
     *
     * @param string[] $args
     * @return array{code:int,out:string,err:string}
     */
    private static function run(string $binary, array $args): array
    {
        $command = escapeshellarg($binary);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $outFile = (string) tempnam(sys_get_temp_dir(), 'sp-out');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'sp-err');
        $null    = stripos(PHP_OS_FAMILY, 'win') === 0 ? 'NUL' : '/dev/null';

        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);
            throw new Failure("Could not run: {$binary}");
        }

        $code = proc_close($process);

        $out = (string) @file_get_contents($outFile);
        $err = (string) @file_get_contents($errFile);
        @unlink($outFile);
        @unlink($errFile);

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    private static function path(string $p): string
    {
        return str_replace('\\', '/', $p);
    }
}
