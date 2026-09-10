# softpack

Build a **Softaculous/cPanel backup pair** from a local WordPress install, so a
site can be moved to a cPanel server by FTP upload + *Restore* instead of a
manual migration.

Run it inside a WordPress folder. It works out the install for itself, asks
once about the server, saves that as a project, and rebuilds on demand.

```
$ cd /c/xampp/htdocs/mysite
$ softpack init          # five questions, saved as a project
$ softpack build         # produces the pair, then proves it is sound
```

---

## What it produces

Two files that Softaculous accepts as one of its own backups:

```
wp.<insid>.<YYYY-MM-DD_HH-MM-SS>          PHP-serialised metadata, no extension
wp.<insid>.<YYYY-MM-DD_HH-MM-SS>.tar.gz   site files + database + artefacts
```

They share a base name but go to **two different directories** on the server:

```
wp.<insid>.<stamp>          ->  <home>/.softaculous/backups/
wp.<insid>.<stamp>.tar.gz   ->  <home>/softaculous_backups/
```

`.softaculous` is a dot-directory — turn on "show hidden files" in your FTP
client or you will not see it. Then cPanel → Softaculous → Backups → Restore.

If the backup never appears in the list, the metadata is in the wrong one of
those two directories. If it appears but Restore fails at once, the archive is.

---

## Install

Needs PHP 7.4+ with `mysqli`, and `tar` (Windows 10/11 and every Linux ship
it). 7-Zip or `gzip` are used for compression when present; otherwise softpack
compresses with its own zlib writer.

Clone or copy the repository somewhere permanent, then put `bin/` on PATH.

**Windows (PowerShell, current user):**

```powershell
$bin = "C:\tools\softpack\bin"   # wherever you cloned it
[Environment]::SetEnvironmentVariable(
    "Path",
    [Environment]::GetEnvironmentVariable("Path", "User") + ";$bin",
    "User")
```

If `php` is not on PATH — a normal XAMPP situation — point softpack at it:

```powershell
[Environment]::SetEnvironmentVariable("SOFTPACK_PHP", "C:\xampp\php\php.exe", "User")
```

Open a new terminal, then check:

```
softpack version
```

**Linux / macOS:**

```sh
sudo ln -s /opt/softpack/bin/softpack /usr/local/bin/softpack
chmod +x /opt/softpack/bin/softpack
```

---

## Commands

| Command | What it does |
|---|---|
| `softpack init` | Set up a project for the install in this folder |
| `softpack build [project]` | Build a pair; defaults to the project for this folder |
| `softpack list` | List saved projects |
| `softpack show [project]` | Print a project's settings |
| `softpack edit [project]` | Re-run setup with current values as defaults |
| `softpack rm <project>` | Delete a project |
| `softpack verify <pair>` | Check an existing pair — pass either half |
| `softpack version` | Print the version |

Options: `--root=<path>`, `--out=<path>`, `--keep-tar`, `--no-verify`,
`--non-interactive`, `--yes`.

---

## Projects

A project is one WordPress install plus the server it is destined for. They
live in `~/.softpack/projects/<name>.json`, outside the WordPress root, so the
install stays pristine and updating softpack never disturbs them. Override the
location with `SOFTPACK_HOME`.

Project files are written `0600` because they hold a database password.

Rebuilding **reuses the project's `insid`**, which is what makes Softaculous
treat a new archive as a newer backup *of the same installation* rather than a
different one. That is the point of keeping projects: change some content,
run `softpack build` again, upload, restore.

---

## What it detects, and what it asks

Read from the install, never asked:

- WordPress version, from `wp-includes/version.php`
- database name, user, password, host and table prefix, from `wp-config.php`
- the current site URL, from the `siteurl` **row** — not `wp option get`, which
  reports a `WP_SITEURL` constant if wp-config pins one
- site name, admin username and admin email, from the database
- top-level folders that are not part of a stock WordPress install, offered
  for exclusion one by one

Asked once, then saved:

1. the live URL
2. the cPanel username and account home path
3. the document root on the server
4. the database name, user and password (name and user are pre-filled with the
   `<cpuser>_` prefix cPanel requires, and softpack warns if you drop it)
5. the table prefix, defaulting to the one the install already uses

---

## How it works

**The database is never modified.** Rows are streamed out of the live install,
rewritten in memory and written to `softsql.sql`. No clone, no temporary
edits, no risk to the site you are still working on.

**URLs are rewritten through the serialisation.** WordPress — Elementor
especially — stores absolute URLs inside serialised arrays where every string
carries its byte length. softpack unpacks, rewrites and repacks, and covers
both the plain form and the escaped-slash form (`http:\/\/host\/path`) that
Elementor writes into its JSON. Missing the second form is the single most
common way a hand-run migration leaves images pointing at localhost.

A value is only transformed when `serialize(unserialize($v)) === $v`, i.e. the
round trip is provably lossless. Anything else is left untouched and reported.
A warning beats a corrupted `post_meta` row.

**Files are not copied.** The archive is built straight from the WordPress root
with `tar`, with the handful of generated files (`wp-config.php`, `.htaccess`,
`softsql.sql`, `softperms.txt`, `softver.txt`, `cgi-bin/`, the metadata)
supplied from a small staging folder in one pass.

**Caches that embed the old domain are dropped**, not rewritten:
`_elementor_element_cache`, `_elementor_css`, the global Elementor CSS options,
all transients, and `wp-content/uploads/elementor/css/`. Elementor regenerates
them on first page load.

---

## Verification

A build is not called a success until these pass. Every one corresponds to a
way a hand-built pair has actually failed:

```
metadata      inner unserialises, 33 keys
              outer unserialises, 34 keys
              outer adds only "size", and it matches the archive byte count
              every other key identical between the two
prefix        wp-config == metadata == the CREATE TABLE statements
dump          no CREATE DATABASE / USE / DROP TABLE / LOCK TABLES
              AUTO_INCREMENT counters stripped
              LF line endings
              no surviving references to the old host
              imports into a scratch database and reads back
              siteurl is the target URL
              sampled serialised values still unserialise
archive       forward slashes, no ./ prefix, no wrapper directory
              required entries present
              long paths stored with the GNU extension
              entry count matches the walked file list
              gzip stream reads back cleanly
```

`--no-verify` skips them. Don't.

---

## Limits

- **Cache purging knows Elementor and core transients.** WooCommerce, WP Rocket
  and LiteSpeed have their own caches; softpack does not know about them.
- **Changing the table prefix is not supported yet.** It would also mean
  rewriting `<prefix>capabilities`, `<prefix>user_level` and
  `<prefix>user_roles`; get that wrong and nobody is an administrator any more.
  softpack warns and offers to keep the existing prefix.
- **A single database row larger than `max_allowed_packet`** will fail the
  import check. That is a real problem, not a false alarm — the destination
  server will hit the same limit.
- Multisite is not handled.

---

## Licence

MIT.
