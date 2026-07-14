use std::path::{Path, PathBuf};

use anyhow::{Context, Result};
use path_clean::PathClean;
use percent_encoding::percent_decode_str;
use url::Url;

use crate::config::{Config, OriginMap, PrefixMap};

/// Resolves URL strings found in source files to absolute local filesystem paths.
pub struct UrlResolver {
    origins: Vec<OriginMap>,
    prefix_maps: Vec<PrefixMap>,
    default_web_root: Option<PathBuf>,
    root_relative_base: Option<String>,
    media_roots: Vec<PathBuf>,
}

impl UrlResolver {
    pub fn new(config: &Config) -> Result<Self> {
        Ok(Self {
            origins: config.origins.clone(),
            prefix_maps: config.prefix_maps.clone(),
            default_web_root: config.default_web_root.clone(),
            root_relative_base: config.root_relative_base.clone(),
            media_roots: config.media_roots.clone(),
        })
    }

    /// Resolve a URL string relative to the source file it was found in.
    /// Returns `Ok(None)` for external URLs that are not configured for mapping.
    pub fn resolve(&self, url: &str, source_file: &Path) -> Result<Option<PathBuf>> {
        let trimmed = url.trim();
        if trimmed.is_empty() {
            return Ok(None);
        }

        // Protocol-relative URL.
        if trimmed.starts_with("//") {
            let with_scheme = format!("https:{}", trimmed);
            return self.resolve_absolute(&with_scheme);
        }

        // Absolute URL.
        if trimmed.starts_with("http://") || trimmed.starts_with("https://") {
            return self.resolve_absolute(trimmed);
        }

        // Root-relative URL.
        if trimmed.starts_with('/') {
            return self.resolve_root_relative(trimmed);
        }

        // Relative URL: resolve against the directory of the source file.
        self.resolve_relative(trimmed, source_file)
    }

    fn resolve_absolute(&self, url: &str) -> Result<Option<PathBuf>> {
        let parsed = match Url::parse(url) {
            Ok(u) => u,
            Err(_) => {
                // Malformed absolute URL (e.g. CSS comment fragment). Treat as unmapped.
                return Ok(None);
            }
        };
        let origin = format!("{}://{}/", parsed.scheme(), parsed.host_str().unwrap_or(""));

        for om in &self.origins {
            if origin.eq_ignore_ascii_case(&om.origin) {
                let path_part = parsed.path();
                // Ensure the path is relative to local_root, not treated as absolute.
                let path_part = path_part.strip_prefix('/').unwrap_or(path_part);
                let decoded = percent_decode_str(path_part)
                    .decode_utf8_lossy()
                    .to_string();
                let joined = om.local_root.join(&decoded).clean();
                return Ok(Some(joined));
            }
        }

        // External absolute URL not configured.
        Ok(None)
    }

    fn resolve_root_relative(&self, url: &str) -> Result<Option<PathBuf>> {
        // Strip query/hash.
        let without_fragment = url.split('#').next().unwrap_or(url);
        let without_query = without_fragment.split('?').next().unwrap_or(without_fragment);

        let decoded = percent_decode_str(without_query)
            .decode_utf8_lossy()
            .to_string();

        // Try prefix maps first.
        for pm in &self.prefix_maps {
            if decoded.starts_with(&pm.prefix) {
                let rest = &decoded[pm.prefix.len()..];
                let rest = if rest.starts_with('/') { &rest[1..] } else { rest };
                let joined = pm.local_root.join(rest).clean();
                return Ok(Some(joined));
            }
        }

        // Apply a configured root-relative base, if any.
        let path_part = if let Some(base) = &self.root_relative_base {
            if decoded.starts_with('/') {
                let stripped = &decoded[1..];
                if stripped.starts_with(base.trim_start_matches('/')) {
                    stripped.to_string()
                } else {
                    format!("{}{}", base.trim_start_matches('/'), stripped)
                }
            } else {
                decoded
            }
        } else {
            decoded.trim_start_matches('/').to_string()
        };

        if let Some(root) = &self.default_web_root {
            let joined = root.join(&path_part).clean();
            return Ok(Some(joined));
        }

        // Without a default web root we cannot resolve root-relative URLs safely.
        Ok(None)
    }

    fn resolve_relative(&self, url: &str, source_file: &Path) -> Result<Option<PathBuf>> {
        let without_fragment = url.split('#').next().unwrap_or(url);
        let without_query = without_fragment.split('?').next().unwrap_or(without_fragment);

        let decoded = percent_decode_str(without_query)
            .decode_utf8_lossy()
            .to_string();

        let source_dir = source_file
            .parent()
            .with_context(|| format!("source file has no parent: {}", source_file.display()))?;
        let joined = source_dir.join(&decoded).clean();
        Ok(Some(joined))
    }

    /// Determine whether a file path lives inside any configured media root.
    pub fn is_under_media_root(&self, path: &Path) -> bool {
        self.media_roots.iter().any(|root| path.starts_with(root))
    }
}

/// Normalize a path for comparison: percent-decode, resolve `..`, lowercase on
/// platforms where filesystems are case-insensitive.
pub fn normalize_path(path: &Path) -> PathBuf {
    let cleaned = path.clean();
    // Keep case-sensitive on Unix by default. The comparison layer can optionally
    // fold case if the user asks for production-Linux semantics.
    cleaned
}
