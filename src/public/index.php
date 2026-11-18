<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/app.php';

$requestStartedAt = microtime(true);
$metricsFile = '/tmp/clean-air-metrics/metrics.json';

/**
 * Map URLs to fixed route labels.
 * Never use query strings or arbitrary URLs as metric labels.
 */
function metricRoute(string $path): string
{
    return match ($path) {
        '/api/v1/health' => '/api/v1/health',
        '/api/v1/health/database' => '/api/v1/health/database',
        '/api/v1/health/ready' => '/api/v1/health/ready',
        '/api/v1/air-quality/cleanest' => '/api/v1/air-quality/cleanest',
        '/api/v1/air-quality/daily' => '/api/v1/air-quality/daily',
        '/metrics' => '/metrics',
        default => 'other',
    };
}

/**
 * Record a completed HTTP response in a shared, file-backed store.
 */
function recordMetric(
    string $method,
    string $route,
    int $status,
    float $duration,
    string $file
): void {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $handle = fopen($file, 'c+');

    if ($handle === false) {
        error_log('Unable to open metrics storage.');
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            error_log('Unable to lock metrics storage.');
            return;
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $metrics = json_decode($contents ?: '', true);

        if (!is_array($metrics)) {
            $metrics = [
                'requests' => [],
                'durations' => [],
            ];
        }

        $requestKey = json_encode([$method, $route, $status]);
        $durationKey = json_encode([$method, $route]);

        $metrics['requests'][$requestKey] =
            ($metrics['requests'][$requestKey] ?? 0) + 1;

        $buckets = [
            0.005, 0.01, 0.025, 0.05, 0.1,
            0.25, 0.5, 1, 2.5, 5, 10,
        ];

        if (!isset($metrics['durations'][$durationKey])) {
            $metrics['durations'][$durationKey] = [
                'buckets' => [],
                'count' => 0,
                'sum' => 0.0,
            ];
        }

        $durationMetrics = &$metrics['durations'][$durationKey];

        foreach ($buckets as $bucket) {
            $bucketKey = (string) $bucket;

            if ($duration <= $bucket) {
                $durationMetrics['buckets'][$bucketKey] =
                    ($durationMetrics['buckets'][$bucketKey] ?? 0) + 1;
            }
        }

        $durationMetrics['count']++;
        $durationMetrics['sum'] += $duration;

        rewind($handle);
        ftruncate($handle, 0);

        fwrite(
            $handle,
            json_encode($metrics, JSON_THROW_ON_ERROR)
        );

        fflush($handle);
        flock($handle, LOCK_UN);
    } catch (Throwable $exception) {
        error_log('Metrics recording failed: ' . $exception->getMessage());
    } finally {
        fclose($handle);
    }
}

/**
 * Send an HTTP response and record its metrics.
 */
