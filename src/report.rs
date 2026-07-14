use std::fs;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use anyhow::{Context, Result};
use chrono::{DateTime, FixedOffset, Utc};
use clap::ValueEnum;
use serde::{Deserialize, Serialize};

use crate::analyze::AnalysisResult;
use crate::config::{OriginMap, PrefixMap};

const BOOTSTRAP_CSS: &str = include_str!("../assets/vendor/bootstrap.min.css");
const DATATABLES_CSS: &str = include_str!("../assets/vendor/dataTables.bootstrap5.min.css");
const JQUERY_JS: &str = include_str!("../assets/vendor/jquery.min.js");
const BOOTSTRAP_JS: &str = include_str!("../assets/vendor/bootstrap.bundle.min.js");
const DATATABLES_JS: &str = include_str!("../assets/vendor/jquery.dataTables.min.js");
const DATATABLES_BOOTSTRAP_JS: &str = include_str!("../assets/vendor/dataTables.bootstrap5.min.js");

#[derive(Debug, Clone, Copy, PartialEq, Eq, ValueEnum)]
pub enum ReportFormat {
    /// Human-readable text summary to stdout; JSON report file still written.
    Text,
    /// JSON output to stdout and JSON report file.
    Json,
    /// CSV output to stdout; JSON report file still written for approval/apply.
    Csv,
    /// HTML output to stdout; JSON report file still written for approval/apply.
    Html,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Report {
    pub meta: ReportMeta,
    pub summary: ReportSummary,
    pub unused_files: Vec<ReportEntry>,
    pub broken_references: Vec<ReportEntry>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReportMeta {
    pub generated_at: DateTime<Utc>,
    pub tool_version: String,
    pub dry_run: bool,
    pub source_roots: Vec<PathBuf>,
    pub media_roots: Vec<PathBuf>,
    #[serde(default)]
    pub origins: Vec<OriginMap>,
    #[serde(default)]
    pub prefix_maps: Vec<PrefixMap>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub default_web_root: Option<PathBuf>,
    #[serde(default)]
    pub exclude_patterns: Vec<String>,
    #[serde(default)]
    pub include_patterns: Vec<String>,
    #[serde(default)]
    pub protected_patterns: Vec<String>,
    #[serde(default = "crate::config::default_quarantine_dir")]
    pub quarantine_dir: PathBuf,
    #[serde(default = "crate::config::default_display_timezone")]
    pub display_timezone: String,
    #[serde(default)]
    pub media_roots_as_disposable: bool,
    #[serde(default)]
    pub remove_empty_folders_on_apply: bool,
    pub approved: bool,
    pub approval_token: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReportSummary {
    pub source_files_scanned: usize,
    pub media_files_scanned: usize,
    pub referenced_paths_found: usize,
    pub unused_files_count: usize,
    pub broken_references_count: usize,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ReportEntry {
    pub path: PathBuf,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub confidence: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub reason: Option<String>,
}

impl Report {
    pub fn from_analysis(
        analysis: AnalysisResult,
        config: Arc<crate::config::Config>,
        dry_run: bool,
        source_files_scanned: usize,
    ) -> Self {
        let unused_files: Vec<_> = analysis
            .unused
            .iter()
            .map(|p| ReportEntry {
                path: p.clone(),
                confidence: Some("high".to_string()),
                reason: Some("No source file references this media path".to_string()),
            })
            .collect();

        let broken_references: Vec<_> = analysis
            .broken
            .iter()
            .map(|b| ReportEntry {
                path: b.path.clone(),
                confidence: Some("high".to_string()),
                reason: Some("Referenced by source but missing on disk".to_string()),
            })
            .collect();

        Report {
            meta: ReportMeta {
                generated_at: Utc::now(),
                tool_version: env!("CARGO_PKG_VERSION").to_string(),
                dry_run,
                source_roots: config.source_roots.clone(),
                media_roots: config.media_roots.clone(),
                origins: config.origins.clone(),
                prefix_maps: config.prefix_maps.clone(),
                default_web_root: config.default_web_root.clone(),
                exclude_patterns: config.exclude_patterns.clone(),
                include_patterns: config.include_patterns.clone(),
                protected_patterns: config.protected_patterns.clone(),
                quarantine_dir: config.quarantine_dir.clone(),
                display_timezone: config.display_timezone.clone(),
                media_roots_as_disposable: config.media_roots_as_disposable,
                remove_empty_folders_on_apply: config.remove_empty_folders_on_apply,
                approved: false,
                approval_token: None,
            },
            summary: ReportSummary {
                source_files_scanned,
                media_files_scanned: analysis.media_count,
                referenced_paths_found: analysis.referenced_count,
                unused_files_count: unused_files.len(),
                broken_references_count: broken_references.len(),
            },
            unused_files,
            broken_references,
        }
    }

    pub fn approve(&mut self) -> String {
        let token = format!("approve-{}", self.meta.generated_at.timestamp_millis());
        self.meta.approved = true;
        self.meta.approval_token = Some(token.clone());
        token
    }

    pub fn is_approved(&self) -> bool {
        self.meta.approved && self.meta.approval_token.is_some()
    }
}

pub fn write_report(report: &Report, report_dir: &Path) -> Result<PathBuf> {
    fs::create_dir_all(report_dir)
        .with_context(|| format!("creating report directory: {}", report_dir.display()))?;

    let filename = format!(
        "report-{}.json",
        report.meta.generated_at.format("%Y%m%d-%H%M%S")
    );
    let path = report_dir.join(filename);
    let json = serde_json::to_string_pretty(report)?;
    fs::write(&path, json).with_context(|| format!("writing report: {}", path.display()))?;

    eprintln!("Report written to: {}", path.display());
    Ok(path)
}

pub fn write_formatted_output(report: &Report, format: ReportFormat, report_dir: &Path) -> Result<()> {
    match format {
        ReportFormat::Text => print_summary(report),
        ReportFormat::Json => {
            println!("{}", serde_json::to_string_pretty(report)?);
            Ok(())
        }
        ReportFormat::Csv => {
            let path = write_csv_file(report, report_dir)?;
            eprintln!("CSV report written to: {}", path.display());
            print_csv(report)
        }
        ReportFormat::Html => {
            let path = write_html_file(report, report_dir)?;
            eprintln!("HTML report written to: {}", path.display());
            print_html(report)
        }
    }
}

fn formatted_report_path(report: &Report, report_dir: &Path, ext: &str) -> PathBuf {
    let filename = format!(
        "report-{}.{}",
        report.meta.generated_at.format("%Y%m%d-%H%M%S"),
        ext
    );
    report_dir.join(filename)
}

fn write_csv_file(report: &Report, report_dir: &Path) -> Result<PathBuf> {
    fs::create_dir_all(report_dir)?;
    let path = formatted_report_path(report, report_dir, "csv");
    let mut lines: Vec<String> = Vec::new();
    lines.push("type,path,confidence,reason".to_string());
    for entry in &report.unused_files {
        lines.push(format!(
            "unused,{},{},\"{}\"",
            escape_csv(&entry.path.to_string_lossy()),
            entry.confidence.as_deref().unwrap_or(""),
            entry.reason.as_deref().map(escape_csv).unwrap_or_default()
        ));
    }
    for entry in &report.broken_references {
        lines.push(format!(
            "broken,{},{},\"{}\"",
            escape_csv(&entry.path.to_string_lossy()),
            entry.confidence.as_deref().unwrap_or(""),
            entry.reason.as_deref().map(escape_csv).unwrap_or_default()
        ));
    }
    fs::write(&path, lines.join("\n"))
        .with_context(|| format!("writing CSV report: {}", path.display()))?;
    Ok(path)
}

fn write_html_file(report: &Report, report_dir: &Path) -> Result<PathBuf> {
    fs::create_dir_all(report_dir)?;
    let path = formatted_report_path(report, report_dir, "html");
    fs::write(&path, render_html_report(report))
        .with_context(|| format!("writing HTML report: {}", path.display()))?;
    Ok(path)
}

fn render_html_report(report: &Report) -> String {
    let generated = format_generated_time(&report.meta.generated_at, &report.meta.display_timezone);
    let run_mode = if report.meta.dry_run {
        r#"<span class="badge bg-warning text-dark">Dry run</span>"#.to_string()
    } else {
        r#"<span class="badge bg-success">Live run</span>"#.to_string()
    };
    let approved = if report.meta.approved {
        r#"<span class="badge bg-info">Approved</span>"#.to_string()
    } else {
        r#"<span class="badge bg-secondary">Pending approval</span>"#.to_string()
    };

    let source_roots = list_items(&report.meta.source_roots);
    let media_roots = list_items(&report.meta.media_roots);
    let media_roots_disposable = if report.meta.media_roots_as_disposable {
        r#"<span class="badge bg-danger">Enabled</span>"#.to_string()
    } else {
        r#"<span class="badge bg-secondary">Disabled</span>"#.to_string()
    };

    let remove_empty_folders = if report.meta.remove_empty_folders_on_apply {
        r#"<span class="badge bg-danger">Enabled</span>"#.to_string()
    } else {
        r#"<span class="badge bg-secondary">Disabled</span>"#.to_string()
    };

    let origins_rows: String = report
        .meta
        .origins
        .iter()
        .map(|o| {
            format!(
                "<tr><td class=\"text-break\">{}</td><td class=\"text-break\">{}</td></tr>",
                html_escape(&o.origin),
                html_escape(&o.local_root.to_string_lossy())
            )
        })
        .collect();
    let prefix_rows: String = report
        .meta
        .prefix_maps
        .iter()
        .map(|p| {
            format!(
                "<tr><td class=\"text-break\">{}</td><td class=\"text-break\">{}</td></tr>",
                html_escape(&p.prefix),
                html_escape(&p.local_root.to_string_lossy())
            )
        })
        .collect();

    let default_web_root = report
        .meta
        .default_web_root
        .as_ref()
        .map(|p| html_escape(&p.to_string_lossy()))
        .unwrap_or_else(|| "<em>Not configured</em>".to_string());

    let pattern_list = |patterns: &[String]| {
        if patterns.is_empty() {
            "<li class=\"list-group-item text-muted\">None</li>".to_string()
        } else {
            patterns
                .iter()
                .map(|p| format!(
                    "<li class=\"list-group-item\"><code>{}</code></li>",
                    html_escape(p)
                ))
                .collect()
        }
    };

    let unused_rows: String = report
        .unused_files
        .iter()
        .enumerate()
        .map(|(i, e)| entry_row(i + 1, e))
        .collect();
    let broken_rows: String = report
        .broken_references
        .iter()
        .enumerate()
        .map(|(i, e)| entry_row(i + 1, e))
        .collect();

    const HTML_TEMPLATE: &str = r##"<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>File Cleaner Report</title>
<style>
__BOOTSTRAP_CSS__
__DATATABLES_CSS__
</style>
<style>
body { background: #f8f9fa; }
.navbar { background: linear-gradient(90deg, #0d6efd, #6610f2); }
.card-summary { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.08); }
.card-summary .card-body { text-align: center; }
.card-summary .display-6 { font-weight: 700; }
.section-title { margin-top: 2rem; margin-bottom: 1rem; }
.table-responsive { background: #fff; border-radius: .75rem; padding: 1rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.08); }
.table-path { width: 60%; word-break: break-all; }
.table-confidence { width: 10%; white-space: nowrap; }
.table-reason { width: 25%; }
footer { margin-top: 3rem; padding: 1.5rem 0; color: #6c757d; text-align: center; }
</style>
</head>
<body>
<nav class="navbar navbar-dark mb-4">
<div class="container-fluid">
<a class="navbar-brand fw-bold d-flex align-items-center" href="#">
<svg class="me-2" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="fill:#fff">
<path d="M6 3a3 3 0 0 0-3 3v18a4 4 0 0 0 4 4h16a3 3 0 0 0 3-3V9.5a1 1 0 0 0-.293-.707l-6.5-6.5A1 1 0 0 0 18.5 2H9a3 3 0 0 0-3 1Zm3 0h9.5l6.5 6.5V23a2 2 0 0 1-2 2H9a3 3 0 0 1-3-3V6a2 2 0 0 1 2-2Z" opacity=".75"/>
<path d="M11 12h10v2H11zm0 4h8v2h-8zm0 4h10v2H11z"/>
<circle cx="23.5" cy="20.5" r="5.5" fill="#fff"/>
<path d="m21.5 20.5 1.5-1.5 1.5 1.5-1.5 1.5-1.5-1.5z" fill="#0d6efd"/>
</svg>
File Cleaner
</a>
<div class="d-flex gap-2">__RUN_MODE__ __APPROVED__</div>
</div>
</nav>

<div class="container-fluid px-4">
<div class="row g-3 mb-4">
<div class="col-12">
<h1 class="h3 mb-0">Scan Report</h1>
<p class="text-muted mb-0">Generated at __GENERATED__ · Tool version __VERSION__</p>
</div>
</div>

<div class="row g-3 mb-4">
<div class="col-6 col-md-4 col-lg">
<div class="card card-summary h-100">
<div class="card-body">
<div class="display-6 text-primary">__SRC_FILES__</div>
<div class="small text-muted text-uppercase">Source files</div>
</div>
</div>
</div>
<div class="col-6 col-md-4 col-lg">
<div class="card card-summary h-100">
<div class="card-body">
<div class="display-6 text-info">__MEDIA_FILES__</div>
<div class="small text-muted text-uppercase">Media files</div>
</div>
</div>
</div>
<div class="col-6 col-md-4 col-lg">
<div class="card card-summary h-100">
<div class="card-body">
<div class="display-6 text-secondary">__REFERENCED__</div>
<div class="small text-muted text-uppercase">Referenced paths</div>
</div>
</div>
</div>
<div class="col-6 col-md-4 col-lg">
<div class="card card-summary h-100">
<div class="card-body">
<div class="display-6 text-danger">__UNUSED_COUNT__</div>
<div class="small text-muted text-uppercase">Unused files</div>
</div>
</div>
</div>
<div class="col-6 col-md-4 col-lg">
<div class="card card-summary h-100">
<div class="card-body">
<div class="display-6 text-warning">__BROKEN_COUNT__</div>
<div class="small text-muted text-uppercase">Broken references</div>
</div>
</div>
</div>
</div>

<ul class="nav nav-tabs" id="reportTabs" role="tablist">
<li class="nav-item" role="presentation">
<button class="nav-link active" id="unused-tab" data-bs-toggle="tab" data-bs-target="#unused" type="button" role="tab">Unused files <span class="badge bg-danger">__UNUSED_COUNT__</span></button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="broken-tab" data-bs-toggle="tab" data-bs-target="#broken" type="button" role="tab">Broken references <span class="badge bg-warning text-dark">__BROKEN_COUNT__</span></button>
</li>
<li class="nav-item" role="presentation">
<button class="nav-link" id="config-tab" data-bs-toggle="tab" data-bs-target="#config" type="button" role="tab">Configuration</button>
</li>
</ul>

<div class="tab-content" id="reportTabsContent">
<div class="tab-pane fade show active" id="unused" role="tabpanel">
<h2 class="h4 section-title">Unused media files</h2>
<div class="table-responsive">
<table id="unusedTable" class="table table-striped table-hover" style="width:100%">
<thead>
<tr><th>#</th><th>Path</th><th>Confidence</th><th>Reason</th></tr>
</thead>
<tbody>
__UNUSED_ROWS__
</tbody>
</table>
</div>
</div>

<div class="tab-pane fade" id="broken" role="tabpanel">
<h2 class="h4 section-title">Broken references</h2>
<div class="table-responsive">
<table id="brokenTable" class="table table-striped table-hover" style="width:100%">
<thead>
<tr><th>#</th><th>Path</th><th>Confidence</th><th>Reason</th></tr>
</thead>
<tbody>
__BROKEN_ROWS__
</tbody>
</table>
</div>
</div>

<div class="tab-pane fade" id="config" role="tabpanel">
<h2 class="h4 section-title">Scan configuration</h2>
<div class="row g-4">
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Source roots</div>
<ul class="list-group list-group-flush">__SOURCE_ROOTS__</ul>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Media roots</div>
<ul class="list-group list-group-flush">__MEDIA_ROOTS__</ul>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Treat media roots as disposable</div>
<div class="card-body">__MEDIA_ROOTS_DISPOSABLE__</div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Remove empty folders on apply</div>
<div class="card-body">__REMOVE_EMPTY_FOLDERS__</div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Origin mappings</div>
<div class="card-body p-0">
<table class="table table-sm mb-0">
<thead><tr><th>Origin</th><th>Local root</th></tr></thead>
<tbody>__ORIGINS_ROWS__</tbody>
</table>
</div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Prefix mappings</div>
<div class="card-body p-0">
<table class="table table-sm mb-0">
<thead><tr><th>URL prefix</th><th>Local root</th></tr></thead>
<tbody>__PREFIX_ROWS__</tbody>
</table>
</div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Default web root</div>
<div class="card-body"><code class="text-break">__DEFAULT_WEB_ROOT__</code></div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Quarantine directory</div>
<div class="card-body"><code class="text-break">__QUARANTINE_DIR__</code></div>
</div>
</div>
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-bold">Patterns</div>
<div class="card-body">
<h6 class="card-subtitle mb-2 text-muted">Exclude</h6>
<ul class="list-group list-group-flush mb-3">__EXCLUDE_PATTERNS__</ul>
<h6 class="card-subtitle mb-2 text-muted">Include</h6>
<ul class="list-group list-group-flush mb-3">__INCLUDE_PATTERNS__</ul>
<h6 class="card-subtitle mb-2 text-muted">Protected</h6>
<ul class="list-group list-group-flush">__PROTECTED_PATTERNS__</ul>
</div>
</div>
</div>
</div>
</div>
</div>

<footer>
<small>Generated by File Cleaner v__VERSION__ · __RUN_MODE__ · __APPROVED__</small>
</footer>
</div>

<script>
__JQUERY_JS__
</script>
<script>
__BOOTSTRAP_JS__
</script>
<script>
__DATATABLES_JS__
</script>
<script>
__DATATABLES_BOOTSTRAP_JS__
</script>
<script>
$(document).ready(function() {
    $('#unusedTable').DataTable({ pageLength: 25, order: [[0, 'asc']], language: { search: 'Filter:' } });
    $('#brokenTable').DataTable({ pageLength: 25, order: [[0, 'asc']], language: { search: 'Filter:' } });
});
</script>
</body>
</html>"##;

    let origins_rows = if origins_rows.is_empty() {
        r#"<tr><td colspan="2" class="text-muted">None</td></tr>"#.to_string()
    } else {
        origins_rows
    };
    let prefix_rows = if prefix_rows.is_empty() {
        r#"<tr><td colspan="2" class="text-muted">None</td></tr>"#.to_string()
    } else {
        prefix_rows
    };
    let unused_rows = if unused_rows.is_empty() {
        r#"<tr><td></td><td class="text-muted">No unused files found.</td><td></td><td></td></tr>"#.to_string()
    } else {
        unused_rows
    };
    let broken_rows = if broken_rows.is_empty() {
        r#"<tr><td></td><td class="text-muted">No broken references found.</td><td></td><td></td></tr>"#.to_string()
    } else {
        broken_rows
    };

    HTML_TEMPLATE
        .replace("__BOOTSTRAP_CSS__", BOOTSTRAP_CSS)
        .replace("__DATATABLES_CSS__", DATATABLES_CSS)
        .replace("__JQUERY_JS__", JQUERY_JS)
        .replace("__BOOTSTRAP_JS__", BOOTSTRAP_JS)
        .replace("__DATATABLES_JS__", DATATABLES_JS)
        .replace("__DATATABLES_BOOTSTRAP_JS__", DATATABLES_BOOTSTRAP_JS)
        .replace("__RUN_MODE__", &run_mode)
        .replace("__APPROVED__", &approved)
        .replace("__GENERATED__", &generated)
        .replace("__VERSION__", &html_escape(&report.meta.tool_version))
        .replace("__SRC_FILES__", &report.summary.source_files_scanned.to_string())
        .replace("__MEDIA_FILES__", &report.summary.media_files_scanned.to_string())
        .replace("__REFERENCED__", &report.summary.referenced_paths_found.to_string())
        .replace("__UNUSED_COUNT__", &report.summary.unused_files_count.to_string())
        .replace("__BROKEN_COUNT__", &report.summary.broken_references_count.to_string())
        .replace("__SOURCE_ROOTS__", &source_roots)
        .replace("__MEDIA_ROOTS__", &media_roots)
        .replace("__MEDIA_ROOTS_DISPOSABLE__", &media_roots_disposable)
        .replace("__REMOVE_EMPTY_FOLDERS__", &remove_empty_folders)
        .replace("__ORIGINS_ROWS__", &origins_rows)
        .replace("__PREFIX_ROWS__", &prefix_rows)
        .replace("__DEFAULT_WEB_ROOT__", &default_web_root)
        .replace("__QUARANTINE_DIR__", &html_escape(&report.meta.quarantine_dir.to_string_lossy()))
        .replace("__EXCLUDE_PATTERNS__", &pattern_list(&report.meta.exclude_patterns))
        .replace("__INCLUDE_PATTERNS__", &pattern_list(&report.meta.include_patterns))
        .replace("__PROTECTED_PATTERNS__", &pattern_list(&report.meta.protected_patterns))
        .replace("__UNUSED_ROWS__", &unused_rows)
        .replace("__BROKEN_ROWS__", &broken_rows)
}

fn print_csv(report: &Report) -> Result<()> {
    println!("type,path,confidence,reason");
    for entry in &report.unused_files {
        println!(
            "unused,{},{},\"{}\"",
            escape_csv(&entry.path.to_string_lossy()),
            entry.confidence.as_deref().unwrap_or(""),
            entry.reason.as_deref().map(escape_csv).unwrap_or_default()
        );
    }
    for entry in &report.broken_references {
        println!(
            "broken,{},{},\"{}\"",
            escape_csv(&entry.path.to_string_lossy()),
            entry.confidence.as_deref().unwrap_or(""),
            entry.reason.as_deref().map(escape_csv).unwrap_or_default()
        );
    }
    Ok(())
}

fn escape_csv(value: &str) -> String {
    if value.contains('"') || value.contains(',') || value.contains('\n') {
        format!("\"{}\"", value.replace('"', "\"\""))
    } else {
        value.to_string()
    }
}

fn print_html(report: &Report) -> Result<()> {
    println!("{}", render_html_report(report));
    Ok(())
}

fn list_items(paths: &[PathBuf]) -> String {
    if paths.is_empty() {
        r#"<li class="list-group-item text-muted">None</li>"#.to_string()
    } else {
        paths
            .iter()
            .map(|p| {
                format!(
                    r#"<li class="list-group-item text-break">{}</li>"#,
                    html_escape(&p.to_string_lossy())
                )
            })
            .collect()
    }
}

fn format_generated_time(dt: &DateTime<Utc>, tz_name: &str) -> String {
    // Try to parse the configured timezone and convert; fall back to RFC3339 on error.
    if let Ok(tz) = tz_name.parse::<chrono_tz::Tz>() {
        return dt.with_timezone(&tz).format("%Y-%m-%d %H:%M:%S %Z").to_string();
    }
    // For fixed-offset strings like "+08:00".
    if let Ok(offset) = tz_name.parse::<FixedOffset>() {
        return dt.with_timezone(&offset).format("%Y-%m-%d %H:%M:%S %Z").to_string();
    }
    dt.to_rfc3339()
}

fn confidence_badge(confidence: &str) -> String {
    let cls = match confidence.to_ascii_lowercase().as_str() {
        "high" => "bg-danger",
        "medium" => "bg-warning text-dark",
        "low" => "bg-info text-dark",
        _ => "bg-secondary",
    };
    format!(
        r#"<span class="badge {cls}">{}</span>"#,
        html_escape(confidence)
    )
}

fn entry_row(index: usize, entry: &ReportEntry) -> String {
    let confidence = entry.confidence.as_deref().unwrap_or("high");
    format!(
        "<tr><td>{index}</td><td class=\"table-path\">{}</td><td class=\"table-confidence\">{}</td><td class=\"table-reason\">{}</td></tr>",
        html_escape(&entry.path.to_string_lossy()),
        confidence_badge(confidence),
        html_escape(entry.reason.as_deref().unwrap_or("-"))
    )
}

fn html_escape(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

pub fn load_report(path: &Path) -> Result<Report> {
    let content = fs::read_to_string(path)
        .with_context(|| format!("reading report: {}", path.display()))?;
    let report: Report = serde_json::from_str(&content)
        .with_context(|| format!("parsing report: {}", path.display()))?;
    Ok(report)
}

pub fn print_summary(report: &Report) -> Result<()> {
    // Write the text summary to stderr so it is captured together with other
    // diagnostic output. The Rust scan runner emits progress lines on stderr,
    // and the PHP web UI captures the combined stdout/stderr stream.
    eprintln!("Scan report summary");
    eprintln!("===================");
    eprintln!("Generated:          {}", report.meta.generated_at.to_rfc3339());
    eprintln!("Dry run:            {}", report.meta.dry_run);
    eprintln!("Source files:       {}", report.summary.source_files_scanned);
    eprintln!("Media files:        {}", report.summary.media_files_scanned);
    eprintln!("Referenced paths:   {}", report.summary.referenced_paths_found);
    eprintln!("Unused files:       {}", report.summary.unused_files_count);
    eprintln!("Broken references:  {}", report.summary.broken_references_count);

    if !report.unused_files.is_empty() {
        eprintln!("\nUnused files (sample up to 10):");
        for entry in report.unused_files.iter().take(10) {
            eprintln!("  - {}", entry.path.display());
        }
    }
    if !report.broken_references.is_empty() {
        eprintln!("\nBroken references (sample up to 10):");
        for entry in report.broken_references.iter().take(10) {
            eprintln!("  - {}", entry.path.display());
        }
    }
    Ok(())
}

pub struct ReportOptions {
    pub report_path: PathBuf,
}
