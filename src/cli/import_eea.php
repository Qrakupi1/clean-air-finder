<?php

declare(strict_types=1);

/**
 * Clean Air Finder - EEA Parquet to MySQL importer.
 *
 * Requirements:
 * - PHP CLI with PDO MySQL
 * - DuckDB CLI
 * - Existing cities, stations, ingestion_runs and measurements tables
 *
 * Example:
 * php src/cli/import_eea.php \
 *   --parquet="/app/output/**\/*.parquet" \
 *   --from=2026-10-01 \
 *   --to=2026-11-16 \
 *   --dataset=2
 */

$config = require __DIR__ . '/../config/app.php';

function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}" . PHP_EOL);
    exit(1);
}

function getArguments(array $argv): array
{
    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $parts = explode('=', substr($argument, 2), 2);
        $arguments[$parts[0]] = $parts[1] ?? true;
    }

    return $arguments;
}

function validateDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

function sqlString(string $value): string
{
    return str_replace("'", "''", $value);
}

function connectDatabase(array $config): PDO
{
    $db = $config['database'];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    return new PDO(
        $dsn,
        $db['user'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function createIngestionRun(
    PDO $pdo,
    string $dataset,
    string $dateFrom,
    string $dateTo
): int {
    $stmt = $pdo->prepare(
        "INSERT INTO ingestion_runs (
            source,
            dataset,
            date_from,
            date_to,
            status
        ) VALUES ('EEA', ?, ?, ?, 'running')"
    );

    $stmt->execute([$dataset, $dateFrom, $dateTo]);

    return (int) $pdo->lastInsertId();
}

function finishIngestionRun(
    PDO $pdo,
    int $runId,
    string $status,
    int $downloaded,
    int $imported,
    int $rejected,
    ?string $error = null
): void {
    $stmt = $pdo->prepare(
        "UPDATE ingestion_runs
         SET status = ?,
             records_downloaded = ?,
             records_imported = ?,
             records_rejected = ?,
             finished_at = NOW(),
             error_message = ?
         WHERE id = ?"
    );

    $stmt->execute([
        $status,
        $downloaded,
        $imported,
        $rejected,
        $error,
        $runId,
    ]);
}

function loadStations(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, eea_station_code
         FROM stations
         WHERE is_active = TRUE"
    );

    $stations = [];

    foreach ($stmt->fetchAll() as $station) {
        $stations[$station['eea_station_code']] = (int) $station['id'];
    }

    return $stations;
}

function extractStationCode(string $samplingPoint): ?string
{
    if (preg_match('/SPO-(BG[0-9]+[A-Z])_/', $samplingPoint, $matches)) {
        return $matches[1];
    }

    return null;
}

function normalizeUnit(string $unit): ?string
{
    $unit = trim(strtolower($unit));

    $acceptedUnits = [
        'ug.m-3',
        'µg.m-3',
        'ug/m3',
        'µg/m3',
        'µg/m³',
    ];

    return in_array($unit, $acceptedUnits, true)
        ? 'µg/m³'
        : null;
}

function normalizeTimestamp(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    try {
        // Preserve the timestamp as supplied by DuckDB.
        // Do not apply an automatic timezone conversion.
        return (new DateTimeImmutable($value))
            ->format('Y-m-d H:i:s.v');
    } catch (Throwable) {
        return null;
    }
}

function createSourceRecordKey(
    string $samplingPoint,
    string $pollutant,
    string $observedFrom,
    ?string $observedTo,
    string $aggregationType
): string {
    $parts = [
        $samplingPoint,
        $pollutant,
        $observedFrom,
        $observedTo ?? '',
        $aggregationType,
    ];

    return hash('sha256', implode('|', $parts));
}

function exportParquetToCsv(
    string $duckdb,
    string $parquetPattern,
    string $csvPath,
    string $dateFrom,
    string $dateTo
): void {
    $parquetPath = sqlString($parquetPattern);
    $outputPath = sqlString($csvPath);

    $fromSql = sqlString($dateFrom);
    $toSql = sqlString($dateTo);

    $sql = <<<SQL
COPY (
    SELECT
        Samplingpoint,
        Pollutant,
        CAST(Start AS VARCHAR) AS Start,
        CAST("End" AS VARCHAR) AS "End",
        CAST(Value AS VARCHAR) AS Value,
        Unit,
        AggType,
        Validity,
        Verification,
        CAST(ResultTime AS VARCHAR) AS ResultTime,
        CAST(DataCapture AS VARCHAR) AS DataCapture,
        FkObservationLog
    FROM read_parquet('{$parquetPath}', union_by_name = true)
    WHERE Pollutant = 6001
    AND Start >= TIMESTAMP '{$fromSql}'
    AND Start < (TIMESTAMP '{$toSql}' + INTERVAL 1 DAY)
)
TO '{$outputPath}'
(
    HEADER,
    DELIMITER ',',
    QUOTE '"',
    ESCAPE '"'
);
SQL;

    $command = escapeshellarg($duckdb)
        . ' -c '
        . escapeshellarg($sql)
        . ' 2>&1';

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "DuckDB export failed:\n" . implode("\n", $output)
        );
    }
}

