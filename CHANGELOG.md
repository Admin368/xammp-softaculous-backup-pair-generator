# Changelog

All notable changes to softpack are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning is [semantic](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://example.invalid/softpack/releases/tag/v1.0.0
