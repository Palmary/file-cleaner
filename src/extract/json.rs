use std::collections::HashSet;

use anyhow::Result;
use regex::Regex;

/// Extract URL-like strings from JSON and JSON-like data files.
/// We use the same string literal extraction as JS.
pub fn extract_urls(content: &str) -> Result<HashSet<String>> {
    let mut urls = HashSet::new();

    let string_re = Regex::new(r#""([^"\\]{3,})""#)?;
    for cap in string_re.captures_iter(content) {
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
    if s.len() < 4 {
        return false;
    }
    let lower = s.to_ascii_lowercase();
    if lower.starts_with("data:") || lower.starts_with("javascript:") || lower.starts_with("mailto:") {
        return false;
    }
    s.starts_with("http://")
        || s.starts_with("https://")
        || s.starts_with('/')
        || s.contains('/')
        || s.ends_with(".jpg")
        || s.ends_with(".jpeg")
        || s.ends_with(".png")
        || s.ends_with(".gif")
        || s.ends_with(".svg")
        || s.ends_with(".webp")
        || s.ends_with(".pdf")
        || s.ends_with(".mp4")
        || s.ends_with(".webm")
}
