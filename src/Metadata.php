<?php

declare(strict_types=1);

namespace Softpack;

/**
 * The Softaculous metadata file - a PHP-serialised array, written twice:
 *
 *   inner (inside the tar)      33 keys, last key "ext"
 *   outer (beside the .tar.gz)  34 keys, adds "size"
 *
 * "size" is the byte length of the finished archive, which is why the outer
 * copy can only be written after compression.
 *
 * Key order matches a genuine Softaculous backup exactly. It is built with
 * serialize() rather than by hand because every string carries a byte-length
 * prefix; one wrong count and Softaculous refuses the backup without saying why.
 */
final class Metadata
{
    /**
     * @return array<string,mixed>
     */
    public static function build(Project $p): array
    {
        $t = $p->target();
        $s = $p->softaculous();

        return [
            'sid'                => (int) $s['sid'],
            'ver'                => (string) $p->get('wp_version'),
            'itime'              => (int) $s['itime'],
            'softpath'           => (string) $t['softpath'],
            'softurl'            => (string) $t['url'],
            'adminurl'           => 'wp-admin/',
            'disable_wp_cron'    => '',
            'admin_username'     => (string) $s['admin_username'],
            'admin_email'        => (string) $s['admin_email'],
            'softdomain'         => (string) $t['domain'],
            'softdb'             => (string) $t['db_name'],
            'softdbuser'         => (string) $t['db_user'],
            'softdbhost'         => (string) $t['db_host'],
            'softdbpass'         => (string) $t['db_pass'],
            'dbprefix'           => (string) $t['db_prefix'],
            'dbcreated'          => true,
            'fileindex'          => Detect::FILE_INDEX,
            'site_name'          => (string) $s['site_name'],
            'insid'              => (string) $s['insid'],
            'script_name'        => 'WordPress',
            'display_softdbpass' => (string) $t['db_pass'],
            'name'               => (string) $p->buildName(),
            'path'               => (string) $t['home'] . '/softaculous_backups',
            'backup_db'          => 1,
            'backup_dir'         => 1,
            'backup_datadir'     => 0,
            'backup_wwwdir'      => 0,
            'backup_note'        => (string) $p->get('note', ''),
            'ssk'                => (string) $s['ssk'],
            'email'              => (string) $s['notify_email'],
            'soft_version'       => (string) $s['soft_version'],
            'btime'              => (int) $p->get('btime'),
            'ext'                => 'tar.gz',
        ];
    }

    public static function writeInner(Project $p, string $destination): int
    {
        $bytes = serialize(self::build($p));
        self::put($destination, $bytes);
        return strlen($bytes);
    }

    public static function writeOuter(Project $p, string $destination, int $archiveSize): int
    {
        $meta         = self::build($p);
        $meta['size'] = $archiveSize;
        $bytes        = serialize($meta);
        self::put($destination, $bytes);
        return strlen($bytes);
    }

    /**
     * Read a metadata file back. Returns null when it is not valid.
     *
     * @return array<string,mixed>|null
     */
    public static function read(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }

    private static function put(string $path, string $bytes): void
    {
        if (@file_put_contents($path, $bytes) === false) {
            throw new Failure("Cannot write {$path}");
        }
    }
}
