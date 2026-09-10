<?php

declare(strict_types=1);

namespace Softpack;

use mysqli;
use mysqli_result;

/**
 * Thin mysqli wrapper. softpack only ever reads from the site database -
 * the export is built by streaming rows out and rewriting them in memory,
 * so the live install is never modified.
 */
final class Db
{
    /** @var mysqli */
    private $link;

    public function __construct(string $host, string $user, string $pass, string $name)
    {
        $port   = null;
        $socket = null;

        // wp-config allows "host:port" and "host:/path/to/socket".
        if (strpos($host, ':') !== false) {
            [$host, $tail] = explode(':', $host, 2);
            if (is_numeric($tail)) {
                $port = (int) $tail;
            } else {
                $socket = $tail;
            }
        }
        if ($host === '') {
            $host = 'localhost';
        }

        $link = @new mysqli($host, $user, $pass, $name, $port ?? (int) ini_get('mysqli.default_port'), $socket ?? '');
        if ($link->connect_errno) {
            throw new Failure(
                "Cannot connect to MySQL as '{$user}' on '{$host}': {$link->connect_error}\n"
                . '  Is the database server running?'
            );
        }
        $link->set_charset('utf8mb4');
        $this->link = $link;
    }

    public function serverVersion(): string
    {
        return (string) $this->link->server_info;
    }

    public function escape(string $value): string
    {
        return $this->link->real_escape_string($value);
    }

    /** @return string[] */
    public function tables(): array
    {
        $out = [];
        $res = $this->query('SHOW TABLES');
        while ($row = $res->fetch_row()) {
            $out[] = (string) $row[0];
        }
        $res->free();
        sort($out);
        return $out;
    }

    public function createTable(string $table): string
    {
        $res = $this->query('SHOW CREATE TABLE `' . $this->ident($table) . '`');
        $row = $res->fetch_row();
        $res->free();
        return (string) $row[1];
    }

    /**
     * Column name => true when the column can be written to the dump unquoted.
     *
     * @return array<string,bool>
     */
    public function numericColumns(string $table): array
    {
        $out = [];
        $res = $this->query('SHOW COLUMNS FROM `' . $this->ident($table) . '`');
        while ($row = $res->fetch_assoc()) {
            $out[(string) $row['Field']] = (bool) preg_match(
                '/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double|year)\b/i',
                (string) $row['Type']
            );
        }
        $res->free();
        return $out;
    }

    public function count(string $table): int
    {
        $res = $this->query('SELECT COUNT(*) FROM `' . $this->ident($table) . '`');
        $n   = (int) $res->fetch_row()[0];
        $res->free();
        return $n;
    }

    /**
     * Unbuffered read so a large table does not have to fit in memory.
     */
    public function stream(string $table): mysqli_result
    {
        $res = $this->link->query('SELECT * FROM `' . $this->ident($table) . '`', MYSQLI_USE_RESULT);
        if (!$res instanceof mysqli_result) {
            throw new Failure("Reading {$table} failed: " . $this->link->error);
        }
        return $res;
    }

    /** @param array<int,string> $params */
    public function one(string $sql, array $params = []): ?array
    {
        if ($params === []) {
            $res = $this->query($sql);
            $row = $res->fetch_assoc();
            $res->free();
            return $row ?: null;
        }

        $stmt = $this->link->prepare($sql);
        if ($stmt === false) {
            throw new Failure('Query failed: ' . $this->link->error);
        }
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function query(string $sql): mysqli_result
    {
        $res = $this->link->query($sql);
        if (!$res instanceof mysqli_result) {
            throw new Failure('Query failed: ' . $this->link->error . "\n  " . $sql);
        }
        return $res;
    }

    public function exec(string $sql): void
    {
        if ($this->link->query($sql) === false) {
            throw new Failure('Statement failed: ' . $this->link->error . "\n  " . $sql);
        }
    }

    /**
     * Run a dump one statement at a time.
     *
     * multi_query() would send the whole file as a single packet and trip
     * max_allowed_packet on any dump larger than the server's limit, so the
     * script is split first. Splitting tracks quote state rather than just
     * cutting on semicolons, because serialised post content is full of them.
     *
     * @return int statements executed
     */
    public function runStatements(string $sql): int
    {
        $length   = strlen($sql);
        $buffer   = '';
        $inString = false;
        $count    = 0;
        $i        = 0;

        while ($i < $length) {
            if (!$inString) {
                $run     = strcspn($sql, "';", $i);
                $buffer .= substr($sql, $i, $run);
                $i      += $run;
                if ($i >= $length) {
                    break;
                }
                if ($sql[$i] === "'") {
                    $inString = true;
                    $buffer  .= "'";
                    $i++;
                    continue;
                }
                // semicolon: end of statement
                $i++;
                if ($this->executeIfMeaningful($buffer)) {
                    $count++;
                }
                $buffer = '';
                continue;
            }

            $run     = strcspn($sql, "'\\", $i);
            $buffer .= substr($sql, $i, $run);
            $i      += $run;
            if ($i >= $length) {
                break;
            }
            if ($sql[$i] === '\\') {
                $buffer .= substr($sql, $i, 2);   // escaped char, whatever it is
                $i      += 2;
                continue;
            }
            if ($i + 1 < $length && $sql[$i + 1] === "'") {
                $buffer .= "''";                  // doubled quote inside a string
                $i      += 2;
                continue;
            }
            $inString = false;
            $buffer  .= "'";
            $i++;
        }

        if ($this->executeIfMeaningful($buffer)) {
            $count++;
        }

        return $count;
    }

    /**
     * Skip chunks that are only comments or whitespace.
     */
    private function executeIfMeaningful(string $statement): bool
    {
        $stripped = preg_replace('/^\s*--[^\n]*$/m', '', $statement) ?? $statement;
        if (trim($stripped) === '') {
            return false;
        }
        $this->exec(trim($statement));
        return true;
    }

    public function selectDatabase(string $name): void
    {
        if (!$this->link->select_db($name)) {
            throw new Failure("Cannot switch to database '{$name}': " . $this->link->error);
        }
    }

    public function close(): void
    {
        @$this->link->close();
    }

    private function ident(string $name): string
    {
        return str_replace('`', '``', $name);
    }
}
