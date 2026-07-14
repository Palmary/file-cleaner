use std::collections::HashSet;

use anyhow::Result;
use regex::Regex;

/// Extracts URL-like strings from raw HTML text.
/// We intentionally use regexes rather than a full DOM parser so we can:
/// - handle templates/partials without valid document structure,
/// - capture references inside `<script>` and `<style>` blocks,
/// - remain fast and dependency-light.
///
/// Attributes scanned: src, href, data-src, poster, srcset, action, content (for meta URLs),
/// and CSS `url(...)` inside `<style>` blocks and inline `style=` attributes.
pub fn extract_urls(content: &str) -> Result<HashSet<String>> {
    let mut urls = HashSet::new();

    // Attribute-based references.
    let attr_re = Regex::new(
        r#"(?i)(?:src|href|data-src|poster|srcset|action|content|data-url)\s*=\s*["']?([^"'\s>]+)"#,
    )?;
    for cap in attr_re.captures_iter(content) {
        if let Some(m) = cap.get(1) {
            for part in split_srcset(m.as_str()) {
                if looks_like_url(&part) {
                    urls.insert(part);
                }
            }
        }
    }

    // CSS url(...) references.
    let url_re = Regex::new(r#"(?i)url\s*\(\s*["']?([^"')\s]+)"#)?;
    for cap in url_re.captures_iter(content) {
        if let Some(m) = cap.get(1) {
            let s = m.as_str().to_string();
            if looks_like_url(&s) {
                urls.insert(s);
            }
        }
    }

    Ok(urls)
}

fn split_srcset(value: &str) -> Vec<String> {
    value
        .split(',')
        .map(|s| s.split_whitespace().next().unwrap_or(s).to_string())
        .collect()
}

fn looks_like_url(s: &str) -> bool {
    // Skip inline javascript: and mailto: anchors, and data URIs.
    let lower = s.to_ascii_lowercase();
    if lower.starts_with("javascript:")
        || lower.starts_with("mailto:")
        || lower.starts_with("tel:")
        || lower.starts_with("data:")
        || lower.starts_with("#")
        || s.is_empty()
    {
        return false;
    }
    // Accept absolute URLs, root-relative URLs, and relative URLs.
    s.starts_with("http://")
        || s.starts_with("https://")
        || s.starts_with("//")
        || s.starts_with('/')
        || !s.contains(' ')
}