function respond(int $statusCode, array $data): never
{
    global $method, $path, $requestStartedAt, $metricsFile;

    $route = metricRoute($path);

    if ($route !== '/metrics') {
        recordMetric(
            $method,
            $route,
            $statusCode,
            microtime(true) - $requestStartedAt,
            $metricsFile
        );
    }

    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Prometheus metrics endpoint.
if ($method === 'GET' && $path === '/metrics') {
    header_remove('Content-Type');
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

    $handle = fopen($metricsFile, 'c+');
    $metrics = [
        'requests' => [],
        'durations' => [],
    ];

    if ($handle !== false) {
        if (flock($handle, LOCK_SH)) {
            rewind($handle);
            $contents = stream_get_contents($handle);
            $stored = json_decode($contents ?: '', true);

            if (is_array($stored)) {
                $metrics = array_replace_recursive($metrics, $stored);
            }

            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }

    echo "# HELP clean_air_http_requests_total Total HTTP responses.\n";
    echo "# TYPE clean_air_http_requests_total counter\n";

    foreach ($metrics['requests'] as $key => $count) {
        [$requestMethod, $route, $status] = json_decode($key, true);

        printf(
            "clean_air_http_requests_total{method=\"%s\",route=\"%s\",status=\"%s\"} %d\n",
            $requestMethod,
            $route,
            $status,
            $count
        );
    }

    echo "\n# HELP clean_air_http_request_duration_seconds HTTP response duration.\n";
    echo "# TYPE clean_air_http_request_duration_seconds histogram\n";

    $buckets = [
        0.005, 0.01, 0.025, 0.05, 0.1,
        0.25, 0.5, 1, 2.5, 5, 10,
    ];

    foreach ($metrics['durations'] as $key => $duration) {
        [$requestMethod, $route] = json_decode($key, true);

        foreach ($buckets as $bucket) {
            $bucketKey = (string) $bucket;
            $count = $duration['buckets'][$bucketKey] ?? 0;

            printf(
                "clean_air_http_request_duration_seconds_bucket{method=\"%s\",route=\"%s\",le=\"%s\"} %d\n",
                $requestMethod,
                $route,
                $bucket,
                $count
            );
        }

        printf(
            "clean_air_http_request_duration_seconds_bucket{method=\"%s\",route=\"%s\",le=\"+Inf\"} %d\n",
            $requestMethod,
            $route,
            $duration['count']
        );

        printf(
            "clean_air_http_request_duration_seconds_sum{method=\"%s\",route=\"%s\"} %.6f\n",
            $requestMethod,
            $route,
            $duration['sum']
        );

        printf(
            "clean_air_http_request_duration_seconds_count{method=\"%s\",route=\"%s\"} %d\n",
            $requestMethod,
            $route,
            $duration['count']
        );
    }

    echo "\n# HELP clean_air_app_info Clean Air Finder application information.\n";
    echo "# TYPE clean_air_app_info gauge\n";
    echo "clean_air_app_info{environment=\"" .
        htmlspecialchars($config['app']['env'], ENT_QUOTES, 'UTF-8') .
        "\"} 1\n";

    exit;
}

// Application readiness: verify database connectivity and required tables.
if ($method === 'GET' && $path === '/api/v1/health/ready') {
    try {
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
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $requiredTables = [
            'cities',
            'stations',
            'measurements',
            'ingestion_runs',
            'daily_station_air_quality',
            'daily_city_air_quality',
        ];

        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = ?'
        );

        $missingTables = [];

        foreach ($requiredTables as $table) {
            $statement->execute([$table]);

            if ((int) $statement->fetchColumn() !== 1) {
                $missingTables[] = $table;
            }
        }

        if ($missingTables !== []) {
            respond(503, [
                'status' => 'not_ready',
                'database' => 'connected',
                'missing_tables' => $missingTables,
            ]);
        }

        respond(200, [
            'status' => 'ready',
            'database' => 'connected',
            'required_tables' => count($requiredTables),
        ]);
    } catch (Throwable $exception) {
        error_log('Readiness check failed: ' . $exception->getMessage());

        respond(503, [
            'status' => 'not_ready',
            'database' => 'unavailable',
        ]);
    }
}

// Basic health check: confirms PHP and Nginx are serving requests.
if ($method === 'GET' && $path === '/api/v1/health') {
    respond(200, [
        'status' => 'ok',
        'application' => 'Clean Air Finder',
        'environment' => $config['app']['env'],
        'timestamp' => gmdate('c'),
    ]);
}

// Database check: confirms PHP can connect to MySQL.
if ($method === 'GET' && $path === '/api/v1/health/database') {
    try {
        $db = $config['database'];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        $pdo = new PDO(
            $dsn,
            $db['user'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );

        $pdo->query('SELECT 1');

        respond(200, [
            'status' => 'ok',
            'database' => 'connected',
        ]);
    } catch (Throwable $exception) {
        error_log('Database health check failed: ' . $exception->getMessage());

        respond(503, [
            'status' => 'error',
            'database' => 'unavailable',
        ]);
    }
}


// GET /api/v1/air-quality/cleanest?from=YYYY-MM-DD&to=YYYY-MM-DD

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $path === '/api/v1/air-quality/cleanest'
) {
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $isValidDate = static function (string $date): bool {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone('UTC')
        );

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    };

    if (!$isValidDate($from) || !$isValidDate($to) || $from > $to) {
        respond(400, [
            'error' => 'Invalid date range. Use YYYY-MM-DD and ensure from <= to.',
        ]);
    }

    $startDate = new DateTimeImmutable($from, new DateTimeZone('UTC'));
    $endDate = new DateTimeImmutable($to, new DateTimeZone('UTC'));

    $calendarDays = (int) $startDate->diff($endDate)->days + 1;

    if ($calendarDays > 366) {
        respond(400, [
            'error' => 'The requested date range cannot exceed 366 days.',
        ]);
    }

    $minimumCoverage = 75.0;
    $minimumEligibleDays = (int) ceil(
        $calendarDays * $minimumCoverage / 100
    );

    $db = $config['database'];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $sql = "
            SELECT
                c.id AS city_id,
                c.name AS city_name,

                COUNT(d.id) AS eligible_days,

                ROUND(AVG(d.average_value), 3)
                    AS period_average,

                ROUND(AVG(d.coverage_percent), 2)
                    AS average_daily_coverage,

                COALESCE(ss.stations_count, 0)
                    AS stations_count

            FROM cities c

            LEFT JOIN daily_city_air_quality d
                ON d.city_id = c.id
                AND d.pollutant_code = 'PM2.5'
                AND d.measurement_date BETWEEN :from_city AND :to_city

            LEFT JOIN (
                SELECT
                    s.city_id,
                    COUNT(DISTINCT ds.station_id) AS stations_count

                FROM daily_station_air_quality ds

                INNER JOIN stations s
                    ON s.id = ds.station_id

                WHERE ds.pollutant_code = 'PM2.5'
                    AND ds.is_eligible = 1
                    AND s.is_active = 1
                    AND s.city_id IS NOT NULL
                    AND s.comparison_group = 'urban_background'
                    AND ds.measurement_date
                        BETWEEN :from_station AND :to_station

                GROUP BY s.city_id
            ) ss ON ss.city_id = c.id

            GROUP BY
                c.id,
                c.name,
                ss.stations_count
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            'from_city' => $from,
            'to_city' => $to,
            'from_station' => $from,
            'to_station' => $to,
        ]);

        $rows = $stmt->fetchAll();

        $cities = [];

        foreach ($rows as $row) {
            $eligibleDays = (int) $row['eligible_days'];

            $periodCoverage = round(
                ($eligibleDays / $calendarDays) * 100,
                2
            );

            $hasEnoughData =
                $eligibleDays >= $minimumEligibleDays
                && (int) $row['stations_count'] > 0;

            $cities[] = [
                'city_id' => (int) $row['city_id'],
                'city' => $row['city_name'],
                'rank' => null,
                'status' => $hasEnoughData
                    ? 'ranked'
                    : 'insufficient_data',
                'average_pm25' => $row['period_average'] !== null
                    ? (float) $row['period_average']
                    : null,
                'unit' => 'ug/m3',
                'eligible_days' => $eligibleDays,
                'calendar_days' => $calendarDays,
                'period_coverage_percent' => $periodCoverage,
                'average_daily_coverage_percent' =>
                    $row['average_daily_coverage'] !== null
                        ? (float) $row['average_daily_coverage']
                        : null,
                'stations_count' => (int) $row['stations_count'],
            ];
        }

        // Ranked cities first, lowest PM2.5 average first.
        usort($cities, static function (array $a, array $b): int {
            if ($a['status'] !== $b['status']) {
                return $a['status'] === 'ranked' ? -1 : 1;
            }

            if ($a['average_pm25'] === null) {
                return $b['average_pm25'] === null ? 0 : 1;
            }

            if ($b['average_pm25'] === null) {
                return -1;
            }

            return $a['average_pm25'] <=> $b['average_pm25'];
        });

        $rank = 0;

        foreach ($cities as &$city) {
            if ($city['status'] === 'ranked') {
                $city['rank'] = ++$rank;
            }
        }
        unset($city);

        respond(200, [
            'status' => 'success',
            'data' => [
                'pollutant' => 'PM2.5',
                'unit' => 'ug/m3',
                'period' => [
                    'from' => $from,
                    'to' => $to,
                    'calendar_days' => $calendarDays,
                ],
                'methodology' => [
                    'comparison_group' => 'urban_background',
                    'daily_minimum_coverage_percent' => 50,
                    'period_minimum_coverage_percent' => $minimumCoverage,
                    'minimum_eligible_days' => $minimumEligibleDays,
                    'aggregation' => 'Average of eligible daily city averages',
                ],
                'cities' => $cities,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('Cleanest-city API error: ' . $e->getMessage());

        respond(500, [
            'error' => 'Unable to calculate city air quality.',
        ]);
    }
}

// GET /api/v1/air-quality/daily?city_id=1&from=YYYY-MM-DD&to=YYYY-MM-DD

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $path === '/api/v1/air-quality/daily'
) {
    $cityId = filter_var(
        $_GET['city_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $validDate = static function (string $date): bool {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date;
    };

    if (!$cityId || !$validDate($from) || !$validDate($to) || $from > $to) {
        respond(400, [
            'error' => 'Valid city_id, from and to parameters are required.',
        ]);
    }

    $days = (int) (
        new DateTimeImmutable($from)
    )->diff(new DateTimeImmutable($to))->days + 1;

    if ($days > 366) {
        respond(400, [
            'error' => 'The requested date range cannot exceed 366 days.',
        ]);
    }

    try {
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

        $sql = "
            SELECT
                c.id AS city_id,
                c.name AS city,
                d.measurement_date,
                d.average_value,
                d.unit,
                d.stations_count,
                d.coverage_percent
            FROM daily_city_air_quality d
            INNER JOIN cities c ON c.id = d.city_id
            WHERE d.city_id = :city_id
              AND d.pollutant_code = 'PM2.5'
              AND d.measurement_date BETWEEN :date_from AND :date_to
            ORDER BY d.measurement_date
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'city_id' => $cityId,
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $rows = $stmt->fetchAll();

        $points = array_map(static function (array $row): array {
            return [
                'date' => $row['measurement_date'],
                'average_pm25' => (float) $row['average_value'],
                'unit' => $row['unit'],
                'stations_count' => (int) $row['stations_count'],
                'coverage_percent' => (float) $row['coverage_percent'],
            ];
        }, $rows);

        respond(200, [
            'status' => 'success',
            'data' => [
                'city_id' => (int) $cityId,
                'city' => $rows[0]['city'] ?? null,
                'pollutant' => 'PM2.5',
                'unit' => 'ug/m3',
                'period' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'points' => $points,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('Daily air-quality API error: ' . $e->getMessage());

        respond(500, [
            'error' => 'Unable to retrieve daily air-quality data.',
        ]);
    }
}


respond(404, [
    'error' => 'not_found',
    'message' => 'The requested endpoint does not exist.',
]);
