use std::collections::{HashMap, HashSet};
use std::path::{Path, PathBuf};

use anyhow::Result;

use crate::config::Config;
use crate::normalize::normalize_path;

#[derive(Debug, Clone)]
pub struct AnalysisResult {
    /// Media files that exist but are not referenced by any source file.
    pub unused: Vec<PathBuf>,
    /// Paths referenced by source files but do not exist on disk.
    pub broken: Vec<BrokenReference>,
    /// Total referenced paths discovered.
    pub referenced_count: usize,
    /// Total media files discovered.
    pub media_count: usize,
}

#[derive(Debug, Clone)]
pub struct BrokenReference {
    pub path: PathBuf,
    pub referencing_files: Vec<PathBuf>,
}

pub fn analyze(
    referenced: HashSet<PathBuf>,
    media_files: Vec<PathBuf>,
    config: &Config,
) -> Result<AnalysisResult> {
    let referenced_normalized: HashMap<PathBuf, PathBuf> = referenced
        .iter()
        .map(|p| (normalize_path(p), p.clone()))
        .collect();

    let mut unused = Vec::new();
    for media in &media_files {
        if config.media_roots_as_disposable {
            if !is_protected(media, config) {
                unused.push(media.clone());
            }
            continue;
        }

        let normalized = normalize_path(media);
        if !referenced_normalized.contains_key(&normalized) {
            if !is_protected(media, config) {
                unused.push(media.clone());
            }
        }
    }

    // Broken references: referenced paths not found in media files. We only care
    // about references that look like media (inside media roots or having asset
    // extensions) to avoid flagging every missing HTML page.
    let media_normalized: HashMap<PathBuf, PathBuf> = media_files
        .iter()
        .map(|p| (normalize_path(p), p.clone()))
        .collect();

    let mut broken = Vec::new();
    for (norm, original) in &referenced_normalized {
        if !media_normalized.contains_key(norm) && looks_like_media(original) {
            broken.push(BrokenReference {
                path: original.clone(),
                referencing_files: Vec::new(), // populated later if we track provenance
            });
        }
    }

    Ok(AnalysisResult {
        unused,
        broken,
        referenced_count: referenced.len(),
        media_count: media_files.len(),
    })
}

fn is_protected(path: &Path, config: &Config) -> bool {
    if config.protected_patterns.is_empty() {
        return false;
    }
    let path_str = path.to_string_lossy();
    config.protected_patterns.iter().any(|p| {
        glob::Pattern::new(p)
            .map(|pat| pat.matches(&path_str))
            .unwrap_or(false)
    })
}

fn looks_like_media(path: &Path) -> bool {
    if let Some(ext) = path.extension().and_then(|e| e.to_str()) {
        let ext = ext.to_ascii_lowercase();
        return matches!(
            ext.as_str(),
            "jpg" | "jpeg" | "png" | "gif" | "svg" | "webp" | "ico" | "pdf" | "mp4" | "webm" | "mov" | "mp3" | "wav" | "css" | "js" | "json" | "woff" | "woff2" | "ttf" | "eot" | "otf"
        );
    }
    false
}
