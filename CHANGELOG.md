# Changelog

All notable changes to softpack are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning is [semantic](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-09-10

### Fixed

- **A build could hang indefinitely.** External commands were given pipes for
  stdout and stderr, and the parent drained stdout before reading stderr. A
  child writing more to stderr than the pipe buffer holds blocks on that
  write while the parent is still blocked on the read — neither ever
  finishes. Seen as a build stopping dead at the tar step: 13 minutes, no CPU,
  a zero-byte archive.

  stdout and stderr now go to temporary files, which cannot fill. stdin is
  bound to the null device so an unexpected prompt fails immediately rather
  than blocking on an inherited handle.

- A failed build no longer strands its working directory — potentially a few
  hundred megabytes of tar — in the system temp folder. `Builder::run()`
  cleans up in a `finally`.

- The two upload paths in the closing message line up again.

## [1.0.1] - 2026-09-10

### Fixed

- **Upload instructions named one directory when there are two.** The
  metadata file belongs in `<home>/.softaculous/backups/` and only the
  archive goes to `<home>/softaculous_backups/`. Corrected in the README and
  in the message `build` prints when it finishes, which now names each half
  and its destination separately and points out that `.softaculous` is hidden
  from most FTP clients by default.

  Symptoms of getting it wrong, also now documented: the backup never appears
  in the Softaculous list (metadata in the wrong directory), or it appears but
  Restore fails immediately (archive in the wrong directory).

  No change to the `path` key in the metadata — it refers to where the archive
  lives, and `<home>/softaculous_backups` was already right.

## [1.0.0] - 2026-09-10

First working release. Derived by dissecting a genuine Softaculous backup pair
and reproducing the format from scratch.

### Added

- `init` — interactive project setup. Detects the WordPress version, database
  credentials, table prefix, site URL, site name and admin account from the
  install; asks only about the server side.
- `build` — produces the metadata + `.tar.gz` pair, then verifies it.
- `list`, `show`, `edit`, `rm` — project management.
- `verify` — check an existing pair, including ones softpack did not build.
- Projects saved under `SOFTPACK_HOME` (default `~/.softpack/projects`), mode
  `0600`, holding the target server settings. Rebuilding reuses the project's
  `insid` so Softaculous treats the archive as a newer backup of the same
  installation.
- Serialisation-aware URL rewriting, covering both plain and escaped-slash
  (`http:\/\/host\/path`) forms. Values are only transformed when
  `serialize(unserialize($v)) === $v`; anything else is left alone and
  reported rather than risking a corrupted row.
- Streaming SQL dump in Softaculous' own format, written straight from the
  live database without cloning or modifying it.
- Cache purging for Elementor render/CSS caches and core transients.
- Automatic exclusion offers for top-level folders that are not part of a
  stock WordPress install.
- Archive built directly from the WordPress root with `tar` — no file copying
  — with generated files supplied from staging in the same pass.
- Compression via 7-Zip when available, otherwise a built-in zlib gzip writer
  that stores the original filename in the header, so no external compressor
  is strictly required.
- 25 post-build checks covering the metadata pair, prefix agreement, dump
  shape, a real import into a scratch database, serialised-value integrity,
  and archive layout.
- `bin/softpack` and `bin/softpack.cmd` launchers; `SOFTPACK_PHP` selects the
  interpreter when `php` is not on PATH.

### Notes on two bugs found by the verifier during development

- Importing the dump with `multi_query()` sent the whole file as one packet and
  tripped `max_allowed_packet` (1 MB on stock XAMPP). Replaced with a
  quote-aware statement splitter that executes one statement at a time.
- The import test ran on the shared connection, which switched it away from the
  live database and left a scratch database behind when the import failed. It
  now uses its own connection and cleans up in a `finally`.

### Known limits

- Cache purging covers Elementor and core transients only.
- Changing the table prefix during export is not supported.
- Multisite is not handled.

[1.0.2]: https://example.invalid/softpack/releases/tag/v1.0.2
[1.0.1]: https://example.invalid/softpack/releases/tag/v1.0.1
[1.0.0]: https://example.invalid/softpack/releases/tag/v1.0.0
