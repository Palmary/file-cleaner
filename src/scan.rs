use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use anyhow::{Context, Result};
use rayon::prelude::*;
use walkdir::WalkDir;

use crate::analyze;
use crate::config::{Config, SourceKind};
use crate::decompress::read_text_with_decompression;
use crate::extract;
use crate::normalize::UrlResolver;
use crate::report::{Report, ReportFormat};

pub struct ScanOptions {
    pub config: Config,
    pub dry_run: bool,
    pub format: ReportFormat,
}

pub fn run(options: ScanOptions) -> Result<Report> {
    let config = Arc::new(options.config);

    let source_files = collect_files(&config.source_roots, &config)?;
    let media_files = collect_files(&config.media_roots, &config)?;

    eprintln!("Source files: {}", source_files.len());
    eprintln!("Media files:  {}", media_files.len());

    let resolver = UrlResolver::new(&config)?;
    let references = extract_all_references(&source_files, &resolver)?;

    let analysis = analyze::analyze(references, media_files.clone(), &config)?;
    let report = Report::from_analysis(
        analysis,
        config.clone(),
        options.dry_run,
        source_files.len(),
    );

    crate::report::write_report(&report, &config.report_dir)?;
    crate::report::write_formatted_output(&report, options.format, &config.report_dir)?;

    Ok(report)
}

/// Collect all files under the given roots, applying include/exclude globs.
fn collect_files(roots: &[PathBuf], config: &Config) -> Result<Vec<PathBuf>> {
    let mut files = Vec::new();
    for root in roots {
        for entry in WalkDir::new(root)
            .follow_links(false)
            .into_iter()
            .filter_entry(|e| !is_excluded(e.path(), &config.exclude_patterns))
        {
            let entry = entry.with_context(|| format!("walking directory: {}", root.display()))?;
            if entry.file_type().is_file() {
                let path = entry.into_path();
                if is_included(&path, &config.include_patterns) {
                    files.push(path);
                }
            }
        }
    }
    Ok(files)
}

fn is_excluded(path: &Path, patterns: &[String]) -> bool {
    if patterns.is_empty() {
        return false;
    }
    let path_str = path.to_string_lossy();
    patterns.iter().any(|p| {
        glob::Pattern::new(p)
            .map(|pat| pat.matches(&path_str))
            .unwrap_or(false)
    })
}

fn is_included(path: &Path, patterns: &[String]) -> bool {
    if patterns.is_empty() {
        return true;
    }
    let path_str = path.to_string_lossy();
    patterns.iter().any(|p| {
        glob::Pattern::new(p)
            .map(|pat| pat.matches(&path_str))
            .unwrap_or(false)
    })
}

fn extract_all_references(
    source_files: &[PathBuf],
    resolver: &UrlResolver,
) -> Result<HashSet<PathBuf>> {
    let results: Vec<_> = source_files
        .par_iter()
        .map(|path| extract_from_file(path, resolver))
        .collect::<Result<Vec<_>>>()?;

    let mut combined = HashSet::new();
    for set in results {
        combined.extend(set);
    }
    Ok(combined)
}

fn extract_from_file(path: &Path, resolver: &UrlResolver) -> Result<HashSet<PathBuf>> {
    // Skip binary files that happen to share source extensions (e.g. .png).
    if is_likely_binary(path) {
        return Ok(HashSet::new());
    }

    let content = match read_text_with_decompression(path) {
        Ok(c) => c,
        Err(e) => {
            // Log and ignore non-UTF-8 or unreadable files instead of failing the whole scan.
            eprintln!("Warning: skipping {}: {}", path.display(), e);
            return Ok(HashSet::new());
        }
    };
    let ext = path
        .extension()
        .and_then(|e| e.to_str())
        .unwrap_or("");
    let kind = SourceKind::from_extension(ext);

    let raw_urls = match kind {
        SourceKind::Html => extract::html::extract_urls(&content)?,
        SourceKind::Css => extract::css::extract_urls(&content)?,
        SourceKind::Js => extract::js::extract_urls(&content)?,
        SourceKind::Json => extract::json::extract_urls(&content)?,
    };

    let mut resolved = HashSet::new();
    for url in raw_urls {
        if let Some(target) = resolver.resolve(&url, path)? {
            resolved.insert(target);
        }
    }
    Ok(resolved)
}

fn is_likely_binary(path: &Path) -> bool {
    if let Some(ext) = path.extension().and_then(|e| e.to_str()) {
        let ext = ext.to_ascii_lowercase();
        return matches!(
            ext.as_str(),
            "png" | "jpg" | "jpeg" | "gif" | "svg" | "webp" | "ico" | "pdf" | "mp4" | "webm" | "mov" | "mp3" | "wav" | "woff" | "woff2" | "ttf" | "eot" | "otf" | "zip" | "gz" | "tar"
        );
    }
    false
}
