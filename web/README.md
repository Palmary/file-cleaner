# File Cleaner Web UI

A PHP 8 web interface and JSON API for the Rust `file-cleaner` tool. It wraps
the Rust binary and adds a browser-based dashboard, a visual configuration
editor, report viewing, and programmatic endpoints.

## Features

- **Dashboard** to run scans, view the latest report summary, and trigger
  approve/apply actions.
- **Configurator** to create and edit `config.json` files through a form instead
  of editing raw JSON.
- **Reports browser** to list, view, and download generated reports in JSON,
  HTML, and CSV.
- **Quarantine browser** to view quarantine sessions, inspect logs, and restore
  files.
- **JSON API** for integration with CI/CD, cron jobs, dashboards, or external
  scripts.
- **CSRF protection** for all browser forms.
- **Optional basic authentication** and/or API-key authentication.
- **Multi-language UI** (`en`, `zh` Traditional Chinese, `zhs` Simplified
  Chinese).

## Requirements

- PHP 8.0 or newer
- Web server with PHP support (Apache/Nginx + php-fpm)
- The Rust `file-cleaner` binary built (release build recommended)
- Write permissions for the PHP process to `reports/`, `quarantine/`, and the
  directory where config files are saved

## Installation

1. Build the Rust binary from the project root:

```bash
cd ..
cargo build --release
```

2. Ensure the PHP process can execute `target/release/file-cleaner` and write
   to `reports/`, `quarantine/`, and the config file directory.

3. Copy the example local config to customize settings:

```bash
cp config.local.php.example config.local.php
```

4. (Optional) Enable basic auth or set an API key in `config.local.php`.

5. Serve the `web/` directory with your web server.

## Web UI configuration

Edit `web/config.php` or create `web/config.local.php` to override defaults.
`config.local.php` takes precedence over `config.php`.

| Key | Default | Description |
|-----|---------|-------------|
| `app_name` | `File Cleaner` | Title shown in the navigation bar and footer. |
| `binary_path` | `../target/release/file-cleaner` | Path to the Rust binary. |
| `default_config_path` | `web/config.json` | Default config file used by the dashboard scan form. |
| `default_report_format` | `html` | Default report format (`text`, `json`, `csv`, `html`). |
| `default_dry_run` | `true` | Whether scans default to dry-run mode. |
| `report_dir` | `../reports` | Directory where the Rust tool writes reports. |
| `quarantine_dir` | `../quarantine` | Directory where the Rust tool writes quarantine data. |
| `enable_configurator` | `true` | Allow creating/editing config files via the web. |
| `enable_api` | `true` | Enable `/api.php` JSON endpoints. |
| `api_key` | `''` | API key for external access. Empty string disables API-key checks. |
| `max_execution_time` | `600` | Maximum execution time (seconds) for a scan. |
| `memory_limit` | `512M` | PHP memory limit string. |
| `auth` | `null` | Optional basic auth `['username' => '...', 'password' => password_hash(...)]`. |
| `allowed_config_prefixes` | project root | Paths where config files may be saved/read. |
| `timezone` | `Asia/Hong_Kong` | Timezone for displayed dates. |
| `language` | `en` | UI language (`en`, `zh`, `zhs`). |
| `app_version` | `1.0` | Version shown in the footer. |

## Web pages

### `index.php` — Dashboard

The dashboard shows:

- A scan form pre-filled with `default_config_path`, `default_report_format`,
  and `default_dry_run`.
- A summary of the latest generated report (source files, media files,
  references, unused files, broken references).
- A list of recent reports with View/Download links.
- An **Approve & Apply** button that approves the latest report and immediately
  quarantines its unused files.

Scans are submitted to `run.php` via POST. Alerts are shown as dismissible
Bootstrap banners.

### `configurator.php` — Config editor

The configurator renders a form for every field in the Rust `Config` struct:

- Dynamic list inputs for `source_roots`, `media_roots`, `origins`,
  `prefix_maps`, `exclude_patterns`, `include_patterns`, and
  `protected_patterns`.
- Text inputs for `default_web_root`, `root_relative_base`, `report_dir`,
  `quarantine_dir`, `display_timezone`, and `display_language`.
- Checkboxes for the boolean flags:
  - **Treat media roots as disposable** (`media_roots_as_disposable`)
  - **Remove empty folders on apply** (`remove_empty_folders_on_apply`)
