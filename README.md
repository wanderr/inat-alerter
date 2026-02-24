# iNat Alerter

Weekly digests and hourly alerts for iNaturalist observations.

**Status:** Work in progress

## Quick Start

1. Fork this repository
2. Copy `config.example.yaml` to `config.yaml` and customize
3. Add required secrets to GitHub repository settings (see Configuration)
4. Enable GitHub Actions
5. Workflows will run automatically on schedule

## Configuration

See `config.example.yaml` for full configuration options.

### Required GitHub Secrets

Set these in your repository Settings → Secrets and variables → Actions:

- `SMTP_HOST` - SMTP server hostname
- `SMTP_PORT` - SMTP server port (usually 587)
- `SMTP_USERNAME` - SMTP username
- `SMTP_PASSWORD` - SMTP password  
- `SMTP_FROM_EMAIL` - Sender email address
- `SMTP_FROM_NAME` - Sender display name (optional)
- `EMAIL_RECIPIENTS` - Comma-separated recipient email addresses

### Important Notes

- **Schedules are in UTC**: GitHub Actions cron schedules run in UTC. The digest schedule in `config.yaml` uses your local timezone, but you'll need to convert to UTC for the workflow cron expression.
- **State retention**: State is stored as workflow artifacts with a configurable retention period (default 14 days). If state expires, digest falls back to 7-day lookback and alerts to 1-hour lookback.

## License

MIT
