use std::fs;

use file_cleaner::config::Config;
use file_cleaner::normalize::UrlResolver;
use file_cleaner::report::ReportFormat;
use file_cleaner::scan::ScanOptions;

fn make_test_config(tmp: &std::path::Path) -> Config {
    Config {
        source_roots: vec![tmp.join("site")],
        media_roots: vec![tmp.join("site").join("uploads")],
        origins: vec![file_cleaner::config::OriginMap {
            origin: "https://example.com/".to_string(),
            local_root: tmp.join("site"),
        }],
        prefix_maps: vec![file_cleaner::config::PrefixMap {
            prefix: "/section-a/".to_string(),
            local_root: tmp.join("site"),
        }],
        default_web_root: Some(tmp.join("site")),
        exclude_patterns: vec![],
        include_patterns: vec![],
        protected_patterns: vec![],
        report_dir: tmp.join("reports"),
        quarantine_dir: tmp.join("quarantine"),
        root_relative_base: Some("/".to_string()),
        display_timezone: "UTC".to_string(),
        media_roots_as_disposable: false,
        remove_empty_folders_on_apply: false,
    }
}

#[test]
fn detects_unused_image_and_broken_reference() {
    let tmp = tempfile::tempdir().unwrap();
    let site = tmp.path().join("site");
    let uploads = site.join("uploads");
    fs::create_dir_all(&uploads).unwrap();
    fs::create_dir_all(site.join("en")).unwrap();

    fs::write(
        site.join("en/index.html"),
        r#"<img src="/section-a/uploads/used.jpg">"#,
    )
    .unwrap();
    fs::write(uploads.join("used.jpg"), "x").unwrap();
    fs::write(uploads.join("unused.jpg"), "y").unwrap();

    let config = make_test_config(tmp.path());
    let report = file_cleaner::scan::run(ScanOptions {
        config,
        dry_run: true,
        format: ReportFormat::Text,
    })
    .unwrap();

    assert_eq!(report.summary.unused_files_count, 1);
    assert!(
        report
            .unused_files
            .iter()
            .any(|e| e.path.ends_with("unused.jpg"))
    );
}

#[test]
fn resolves_absolute_url_to_local_path() {
    let tmp = tempfile::tempdir().unwrap();
    let site = tmp.path().join("site");
    let uploads = site.join("uploads");
    fs::create_dir_all(&uploads).unwrap();
    fs::create_dir_all(site.join("en")).unwrap();

    fs::write(
        site.join("en/index.html"),
        r#"<img src="https://example.com/uploads/spaced%20file.jpg">"#,
    )
    .unwrap();
    fs::write(uploads.join("spaced file.jpg"), "x").unwrap();

    let config = make_test_config(tmp.path());
    let resolver = UrlResolver::new(&config).unwrap();
    let resolved = resolver
        .resolve(
            "https://example.com/uploads/spaced%20file.jpg",
            &site.join("en/index.html"),
        )
        .unwrap();

    assert_eq!(resolved, Some(uploads.join("spaced file.jpg")));
}

#[test]
fn extracts_urls_from_js_data_file() {
    let tmp = tempfile::tempdir().unwrap();
    let site = tmp.path().join("site");
    let uploads = site.join("uploads");
    fs::create_dir_all(&uploads).unwrap();
    fs::create_dir_all(site.join("assets/js/en")).unwrap();

    fs::write(
        site.join("assets/js/en/data.js"),
        r#"const items = [{image: "/section-a/uploads/js_asset.png"}]"#,
    )
    .unwrap();
    fs::write(uploads.join("js_asset.png"), "x").unwrap();
    fs::write(uploads.join("orphan.png"), "y").unwrap();

    let config = make_test_config(tmp.path());
    let report = file_cleaner::scan::run(ScanOptions {
        config,
        dry_run: true,
        format: ReportFormat::Text,
    })
    .unwrap();

    assert_eq!(report.summary.unused_files_count, 1);
    assert!(
        report
            .unused_files
            .iter()
            .any(|e| e.path.ends_with("orphan.png"))
    );
}
