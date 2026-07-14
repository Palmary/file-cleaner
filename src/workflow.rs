use std::path::PathBuf;

use anyhow::{bail, Context, Result};
use dialoguer::Confirm;

use crate::report::{load_report, Report};

pub struct ApprovalOptions {
    pub report_path: PathBuf,
    pub skip_confirm: bool,
}

pub fn approve(options: ApprovalOptions) -> Result<Report> {
    let mut report = load_report(&options.report_path)?;

    if report.is_approved() {
        bail!("report is already approved");
    }

    println!("Review report: {}", options.report_path.display());
    println!("Unused files:       {}", report.summary.unused_files_count);
    println!("Broken references:  {}", report.summary.broken_references_count);

    if !options.skip_confirm {
        let confirm = Confirm::new()
            .with_prompt("Approve this report for quarantine?")
            .default(false)
            .interact()
            .context("failed to read approval confirmation")?;

        if !confirm {
            bail!("approval cancelled");
        }
    }

    let token = report.approve();
    let json = serde_json::to_string_pretty(&report)?;
    std::fs::write(&options.report_path, json)
        .with_context(|| format!("saving approved report: {}", options.report_path.display()))?;

    println!("Report approved. Token: {}", token);
    Ok(report)
}
