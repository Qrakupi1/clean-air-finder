<?php
declare(strict_types=1);

/**
 * Import the Bulgarian national PM2.5 hourly CSV export into measurements.
 *
 * Usage inside the PHP container:
 *   php /app/output/import_bulgarian_pm25.php --file=/app/output/reports.csv --dry-run
 *   php /app/output/import_bulgarian_pm25.php --file=/app/output/reports.csv
 *
 * The source file has repeated station sections:
 *   STATION_CODE<TAB>Station name
 *   Date/Time,PM2.5 concentration,
 *   dd.mm.yyyy HH:MM,value,
 *
 * 24:00 is normalized to 00:00 on the following day.
 * Repeated local timestamps (e.g. daylight-saving transitions) are collapsed
 * to one hourly record using the arithmetic mean; all source rows are retained
 * in raw_record for traceability.
 */

function option(string $name, ?string $default = null): ?string {
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

function fail(string $message, int $exitCode = 1): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($exitCode);
}

$file = option('--file');
$dryRun = in_array('--dry-run', $argv, true);

if ($file === null || !is_file($file) || !is_readable($file)) {
    fail('Provide a readable input file using --file=/path/to/reports.csv');
}

$pdo = null;
if (!$dryRun) {
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_NAME') ?: 'clean_air_finder';
    $user = getenv('DB_USER') ?: 'clean_air_app';
    $pass = getenv('DB_PASSWORD') ?: '';
    if ($pass === '') fail('DB_PASSWORD is empty. Check your .env file.');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

$handle = fopen($file, 'rb');
if ($handle === false) fail("Cannot open {$file}");

$stations = [];
$currentCode = null;
$currentName = null;
$lineNumber = 0;
$rejected = [];
$groups = []; // station code => normalized timestamp => source rows
$sourceMin = null;
$sourceMax = null;

while (($line = fgets($handle)) !== false) {
    $lineNumber++;
    $line = trim($line, "\r\n\0");
    if ($line === '') continue;

    // Station section header, e.g. BG0050A<TAB>AMS Hipodruma – Sofia
    if (preg_match('/^(BG\d{4}[AR])\t(.+)$/u', $line, $m)) {
        $currentCode = trim($m[1]);
        $currentName = trim($m[2]);
        $stations[$currentCode] = $currentName;
        continue;
    }

    // Each station section has its own Bulgarian CSV header.
    if (str_starts_with($line, 'Дата/Час') || str_starts_with($line, 'Date/Time')) {
        continue;
    }

    if ($currentCode === null) {
        $rejected[] = ['line' => $lineNumber, 'reason' => 'Data row before station header', 'raw' => $line];
        continue;
    }

    // The file is comma-delimited, despite station sections being tab-delimited.
    $parts = str_getcsv($line, ',', '"', '\\');
    if (count($parts) < 2) {
        $rejected[] = ['line' => $lineNumber, 'reason' => 'Expected timestamp and value columns', 'raw' => $line];
        continue;
    }

    $rawTimestamp = trim((string)$parts[0]);
    $rawValue = trim((string)$parts[1]);

    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})$/', $rawTimestamp, $tm)) {
        $rejected[] = ['line' => $lineNumber, 'reason' => 'Unrecognized timestamp', 'raw' => $line];
        continue;
    }

    $day = (int)$tm[1];
    $month = (int)$tm[2];
    $year = (int)$tm[3];
    $hour = (int)$tm[4];
    $minute = (int)$tm[5];

    if (!checkdate($month, $day, $year) || $minute !== 0 || $hour > 24 || ($hour === 24 && $minute !== 0)) {
        $rejected[] = ['line' => $lineNumber, 'reason' => 'Invalid date/time', 'raw' => $line];
        continue;
    }

    // The export uses end-of-hour timestamps.
    // Convert them to the beginning of the measurement interval.
    $dt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day)
    );

    if ($dt === false) {
        $rejected[] = [
            'line' => $lineNumber,
            'reason' => 'Could not parse date/time',
            'raw' => $line
        ];
        continue;
    }

    $dt = $dt->modify('+' . ($hour - 1) . ' hours');

    $timestamp = $dt->format('Y-m-d H:i:s');
    $dateOnly = $dt->format('Y-m-d');
    $sourceMin = $sourceMin === null || $timestamp < $sourceMin ? $timestamp : $sourceMin;
    $sourceMax = $sourceMax === null || $timestamp > $sourceMax ? $timestamp : $sourceMax;

    $value = null;
    if ($rawValue !== '') {
        // Exports use decimal points. Do not silently accept localized thousands separators.
        if (!is_numeric($rawValue)) {
            $rejected[] = ['line' => $lineNumber, 'reason' => 'Non-numeric concentration', 'raw' => $line];
            continue;
        }
        $value = (float)$rawValue;
        if (!is_finite($value) || $value < 0) {
            $rejected[] = ['line' => $lineNumber, 'reason' => 'Negative or non-finite concentration', 'raw' => $line];
            continue;
        }
    }

    // Blank measurements are intentionally skipped, not converted to zero.
    if ($value === null) {
        $rejected[] = ['line' => $lineNumber, 'reason' => 'Blank concentration (missing observation)', 'raw' => $line];
        continue;
    }

    $groups[$currentCode][$timestamp][] = [
        'line' => $lineNumber,
        'raw_timestamp' => $rawTimestamp,
        'raw_value' => $rawValue,
        'value' => $value,
        'date' => $dateOnly,
    ];
}
fclose($handle);

