# file-cleaner

A Rust command-line tool for scanning a static/local website, finding unused or
broken media/library references, generating human- and machine-readable reports,
and safely quarantining approved files.

## Features

- Recursive scan of HTML, CSS, JS, and JSON source files for asset references.
- Mapping of absolute URLs, root-relative URLs, and relative URLs to local paths.
- UTF-8 percent-decoding support for encoded filenames.
- Detection of:
  - Unused files in media library roots.
  - Broken references from source files to missing media.
- JSON/text/CSV/HTML reports with timestamped filenames.
- Interactive approval workflow before any file operation.
- Safe deletion via quarantine (move to `quarantine/`), with a restore log.
- Dry-run mode by default.
- Optional PHP 8 web UI with configurator and JSON API for integration.

## Web UI

A PHP 8 web interface with a dashboard, visual configurator, report browser,
and JSON API is available in the `web/` directory.

👉 See [web/README.md](web/README.md) for the web version setup and API documentation.

## Installation

### Prebuilt binaries

Prebuilt binaries for macOS (Intel and Apple Silicon), Windows, and Linux are
available from the [GitHub Releases](https://github.com/Palmary/file-cleaner/releases)
page. Download the archive for your platform, extract it, and run the `file-cleaner`
binary.

### Build from source

Requires [Rust](https://rustup.rs/) 1.80+.

```bash
cargo build --release
```

The binary is at `target/release/file-cleaner`.

## Quick start

1. Copy `config.example.json` to `config.json` and adjust paths and URL mappings.
2. Run a dry-run scan:

```bash
./target/release/file-cleaner scan --config config.json --dry-run
```

Output formats: `text` (default), `json`, `csv`, `html`.

```bash
./target/release/file-cleaner scan --config config.json --dry-run --format html
```

3. Review the generated report in `reports/`.
4. Approve the report:

```bash
./target/release/file-cleaner approve reports/report-YYYYMMDD-HHMMSS.json
```

5. Apply the approved report (moves files to quarantine):

```bash
./target/release/file-cleaner apply reports/report-YYYYMMDD-HHMMSS.json
```

6. If needed, restore from the quarantine log:

```bash
./target/release/file-cleaner restore quarantine/session-XXXX/quarantine-log.json
```

## Feature specification

### 1. Source file scanning

`file-cleaner` recursively walks every path listed in `source_roots` and parses
the following file extensions as source files:

| Extension | Kind | Parsed as |
|-----------|------|-----------|
| `.html`, `.htm`, `.xhtml` | HTML | Tag attributes, inline CSS `style=...`, `<style>` blocks, CSS `url(...)` |
| `.css` | CSS | `url(...)` and `@import "..."` |
| `.js` | JavaScript | Single/double-quoted strings and backtick template literals |
| `.json` | JSON | String values that look like URLs/paths |

Binary files that share source extensions (e.g. a misnamed `.png` saved as
`.js`) are skipped automatically. Files that cannot be read as UTF-8 are also
skipped with a warning, so a single bad file does not abort the whole scan.

### 2. URL extraction

The scanner extracts URL-like strings from source files:

- **HTML attributes**: `src`, `href`, `data-src`, `poster`, `srcset`, `action`,
  `content`, `data-url`. `srcset` is split on commas and density descriptors are
  discarded.
- **CSS references**: `url("...")`, `url('...')`, `url(...)`, `@import "..."`.
- **JavaScript/JSON references**: string literals at least 3 characters long
  that contain `/`, start with `http://`, `https://`, `//`, `/`, or end with a
  known media extension.

Ignored schemes: `javascript:`, `mailto:`, `tel:`, `data:`, and `#` fragments.

### 3. URL-to-local-path resolution

Extracted URLs are resolved to absolute local filesystem paths using four
strategies, in order:

1. **Absolute URLs** (`http://`/`https://`/`//`) are matched against the
   `origins` map. Unmatched absolute URLs are treated as external and ignored.
2. **Root-relative URLs** (`/path/to/file`) are matched against `prefix_maps`.
   If no prefix matches, the URL is resolved under `default_web_root`, optionally
   prepended with `root_relative_base`.
3. **Relative URLs** (`../img/file.png`) are resolved against the directory of
   the source file that contained them.
4. **Percent-encoded names** are decoded before path resolution (e.g.
   `spaced%20file.jpg` → `spaced file.jpg`).

### 4. Unused and broken reference detection

After resolution, the tool compares referenced paths against the files found in
`media_roots`:

- **Unused files** — files that exist in `media_roots` but are not referenced by
  any source file. They are candidates for quarantine.
- **Broken references** — referenced paths that look like media assets but do not
  exist on disk. These are reported but never moved.

A path is reported as broken only when it has a media-like extension (images,
PDFs, videos, fonts, CSS, JS, JSON) to avoid flagging ordinary missing HTML
pages.

### 5. Disposable media roots

When `media_roots_as_disposable` is `true`, every file under `media_roots` is
flagged as unused (unless it matches `protected_patterns`). This is useful for
isolated folders such as `others/` or `js/` that are known to be temporary or
legacy content and should be fully cleaned up.

### 6. Protected patterns

Any path matching a glob in `protected_patterns` is excluded from the unused
list and will never be quarantined, even when `media_roots_as_disposable` is
enabled. Use this for logos, favicons, `.htaccess`, or legally required assets.

### 7. Approval and quarantine workflow

All destructive operations require explicit approval:

1. `scan` writes a JSON report but never moves files.
2. `approve` adds an approval token to the report after user confirmation.
3. `apply` reads the approved report, quarantines unused files, and writes a
   restore log. Quarantined files are renamed with a BLAKE3 hash prefix to avoid
   collisions.
4. `restore` reverses the operation using the quarantine log.

Each `apply` run creates a new `session-<timestamp>` directory under
`quarantine_dir`. A `quarantine-log.json` inside that directory records the
original and quarantined paths so files can be restored exactly to their former
locations.

### 8. Empty folder removal

When `remove_empty_folders_on_apply` is `true`, the apply step also removes
empty directories left behind after files are quarantined. It walks upward from
quarantined files, deleting empty parent folders, and finally removes any empty
`media_roots` themselves. Removed directories are recorded in the quarantine log
so the operation remains reversible by recreating parent paths during restore.

### 9. Reports

Four output formats are produced from a single scan:

| Format | CLI output | File written |
|--------|------------|--------------|
| `text` | Human-readable summary to stdout | JSON report |
| `json` | Pretty-printed JSON to stdout | JSON report |
| `csv`  | CSV rows to stdout | JSON + CSV reports |
| `html` | Rendered HTML to stdout | JSON + HTML reports |

All reports are timestamped (`report-YYYYMMDD-HHMMSS.<ext>`) and the JSON report
is required for `approve`/`apply`. The HTML report includes a **Configuration**
tab that displays the active source roots, media roots, origin/prefix mappings,
run mode, and the state of `media_roots_as_disposable` and
`remove_empty_folders_on_apply`.

### 10. Web UI (PHP 8)

A web interface is included in the `web/` directory. It provides a dashboard,
a visual configurator for `config.json`, and a JSON API for integration with
other systems.

See [web/README.md](web/README.md) for setup and API documentation.

## Configuration

See [config.example.json](config.example.json). All path fields must be absolute.

| Field | Description |
|-------|-------------|
| `source_roots` | Directories containing HTML/CSS/JS/JSON files to scan for references. |
| `media_roots` | Directories considered the media library (deletion candidates). |
| `origins` | Map absolute URLs like `https://example.com/` to local roots. |
| `prefix_maps` | Map root-relative URL prefixes like `/section-a/` to local roots. |
| `default_web_root` | Fallback root for root-relative URLs without a prefix map. |
| `root_relative_base` | Prefix added to root-relative URLs when resolving under `default_web_root` (e.g. `/`). |
| `exclude_patterns` | Glob patterns to skip while scanning. |
| `include_patterns` | Glob patterns that restrict scanning when non-empty. |
| `protected_patterns` | Paths that must never be deleted. |
| `report_dir` | Where scan reports are written. |
| `quarantine_dir` | Base directory for quarantined files. |
| `display_timezone` | IANA timezone used in HTML report timestamps (default `UTC`). |
| `display_language` | Language code used by the web UI (`en`, `zh`, `zhs`). |
| `media_roots_as_disposable` | Treat every file under `media_roots` as unused. |
| `remove_empty_folders_on_apply` | Remove empty directories after quarantining files. |

## Commands

- `scan --config FILE [--dry-run] [--format text|json|csv|html]` — scan and produce a report.
- `approve REPORT [--yes]` — interactively approve a report.
- `apply REPORT [--yes]` — quarantine files listed in an approved report.
- `restore LOG [--yes]` — restore files from a quarantine log.

## Important caveats

- JS asset extraction is best-effort: string literals and template literals are
  captured, but dynamically concatenated paths (e.g. `'img/' + id + '.jpg'`) may
  be missed.
- The tool treats absolute URLs not covered by `origins` as external and ignores
  them.
- Non-UTF-8 or binary files with text extensions are skipped with a warning.
- Always review reports before approving; use `--dry-run` first.
