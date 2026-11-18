
CREATE TABLE cities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    country_code CHAR(2) NOT NULL DEFAULT 'BG',
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE stations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    city_id BIGINT UNSIGNED NULL,

    eea_station_code VARCHAR(50) NOT NULL UNIQUE,
    national_station_code VARCHAR(100) NULL,
    station_name VARCHAR(255) NOT NULL,

    station_type VARCHAR(50) NULL,
    station_area VARCHAR(50) NULL,
    station_class VARCHAR(50) NULL,
    comparison_group VARCHAR(50) NULL,

    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,

    metadata_source VARCHAR(100) NULL,
    metadata_verified_at DATETIME NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_stations_city
        FOREIGN KEY (city_id) REFERENCES cities(id)
        ON DELETE SET NULL,

    INDEX idx_stations_city (city_id),
    INDEX idx_stations_comparison (comparison_group),
    INDEX idx_stations_type_area (station_type, station_area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE ingestion_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    source VARCHAR(50) NOT NULL DEFAULT 'EEA',
    dataset VARCHAR(50) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,

    status ENUM('running', 'completed', 'failed')
        NOT NULL DEFAULT 'running',

    records_downloaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
    records_imported BIGINT UNSIGNED NOT NULL DEFAULT 0,
    records_rejected BIGINT UNSIGNED NOT NULL DEFAULT 0,

    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,

    error_message TEXT NULL,

    INDEX idx_ingestion_status (status),
    INDEX idx_ingestion_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE measurements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    station_id BIGINT UNSIGNED NOT NULL,
    ingestion_run_id BIGINT UNSIGNED NULL,

    sampling_point VARCHAR(255) NULL,
    pollutant_code VARCHAR(30) NOT NULL DEFAULT 'PM2.5',

    observed_from DATETIME(3) NOT NULL,
    observed_to DATETIME(3) NULL,
    result_time DATETIME(3) NULL,

    value DECIMAL(12, 5) NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'µg/m³',
    aggregation_type VARCHAR(30) NULL,

    validity VARCHAR(50) NULL,
    verification VARCHAR(50) NULL,
    data_capture DECIMAL(8, 3) NULL,

    source_dataset VARCHAR(50) NOT NULL,
    source_record_key CHAR(64) NOT NULL,

    raw_record JSON NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_measurements_station
        FOREIGN KEY (station_id) REFERENCES stations(id),

    CONSTRAINT fk_measurements_ingestion
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_measurement_source (source_record_key),

    INDEX idx_measurements_station_time
        (station_id, observed_from),

    INDEX idx_measurements_pollutant_time
        (pollutant_code, observed_from),

    INDEX idx_measurements_ingestion (ingestion_run_id),
    INDEX idx_measurements_sampling_time
        (sampling_point, observed_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE daily_city_air_quality (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    city_id BIGINT UNSIGNED NOT NULL,
    pollutant_code VARCHAR(30) NOT NULL DEFAULT 'PM2.5',

    measurement_date DATE NOT NULL,

    average_value DECIMAL(12, 5) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'µg/m³',

    stations_count INT UNSIGNED NOT NULL DEFAULT 0,
    valid_hours INT UNSIGNED NOT NULL DEFAULT 0,
    expected_hours INT UNSIGNED NOT NULL DEFAULT 0,
    coverage_percent DECIMAL(6, 2) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_daily_city
        FOREIGN KEY (city_id) REFERENCES cities(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_city_daily_pollutant (
        city_id,
        pollutant_code,
        measurement_date
    ),

    INDEX idx_daily_date_value (
        measurement_date,
        pollutant_code,
        average_value
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Eligible station-days are the input to city-level aggregation.
CREATE TABLE daily_station_air_quality (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id BIGINT UNSIGNED NOT NULL,
    pollutant_code VARCHAR(30) NOT NULL DEFAULT 'PM2.5',
    measurement_date DATE NOT NULL,
    average_value DECIMAL(12, 5) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'ug/m3',
    valid_hours SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expected_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    coverage_percent DECIMAL(6, 2) NOT NULL DEFAULT 0,
    is_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_station_daily (station_id, pollutant_code, measurement_date),
    INDEX idx_station_daily_date (measurement_date),
    INDEX idx_station_daily_eligible (measurement_date, is_eligible),
    CONSTRAINT fk_daily_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
