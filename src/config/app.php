<?php

return [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'local',
        'debug' => filter_var(
            getenv('APP_DEBUG') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),
        'minimum_coverage_percent' => 50,
    ],

    'database' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'clean_air_finder',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
];