- Default scan settings inherited from the web UI config:
  - `scan_config_path`
  - `scan_report_format`
  - `scan_dry_run`

Changes are saved to the path shown in **Save to path**. That path must be
inside one of `allowed_config_prefixes`.

### `reports.php` — Report viewer

Lists all reports in `report_dir`. Each report row shows:

- Filename prefix and available formats (HTML/JSON/CSV).
- Total size and modification time.
- View/Download actions.

When viewing a report, its HTML version is embedded in an iframe if available;
otherwise the JSON content is rendered. The report page also has an
**Approve & Apply** button for approved action.

### `quarantine.php` — Quarantine manager

Lists quarantine sessions. Each session contains:

- Creation time and number of operations.
- A link to view its `quarantine-log.json`.
- A **Restore** button that moves files back to their original locations.

Restore recreates parent directories automatically using the paths recorded in
the quarantine log.

## API usage

The API returns JSON. If `api_key` is configured, pass it via the
`X-API-Key` header or `api_key` query/body parameter.

### Run a scan

```bash
curl -X POST https://your-server/web/api.php?action=scan \
  -H "X-API-Key: your-key" \
  -d "config_path=/path/to/config.json" \
  -d "format=html" \
  -d "dry_run=1"
```

Parameters:

| Parameter | Required | Description |
|-----------|----------|-------------|
| `config_path` | yes | Absolute path to a `config.json` file. |
| `format` | no | `text`, `json`, `csv`, or `html` (default: web UI default). |
| `dry_run` | no | `1` or `0` (default: web UI default). |

Response on success:

```json
{
  "success": true,
  "exit_code": 0,
  "output": "..."
}
```

### List reports

```bash
curl "https://your-server/web/api.php?action=reports" \
  -H "X-API-Key: your-key"
```

Response:

```json
{
  "success": true,
  "reports": [
    { "filename": "report-YYYYMMDD-HHMMSS.json", "types": ["html", "json"], "size": 12345, ... }
  ]
}
```

### Get a report

```bash
curl "https://your-server/web/api.php?action=report&file=report-20260710-123456.json" \
  -H "X-API-Key: your-key"
```

Response:

```json
{
  "success": true,
  "report": { "meta": {...}, "summary": {...}, "unused_files": [...], "broken_references": [...] }
}
```

### Approve a report

```bash
curl -X POST https://your-server/web/api.php?action=approve \
  -H "X-API-Key: your-key" \
  -d "report_path=/path/to/reports/report-20260710-123456.json"
```

### Apply an approved report

```bash
curl -X POST https://your-server/web/api.php?action=apply \
  -H "X-API-Key: your-key" \
  -d "report_path=/path/to/reports/report-20260710-123456.json" \
  -d "yes=1"
```

Apply moves unused files to `quarantine/session-<timestamp>/` and writes a
`quarantine-log.json` inside that directory.

### Restore a quarantine session

```bash
curl -X POST https://your-server/web/api.php?action=restore \
  -H "X-API-Key: your-key" \
  -d "log_path=/path/to/quarantine/session-XXXX/quarantine-log.json" \
  -d "yes=1"
```

### Delete a report

```bash
curl -X POST https://your-server/web/api.php?action=delete_report \
  -H "X-API-Key: your-key" \
  -d "file=report-20260710-123456.json"
```

## Feature flags exposed in the web UI

The configurator supports the same boolean flags as the Rust CLI config:

| Flag | Config key | Effect |
|------|------------|--------|
| Treat media roots as disposable | `media_roots_as_disposable` | Marks every file under `media_roots` as unused, so entire disposable folders (e.g. `others/`, `js/`) can be quarantined. |
| Remove empty folders on apply | `remove_empty_folders_on_apply` | After quarantining files, delete empty parent directories and any empty media roots. |

Both flags are shown in the **Configuration** tab of HTML reports.

## Security notes

- The web UI executes shell commands by invoking the Rust binary. Restrict
  access to trusted users only.
- Use HTTPS in production.
- Set a strong `api_key` if exposing the API.
- Keep `allowed_config_prefixes` narrow to prevent reading/writing config files
  outside the intended directories.
- Consider running the PHP worker under a dedicated user with limited
  filesystem permissions.
- Do not upload `config.local.php` to version control if it contains passwords
  or API keys.