function createMeasurementStatement(PDO $pdo): PDOStatement
{
    return $pdo->prepare(
        "INSERT INTO measurements (
            station_id,
            ingestion_run_id,
            sampling_point,
            pollutant_code,
            observed_from,
            observed_to,
            result_time,
            value,
            unit,
            aggregation_type,
            validity,
            verification,
            data_capture,
            source_dataset,
            source_record_key,
            raw_record
        ) VALUES (
            ?, ?, ?, 'PM2.5', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        ) AS incoming

        ON DUPLICATE KEY UPDATE
            ingestion_run_id = incoming.ingestion_run_id,
            result_time = incoming.result_time,
            value = incoming.value,
            unit = incoming.unit,
            validity = incoming.validity,
            verification = incoming.verification,
            data_capture = incoming.data_capture,
            source_dataset = incoming.source_dataset,
            raw_record = incoming.raw_record"
    );
}

function nullableDecimal(string $value): ?string
{
    $value = trim($value);

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return $value;
}

function nullableInteger(string $value): ?int
{
    $value = trim($value);

    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    return (int) $value;
}


// ------------------------------------------------------------
// Main
// ------------------------------------------------------------

$arguments = getArguments($argv);

$parquetPattern = $arguments['parquet'] ?? null;
$dateFrom = $arguments['from'] ?? null;
$dateTo = $arguments['to'] ?? null;
$datasetId = (string) ($arguments['dataset'] ?? '');

if (!$parquetPattern || !$dateFrom || !$dateTo || $datasetId === '') {
    fail(
        'Required arguments: --parquet, --from, --to, --dataset'
    );
}

if (!validateDate((string) $dateFrom)
    || !validateDate((string) $dateTo)
    || $dateFrom > $dateTo
) {
    fail('Invalid date range. Use YYYY-MM-DD.');
}

$datasetNames = [
    '1' => 'E2a',
    '2' => 'E1a',
];

if (!isset($datasetNames[$datasetId])) {
    fail('Dataset must be 1 (E2a) or 2 (E1a).');
}

$dataset = $datasetNames[$datasetId];

$duckdb = getenv('DUCKDB_PATH') ?: '/usr/local/bin/duckdb';

if (!is_executable($duckdb)) {
    fail("DuckDB executable not found: {$duckdb}");
}

$csvPath = tempnam(sys_get_temp_dir(), 'eea_import_');

if ($csvPath === false) {
    fail('Could not create temporary CSV file.');
}

$pdo = null;
$runId = null;

$downloaded = 0;
$imported = 0;
$rejected = 0;
$batchSize = 500;

