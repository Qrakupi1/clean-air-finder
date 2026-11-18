# Clean Air Finder

Clean Air Finder is a small web app that compares PM2.5 air pollution in Bulgarian cities. Choose a start and end date to see which city had the lowest average pollution during that period. The page also shows daily values and how much data was available.

## Tech stack

- PHP 8.3 for the API and data import scripts
- Plain JavaScript, HTML and CSS for the interface
- MySQL 8.4 for storing measurements and daily averages
- Nginx and Docker Compose to run everything together

## Data and how the ranking works

The project uses public air-quality measurements from the European Environment Agency (EEA) and an hourly PM2.5 export from Bulgaria's national air-quality monitoring system. Data is imported into MySQL rather than requested from an external service every time someone uses the app.

PM2.5 is measured in **µg/m³** (micrograms per cubic metre). A day needs at least **12 valid hourly readings** (50% coverage) to count. A city needs valid data for at least **75% of the selected days** to be ranked. The ranking uses the average of eligible daily city averages and compares **urban-background** stations, rather than mixing different station types.

The current sample has enough comparable data to rank **Varna, Sofia and Ruse** for the full year from 17 November 2025 to 16 November 2026. Other listed cities are marked as having insufficient data. This is **not** a complete ranking of every city in Bulgaria.

## Run locally

You need Docker with Docker Compose.

1. Copy `.env.example` to `.env` and fill in the database name, user and passwords.
2. Start the app:

   ```bash
   docker compose up -d --build
   ```

3. Open **http://localhost:8080**.

For a new database, run `database/schema.sql` and then `database/seed.sql` against the MySQL database. These create the tables and station list, but **do not include historical measurements**. Import the downloaded data separately using the scripts in `src/cli/`, then run `aggregate_daily.php` to calculate daily averages. The Bulgarian CSV importer expects a local file such as `output/reports.csv`; this file is intentionally not committed to Git.

Useful API endpoints:

- `GET /api/v1/air-quality/cleanest?from=2025-11-17&to=2026-11-16`
- `GET /api/v1/air-quality/daily?city_id=1&from=2025-11-17&to=2026-11-16`
- `GET /api/v1/health/ready`
- `GET /metrics`

## Checks and performance

The API checks date ranges and reports when a city does not have enough measurements. Imported data and daily averages are stored locally, so an unavailable external data source does not stop users from reading existing results. There are health checks and basic Prometheus-format request metrics.

In a local test of **400 requests** across 7-, 30-, 90- and 365-day searches, there were **no failed requests**. The 365-day search had a **p95 response time of 8.73 ms**. This was a local test, not a production load test.

## Limitations and next steps

The main limitation is data coverage: only three cities currently have enough comparable PM2.5 measurements for a full-year ranking. Some hourly timestamps and daylight-saving changes also need careful handling. The project uses a fixed 24-hour expectation for daily coverage.

Next steps are to automate regular imports, archive older measurements, finish the public deployment and connect the metrics to a monitoring service. These are not yet presented as completed features.

## AI usage

I used ChatGPT to help research the data sources, work through SQL and PHP issues, review the Docker setup and improve documentation. I tested the commands and checked the results locally. The implementation choices and final submission remain my responsibility.
