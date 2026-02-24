# iNat Alerter

🦎 Automated weekly digests and hourly alerts for iNaturalist observations, delivered via email.

Uses the public iNaturalist API and GitHub Actions to monitor observations of configured taxa within a geographic area. Perfect for naturalists, researchers, and wildlife enthusiasts who want to stay informed about local biodiversity.

## Features

- **Weekly Digest Emails** - Summary of observations created in the past week, sorted by rarity
- **Hourly Alert Emails** - Real-time notifications for watchlist species
- **Configurable Taxa** - Monitor any taxa (plants, insects, birds, reptiles, fungi, etc.)
- **Geographic Filtering** - Define center point and radius for your area of interest
- **Smart Deduplication** - Never see the same observation twice
- **No Server Required** - Runs entirely on GitHub Actions (free for public repos)
- **Easy to Fork** - Simple configuration, no code changes needed

---

## Quick Start

### 1. Fork This Repository

Click the "Fork" button at the top of this page to create your own copy.

### 2. Get a SendGrid API Key

SendGrid provides free email sending (100 emails/day).

1. Sign up at [SendGrid](https://signup.sendgrid.com/)
2. Verify your email address
3. Create an API Key:
   - Go to Settings → API Keys
   - Click "Create API Key"
   - Choose "Full Access" or "Mail Send" permission
   - Copy the API key (you won't see it again!)

**Optional but recommended:** Verify your sender domain
- Go to Settings → Sender Authentication
- Follow domain verification steps for better deliverability
- If you skip this, emails will work but may have warnings

### 3. Configure GitHub Secrets

In your forked repository:

1. Go to **Settings → Secrets and variables → Actions**
2. Click **"New repository secret"** and add:

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `SENDGRID_API_KEY` | Your SendGrid API key | `SG.xxxxxxxxxxx...` |
| `SENDGRID_FROM_EMAIL` | Email address to send from | `alerts@yourdomain.com` |
| `SENDGRID_FROM_NAME` | Display name for sender (optional) | `iNat Alerter` |
| `EMAIL_RECIPIENTS` | Comma-separated recipient emails | `you@example.com` or `you@example.com, friend@example.com` |

### 4. Configure Your Settings

1. Copy `config.example.yaml` to `config.yaml`:
   ```bash
   cp config.example.yaml config.yaml
   ```

2. Edit `config.yaml` with your preferences:

```yaml
# Timezone for all dates/times in emails
timezone: "America/New_York"

# Geographic area to monitor
location:
  lat: 40.7128        # Latitude (decimal degrees)
  lng: -74.0060       # Longitude (decimal degrees)
  radius: 50          # Radius in kilometers

# Taxa to monitor (use iNaturalist taxon IDs)
taxa:
  include:
    - 26036           # Example: Reptilia (all reptiles)
    - 47178           # Example: Amphibia (all amphibians)
  exclude:
    - 123456          # Optional: exclude specific taxa

# Watchlist taxa for hourly alerts (subset of included taxa)
watchlist:
  taxa_ids:
    - 48460           # Example: specific rare species

# How to calculate rarity
rarity:
  method: "radius"    # Options: "radius", "place", or "global"
  place_id: null      # Optional: iNat place ID (only if method is "place")

# How old can an observation be before it's marked "old"?
old_observation:
  days_old_threshold: 30

# Weekly digest settings
digest:
  enabled: true
  day_of_week: 0      # 0=Sunday, 1=Monday, ..., 6=Saturday
  local_hour: 8       # Hour to run (24-hour format, in configured timezone)

# Hourly alerts settings
alerts:
  enabled: true

# State retention
state:
  artifact_retention_days: 14
```

**Finding Taxon IDs:**
1. Go to [iNaturalist.org](https://www.inaturalist.org)
2. Search for your species/taxon
3. The taxon ID is in the URL: `inaturalist.org/taxa/{ID}`
4. Example: Snakes = `inaturalist.org/taxa/85553` → ID is `85553`

### 5. Commit and Push

```bash
git add config.yaml
git commit -m "Configure for my location and taxa"
git push
```

### 6. Enable GitHub Actions

1. Go to the **Actions** tab in your repository
2. Click **"I understand my workflows, go ahead and enable them"**
3. Workflows will now run on schedule automatically

---

## How It Works

### Weekly Digest Workflow

- Runs weekly at your configured day/time
- Fetches observations created since the last digest (or 7 days on first run)
- Groups observations into:
  - **New** - Observed recently (within threshold)
  - **Old** - Posted recently but observed long ago
- Sorts by rarity (fewest observations first)
- Sends HTML email with photos and details

### Hourly Alerts Workflow

- Runs every hour
- Fetches observations of watchlist taxa created since last run (or 1 hour on first run)
- Filters out "old" observations (observed more than threshold days ago)
- Sends alert email for new sightings

### State Management

- State stored as GitHub Actions artifact (retained for configured days)
- Tracks last run times and observation IDs for deduplication
- If artifact expires, workflows fall back to default lookback periods

---

## Schedule Configuration

GitHub Actions workflows use **UTC cron schedules**. You need to calculate the UTC time that matches your desired local time.

**Example:** If you want the digest to run at **Sunday 8 AM in Asia/Taipei (UTC+8)**:
- Local time: Sunday 8:00 AM
- UTC time: Sunday 12:00 AM (midnight)
- Cron: `0 0 * * 0`

Edit `.github/workflows/weekly-digest.yml` and `.github/workflows/hourly-alerts.yml` to adjust schedules.

---

## Customization

### Email Templates

HTML templates are in the `templates/` directory:
- `templates/digest.html` - Weekly digest layout
- `templates/alert.html` - Hourly alert layout

Edit these to customize the look and feel of your emails.

### Rarity Calculation

Three methods available:

1. **`radius`** (default) - Count observations within your configured radius
2. **`place`** - Count observations within a specific iNat place (requires `place_id`)
3. **`global`** - Count all observations worldwide

To find a place ID, search on iNaturalist and check the URL of the place page.

---

## Troubleshooting

### Workflows not running?

- Check that GitHub Actions are enabled in the Actions tab
- Verify your cron schedule is correct (use [crontab.guru](https://crontab.guru) for help)
- Check workflow logs for errors

### Not receiving emails?

- Verify all SendGrid secrets are set correctly
- Check SendGrid dashboard for delivery status
- Verify `EMAIL_RECIPIENTS` format (comma-separated, no spaces unless quoted)
- Check spam folder

### API errors?

- iNaturalist API has rate limits - workflows will retry automatically
- If you see consistent errors, check the iNaturalist API status

### State artifact expired?

- Normal if workflows haven't run in 14+ days
- Workflows will automatically fall back to default lookback periods
- State will rebuild on next successful run

---

## Contributing

Contributions welcome! Please open an issue or pull request.

---

## License

MIT License - see LICENSE file for details.

---

## Acknowledgments

- Data from [iNaturalist.org](https://www.inaturalist.org)
- Email delivery via [SendGrid](https://sendgrid.com)
- Hosted on [GitHub Actions](https://github.com/features/actions)
