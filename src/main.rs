use std::path::PathBuf;

use anyhow::{Context, Result};
use clap::{Parser, Subcommand};

use file_cleaner::apply::{self, ApplyOptions, RestoreOptions};
use file_cleaner::config::Config;
use file_cleaner::report::ReportFormat;
use file_cleaner::scan::{self, ScanOptions};
use file_cleaner::workflow::{self, ApprovalOptions};

#[derive(Parser)]
#[command(name = "file-cleaner")]
#[command(
    version = env!("CARGO_PKG_VERSION"),
    about = "Scan website source files for unused/broken media entries, generate reports, and safely remove approved files"
)]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    /// Scan source and media roots, then write a report.
    Scan {
        /// Path to configuration file (JSON).
        #[arg(short, long, value_name = "FILE")]
        config: PathBuf,

        /// Only report findings; do not prompt or delete.
        #[arg(long)]
        dry_run: bool,

        /// Report output format.
        #[arg(long, value_enum, default_value_t = ReportFormat::Text)]
        format: ReportFormat,

        /// Output report as JSON only (deprecated: use --format json).
        #[arg(long, hide = true)]
        json: bool,
    },
    /// Approve a generated report.
    Approve {
        /// Path to report file (JSON).
        #[arg(value_name = "REPORT")]
        report: PathBuf,

        /// Skip interactive confirmation.
        #[arg(long)]
        yes: bool,
    },
    /// Apply an approved report (move files to quarantine).
    Apply {
        /// Path to approved report file (JSON).
        #[arg(value_name = "REPORT")]
        report: PathBuf,

        /// Skip interactive confirmation.
        #[arg(long)]
        yes: bool,
    },
    /// Restore files from a quarantine log.
    Restore {
        /// Path to a quarantine log file (JSON).
        #[arg(value_name = "LOG")]
        log: PathBuf,

        /// Skip interactive confirmation.
        #[arg(long)]
        yes: bool,
    },
}

fn main() -> Result<()> {
    let cli = Cli::parse();

    match cli.command {
        Commands::Scan {
            config,
            dry_run,
            format,
            json,
        } => {
            let config = Config::load(&config)
                .with_context(|| format!("failed to load config: {}", config.display()))?;
            let format = if json { ReportFormat::Json } else { format };
            let options = ScanOptions {
                config,
                dry_run,
                format,
            };
            scan::run(options)?;
        }
        Commands::Approve { report, yes } => {
            workflow::approve(ApprovalOptions {
                report_path: report,
                skip_confirm: yes,
            })?;
        }
        Commands::Apply { report, yes } => {
            apply::run(ApplyOptions {
                report_path: report,
                skip_confirm: yes,
            })?;
        }
        Commands::Restore { log, yes } => {
            apply::restore(RestoreOptions {
                log_path: log,
                skip_confirm: yes,
            })?;
        }
    }

    Ok(())
}
