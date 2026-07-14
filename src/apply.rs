use std::collections::HashSet;
use std::fs;
use std::path::{Path, PathBuf};

use anyhow::{bail, Context, Result};
use chrono::{DateTime, Utc};
use dialoguer::Confirm;
use serde::{Deserialize, Serialize};

use crate::report::load_report;

pub struct ApplyOptions {
    pub report_path: PathBuf,
    pub skip_confirm: bool,
}

pub struct RestoreOptions {
    pub log_path: PathBuf,
    pub skip_confirm: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QuarantineLog {
    pub created_at: DateTime<Utc>,
    pub report_path: PathBuf,
    pub quarantine_dir: PathBuf,
    pub operations: Vec<QuarantineOperation>,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub removed_empty_dirs: Vec<PathBuf>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QuarantineOperation {
    pub source: PathBuf,
    pub target: PathBuf,
}

pub fn run(options: ApplyOptions) -> Result<QuarantineLog> {
    let report = load_report(&options.report_path)?;

    if !report.is_approved() {
        bail!("report has not been approved; run `file-cleaner approve` first");
    }

    let targets: Vec<_> = report
        .unused_files
        .iter()
        .map(|e| e.path.clone())
        .collect();

    if targets.is_empty() {
        println!("No unused files to quarantine.");
        return Ok(QuarantineLog {
            created_at: Utc::now(),
            report_path: options.report_path,
            quarantine_dir: PathBuf::new(),
            operations: Vec::new(),
            removed_empty_dirs: Vec::new(),
        });
    }

    if !options.skip_confirm {
        println!("About to quarantine {} file(s).", targets.len());
        let confirm = Confirm::new()
            .with_prompt("Proceed?")
            .default(false)
            .interact()
            .context("failed to read apply confirmation")?;
        if !confirm {
            bail!("apply cancelled");
        }
    }

    // Use a session-specific quarantine directory.
    let base_quarantine_dir = if report.meta.quarantine_dir.as_os_str().is_empty() {
        PathBuf::from("quarantine")
    } else {
        report.meta.quarantine_dir.clone()
    };
    let quarantine_dir = base_quarantine_dir.join(format!("session-{}", Utc::now().timestamp_millis()));
    fs::create_dir_all(&quarantine_dir)
        .with_context(|| format!("creating quarantine directory: {}", quarantine_dir.display()))?;

    let mut operations = Vec::new();
    let mut parents_to_check: HashSet<PathBuf> = HashSet::new();
    for source in &targets {
        if !source.exists() {
            eprintln!("Skipping missing file: {}", source.display());
            continue;
        }

        if let Some(parent) = source.parent() {
            parents_to_check.insert(parent.to_path_buf());
        }

        let file_name = source
            .file_name()
            .with_context(|| format!("path has no file name: {}", source.display()))?;
        // Preserve uniqueness by hashing full source path into target name.
        let hash = blake3::hash(source.to_string_lossy().as_bytes()).to_hex();
        let target_name = format!("{}-{}", hash, file_name.to_string_lossy());
        let target = quarantine_dir.join(target_name);

        fs::rename(source, &target)
            .with_context(|| format!("quarantining {} -> {}", source.display(), target.display()))?;
        operations.push(QuarantineOperation {
            source: source.clone(),
            target: target.clone(),
        });
    }

    let mut removed_empty_dirs = Vec::new();
    if report.meta.remove_empty_folders_on_apply {
        // First pass: remove empty directories under media roots.
        for parent in &parents_to_check {
            if let Some(removed) = remove_empty_dirs_upward(parent, &report.meta.media_roots) {
                removed_empty_dirs.extend(removed);
            }
        }

        // Second pass: remove empty media roots themselves. We collect them to
        // avoid mutating the iterator and to report what was removed.
        let mut media_roots_to_check = report.meta.media_roots.clone();
        media_roots_to_check.sort_by_key(|p| p.to_string_lossy().len());
        media_roots_to_check.reverse();
        for root in &media_roots_to_check {
            if !root.is_dir() {
                continue;
            }
            let is_empty = match fs::read_dir(root) {
                Ok(mut entries) => entries.next().is_none(),
                Err(_) => false,
            };
            if is_empty {
                if let Err(e) = fs::remove_dir(root) {
                    eprintln!("Warning: could not remove empty media root {}: {}", root.display(), e);
                } else {
                    removed_empty_dirs.push(root.clone());
                }
            }
        }
    }

    let log = QuarantineLog {
        created_at: Utc::now(),
        report_path: options.report_path,
        quarantine_dir: quarantine_dir.clone(),
        operations,
        removed_empty_dirs,
    };

    let log_path = quarantine_dir.join("quarantine-log.json");
    let json = serde_json::to_string_pretty(&log)?;
    fs::write(&log_path, json)
        .with_context(|| format!("writing quarantine log: {}", log_path.display()))?;

    if !log.removed_empty_dirs.is_empty() {
        println!("Removed {} empty folder(s)", log.removed_empty_dirs.len());
    }
    println!("Quarantined {} file(s) to {}", log.operations.len(), quarantine_dir.display());
    println!("Quarantine log: {}", log_path.display());
    Ok(log)
}

/// Remove empty directories starting from `start`, walking upward until a
/// non-empty directory or a media root is reached. Returns the list of removed
/// directories (innermost first).
fn remove_empty_dirs_upward(start: &Path, media_roots: &[PathBuf]) -> Option<Vec<PathBuf>> {
    let mut removed = Vec::new();
    let mut current = Some(start.to_path_buf());

    while let Some(dir) = current {
        // Stop at media roots so we never delete the root media folders themselves.
        // The roots themselves are handled separately if needed.
        if media_roots.iter().any(|root| root == &dir) {
            break;
        }

        if !dir.is_dir() {
            break;
        }

        let is_empty = match fs::read_dir(&dir) {
            Ok(mut entries) => entries.next().is_none(),
            Err(_) => break,
        };

        if !is_empty {
            break;
        }

        if let Err(e) = fs::remove_dir(&dir) {
            eprintln!("Warning: could not remove empty directory {}: {}", dir.display(), e);
            break;
        }

        removed.push(dir.clone());
        current = dir.parent().map(Path::to_path_buf);
    }

    if removed.is_empty() {
        None
    } else {
        Some(removed)
    }
}

pub fn restore(options: RestoreOptions) -> Result<()> {
    let content = fs::read_to_string(&options.log_path)
        .with_context(|| format!("reading quarantine log: {}", options.log_path.display()))?;
    let log: QuarantineLog = serde_json::from_str(&content)
        .with_context(|| format!("parsing quarantine log: {}", options.log_path.display()))?;

    if !options.skip_confirm {
        println!("About to restore {} file(s).", log.operations.len());
        let confirm = Confirm::new()
            .with_prompt("Proceed?")
            .default(false)
            .interact()
            .context("failed to read restore confirmation")?;
        if !confirm {
            bail!("restore cancelled");
        }
    }

    let mut restored = 0;
    let mut skipped = 0;
    let mut failed = Vec::new();

    for op in &log.operations {
        let target_exists = op.target.exists();
        let source_exists = op.source.exists();

        if target_exists {
            if let Some(parent) = op.source.parent() {
                fs::create_dir_all(parent)
                    .with_context(|| format!("creating parent directory: {}", parent.display()))?;
            }
            fs::rename(&op.target, &op.source)
                .with_context(|| format!("restoring {} -> {}", op.target.display(), op.source.display()))?;
            restored += 1;
        } else if source_exists {
            // Quarantined file is gone but the original source already exists,
            // so treat this operation as already restored.
            eprintln!(
                "Skipping already-restored file: {}",
                op.source.display()
            );
            skipped += 1;
        } else {
            failed.push(format!(
                "missing both quarantine target and source: {}",
                op.source.display()
            ));
        }
    }

    if !failed.is_empty() {
        bail!("failed to restore {} file(s):\n{}", failed.len(), failed.join("\n"));
    }

    println!("Restored {} file(s), skipped {} already-restored file(s).", restored, skipped);
    Ok(())
}
