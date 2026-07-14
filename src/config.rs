use std::collections::HashMap;
use std::fs;
use std::path::{Path, PathBuf};

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};

/// Mapping from a production origin (scheme://host/) to a local directory root.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OriginMap {
    pub origin: String,
    pub local_root: PathBuf,
}

/// Root-relative path prefix mapped to a local directory.
/// Example: `/section-a/` -> `/example/site/section-a`.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PrefixMap {
    pub prefix: String,
    pub local_root: PathBuf,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Config {
    /// Directories containing HTML/CSS/JS files to scan for references.
    pub source_roots: Vec<PathBuf>,

    /// Directories considered the media library (candidates for deletion).
    pub media_roots: Vec<PathBuf>,

    /// Map absolute URLs to local filesystem roots.
    pub origins: Vec<OriginMap>,

    /// Map root-relative URL prefixes to local filesystem roots.
    pub prefix_maps: Vec<PrefixMap>,

    /// Default web root used for root-relative URLs without a prefix map.
    pub default_web_root: Option<PathBuf>,

    /// Glob patterns to exclude from scanning.
    #[serde(default)]
    pub exclude_patterns: Vec<String>,

    /// Glob patterns to include when scanning.
    #[serde(default)]
    pub include_patterns: Vec<String>,

    /// Patterns that, when a path matches, should never be deleted (allowlist).
    #[serde(default)]
    pub protected_patterns: Vec<String>,

    /// Directory where reports are written.
    #[serde(default = "default_report_dir")]
    pub report_dir: PathBuf,

    /// Directory where quarantined files are moved before deletion.
    #[serde(default = "default_quarantine_dir")]
    pub quarantine_dir: PathBuf,

    /// Default root-relative URL path on disk (e.g. `/`).
    #[serde(default)]
    pub root_relative_base: Option<String>,

    /// Timezone used for timestamps displayed in generated HTML reports.
    /// Format: IANA timezone name, e.g. "Asia/Hong_Kong".
    #[serde(default = "default_display_timezone")]
    pub display_timezone: String,

    /// When true, every file under media_roots is treated as an unused/deletable
    /// candidate regardless of whether it is referenced by a source file.
    /// Useful for isolated folders that are known to be disposable.
    #[serde(default)]
    pub media_roots_as_disposable: bool,

    /// When true, remove empty parent directories after quarantining files.
    #[serde(default)]
    pub remove_empty_folders_on_apply: bool,
}

pub fn default_display_timezone() -> String {
    "UTC".to_string()
}

fn default_report_dir() -> PathBuf {
    PathBuf::from("reports")
}

pub fn default_quarantine_dir() -> PathBuf {
    PathBuf::from("quarantine")
}

impl Config {
    pub fn load<P: AsRef<Path>>(path: P) -> Result<Self> {
        let content = fs::read_to_string(&path)
            .with_context(|| format!("reading config file: {}", path.as_ref().display()))?;
        let config: Config = serde_json::from_str(&content)
            .with_context(|| format!("parsing config file: {}", path.as_ref().display()))?;
        config.validate()?;
        Ok(config)
    }

    pub fn validate(&self) -> Result<()> {
        anyhow::ensure!(
            !self.source_roots.is_empty(),
            "config must contain at least one source_root"
        );
        anyhow::ensure!(
            !self.media_roots.is_empty(),
            "config must contain at least one media_root"
        );

        for root in &self.source_roots {
            anyhow::ensure!(root.is_absolute(), "source_root must be absolute: {}", root.display());
        }
        for root in &self.media_roots {
            anyhow::ensure!(root.is_absolute(), "media_root must be absolute: {}", root.display());
        }
        for origin in &self.origins {
            anyhow::ensure!(
                origin.local_root.is_absolute(),
                "origin local_root must be absolute: {}",
                origin.local_root.display()
            );
        }
        for prefix in &self.prefix_maps {
            anyhow::ensure!(
                prefix.local_root.is_absolute(),
                "prefix local_root must be absolute: {}",
                prefix.local_root.display()
            );
            anyhow::ensure!(
                prefix.prefix.starts_with('/'),
                "prefix must start with '/': {}",
                prefix.prefix
            );
        }

        Ok(())
    }

    /// Build a lookup from file extension to whether it is a source file we parse.
    pub fn source_extensions(&self) -> HashMap<String, SourceKind> {
        let mut map = HashMap::new();
        for ext in ["html", "htm", "xhtml", "css", "js", "json"] {
            map.insert(ext.to_string(), SourceKind::from_extension(ext));
        }
        map
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SourceKind {
    Html,
    Css,
    Js,
    Json,
}

impl SourceKind {
    pub fn from_extension(ext: &str) -> Self {
        match ext.to_ascii_lowercase().as_str() {
            "html" | "htm" | "xhtml" => SourceKind::Html,
            "css" => SourceKind::Css,
            "js" => SourceKind::Js,
            "json" => SourceKind::Json,
            _ => SourceKind::Html,
        }
    }
}
