<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Everything softpack can work out for itself by looking at a WordPress
 * install, so the operator is only ever asked about the server side.
 */
final class Detect
{
    /** Top-level entries Softaculous records for a WordPress install. */
    public const FILE_INDEX = [
        'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-admin',
        'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php',
        'wp-content', 'wp-cron.php', 'wp-includes', 'wp-links-opml.php',
        'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
        'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php', 'wp-config.php',
        '.htaccess',
    ];

    /** Entries that belong to a WP install but are not in FILE_INDEX. */
    private const ALSO_STANDARD = ['wp-config-sample.php', 'cgi-bin', 'error_log'];

    /**
     * Walk up from $start looking for a directory holding wp-load.php.
     */
    public static function findRoot(string $start): string
    {
        $dir = str_replace('\\', '/', rtrim($start, '/\\'));
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/wp-load.php') && is_file($dir . '/wp-includes/version.php')) {
                return $dir;
            }
            $up = dirname($dir);
            if ($up === $dir) {
                break;
            }
            $dir = $up;
        }
        throw new Failure(
            "No WordPress install found at or above:\n    {$start}\n"
            . "  Run softpack from the folder holding wp-load.php, or pass --root=<path>."
        );
    }

    /**
     * @return array{version:string,db:array,prefix:string,pinned_url:?string}
     */
    public static function install(string $root): array
    {
        $configPath = $root . '/wp-config.php';
        if (!is_file($configPath)) {
            throw new Failure("No wp-config.php in {$root}. The install is not configured yet.");
        }
        $config = (string) file_get_contents($configPath);

        $db = [
            'name' => self::phpConstant($config, 'DB_NAME'),
            'user' => self::phpConstant($config, 'DB_USER'),
            'pass' => self::phpConstant($config, 'DB_PASSWORD'),
            'host' => self::phpConstant($config, 'DB_HOST') ?? 'localhost',
        ];
        foreach (['name', 'user'] as $k) {
            if ($db[$k] === null) {
                throw new Failure("Could not read DB_" . strtoupper($k) . " from wp-config.php.");
            }
        }
        $db['pass'] = (string) $db['pass'];

        if (!preg_match('/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/s', $config, $m)) {
            throw new Failure('Could not read $table_prefix from wp-config.php.');
        }
        $prefix = $m[2];

        $versionFile = (string) file_get_contents($root . '/wp-includes/version.php');
        if (!preg_match('/\$wp_version\s*=\s*([\'"])(.*?)\1\s*;/', $versionFile, $vm)) {
            throw new Failure('Could not read $wp_version from wp-includes/version.php.');
        }

        // A wp-config that pins WP_SITEURL masks the database value; worth knowing.
        $pinned = self::phpConstant($config, 'WP_SITEURL') ?? self::phpConstant($config, 'WP_HOME');

        return [
            'version'    => $vm[2],
            'db'         => $db,
            'prefix'     => $prefix,
            'pinned_url' => is_string($pinned) ? $pinned : null,
        ];
    }

    /**
     * Read the site facts that only the database knows.
     *
     * @return array{siteurl:string,home:string,blogname:string,admin_user:string,admin_email:string}
     */
    public static function site(Db $db, string $prefix): array
    {
        $opt = static function (string $name) use ($db, $prefix) {
            $row = $db->one(
                'SELECT option_value FROM `' . $prefix . 'options` WHERE option_name = ? LIMIT 1',
                [$name]
            );
            return $row === null ? null : $row['option_value'];
        };

        $siteurl = $opt('siteurl');
        if ($siteurl === null || $siteurl === '') {
            throw new Failure("No 'siteurl' row in {$prefix}options - is the table prefix right?");
        }

        // The first user carrying an administrator capability.
        $admin = $db->one(
            'SELECT u.user_login, u.user_email
               FROM `' . $prefix . 'users` u
               JOIN `' . $prefix . 'usermeta` m ON m.user_id = u.ID
              WHERE m.meta_key = ? AND m.meta_value LIKE ?
              ORDER BY u.ID ASC LIMIT 1',
            [$prefix . 'capabilities', '%administrator%']
        );
        if ($admin === null) {
            $admin = $db->one('SELECT user_login, user_email FROM `' . $prefix . 'users` ORDER BY ID ASC LIMIT 1');
        }

        return [
            'siteurl'     => rtrim((string) $siteurl, '/'),
            'home'        => rtrim((string) ($opt('home') ?? $siteurl), '/'),
            'blogname'    => (string) ($opt('blogname') ?? 'WordPress'),
            'admin_user'  => (string) ($admin['user_login'] ?? 'admin'),
            'admin_email' => (string) ($admin['user_email'] ?? ''),
        ];
    }

    /**
     * Top-level entries that are not part of a stock WordPress install -
     * build folders, database dumps, materials. Candidates for exclusion.
     *
     * @return string[]
     */
    public static function foreignEntries(string $root): array
    {
        $known = array_merge(self::FILE_INDEX, self::ALSO_STANDARD);
        $found = [];
        foreach ((array) scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, $known, true)) {
                continue;
            }
            $found[] = $entry;
        }
        sort($found);
        return $found;
    }

    /**
     * Pull a define()'d scalar out of wp-config.php source without executing it.
     *
     * @return string|bool|int|null
     */
    private static function phpConstant(string $source, string $name)
    {
        $pattern = '/define\s*\(\s*([\'"])' . preg_quote($name, '/') . '\1\s*,\s*(.+?)\s*\)\s*;/s';
        if (!preg_match($pattern, $source, $m)) {
            return null;
        }
        $raw = trim($m[2]);

        if (preg_match('/^\'(.*)\'$/s', $raw, $q)) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $q[1]);
        }
        if (preg_match('/^"(.*)"$/s', $raw, $q)) {
            return stripcslashes($q[1]);
        }
        if ($raw === 'true')  { return true; }
        if ($raw === 'false') { return false; }
        if ($raw === 'null')  { return null; }
        if (is_numeric($raw)) { return (int) $raw; }

        return null; // an expression we are not going to evaluate
    }
}
