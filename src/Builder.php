<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Turns a project into a finished backup pair.
 *
 * Order matters in one place: the outer metadata carries the byte size of the
 * .tar.gz, so it can only be written after compression has finished.
 */
final class Builder
{
    /** @var Project */
    private $project;

    /** @var Db */
    private $db;

    /** @var string */
    private $work;

    /** @var array{host:string,user:string,pass:string,name:string} */
    private $credentials;

    /**
     * @param array{host:string,user:string,pass:string,name:string} $credentials
     */
    public function __construct(Project $project, Db $db, array $credentials)
    {
        $this->project     = $project;
        $this->db          = $db;
        $this->credentials = $credentials;
    }

    /**
     * @return array{name:string,dir:string,size:int,passed:bool}
     */
    public function run(string $outputDir, bool $keepTar = false, bool $verify = true): array
    {
        $project = $this->project;
        $project->startBuild(time());

        $name      = $project->buildName();
        $root      = rtrim((string) $project->get('wp_root'), '/');
        $target    = $project->target();
        $sourceUrl = rtrim((string) $project->get('source_url'), '/');

        $this->work = $this->makeWorkDir($name);
        $staging    = $this->work . '/staging';
        @mkdir($staging . '/cgi-bin', 0777, true);

        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new Failure("Cannot create the output directory: {$outputDir}");
        }

        // ---- 1. database ------------------------------------------------
        Cli::heading('Database');
        $replacer = new Replacer(Replacer::urlPairs($sourceUrl, (string) $target['url']));
        $dumper   = new Dumper(
            $this->db,
            $replacer,
            (string) $project->get('local_prefix'),
            (bool) $project->get('purge_caches', true)
        );

        $sqlPath = $staging . '/softsql.sql';
        $dumper->write($sqlPath, (string) $target['db_name'], (string) $target['db_host']);

        Cli::ok(sprintf(
            '%d tables, %s rows written  %s',
            $dumper->stats['tables'],
            number_format($dumper->stats['rows']),
            Cli::dim(Cli::bytes((int) filesize($sqlPath)))
        ));
        Cli::ok(sprintf(
            '%s URL replacements across %s values',
            number_format($replacer->replacements),
            number_format($replacer->rowsTouched)
        ));
        if ($dumper->stats['purged'] > 0) {
            Cli::ok(number_format($dumper->stats['purged']) . ' cache/transient rows dropped');
        }
        if ($replacer->skipped !== []) {
            $unique = array_values(array_unique($replacer->skipped));
            Cli::warn(count($replacer->skipped) . ' value(s) left untouched because the '
                . 'serialisation could not be round-tripped safely:');
            foreach (array_slice($unique, 0, 5) as $where) {
                Cli::warn('    ' . $where);
            }
        }

        // ---- 2. generated files ----------------------------------------
        Cli::heading('Files');
        $this->writeWpConfig($staging . '/wp-config.php', $target);
        $this->writeHtaccess($staging . '/.htaccess', (string) $target['softpath'], (string) $target['url']);
        file_put_contents($staging . '/softver.txt', (string) $project->get('wp_version'));

        $payload = new Payload($root, $project->excluded());
        $walked  = $payload->walk();

        // softperms lists the staged entries too, but not the artefacts
        // Softaculous writes after generating it.
        $stagedForPerms = ['.htaccess' => false, 'cgi-bin' => true, 'softver.txt' => false, 'wp-config.php' => false];
        $permCount = $payload->writePerms($staging . '/softperms.txt', $stagedForPerms);
        Cli::ok(number_format($permCount) . ' paths recorded in softperms.txt');

        Metadata::writeInner($project, $staging . '/' . $name);
        Cli::ok('inner metadata written');

        if ($project->excluded() !== []) {
            Cli::ok('excluded: ' . implode(', ', $project->excluded()));
        }

        // ---- 3. archive --------------------------------------------------
        Cli::heading('Archive');
        $tarPath = $this->work . '/' . $name . '.tar';
        $staged  = ['.htaccess', 'cgi-bin', 'softver.txt', 'wp-config.php', 'softsql.sql', 'softperms.txt', $name];

        Archiver::tar(
            $tarPath,
            $root,
            $payload->topLevel(),
            $payload->nestedExcludes(),
            $staging,
            $staged
        );
        Cli::ok('tar built  ' . Cli::dim(Cli::bytes((int) filesize($tarPath))));

        $gzPath = $outputDir . '/' . $name . '.tar.gz';
        $method = Archiver::gzip($tarPath, $gzPath);
        $size   = (int) filesize($gzPath);
        Cli::ok('gzip via ' . $method . '  ' . Cli::dim(Cli::bytes($size)));

        // ---- 4. outer metadata, now that the size is known ---------------
        $outerPath = $outputDir . '/' . $name;
        Metadata::writeOuter($project, $outerPath, $size);
        Cli::ok('outer metadata written with size=' . $size);

