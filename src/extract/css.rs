use std::collections::HashSet;

use anyhow::Result;
use regex::Regex;

/// Extract URLs from CSS files. Handles `url(...)` and `@import "..."`.
pub fn extract_urls(content: &str) -> Result<HashSet<String>> {
    let mut urls = HashSet::new();

    let url_re = Regex::new(r#"(?i)url\s*\(\s*["']?([^"')\s]+)"#)?;
    for cap in url_re.captures_iter(content) {
        if let Some(m) = cap.get(1) {
            let s = m.as_str().to_string();
            if looks_like_url(&s) {
                urls.insert(s);
            }
        }
    }

    let import_re = Regex::new(r#"(?i)@import\s+["']([^"']+)["']"#)?;
    for cap in import_re.captures_iter(content) {
        if let Some(m) = cap.get(1) {
            let s = m.as_str().to_string();
            if looks_like_url(&s) {
                urls.insert(s);
            }
        }
    }

    Ok(urls)
}

fn looks_like_url(s: &str) -> bool {
    let lower = s.to_ascii_lowercase();
    if lower.starts_with("data:") || s.is_empty() {
        return false;
    }
    true
}