try {
    logMessage('Connecting to MySQL...');
    $pdo = connectDatabase($config);

    logMessage('Loading active station mappings...');
    $stations = loadStations($pdo);

    if (count($stations) === 0) {
        throw new RuntimeException('No active stations found in MySQL.');
    }

    $runId = createIngestionRun(
        $pdo,
        $dataset,
        (string) $dateFrom,
        (string) $dateTo
    );

    logMessage("Created ingestion run #{$runId}");
    logMessage("Reading PM2.5 records from {$parquetPattern}");

    exportParquetToCsv(
        $duckdb,
        (string) $parquetPattern,
        $csvPath,
        (string) $dateFrom,
        (string) $dateTo
    );

    $file = fopen($csvPath, 'rb');

    if ($file === false) {
        throw new RuntimeException('Could not open exported CSV.');
    }

    $header = fgetcsv($file);

    if ($header === false) {
        throw new RuntimeException('CSV export is empty.');
    }

    $header = array_map(
        static fn($column) => trim((string) $column),
        $header
    );

    $measurementStatement = createMeasurementStatement($pdo);

    $pdo->beginTransaction();

    while (($values = fgetcsv($file)) !== false) {
        if (count($values) !== count($header)) {
            $rejected++;
            continue;
        }

        $record = array_combine($header, $values);

        if ($record === false) {
            $rejected++;
            continue;
        }

        $downloaded++;

        $samplingPoint = trim((string) $record['Samplingpoint']);
        $stationCode = extractStationCode($samplingPoint);

        if ($stationCode === null || !isset($stations[$stationCode])) {
            $rejected++;
            continue;
        }

        // Conservative project quality filter.
        if ((string) $record['Pollutant'] !== '6001'
            || (string) $record['Validity'] !== '1'
            || (string) $record['Verification'] !== '1'
            || strtolower(trim((string) ($record['AggType'] ?? ''))) !== 'hour'
        ) {
            $rejected++;
            continue;
        }

        $unit = normalizeUnit((string) $record['Unit']);

        if ($unit === null) {
            $rejected++;
            continue;
        }

        $value = nullableDecimal((string) $record['Value']);

        if ($value === null) {
            $rejected++;
            continue;
        }

        $observedFrom = normalizeTimestamp((string) $record['Start']);
        $observedTo = normalizeTimestamp((string) $record['End']);

        if ($observedFrom === null) {
            $rejected++;
            continue;
        }

        $resultTime = normalizeTimestamp(
            (string) ($record['ResultTime'] ?? '')
        );

        $aggregationType = trim((string) $record['AggType']);

        if ($aggregationType === '') {
            $rejected++;
            continue;
        }

        $dataCapture = nullableDecimal(
            (string) ($record['DataCapture'] ?? '')
        );

        $sourceRecordKey = createSourceRecordKey(
            $samplingPoint,
            'PM2.5',
            $observedFrom,
            $observedTo,
            $aggregationType
        );

        $rawRecord = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        $measurementStatement->execute([
            $stations[$stationCode],
            $runId,
            $samplingPoint,
            $observedFrom,
            $observedTo,
            $resultTime,
            $value,
            $unit,
            $aggregationType,
            (string) $record['Validity'],
            (string) $record['Verification'],
            $dataCapture,
            $dataset,
            $sourceRecordKey,
            $rawRecord,
        ]);

        $imported++;

        if (($imported + $rejected) % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();

            logMessage(
                "Processed {$downloaded}; accepted {$imported}; rejected {$rejected}"
            );
        }
    }

    fclose($file);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    finishIngestionRun(
        $pdo,
        $runId,
        'completed',
        $downloaded,
        $imported,
        $rejected
    );

    logMessage('Import completed successfully.');
    logMessage("Records downloaded: {$downloaded}");
    logMessage("Records imported/upserted: {$imported}");
    logMessage("Records rejected: {$rejected}");

} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($pdo instanceof PDO && $runId !== null) {
        try {
            finishIngestionRun(
                $pdo,
                $runId,
                'failed',
                $downloaded,
                $imported,
                $rejected,
                substr($exception->getMessage(), 0, 60000)
            );
        } catch (Throwable $updateError) {
            logMessage('Could not update ingestion run status.');
        }
    }

    fail($exception->getMessage());

} finally {
    if (is_file($csvPath)) {
        unlink($csvPath);
    }
}
