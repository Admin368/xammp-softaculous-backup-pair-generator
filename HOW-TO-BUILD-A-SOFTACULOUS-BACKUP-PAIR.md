# Building a Softaculous / cPanel backup pair by hand

How to turn a local XAMPP WordPress site into a **backup pair** that Softaculous
will accept as one of its own, so a site can be moved to a cPanel server by FTP
upload + "Restore" instead of a manual migration.

This is the format specification softpack implements. Read it if you are
debugging a pair softpack produced, extending the tool, or need to build one by
hand. Each section names the file in `src/` that implements it.

**Conventions used in the examples.** All identifiers below are placeholders:

| Placeholder | Means |
|---|---|
| `acme` | the cPanel account username |
| `<home>` | the account home directory — `/home/acme`, `/home2/acme`, … depending on the server |
| `example.com` | the domain of an existing reference install |
| `newsite.example.com` | the domain you are restoring to |
| `C:\xampp\htdocs\mysite` | the local WordPress root |
| `mysite_db` | the local database |

---

## 1. What a "pair" is

Two files that **share the same base name** but live in **two different
directories** on the server:

| File | What it is | Where it goes |
|---|---|---|
| `wp.<insid>.<stamp>` | Metadata. A PHP-serialised array. **No file extension.** | `<home>/.softaculous/backups/` |
| `wp.<insid>.<stamp>.tar.gz` | The payload: site files + database + a few Softaculous artefacts | `<home>/softaculous_backups/` |

They are built together and named together, but they are **not uploaded to the
same place**. The metadata goes into Softaculous' own hidden data directory,
which is what it reads to build the backup list; the archive goes into the
visible backups directory, which is where it looks when you press Restore.

> `.softaculous` is a dot-directory, so most FTP clients hide it by default.
> Turn on "show hidden files" (FileZilla: Server → Force showing hidden files)
> or you will not see it, and creating a second one alongside will not help.

Softaculous lists a backup only when it can read the metadata file. The
`.tar.gz` alone is invisible to it; the metadata file alone gives a listing that
fails on restore. Both, or nothing.

Name format: `wp` . `<insid>` . `<YYYY-MM-DD_HH-MM-SS>`

- `wp` is the script slug (WordPress)
- `<insid>` is `<sid>_<installid>`, where **sid 26 = WordPress**. The install id
  is arbitrary — pick an unused number, e.g. `26_10042`.

*softpack: `src/Project.php` (`buildName()`).*

---

## 2. Inside the .tar.gz

It is a gzip wrapping a **single .tar**, and the tar's entries sit at the
**archive root — there is no wrapper directory**:

```
wp.<insid>.<stamp>.tar.gz
 └── wp.<insid>.<stamp>.tar
      ├── wp.<insid>.<stamp>      <- the metadata file again (inner copy)
      ├── softsql.sql             <- the database
      ├── softperms.txt           <- every path + its octal mode
      ├── softver.txt             <- WordPress version, e.g. "7.1"
      ├── cgi-bin/                <- present but empty
      ├── .htaccess
      ├── wp-config.php
      ├── index.php  license.txt  readme.html  wp-*.php  xmlrpc.php
      ├── wp-admin/
      ├── wp-content/
      └── wp-includes/
```

If you extract with 7-Zip on Windows it will *appear* to create a folder named
after the archive — that is 7-Zip being helpful, not part of the format. Confirm
the real layout with `tar -tf archive.tar | head`.

*softpack: `src/Archiver.php`, `src/Payload.php`.*

---

## 3. The metadata file

A PHP-serialised associative array on a single line. **There are two versions of
it**, and they differ:

| | Keys | Ends with |
|---|---|---|
| **inner** (inside the tar) | 33 | `ext` |
| **outer** (beside the .tar.gz) | 34 | `ext`, then `size` |

`size` is the byte size of the finished `.tar.gz`. That is why the outer file has
to be written **last**, after compression.

### The keys