        // ---- 5. verify ----------------------------------------------------
        $passed = true;
        if ($verify) {
            Cli::heading('Verification');
            $verifier = new Verifier();
            $verifier->metadataPair($staging . '/' . $name, $outerPath, $size);
            $verifier->prefixAgreement((string) $target['db_prefix'], $staging . '/wp-config.php', $sqlPath);
            $verifier->dumpShape($sqlPath, (string) parse_url($sourceUrl, PHP_URL_HOST));
            $verifier->dumpImports($this->credentials, $sqlPath, (string) $target['db_prefix'], (string) $target['url']);
            $verifier->archiveShape(
                $tarPath,
                ['softsql.sql', 'softperms.txt', 'softver.txt', 'wp-config.php', '.htaccess', 'cgi-bin', $name],
                count($walked) + count($stagedForPerms) + 3
            );
            $verifier->check(Archiver::verifyGzip($gzPath), 'gzip stream reads back cleanly');
            $verifier->report();
            $passed = $verifier->passed();
        } else {
            Cli::heading('Verification');
            Cli::warn('skipped (--no-verify)');
        }

        if ($keepTar) {
            @rename($tarPath, $outputDir . '/' . $name . '.tar');
        }

        $project->recordBuild($name, $size, $outputDir);
        $project->save();

        $this->cleanup();

        return ['name' => $name, 'dir' => $outputDir, 'size' => $size, 'passed' => $passed];
    }

    /**
     * @param array<string,mixed> $target
     */
    private function writeWpConfig(string $destination, array $target): void
    {
        $salts = '';
        foreach ([
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ] as $key) {
            $salts .= sprintf("define( %-19s %s );\n", "'{$key}',", "'" . self::salt() . "'");
        }
        $salts = rtrim($salts, "\n");

        $q = static function (string $v): string {
            return str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
        };

        $php = "<?php\n"
            . "/**\n"
            . " * WordPress configuration, written by softpack for the live server.\n"
            . " *\n"
            . " * Note there is deliberately no WP_HOME / WP_SITEURL here: the site URL\n"
            . " * comes from the database, and a pinned constant would override it.\n"
            . " */\n\n"
            . "// ** Database settings ** //\n"
            . "define( 'DB_NAME', '" . $q((string) $target['db_name']) . "' );\n"
            . "define( 'DB_USER', '" . $q((string) $target['db_user']) . "' );\n"
            . "define( 'DB_PASSWORD', '" . $q((string) $target['db_pass']) . "' );\n"
            . "define( 'DB_HOST', '" . $q((string) $target['db_host']) . "' );\n"
            . "define( 'DB_CHARSET', 'utf8mb4' );\n"
            . "define( 'DB_COLLATE', '' );\n\n"
            . "/**#@+\n"
            . " * Authentication unique keys and salts.\n"
            . " *\n"
            . " * @since 2.6.0\n"
            . " */\n"
            . $salts . "\n\n"
            . "/**#@-*/\n\n"
            . "/** WordPress database table prefix. */\n"
            . "\$table_prefix = '" . $q((string) $target['db_prefix']) . "';\n\n"
            . "/** For developers: WordPress debugging mode. */\n"
            . "define( 'WP_DEBUG', false );\n\n"
            . "/* Add any custom values between this line and the \"stop editing\" line. */\n\n"
            . "define( 'FS_METHOD', 'direct' );\n"
            . "define( 'WP_MEMORY_LIMIT', '256M' );\n\n"
            . "/* That's all, stop editing! Happy publishing. */\n\n"
            . "/** Absolute path to the WordPress directory. */\n"
            . "if ( ! defined( 'ABSPATH' ) ) {\n"
            . "\tdefine( 'ABSPATH', __DIR__ . '/' );\n"
            . "}\n\n"
            . "/** Sets up WordPress vars and included files. */\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";

        file_put_contents($destination, $php);
    }

    /**
     * RewriteBase has to match where the install actually sits under the
     * document root, which is derived from the target URL's path.
     */
    private function writeHtaccess(string $destination, string $softpath, string $url): void
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $base = $path === '' ? '/' : '/' . $path . '/';

        $lines = [
            '# BEGIN WordPress',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            'RewriteBase ' . $base,
            'RewriteRule ^index\.php$ - [L]',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule . ' . $base . 'index.php [L]',
            '</IfModule>',
            '# END WordPress',
        ];
        file_put_contents($destination, implode("\n", $lines) . "\n");
    }

    public static function salt(int $length = 64): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    public static function password(int $length = 16): string
    {
        // No quotes or backslashes: this value is embedded in single-quoted
        // PHP and passed through cPanel's own forms.
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%^*-_=+';
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    private function makeWorkDir(string $name): string
    {
        $dir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
            . '/softpack-' . $name . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Failure("Cannot create a working directory at {$dir}");
        }
        return $dir;
    }

    private function cleanup(): void
    {
        if ($this->work === '' || !is_dir($this->work)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->work);
    }
}
