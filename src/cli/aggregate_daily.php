<?php
declare(strict_types=1);

/**
 * Daily PM2.5 aggregation.
 *
 * Usage:
 * php aggregate_daily.php --from=2026-10-01 --to=2026-11-15
 */

$config = require __DIR__ . '/../config/app.php';

function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

function argument(string $name, ?string $default = null): ?string
{
    global $argv;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function validateDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

$dateFrom = argument('from');
$dateTo = argument('to');

if (!$dateFrom || !$dateTo ||
    !validateDate($dateFrom) ||
    !validateDate($dateTo) ||
    $dateFrom > $dateTo
) {
    fwrite(STDERR, "Usage: php aggregate_daily.php --from=YYYY-MM-DD --to=YYYY-MM-DD\n");
    exit(1);
}

$db = $config['database'];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset']
);

$pdo = new PDO($dsn, $db['user'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

try {
    logMessage("Connected to MySQL.");
    logMessage("Aggregating $dateFrom through $dateTo.");

    $pdo->beginTransaction();

    $deleteStationDays = $pdo->prepare("
    DELETE FROM daily_station_air_quality
    WHERE pollutant_code = 'PM2.5'
      AND measurement_date BETWEEN :date_from AND :date_to
    ");

    $deleteStationDays->execute([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    /*
     * 1. Station daily averages.
     *
     * Accepted EEA and Bulgarian national hourly PM2.5 measurements are included.
     * National observations take precedence if both sources report the same hour.
     * Do not count overlapping station-hours twice.
     * 12 valid hours out of 24 = 50% coverage.
     */
    $stationSql = "
        INSERT INTO daily_station_air_quality (
            station_id,
            pollutant_code,
            measurement_date,
            average_value,
            unit,
            valid_hours,
            expected_hours,
            coverage_percent,
            is_eligible
        )
        SELECT
            m.station_id,
            'PM2.5',
            DATE(m.observed_from),
            AVG(m.value),
            'ug/m3',
            COUNT(*),
            24,
            ROUND(COUNT(*) / 24 * 100, 2),
            (COUNT(*) >= 12)
        FROM measurements m
        INNER JOIN stations s
            ON s.id = m.station_id
        WHERE m.pollutant_code = 'PM2.5'
          AND (
              (m.source_dataset = 'BG_NATIONAL_PM25_HOURLY'
               AND m.validity = 'national_export'
               AND m.verification = 'not_provided')
              OR
              (m.source_dataset <> 'BG_NATIONAL_PM25_HOURLY'
               AND m.validity = '1' AND m.verification = '1'
               AND NOT EXISTS (
                   SELECT 1 FROM measurements n
                   WHERE n.station_id = m.station_id
                     AND n.observed_from = m.observed_from
                     AND n.pollutant_code = 'PM2.5'
                     AND n.source_dataset = 'BG_NATIONAL_PM25_HOURLY'
                     AND n.validity = 'national_export'
                     AND n.verification = 'not_provided'
                     AND n.aggregation_type = 'hour'
                     AND n.value IS NOT NULL AND n.value >= 0
               ))
          )
          AND m.aggregation_type = 'hour'
          AND m.unit IN ('ug/m3', 'ug.m-3', 'µg/m³')
          AND m.value IS NOT NULL
          AND m.value >= 0
          AND m.observed_from >= :date_from
          AND m.observed_from < DATE_ADD(:date_to, INTERVAL 1 DAY)
          AND s.is_active = 1
        GROUP BY
            m.station_id,
            DATE(m.observed_from)
        ON DUPLICATE KEY UPDATE
            average_value = VALUES(average_value),
            unit = VALUES(unit),
            valid_hours = VALUES(valid_hours),
            expected_hours = VALUES(expected_hours),
            coverage_percent = VALUES(coverage_percent),
            is_eligible = VALUES(is_eligible)
    ";

    $stmt = $pdo->prepare($stationSql);
    $stmt->execute([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    logMessage("Station daily aggregates calculated: " . $stmt->rowCount());

    $deleteCityDays = $pdo->prepare("
    DELETE FROM daily_city_air_quality
    WHERE pollutant_code = 'PM2.5'
      AND measurement_date BETWEEN :date_from AND :date_to
    ");

    $deleteCityDays->execute([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    /*
     * 2. City daily averages.
     *
     * Only eligible urban-background station-days contribute.
     * Each station receives equal weight in the city average.
     */
    $citySql = "
        INSERT INTO daily_city_air_quality (
            city_id,
            pollutant_code,
            measurement_date,
            average_value,
            unit,
            stations_count,
            valid_hours,
            expected_hours,
            coverage_percent
        )
        SELECT
            s.city_id,
            d.pollutant_code,
            d.measurement_date,
            AVG(d.average_value),
            'ug/m3',
            COUNT(*),
            SUM(d.valid_hours),
            SUM(d.expected_hours),
            ROUND(
                SUM(d.valid_hours) / SUM(d.expected_hours) * 100,
                2
            )
        FROM daily_station_air_quality d
        INNER JOIN stations s
            ON s.id = d.station_id
        WHERE d.pollutant_code = 'PM2.5'
          AND d.is_eligible = 1
          AND s.is_active = 1
          AND s.city_id IS NOT NULL
          AND s.comparison_group = 'urban_background'
          AND d.measurement_date >= :date_from
          AND d.measurement_date <= :date_to
        GROUP BY
            s.city_id,
            d.pollutant_code,
            d.measurement_date
        ON DUPLICATE KEY UPDATE
            average_value = VALUES(average_value),
            unit = VALUES(unit),
            stations_count = VALUES(stations_count),
            valid_hours = VALUES(valid_hours),
            expected_hours = VALUES(expected_hours),
            coverage_percent = VALUES(coverage_percent)
    ";

    $stmt = $pdo->prepare($citySql);
    $stmt->execute([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    logMessage("City daily aggregates calculated: " . $stmt->rowCount());

    $pdo->commit();

    logMessage("Daily aggregation completed successfully.");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}