| Key | Type | Notes |
|---|---|---|
| `sid` | int | `26` for WordPress |
| `ver` | string | WordPress version, e.g. `"7.1"` |
| `itime` | int | unix time the install was created |
| `softpath` | string | absolute docroot on the server, **restore target** |
| `softurl` | string | live URL, no trailing slash |
| `adminurl` | string | `wp-admin/` |
| `disable_wp_cron` | string | empty string |
| `admin_username` | string | WP admin login |
| `admin_email` | string | |
| `softdomain` | string | bare domain, no scheme |
| `softdb` | string | database name — **must carry the cPanel prefix** |
| `softdbuser` | string | database user — same prefix rule |
| `softdbhost` | string | `localhost` |
| `softdbpass` | string | |
| `dbprefix` | string | WP **table** prefix, e.g. `wp_` |
| `dbcreated` | bool | `true` |
| `fileindex` | array | top-level entries of a WP install (21 items) |
| `site_name` | string | |
| `insid` | string | e.g. `26_10042` |
| `script_name` | string | `WordPress` |
| `display_softdbpass` | string | same value as `softdbpass` |
| `name` | string | the backup base name |
| `path` | string | `<home>/softaculous_backups` — where the **archive** lives, not the metadata file |
| `backup_db` | int | `1` |
| `backup_dir` | int | `1` |
| `backup_datadir` | int | `0` |
| `backup_wwwdir` | int | `0` |
| `backup_note` | string | empty |
| `ssk` | string | 32-char random token |
| `email` | string | Softaculous account notification address |
| `soft_version` | string | Softaculous version, e.g. `6.3.2` |
| `btime` | int | unix time of the backup; keep consistent with `<stamp>` |
| `ext` | string | `tar.gz` |
| `size` | int | **outer file only** — byte size of the .tar.gz |

> **The metadata file holds a database password in clear text**, as
> `softdbpass` and again as `display_softdbpass`. Treat a copy of one the way
> you would treat any credential: do not commit it, do not paste it into an
> issue, and scrub both fields before sharing a sample.

> **Never hand-edit this file.** PHP serialisation encodes a byte length in front
> of every string (`s:22:"/home/acme/example.com"`). Change a character
> without fixing the count and Softaculous silently refuses the backup. Always
> regenerate it with PHP's `serialize()`.
>
> Verify with:
> ```bash
> php -r '$d=unserialize(file_get_contents($argv[1])); var_dump($d===false ? "INVALID" : count($d));' <file>
> ```

*softpack: `src/Metadata.php`.*

---

## 4. The rule that actually bites: cPanel prefixes

There are **two different "prefixes"** in play and they are unrelated. Getting
either wrong is the usual cause of a restore that dies halfway.

### 4a. The cPanel account prefix — on the database name and user

cPanel physically cannot create a database or database user that is not named
`<cpaneluser>_something`. For an account called `acme`:

```
softdb     = acme_wp123
softdbuser = acme_wp123
```

A local name like `mysite_db` is **invalid on the server**. Rename it in the
metadata *and* in `wp-config.php`. Softaculous names its own installs
`<cpuser>_wp<random>`, but any valid suffix works — `acme_wpsite` is fine and
easier to recognise later.

The account prefix also fixes the home path shape. On many cPanel hosts each
domain gets a docroot directly under the home directory:

```
<home>/example.com               <- an existing install
<home>/newsite.example.com       <- the one being restored
```

Check how the target account is actually laid out before assuming it; some
hosts put everything under `public_html` instead.

### 4b. The WordPress table prefix — must agree in three places

```
metadata      dbprefix       = wp_
wp-config.php $table_prefix  = wp_
softsql.sql   CREATE TABLE `wp_...`
```

Softaculous randomises this at install time, so a pair produced by Softaculous
itself will show something like `wpxy_`. **You do not have to match that** — you
have to be internally consistent. Keeping the site's existing prefix is the
low-risk choice, because a prefix change also means rewriting the prefix-bound
rows:

```
wp_options  : wp_user_roles
wp_usermeta : wp_capabilities, wp_user_level, wp_dashboard_quick_press_last_post_id
```

Miss those and every user loses their role — the site loads but nobody is an
administrator. softpack warns and offers to keep the existing prefix rather
than attempting the rewrite.

