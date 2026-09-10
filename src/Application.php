<?php

declare(strict_types=1);

namespace Softpack;

/**
 * Command routing and the interactive setup.
 */
final class Application
{
    /** @var string[] */
    private $argv;

    /** @var array<string,string|bool> */
    private $options = [];

    /** @var string[] */
    private $arguments = [];

    /**
     * @param string[] $argv
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        array_shift($this->argv);

        foreach ($this->argv as $token) {
            if (strpos($token, '--') === 0) {
                $token = substr($token, 2);
                if (strpos($token, '=') !== false) {
                    [$k, $v] = explode('=', $token, 2);
                    $this->options[$k] = $v;
                } else {
                    $this->options[$token] = true;
                }
            } else {
                $this->arguments[] = $token;
            }
        }

        if (isset($this->options['non-interactive']) || isset($this->options['yes'])) {
            Cli::$interactive = false;
        }
    }

    public function run(): int
    {
        $command = $this->arguments[0] ?? 'help';

        try {
            switch ($command) {
                case 'init':    return $this->cmdInit();
                case 'build':   return $this->cmdBuild();
                case 'list':    return $this->cmdList();
                case 'show':    return $this->cmdShow();
                case 'edit':    return $this->cmdInit(true);
                case 'rm':
                case 'remove':  return $this->cmdRemove();
                case 'verify':  return $this->cmdVerify();
                case 'version':
                case '-v':
                case '--version': Cli::out('softpack ' . SOFTPACK_VERSION); return 0;
                case 'help':
                case '-h':
                case '--help':  return $this->cmdHelp();
                default:
                    Cli::fail("Unknown command '{$command}'.");
                    Cli::out();
                    return $this->cmdHelp() ?: 1;
            }
        } catch (Failure $e) {
            Cli::out();
            Cli::fail($e->getMessage());
            Cli::out();
            return 1;
        } catch (\Throwable $e) {
            Cli::out();
            Cli::fail(get_class($e) . ': ' . $e->getMessage());
            Cli::out(Cli::dim('  ' . $e->getFile() . ':' . $e->getLine()));
            Cli::out();
            return 1;
        }
    }

    // -----------------------------------------------------------------
    // commands
    // -----------------------------------------------------------------

    private function cmdHelp(): int
    {
        $home = Project::home();
        Cli::out(<<<TEXT

softpack {$this->version()} - build Softaculous/cPanel backup pairs from a local WordPress install

USAGE
  softpack <command> [options]

  Run it from inside a WordPress folder and it works out the install for
  itself. Everything it cannot know - the server, the domain, the database
  name - is asked once and saved as a project you can rebuild from later.

COMMANDS
  init              Set up (or re-run setup for) a project for this install
  build [project]   Build a backup pair; defaults to the project for this folder
  list              List saved projects
  show [project]    Print a project's settings
  edit [project]    Re-run setup with the current values as defaults
  rm <project>      Delete a saved project
  verify <pair>     Check an existing pair (pass either half of it)
  version           Print the version
  help              This text

OPTIONS
  --root=<path>     WordPress root, if not the current directory
  --out=<path>      Where to write the pair (default: the project's output dir)
  --keep-tar        Keep the intermediate .tar next to the .tar.gz
  --no-verify       Skip the post-build checks (not advised)
  --non-interactive Never prompt; fail if a value is missing
  --yes             Accept every default without prompting

PROJECTS
  Stored in {$home}/projects
  Override the location with the SOFTPACK_HOME environment variable.


TEXT);
        return 0;
    }

    private function version(): string
    {
        return SOFTPACK_VERSION;
    }

    private function cmdInit(bool $editing = false): int
    {
        $root    = $this->resolveRoot();
        $install = Detect::install($root);

        Cli::heading('Install');
        Cli::info('root', $root);
        Cli::info('WordPress', $install['version']);
        Cli::info('database', $install['db']['name'] . Cli::dim(' @ ' . $install['db']['host']));
        Cli::info('table prefix', $install['prefix']);

        $db   = $this->connect($install);
        $site = Detect::site($db, $install['prefix']);

        Cli::info('site name', $site['blogname']);
        Cli::info('site URL', $site['siteurl']);
        Cli::info('admin', $site['admin_user'] . Cli::dim(' <' . $site['admin_email'] . '>'));

        if ($install['pinned_url'] !== null && rtrim($install['pinned_url'], '/') !== $site['siteurl']) {
            Cli::warn('wp-config pins the URL to ' . $install['pinned_url']
                . ' but the database says ' . $site['siteurl'] . '.');
            Cli::warn('The database value is the one that will be rewritten.');
        }

        $existing = Project::forRoot($root);
        if ($existing !== null && !$editing) {
            Cli::out();
            Cli::warn("This install already has a project: '{$existing->name()}'.");
            if (!Cli::confirm('Update it?', true)) {
                return 0;
            }
        }
        $project = $existing ?? null;

        if ($project === null) {
            $suggested = Project::slug($site['blogname']);
            $name      = Cli::ask('Project name', $suggested);
            $project   = Project::exists($name) ? Project::load($name) : Project::create($name);
        }

        $prior  = $project->target();
        $soft   = $project->softaculous();

        Cli::heading('Server');
        $url = rtrim(Cli::ask('Live URL', $prior['url'] ?? 'https://example.com'), '/');
        if (!preg_match('#^https?://[^/\s]+#', $url)) {
            throw new Failure("'{$url}' does not look like a URL. Include the scheme, e.g. https://example.com");
        }
        $domain = (string) parse_url($url, PHP_URL_HOST);

        $cpuser = Cli::ask('cPanel username', $prior['cpanel_user'] ?? null);
        $home   = rtrim(Cli::ask('Account home path', $prior['home'] ?? '/home/' . $cpuser), '/');
        $docroot = Cli::ask('Document root on the server', $prior['softpath'] ?? $home . '/' . $domain);

        Cli::heading('Database on the server');
        Cli::out('  ' . Cli::dim('cPanel forces the account prefix on database names and users.'));
        $suggestedDb = $prior['db_name'] ?? $cpuser . '_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($project->name())) ?: 'wp', 0, 12);
        $dbName = Cli::ask('Database name', $suggestedDb);
        $dbUser = Cli::ask('Database user', $prior['db_user'] ?? $dbName);

        foreach (['name' => $dbName, 'user' => $dbUser] as $label => $value) {
            if (strpos($value, $cpuser . '_') !== 0) {
                Cli::warn("Database {$label} '{$value}' does not start with '{$cpuser}_' - cPanel will reject it.");
                if (!Cli::confirm('Keep it anyway?', false)) {
                    throw new Failure('Aborted. Re-run and use the ' . $cpuser . '_ prefix.');
                }
            }
        }

        $dbPass = Cli::ask('Database password', $prior['db_pass'] ?? Builder::password());
        $prefix = Cli::ask('WordPress table prefix', $prior['db_prefix'] ?? $install['prefix']);
        if ($prefix !== $install['prefix']) {
            Cli::warn('Changing the table prefix also means rewriting ' . $install['prefix']
                . 'capabilities, ' . $install['prefix'] . 'user_level and ' . $install['prefix'] . 'user_roles.');
            Cli::warn('softpack does not do that yet. Keeping the current prefix is the safe choice.');
            if (!Cli::confirm('Continue with the changed prefix?', false)) {
                $prefix = $install['prefix'];
            }
        }

        Cli::heading('Contents');
        $foreign = Detect::foreignEntries($root);
        $saved   = $project->excluded();          // snapshot: the loop mutates $exclude
        $isNew   = $saved === [];
        $exclude = $saved;
        if ($foreign !== []) {
            Cli::out('  ' . Cli::dim('These are not part of a stock WordPress install:'));
            foreach ($foreign as $entry) {
                $default = $isNew ? true : in_array($entry, $saved, true);
                if (Cli::confirm("  exclude '{$entry}' from the backup?", $default)) {
                    $exclude[] = $entry;
                } else {
                    $exclude = array_values(array_diff($exclude, [$entry]));
                }
            }
        }
        // Elementor's compiled CSS hardcodes the old domain; it regenerates.
        $exclude[] = 'wp-content/uploads/elementor/css';
        $exclude   = array_values(array_unique($exclude));

        $purge = Cli::confirm('Drop cached/transient rows from the export?', (bool) $project->get('purge_caches', true));

        $outDefault = (string) $project->get('output_dir', dirname($root) . '/softpack-out/' . $project->name());
        $outputDir  = rtrim(Cli::ask('Write the pair to', $outDefault), '/');

        $project->set('wp_root', $root);
        $project->set('wp_version', $install['version']);
        $project->set('local_prefix', $install['prefix']);
        $project->set('source_url', $site['siteurl']);
        $project->set('purge_caches', $purge);
        $project->set('exclude', $exclude);
        $project->set('output_dir', $outputDir);
        $project->set('target', [
            'url'         => $url,
            'domain'      => $domain,
            'cpanel_user' => $cpuser,
            'home'        => $home,
            'softpath'    => rtrim($docroot, '/'),
            'db_name'     => $dbName,
            'db_user'     => $dbUser,
            'db_pass'     => $dbPass,
            'db_host'     => 'localhost',
            'db_prefix'   => $prefix,
        ]);
        $project->set('softaculous', [
            'sid'            => 26,
            // Kept stable across rebuilds so Softaculous sees a newer backup
            // of the same installation rather than a different one.
            'insid'          => $soft['insid'] ?? ('26_' . random_int(10000, 99999)),
            'itime'          => $soft['itime'] ?? (time() - 86400),
            'soft_version'   => $soft['soft_version'] ?? '6.3.2',
            'ssk'            => $soft['ssk'] ?? Builder::salt(32),
            'notify_email'   => $soft['notify_email'] ?? $site['admin_email'],
            'admin_username' => $site['admin_user'],
            'admin_email'    => $site['admin_email'],
            'site_name'      => $site['blogname'],
        ]);
        $project->save();

        $db->close();

        Cli::heading('Saved');
        Cli::info('project', $project->name());
        Cli::info('file', $project->path());
        Cli::out();
        Cli::out('  Build it with: ' . Cli::cyan('softpack build'));
        Cli::out();
        return 0;
    }

    private function cmdBuild(): int
    {
        $project = $this->resolveProject();

        $root = (string) $project->get('wp_root');
        if (!is_dir($root)) {
            throw new Failure("The project points at {$root}, which does not exist.");
        }

        // Re-read the install: the version or prefix may have changed since setup.
        $install = Detect::install($root);
        $project->set('wp_version', $install['version']);
        $project->set('local_prefix', $install['prefix']);

        $db     = $this->connect($install);
        $target = $project->target();

        Cli::heading('Building ' . $project->name());
        Cli::info('from', $root);
        Cli::info('source URL', (string) $project->get('source_url'));
        Cli::info('target URL', (string) $target['url']);
        Cli::info('softpath', (string) $target['softpath']);
        Cli::info('database', (string) $target['db_name'] . Cli::dim(' prefix ' . $target['db_prefix']));

        $outputDir = rtrim((string) ($this->options['out'] ?? $project->get('output_dir')), '/');

        $builder = new Builder($project, $db, $install['db']);
        $result  = $builder->run(
            $outputDir,
            isset($this->options['keep-tar']),
            !isset($this->options['no-verify'])
        );

        $db->close();

        Cli::heading('Done');
        Cli::info('directory', $result['dir']);
        Cli::info('metadata', $result['name']);
        Cli::info('archive', $result['name'] . '.tar.gz  ' . Cli::dim(Cli::bytes($result['size'])));
        Cli::out();

        if (!$result['passed']) {
            Cli::fail('Some checks failed - do not upload this pair until they are resolved.');
            Cli::out();
            return 1;
        }

        // The two halves go to different directories: the metadata into
        // Softaculous' hidden data folder, the archive into the visible one.
        Cli::out('  Upload the two halves to ' . Cli::bold('different') . ' directories, in binary mode:');
        Cli::out();
        $width = strlen($result['name']) + 8;   // room for the ".tar.gz" suffix
        Cli::out('    ' . str_pad($result['name'], $width) . Cli::dim(' -> ')
            . Cli::cyan($target['home'] . '/.softaculous/backups/'));
        Cli::out('    ' . str_pad($result['name'] . '.tar.gz', $width) . Cli::dim(' -> ')
            . Cli::cyan($target['home'] . '/softaculous_backups/'));
        Cli::out();
        Cli::out('  ' . Cli::dim('.softaculous is hidden - enable "show hidden files" in your FTP client.'));
        Cli::out('  Then cPanel > Softaculous > Backups > Restore.');
        Cli::out();
        Cli::out('  ' . Cli::dim('Database password: ') . $target['db_pass']);
        Cli::out();
        return 0;
    }

    private function cmdList(): int
    {
        $slugs = Project::all();
        if ($slugs === []) {
            Cli::out();
            Cli::out('  No projects yet. Run ' . Cli::cyan('softpack init') . ' inside a WordPress folder.');
            Cli::out();
            return 0;
        }

        Cli::heading('Projects');
        foreach ($slugs as $slug) {
            $p      = Project::load($slug);
            $target = $p->target();
            $builds = (array) $p->get('builds', []);
            $last   = $builds === [] ? 'never built' : 'last ' . substr((string) end($builds)['at'], 0, 10);
            Cli::out(sprintf(
                '  %-20s %-38s %s',
                Cli::bold($slug),
                (string) ($target['url'] ?? '?'),
                Cli::dim($last)
            ));
        }
        Cli::out();
        return 0;
    }

    private function cmdShow(): int
    {
        $project = $this->resolveProject();
        $data    = $project->toArray();

        // The password is shown deliberately - it is needed on the server -
        // but nothing else about the project is secret.
        Cli::heading('Project ' . $project->name());
        Cli::out(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Cli::out();
        return 0;
    }

    private function cmdRemove(): int
    {
        $name = $this->arguments[1] ?? null;
        if ($name === null) {
            throw new Failure('Which project? Usage: softpack rm <project>');
        }
        $project = Project::load($name);
        if (!Cli::confirm("Delete project '{$project->name()}'?", false)) {
            return 0;
        }
        $project->delete();
        Cli::ok('deleted');
        return 0;
    }

    private function cmdVerify(): int
    {
        $path = $this->arguments[1] ?? null;
        if ($path === null) {
            throw new Failure('Which pair? Usage: softpack verify <path to either half>');
        }
        $path = str_replace('\\', '/', rtrim($path, '/'));
        $base = preg_replace('/\.tar\.gz$/', '', $path) ?? $path;

        $meta    = $base;
        $archive = $base . '.tar.gz';

        if (!is_file($meta))    { throw new Failure("Metadata file not found: {$meta}"); }
        if (!is_file($archive)) { throw new Failure("Archive not found: {$archive}"); }

        Cli::heading('Verifying ' . basename($base));

        $verifier = new Verifier();
        $data     = Metadata::read($meta);
        $verifier->check($data !== null, 'metadata unserialises', $data === null ? 'invalid' : count($data) . ' keys');

        if ($data !== null) {
            $verifier->check(count($data) === 34, 'outer metadata has 34 keys', 'got ' . count($data));
            $verifier->check(
                ($data['size'] ?? -1) === filesize($archive),
                'size matches the archive',
                (string) ($data['size'] ?? 'missing') . ' vs ' . filesize($archive)
            );
            $verifier->check(
                ($data['name'] ?? '') === basename($base),
                'name matches the filename',
                (string) ($data['name'] ?? '')
            );
            $cpuser = explode('_', (string) ($data['softdb'] ?? ''))[0];
            $verifier->check(
                strpos((string) ($data['softdb'] ?? ''), '_') !== false,
                'database name carries a cPanel prefix',
                (string) ($data['softdb'] ?? '') . Cli::dim(" (account '{$cpuser}')")
            );
            foreach (['softpath', 'softurl', 'dbprefix', 'insid'] as $key) {
                Cli::info($key, (string) ($data[$key] ?? '-'));
            }
        }

        $verifier->check(Archiver::verifyGzip($archive), 'gzip stream reads back cleanly');
        $verifier->report();
        Cli::out();

        return $verifier->passed() ? 0 : 1;
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function resolveRoot(): string
    {
        if (isset($this->options['root']) && is_string($this->options['root'])) {
            return Detect::findRoot($this->options['root']);
        }
        return Detect::findRoot((string) getcwd());
    }

    private function resolveProject(): Project
    {
        $named = $this->arguments[1] ?? null;
        if ($named !== null) {
            return Project::load($named);
        }

        $root    = $this->resolveRoot();
        $project = Project::forRoot($root);
        if ($project === null) {
            throw new Failure(
                "No project is set up for:\n    {$root}\n"
                . '  Run ' . Cli::cyan('softpack init') . ' here first, or name one: softpack build <project>'
            );
        }
        return $project;
    }

    /**
     * @param array{db:array{name:string,user:string,pass:string,host:string}} $install
     */
    private function connect(array $install): Db
    {
        return new Db(
            (string) $install['db']['host'],
            (string) $install['db']['user'],
            (string) $install['db']['pass'],
            (string) $install['db']['name']
        );
    }
}