if ($stations === []) fail('No station section headers found in the file.');

$prepared = [];
$duplicateGroups = 0;
$duplicateRows = 0;
foreach ($groups as $code => $timestamps) {
    foreach ($timestamps as $timestamp => $rows) {
        if (count($rows) > 1) {
            $duplicateGroups++;
            $duplicateRows += count($rows) - 1;
        }
        $values = array_column($rows, 'value');
        $prepared[] = [
            'station_code' => $code,
            'timestamp' => $timestamp,
            'value' => array_sum($values) / count($values),
            'raw_rows' => $rows,
        ];
    }
}

echo "Input: {$file}\n";
echo "Stations: " . count($stations) . "\n";
foreach ($stations as $code => $name) {
    $count = count($groups[$code] ?? []);
    echo "  {$code} | {$name} | {$count} unique hourly timestamps\n";
}
echo "Prepared measurements: " . count($prepared) . "\n";
echo "Missing/invalid rows skipped: " . count($rejected) . "\n";
echo "Duplicate timestamp groups averaged: {$duplicateGroups} (extra rows: {$duplicateRows})\n";
echo "Normalized timestamp range: " . ($sourceMin ?? 'n/a') . " to " . ($sourceMax ?? 'n/a') . "\n";

if ($dryRun) {
    echo "DRY RUN: no database changes made.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $stationLookup = $pdo->prepare(
        'SELECT id FROM stations WHERE eea_station_code = :code LIMIT 1'
    );
    $stationIds = [];
    foreach ($stations as $code => $name) {
        $stationLookup->execute(['code' => $code]);
        $row = $stationLookup->fetch();
        if (!$row) {
            throw new RuntimeException("Station {$code} is missing from the stations table. Seed/map it before importing.");
        }
        $stationIds[$code] = (int)$row['id'];
    }

    $runInsert = $pdo->prepare(
        "INSERT INTO ingestion_runs
            (source, dataset, date_from, date_to, status, records_downloaded, records_imported, records_rejected, started_at)
         VALUES
            (:source, :dataset, :date_from, :date_to, 'running', :downloaded, 0, :rejected, NOW())"
    );
    $runInsert->execute([
        'source' => 'Bulgarian National Air Quality Monitoring System',
        'dataset' => 'PM2.5 hourly CSV export',
        'date_from' => $sourceMin ? substr($sourceMin, 0, 10) : null,
        'date_to' => $sourceMax ? substr($sourceMax, 0, 10) : null,
        'downloaded' => array_sum(array_map('count', $groups)),
        'rejected' => count($rejected),
    ]);
    $runId = (int)$pdo->lastInsertId();

    $insert = $pdo->prepare(
        "INSERT INTO measurements
            (station_id, ingestion_run_id, pollutant_code, sampling_point,
             observed_from, observed_to, value, unit, aggregation_type,
             result_time, data_capture, validity, verification, source_dataset,
             source_record_key, raw_record)
         VALUES
            (:station_id, :run_id, 'PM2.5', :sampling_point,
             :observed_from, :observed_to, :value, 'ug/m3', 'hour',
             NULL, NULL, 'national_export', 'not_provided', :source_dataset,
             :source_record_key, :raw_record)
         ON DUPLICATE KEY UPDATE
            value = VALUES(value),
            ingestion_run_id = VALUES(ingestion_run_id),
            raw_record = VALUES(raw_record),
            source_dataset = VALUES(source_dataset)"
    );

    $imported = 0;
    foreach ($prepared as $item) {
        $code = $item['station_code'];
        $key = hash('sha256', 'BG_NATIONAL_PM25|' . $code . '|' . $item['timestamp']);
        $raw = [
            'station_code' => $code,
            'station_name' => $stations[$code],
            'normalized_timestamp' => $item['timestamp'],
            'duplicate_policy' => count($item['raw_rows']) > 1 ? 'arithmetic_mean' : 'none',
            'source_rows' => $item['raw_rows'],
        ];
        $from = new DateTimeImmutable($item['timestamp']);
        $to = $from->modify('+1 hour');

        $insert->execute([
            'station_id' => $stationIds[$code],
            'run_id' => $runId,
            'sampling_point' => $code,
            'observed_from' => $from->format('Y-m-d H:i:s'),
            'observed_to' => $to->format('Y-m-d H:i:s'),
            'value' => number_format($item['value'], 3, '.', ''),
            'source_dataset' => 'BG_NATIONAL_PM25_HOURLY',
            'source_record_key' => $key,
            'raw_record' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $imported++;
    }

    $finish = $pdo->prepare(
        "UPDATE ingestion_runs
         SET status = 'completed', records_imported = :imported, finished_at = NOW()
         WHERE id = :id"
    );
    $finish->execute(['imported' => $imported, 'id' => $runId]);

    $pdo->commit();
    echo "Import completed. Ingestion run ID: {$runId}\n";
    echo "Inserted/updated hourly measurements: {$imported}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage());
}

if ($rejected !== []) {
    $reportPath = dirname($file) . '/pm25_import_skipped_rows.json';
    file_put_contents($reportPath, json_encode($rejected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Skipped-row details: {$reportPath}\n";
}