*softpack: `src/Application.php` (`cmdInit()`), `src/Verifier.php`
(`prefixAgreement()`).*

---

## 5. softsql.sql conventions

Softaculous restores by piping this straight into the freshly created database,
so the dump must **not** try to pick its own:

| Must NOT contain | Why |
|---|---|
| `CREATE DATABASE` / `USE` | the target DB name is decided at restore time |
| `DROP TABLE` | the DB is new and empty |
| `LOCK TABLES` / `UNLOCK TABLES` | not used by the format |
| `AUTO_INCREMENT=<n>` on `CREATE TABLE` | stripped so counters start clean |

Other properties: **LF line endings**, `utf8mb4` / `utf8mb4_unicode_520_ci`,
`INSERT INTO \`t\` VALUES` with no column list, one row per line.

Header and footer, verbatim — only the four comment values vary per machine:

```sql
-- Softaculous SQL Dump
-- http://www.softaculous.com
--
-- Host: localhost
-- Generation Time: January 1, 2026, 9:29 am
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `acme_wpsite`
--
```

...then per table, separated by `-- ---...---` rules:

```sql
-- --------------------------------------------------------

--
-- Table structure for table `wp_terms`
--

CREATE TABLE `wp_terms` (
  ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Dumping data for table `wp_terms`
--

INSERT INTO `wp_terms` VALUES
(1, 'Uncategorized', 'uncategorized', 0),
(2, 'Menu', 'menu', 0);
```

...and finally:

```sql
-- --------------------------------------------------------

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
```

### MariaDB → MySQL

XAMPP ships MariaDB; cPanel usually runs MySQL 5.7. In practice this is fine for
a WordPress schema — InnoDB, `utf8mb4_unicode_520_ci` and `bigint(20)` all exist
on both. Version-gated comments MariaDB emits (`/*!100101 ... */`) are skipped by
MySQL 5.7 because `100101 > 50700`. Just don't let `mysqldump` add
`/*M!...*/` blocks — those are MariaDB-only syntax. Generating the dump yourself
avoids the question entirely.

*softpack: `src/Dumper.php`.*

---

## 6. The other artefacts

**`softver.txt`** — the WordPress version, no trailing newline: `7.1`

**`cgi-bin/`** — an empty directory. Present in genuine Softaculous pairs;
harmless and cheap to include.

**`softperms.txt`** — one line per path, `<relative path> <octal mode>`, with the
install root first:

```
/ 0750
wp-settings.php 0644
wp-includes 0755
wp-includes/interactivity-api 0755
...
```

Directories `0755`, files `0644`, root `0750`. It **includes** `softver.txt`,
`cgi-bin`, `.htaccess` and `wp-config.php`, but **excludes** `softsql.sql`,
`softperms.txt` and the metadata file — those are written after it is generated.

*softpack: `src/Payload.php` (`writePerms()`).*

---

## 7. Doing it by hand

> Normally you would just run softpack:
>
> ```bash
> softpack init     # detects the install, asks five questions about the server
> softpack build    # produces the pair and verifies it before calling it done
> ```
>
> The rest of this section is the manual equivalent, kept because it explains
> what the tool is doing and is what you need if you are debugging a pair
> without it.

### Step 1 — rewrite the URLs on a *copy* of the database

Elementor stores absolute URLs inside PHP-serialised data, where every string
carries its byte length. A plain SQL `REPLACE` corrupts it. Use WP-CLI, which
unserialises → replaces → reserialises.

Work on a clone so the local site keeps running:

```bash
mysql -u root -e "CREATE DATABASE mysite_export DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
mysqldump -u root --single-transaction mysite_db | mysql -u root mysite_export
```

Point WP-CLI at the clone without touching `wp-config.php`, using a `--require`
file that defines `DB_NAME` first (PHP keeps the first definition and only warns
about the second):

```php
<?php  // override-db.php
define( 'DB_NAME', 'mysite_export' );
```

