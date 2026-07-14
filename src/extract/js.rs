use std::collections::HashSet;

use anyhow::Result;
use regex::Regex;

/// Best-effort extraction of URL-like strings from JavaScript.
/// This is intentionally not a full AST because the target files are mostly
/// data files or simple includes; we extract string literals and template
/// literal bodies. Concatenated paths are low-confidence and reported separately.
pub fn extract_urls(content: &str) -> Result<HashSet<String>> {
    let mut urls = HashSet::new();

    // Single/double quoted strings.
    let quote_re = Regex::new(r#"["']([^"']{3,})["']"#)?;
    for cap in quote_re.captures_iter(content) {
        if let Some(m) = cap.get(1) {
            let s = m.as_str().to_string();
            if looks_like_url(&s) {
                urls.insert(s);
            }
        }
    }

    // Backtick template literals (take the whole literal body).
    let backtick_re = Regex::new(r"`([^`]{3,})`")?;
    for cap in backtick_re.captures_iter(content) {
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
    let trimmed = s.trim();
    if trimmed.len() < 4 {
        return false;
    }
    let lower = trimmed.to_ascii_lowercase();
    if lower.starts_with("javascript:")
        || lower.starts_with("mailto:")
        || lower.starts_with("data:")
        || lower.starts_with("#")
    {
        return false;
    }
    // Accept paths that look like assets or URLs.
    trimmed.starts_with("http://")
        || trimmed.starts_with("https://")
        || trimmed.starts_with("//")
        || trimmed.starts_with('/')
        || trimmed.contains('/')
        || trimmed.ends_with(".jpg")
        || trimmed.ends_with(".jpeg")
        || trimmed.ends_with(".png")
        || trimmed.ends_with(".gif")
        || trimmed.ends_with(".svg")
        || trimmed.ends_with(".webp")
        || trimmed.ends_with(".pdf")
        || trimmed.ends_with(".mp4")
        || trimmed.ends_with(".webm")
}
