use std::borrow::Cow;
use std::io::Read;

use anyhow::{Context, Result};
use flate2::read::{DeflateDecoder, GzDecoder, ZlibDecoder};

/// Try to decompress bytes that were compressed with pako (zlib/gzip/raw deflate).
/// Returns the original bytes if none of the formats match or decompression fails.
pub fn decompress_if_needed(bytes: &[u8]) -> Result<Cow<'_, [u8]>> {
    if bytes.is_empty() {
        return Ok(Cow::Borrowed(bytes));
    }

    // pako.deflate() produces zlib format (starts with 0x78 usually).
    if looks_like_zlib(bytes) {
        let mut decoder = ZlibDecoder::new(bytes);
        let mut output = Vec::new();
        if decoder.read_to_end(&mut output).is_ok() {
            return Ok(Cow::Owned(output));
        }
    }

    // pako.gzip() produces gzip format.
    if looks_like_gzip(bytes) {
        let mut decoder = GzDecoder::new(bytes);
        let mut output = Vec::new();
        if decoder.read_to_end(&mut output).is_ok() {
            return Ok(Cow::Owned(output));
        }
    }

    // pako.deflateRaw() produces raw deflate. Try it as a fallback, but only
    // when the content does not look like plain text.
    if !looks_like_plain_text(bytes) {
        let mut decoder = DeflateDecoder::new(bytes);
        let mut output = Vec::new();
        if decoder.read_to_end(&mut output).is_ok() && !output.is_empty() {
            return Ok(Cow::Owned(output));
        }
    }

    Ok(Cow::Borrowed(bytes))
}

fn looks_like_zlib(bytes: &[u8]) -> bool {
    bytes.len() >= 2 && bytes[0] == 0x78 && matches!(bytes[1], 0x01 | 0x5e | 0x9c | 0xda)
}

fn looks_like_gzip(bytes: &[u8]) -> bool {
    bytes.len() >= 2 && bytes[0] == 0x1f && bytes[1] == 0x8b
}

fn looks_like_plain_text(bytes: &[u8]) -> bool {
    // Treat as plain text if the first 1 KiB contains no null bytes and is valid UTF-8.
    let sample = &bytes[..bytes.len().min(1024)];
    if sample.contains(&0) {
        return false;
    }
    std::str::from_utf8(sample).is_ok()
}

/// Convenience helper: read a possibly-compressed file and return its UTF-8 text.
pub fn read_text_with_decompression(path: &std::path::Path) -> Result<String> {
    let bytes = std::fs::read(path)
        .with_context(|| format!("reading file: {}", path.display()))?;
    let decompressed = decompress_if_needed(&bytes)?;
    Ok(String::from_utf8_lossy(&decompressed).into_owned())
}