```bash
WP="php wp-cli.phar --path=C:/xampp/htdocs/mysite --require=override-db.php --skip-plugins --skip-themes"

$WP search-replace 'http://localhost/mysite'     'https://newsite.example.com'     --all-tables --precise
$WP search-replace 'http:\/\/localhost\/mysite'  'https:\/\/newsite.example.com'   --all-tables --precise
```

**Both passes are required.** Elementor also stores JSON with escaped slashes
(`http:\/\/localhost\/mysite`), which the first pass does not match. On one real
Elementor site that split 205 plain / 40 escaped — skip the second pass and 40
image and link references stay pointed at localhost.

Then confirm nothing is left:

```bash
$WP search-replace 'localhost' 'XXX' --all-tables --precise --dry-run
```

*softpack does this in-process and never clones: `src/Replacer.php` streams the
rows, rewrites them in memory and writes them straight to the dump, so the live
database is never modified.*

### Step 2 — purge caches that embed the old URL

```sql
DELETE FROM wp_postmeta WHERE meta_key IN ('_elementor_element_cache','_elementor_css');
DELETE FROM wp_options  WHERE option_name IN ('_elementor_global_css','elementor_global_css');
DELETE FROM wp_options  WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%';
DELETE FROM wp_postmeta WHERE meta_key = '_edit_lock';
TRUNCATE TABLE wp_e_events;
```

Elementor regenerates all of it on first page load. Also delete
`wp-content/uploads/elementor/css/` from the file payload — those compiled CSS
files hardcode the old domain.

*softpack skips these rows while dumping rather than deleting anything:
`src/Dumper.php` (`PURGE`, `TRUNCATE`).*

### Step 3 — stage the files

Copy the site, leaving out anything that is not the site — build folders,
database dumps, source material:

```powershell
robocopy C:\xampp\htdocs\mysite .\payload /E /XD ...\_build ...\wp-content\uploads\elementor\css /XF .gitignore
```

`robocopy` exits **1** on success ("files were copied"). Only ≥ 8 is an error.

### Step 4 — production `wp-config.php` and `.htaccess`

Fresh salts, server DB credentials, and **no `WP_HOME` / `WP_SITEURL`
constants** — a local config often pins those to `http://localhost/...`, which
would override the database and send the live site back to localhost.

`.htaccess` needs `RewriteBase /` for a domain root (the local copy will say
`/mysite/`).

*softpack: `src/Builder.php` (`writeWpConfig()`, `writeHtaccess()`).*

### Step 5 — artefacts, permissions, inner metadata

```bash
printf '7.1' > payload/softver.txt
mkdir -p payload/cgi-bin
cp softsql.sql payload/
```

Then generate `softperms.txt` (section 6) and the **inner** metadata file —
33 keys, no `size`.

### Step 6 — tar, then gzip

Two separate steps — tar first, gzip second:

```powershell
cd payload
& "C:\Program Files\7-Zip\7z.exe" a -ttar ..\$NAME.tar *
cd ..
& "C:\Program Files\7-Zip\7z.exe" a -tgzip -mx=6 $NAME.tar.gz $NAME.tar
```

Running `7z a` from *inside* `payload` is what puts the entries at the tar root
with no wrapper directory. The `*` does pick up dotfiles, so `.htaccess` is
included — verify it.

Then verify the tar before shipping:

```powershell
& "C:\Program Files\7-Zip\7z.exe" l $NAME.tar | Select-String "Characteristics"
#   want: GNU LongName ASCII   (WordPress has paths over tar's 100-char limit)

tar -tf $NAME.tar | Where-Object { $_ -like '*\*' }
#   want: nothing. Backslashes in entry names break extraction on Linux.
```

### Step 7 — outer metadata, with `size`

Only now is the `.tar.gz` size known. Write the metadata again with the extra
`size` key set to the archive's byte count, then sanity-check that the two
copies differ by exactly one key:

```bash
php -r '$i=unserialize(file_get_contents("payload/'$NAME'"));
        $o=unserialize(file_get_contents("'$NAME'"));
        echo count($i)." vs ".count($o)."  extra: ".implode(",",array_diff(array_keys($o),array_keys($i)))."\n";'
#   want: 33 vs 34  extra: size
```

