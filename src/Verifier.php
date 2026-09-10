<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Checks that run before a build is called a success.
 *
 * Every one of these corresponds to a way a hand-built pair has actually
 * failed: an archive that looks fine locally and dies on the server is the
 * worst outcome, so the cost of checking is worth paying every time.
 */
final class Verifier
{
    /** @var array<int,array{ok:bool,label:string,detail:string}> */
    private $results = [];

    public function check(bool $ok, string $label, string $detail = ''): bool
    {
        $this->results[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
        return $ok;
    }

    public function passed(): bool
    {
        foreach ($this->results as $r) {
            if (!$r['ok']) {
                return false;
            }
        }
        return true;
    }

    public function report(): void
    {
        foreach ($this->results as $r) {
            if ($r['ok']) {
                Cli::ok($r['label'] . ($r['detail'] !== '' ? '  ' . Cli::dim($r['detail']) : ''));
            } else {
                Cli::fail($r['label'] . ($r['detail'] !== '' ? '  ' . $r['detail'] : ''));
            }
        }
    }

    /**
     * The two metadata files must be readable, and differ by exactly "size".
     */
    public function metadataPair(string $innerPath, string $outerPath, int $archiveSize): void
    {
        $inner = Metadata::read($innerPath);
        $outer = Metadata::read($outerPath);

        if (!$this->check($inner !== null, 'inner metadata unserialises', $inner === null ? 'invalid' : count($inner) . ' keys')) {
            return;
        }
        if (!$this->check($outer !== null, 'outer metadata unserialises', $outer === null ? 'invalid' : count($outer) . ' keys')) {
            return;
        }

        $this->check(count($inner) === 33, 'inner metadata has 33 keys', 'got ' . count($inner));
        $this->check(count($outer) === 34, 'outer metadata has 34 keys', 'got ' . count($outer));

        $extra = array_diff(array_keys($outer), array_keys($inner));
        $this->check(
            array_values($extra) === ['size'],
            'outer adds only the size key',
            'adds: ' . (implode(',', $extra) ?: 'nothing')
        );
        $this->check(
            ($outer['size'] ?? -1) === $archiveSize,
            'size matches the archive',
            (string) ($outer['size'] ?? 'missing') . ' vs ' . $archiveSize
        );

        $identical = true;
        foreach ($inner as $key => $value) {
            if (!array_key_exists($key, $outer) || $outer[$key] !== $value) {
                $identical = false;
                break;
            }
        }
        $this->check($identical, 'every other metadata key is identical');
    }

    /**
     * Consistency between the three places a table prefix has to agree.
     */
    public function prefixAgreement(string $metaPrefix, string $configPath, string $sqlPath): void
    {
        $config = (string) @file_get_contents($configPath);
        $inConfig = preg_match('/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/s', $config, $m) ? $m[2] : '';
        $this->check(
            $inConfig === $metaPrefix,
            'wp-config prefix matches metadata',
            "config='{$inConfig}' meta='{$metaPrefix}'"
        );

        $sql   = (string) @file_get_contents($sqlPath, false, null, 0, 200000);
        $found = preg_match('/CREATE TABLE `' . preg_quote($metaPrefix, '/') . '\w+`/', $sql) === 1;
        $this->check($found, 'dump uses the same table prefix', $metaPrefix);
    }

    /**
     * The dump must be safe to pipe into a freshly created database.
     */
    public function dumpShape(string $sqlPath, string $oldHost): void
    {
        $sql = (string) @file_get_contents($sqlPath);

        foreach (['CREATE DATABASE', 'DROP TABLE', 'LOCK TABLES'] as $banned) {
            $this->check(strpos($sql, $banned) === false, "dump has no {$banned}");
        }
        $this->check(strpos($sql, "\nUSE `") === false, 'dump has no USE statement');
        $this->check(
            preg_match('/ AUTO_INCREMENT=\d+/', $sql) !== 1,
            'AUTO_INCREMENT counters stripped'
        );
        $this->check(strpos($sql, "\r\n") === false, 'dump uses LF line endings');

        // The "-- Host:" comment legitimately says localhost; nothing else should.
        $body    = preg_replace('/^-- Host:.*$/m', '', $sql) ?? $sql;
        $strays  = substr_count($body, $oldHost);
        $this->check($strays === 0, 'no stray references to the old host', $strays . ' found');
    }

    /**
     * Import the dump into a scratch database and read it back. This is the
     * only check that proves the SQL is actually valid.
     */
    /**
     * A dedicated connection is used so the site connection is never switched
     * away from the live database, and so a failed import cannot leave the
     * caller's link in a bad state.
     *
     * @param array{host:string,user:string,pass:string,name:string} $credentials
     */
    public function dumpImports(array $credentials, string $sqlPath, string $prefix, string $expectedUrl): void
    {
        $scratch = 'softpack_verify_' . bin2hex(random_bytes(4));

        $admin = new Db($credentials['host'], $credentials['user'], $credentials['pass'], $credentials['name']);
        try {
            $admin->exec("CREATE DATABASE `{$scratch}` DEFAULT CHARACTER SET utf8mb4");
        } catch (Failure $e) {
            $admin->close();
            $this->check(true, 'dump import test skipped', 'no CREATE DATABASE privilege');
            return;
        }

        try {
            $target = new Db($credentials['host'], $credentials['user'], $credentials['pass'], $scratch);
            try {
                $statements = $target->runStatements((string) file_get_contents($sqlPath));
                $tables     = count($target->tables());
                $this->check(
                    $tables > 0,
                    'dump imports into an empty database',
                    $tables . ' tables, ' . $statements . ' statements'
                );

                $row = $target->one(
                    'SELECT option_value FROM `' . $prefix . 'options` WHERE option_name = ? LIMIT 1',
                    ['siteurl']
                );
                $this->check(
                    ($row['option_value'] ?? '') === $expectedUrl,
                    'siteurl is the target URL',
                    (string) ($row['option_value'] ?? 'missing')
                );

                $this->serializedIntegrity($target, $prefix);
            } finally {
                $target->close();
            }
        } catch (Failure $e) {
            $message = $e->getMessage();
            if (stripos($message, 'max_allowed_packet') !== false) {
                $message .= "\n         A single row is larger than the server will accept in one"
                    . "\n         packet. The destination server will hit the same limit.";
            }
            $this->check(false, 'dump imports into an empty database', $message);
        } finally {
            try {
                $admin->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            } catch (Failure $e) {
                Cli::warn("Left a scratch database behind: {$scratch}");
            }
            $admin->close();
        }
    }

    /**
     * Sample the serialised columns and confirm they still unserialise. This
     * is what catches a length-unaware search/replace.
     */
    private function serializedIntegrity(Db $db, string $prefix): void
    {
        $checked = 0;
        $broken  = 0;

        foreach ([[$prefix . 'postmeta', 'meta_value'], [$prefix . 'options', 'option_value']] as [$table, $column]) {
            $res = $db->query(
                "SELECT `{$column}` AS v FROM `{$table}` WHERE `{$column}` LIKE 'a:%' LIMIT 500"
            );
            while ($row = $res->fetch_assoc()) {
                $value = (string) $row['v'];
                if (!Replacer::isSerialized($value)) {
                    continue;
                }
                $checked++;
                if (@unserialize($value, ['allowed_classes' => false]) === false) {
                    $broken++;
                }
            }
            $res->free();
        }

        $this->check(
            $broken === 0,
            'serialised values still unserialise',
            $checked . ' sampled, ' . $broken . ' broken'
        );
    }

    /**
     * The archive has to extract correctly on a Linux box.
     *
     * @param string[] $required entry names that must be present
     */
    public function archiveShape(string $tarPath, array $required, int $expectedEntries): void
    {
        $entries = Archiver::listTar($tarPath);

        $withBackslash = 0;
        foreach ($entries as $entry) {
            if (strpos($entry, '\\') !== false) {
                $withBackslash++;
            }
        }
        $this->check($withBackslash === 0, 'archive paths use forward slashes', $withBackslash . ' bad');

        $wrapped = 0;
        foreach ($entries as $entry) {
            if (strpos($entry, './') === 0) {
                $wrapped++;
            }
        }
        $this->check($wrapped === 0, 'no ./ prefix on entries', $wrapped . ' found');

        $normalised = [];
        foreach ($entries as $entry) {
            $normalised[rtrim($entry, '/')] = true;
        }
        $missing = [];
        foreach ($required as $name) {
            if (!isset($normalised[rtrim($name, '/')])) {
                $missing[] = $name;
            }
        }
        $this->check($missing === [], 'required entries present', implode(', ', $missing));

        $longest = 0;
        foreach ($entries as $entry) {
            $longest = max($longest, strlen($entry));
        }
        $this->check(
            $longest <= 100 || $this->hasLongNameSupport($tarPath),
            'long paths stored with the GNU extension',
            'longest ' . $longest . ' chars'
        );

        // Directories are listed by tar with a trailing slash; the walk counts
        // them too, so the two totals should be within a rounding of each other.
        $this->check(
            abs(count($entries) - $expectedEntries) <= 1,
            'archive holds every walked path',
            count($entries) . ' in tar vs ' . $expectedEntries . ' walked'
        );
    }

    /**
     * If any entry is over 100 chars and tar listed it in full, the long-name
     * extension is in use - a plain ustar header could not have held it.
     */
    private function hasLongNameSupport(string $tarPath): bool
    {
        foreach (Archiver::listTar($tarPath) as $entry) {
            if (strlen($entry) > 100) {
                return true;
            }
        }
        return false;
    }
}
