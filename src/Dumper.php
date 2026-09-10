<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Writes softsql.sql in Softaculous' own dump format.
 *
 * Rows are streamed straight out of the live database, rewritten in memory and
 * written to the file. Nothing is cloned and nothing is modified, so the local
 * site keeps working while an export is built.
 *
 * The format deliberately omits CREATE DATABASE / USE / DROP TABLE /
 * LOCK TABLES: Softaculous creates the target database itself and pipes this
 * straight in.
 */
final class Dumper
{
    /** Rows dropped from the export, keyed by table name minus the prefix. */
    private const PURGE = [
        'postmeta' => [
            'column' => 'meta_key',
            'exact'  => ['_elementor_element_cache', '_elementor_css', '_edit_lock'],
        ],
        'options' => [
            'column' => 'option_name',
            'exact'  => ['_elementor_global_css', 'elementor_global_css'],
            'like'   => ['_transient_%', '_site_transient_%'],
        ],
    ];

    /** Tables emptied entirely (structure kept). */
    private const TRUNCATE = ['e_events', 'actionscheduler_logs'];

    /** @var Db */
    private $db;

    /** @var Replacer */
    private $replacer;

    /** @var string */
    private $prefix;

    /** @var bool */
    private $purge;

    /** @var array<string,int> */
    public $stats = ['tables' => 0, 'rows' => 0, 'purged' => 0];

    public function __construct(Db $db, Replacer $replacer, string $prefix, bool $purge = true)
    {
        $this->db       = $db;
        $this->replacer = $replacer;
        $this->prefix   = $prefix;
        $this->purge    = $purge;
    }

    public function write(string $path, string $targetDbName, string $dbHost): void
    {
        $fh = fopen($path, 'wb');   // binary: never translate \n on Windows
        if ($fh === false) {
            throw new Failure("Cannot write {$path}");
        }

        $w = static function (string $s) use ($fh): void {
            fwrite($fh, $s);
        };

        $w("-- Softaculous SQL Dump\n");
        $w("-- http://www.softaculous.com\n");
        $w("--\n");
        $w('-- Host: ' . $dbHost . "\n");
        $w('-- Generation Time: ' . gmdate('F j, Y, g:i a') . "\n");
        $w('-- Server version: ' . $this->db->serverVersion() . "\n");
        $w('-- PHP Version: ' . PHP_VERSION . "\n");
        $w("\n");
        $w("SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n");
        $w("SET time_zone = \"+00:00\";\n");
        $w("\n\n");
        $w("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        $w("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
        $w("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
        $w("/*!40101 SET NAMES utf8mb4 */;\n");
        $w("\n");
        $w("--\n-- Database: `" . $targetDbName . "`\n--\n");

        foreach ($this->db->tables() as $table) {
            $this->stats['tables']++;
            $short = $this->unprefixed($table);

            $w("\n-- --------------------------------------------------------\n\n");
            $w("--\n-- Table structure for table `{$table}`\n--\n\n");

            // Softaculous strips the counter so a restored table starts clean.
            $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $this->db->createTable($table));
            $w($create . ";\n");

            if (in_array($short, self::TRUNCATE, true)) {
                continue;
            }
            if ($this->db->count($table) === 0) {
                continue;
            }

            $this->writeRows($fh, $table, $short);
        }

        $w("\n-- --------------------------------------------------------\n\n");
        $w("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
        $w("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
        $w("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");

        fclose($fh);
    }

    /**
     * @param resource $fh
     */
    private function writeRows($fh, string $table, string $short): void
    {
        $numeric = $this->db->numericColumns($table);
        $res     = $this->db->stream($table);

        $header  = "\n--\n-- Dumping data for table `{$table}`\n--\n\n";
        $started = false;
        $open    = false;
        $bytes   = 0;
        $rows    = 0;

        while ($row = $res->fetch_assoc()) {
            if ($this->purge && $this->isPurged($short, $row)) {
                $this->stats['purged']++;
                continue;
            }

            $values = [];
            foreach ($row as $column => $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                    continue;
                }
                if (($numeric[$column] ?? false) && $value !== '') {
                    $values[] = (string) $value;
                    continue;
                }
                $rewritten = $this->replacer->value((string) $value, $table . '.' . $column);
                $values[]  = "'" . $this->db->escape((string) $rewritten) . "'";
            }

            $line = '(' . implode(', ', $values) . ')';

            if (!$started) {
                fwrite($fh, $header);
                $started = true;
            }
            if (!$open) {
                fwrite($fh, "INSERT INTO `{$table}` VALUES\n");
                $open  = true;
                $bytes = 0;
                $rows  = 0;
            } else {
                fwrite($fh, ",\n");
            }

            fwrite($fh, $line);
            $bytes += strlen($line);
            $rows++;
            $this->stats['rows']++;

            // Keep individual statements to a size any server will accept.
            if ($bytes > 500000 || $rows >= 250) {
                fwrite($fh, ";\n\n");
                $open = false;
            }
        }

        $res->free();

        if ($open) {
            fwrite($fh, ";\n");
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isPurged(string $short, array $row): bool
    {
        if (!isset(self::PURGE[$short])) {
            return false;
        }
        $rule   = self::PURGE[$short];
        $column = $rule['column'];
        if (!isset($row[$column])) {
            return false;
        }
        $value = (string) $row[$column];

        if (in_array($value, $rule['exact'] ?? [], true)) {
            return true;
        }
        foreach ($rule['like'] ?? [] as $pattern) {
            // '%' is the only wildcard; everything else is matched literally.
            $regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/';
            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }
        return false;
    }

    private function unprefixed(string $table): string
    {
        return strpos($table, $this->prefix) === 0
            ? substr($table, strlen($this->prefix))
            : $table;
    }
}