Ship `$NAME` and `$NAME.tar.gz`. Delete the intermediate `.tar`.

---

## 8. Restoring on the server

1. FTP/SFTP the two halves to **two different directories**, in binary mode,
   creating either if it is missing:

   ```
   wp.<insid>.<stamp>          ->  <home>/.softaculous/backups/
   wp.<insid>.<stamp>.tar.gz   ->  <home>/softaculous_backups/
   ```

   `.softaculous` is hidden; enable "show hidden files" in your FTP client
   first. Do not rename either file — the base names have to match.
2. cPanel → **Softaculous Apps Installer** → **Backups** (the box icon).
   The backup appears in the list, identified by its metadata file.
3. Click **Restore**. Softaculous creates `softdb` + `softdbuser` with
   `softdbpass`, imports `softsql.sql`, extracts the tar into `softpath`, and
   applies `softperms.txt`.
4. Point the domain/subdomain at `softpath` in cPanel if it does not exist yet.
   Softaculous will create the directory but not the DNS or the vhost.
5. Load the site, then **Settings → Permalinks → Save** once to rewrite
   `.htaccess` for the real server.

If the backup does not appear in the list, the metadata file is the suspect:
wrong name, invalid serialisation, or — most often — uploaded next to the
archive in `softaculous_backups/` instead of into `.softaculous/backups/`.

If it appears in the list but Restore fails immediately, it is the other way
round: the archive is missing from `softaculous_backups/`.

---

## 9. Checklist

- [ ] Both files present, identical base name, metadata has **no** extension
- [ ] Metadata uploaded to `.softaculous/backups/`, archive to `softaculous_backups/`
- [ ] Outer metadata has 34 keys including `size`; inner has 33
- [ ] `unserialize()` returns an array for both
- [ ] `softdb` / `softdbuser` start with `<cpuser>_`
- [ ] `dbprefix` == `$table_prefix` == the prefix in `softsql.sql`
- [ ] `softpath` and `softurl` point at the real target
- [ ] `wp-config.php` has server credentials and no localhost `WP_HOME`/`WP_SITEURL`
- [ ] `.htaccess` `RewriteBase` matches the target
- [ ] Dump has no `CREATE DATABASE` / `USE` / `DROP TABLE` / `LOCK TABLES`
- [ ] Dump imports cleanly into an empty DB locally (test it)
- [ ] No `localhost` left in the dump except the `-- Host:` comment
- [ ] Both plain and escaped-slash URL passes were run
- [ ] Tar entries at root, forward slashes, GNU LongName
- [ ] `.htaccess`, `cgi-bin/`, `softver.txt`, `softperms.txt`, `softsql.sql` all in the tar

`softpack build` runs all of these automatically — see `src/Verifier.php`.

---

## 10. Things that cost time the first round

- **7-Zip's listing shows backslashes** on Windows even when the tar stores
  forward slashes. Check with `tar -tf`, not `7z l`.
- **GNU tar treats `C:/...` as a remote host spec.** On Windows, prefer the
  bsdtar in `System32`, or pass `--force-local`.
- **Bash heredocs eat backslashes.** Writing PHP that contains `'\\'` through a
  `<<'EOF'` heredoc silently produced `'\'` and a parse error. Use `chr(92)`, or
  write the file with an editor.
- **`wp option get siteurl` can lie.** If `wp-config.php` defines `WP_SITEURL`,
  WP-CLI reports the constant, not the row. Read `wp_options` directly.
- **`robocopy` exit 1 is success.** PowerShell reports it as a failure.
- **The 100-character tar limit is real** — a stock Elementor install has ~600
  paths over it. Confirm `GNU LongName` in the tar header.
- **Importing a dump with `multi_query()` trips `max_allowed_packet`** (1 MB on
  stock XAMPP) because the whole file goes as one packet. Split into statements
  first, tracking quote state — serialised post content is full of semicolons.
- **`proc_open` with pipes for both stdout and stderr can deadlock.** If the
  child writes more to stderr than the pipe buffer holds while the parent is
  still draining stdout, both block forever. Use temp files.
