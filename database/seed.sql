
-- ============================================================
-- Clean Air Finder
-- Initial Bulgarian cities and EEA PM2.5 monitoring stations
-- MySQL 8+
-- ============================================================

-- ------------------------------------------------------------
-- 1. Cities
-- Coordinates are approximate city-centre coordinates.
-- ------------------------------------------------------------

INSERT INTO cities (
    name,
    slug,
    country_code,
    latitude,
    longitude
) VALUES
    ('Sofia', 'sofia', 'BG', 42.6977, 23.3219),
    ('Plovdiv', 'plovdiv', 'BG', 42.1354, 24.7453),
    ('Varna', 'varna', 'BG', 43.2141, 27.9147),
    ('Burgas', 'burgas', 'BG', 42.5048, 27.4626),
    ('Ruse', 'ruse', 'BG', 43.8356, 25.9657),
    ('Stara Zagora', 'stara-zagora', 'BG', 42.4258, 25.6345),
    ('Veliko Tarnovo', 'veliko-tarnovo', 'BG', 43.0757, 25.6172),
    ('Pernik', 'pernik', 'BG', 42.6052, 23.0378)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    country_code = VALUES(country_code),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude);


-- ------------------------------------------------------------
-- 2. Monitoring stations
--
-- Comparison groups:
-- urban_background
-- suburban_background
-- traffic_urban
-- rural_regional
-- rural_nearcity
--
-- BG0053R is outside the eight selected cities.
-- BG0070A is Kopitoto, classified rural-near-city.
-- BG0012A has national code Vn2; its exact station name
-- and coordinates still need confirmation.
-- ------------------------------------------------------------

INSERT INTO stations (
    city_id,
    eea_station_code,
    national_station_code,
    station_name,
    station_type,
    station_area,
    comparison_group,
    latitude,
    longitude,
    metadata_source,
    metadata_verified_at,
    is_active
)
SELECT
    c.id,
    seed.eea_station_code,
    seed.national_station_code,
    seed.station_name,
    seed.station_type,
    seed.station_area,
    seed.comparison_group,
    seed.latitude,
    seed.longitude,
    seed.metadata_source,
    CURRENT_TIMESTAMP,
    TRUE
FROM (
    SELECT
        'sofia' AS city_slug,
        'BG0050A' AS eea_station_code,
        NULL AS national_station_code,
        'AMS Hipodruma - Sofia' AS station_name,
        'background' AS station_type,
        'urban' AS station_area,
        'urban_background' AS comparison_group,
        42.6806 AS latitude,
        23.2968 AS longitude,
        'EEA station metadata and spatial layer' AS metadata_source

    UNION ALL SELECT
        'sofia', 'BG0070A', NULL,
        'AMS Kopitoto - Sofia',
        'background', 'rural-nearcity',
        'rural_nearcity',
        42.6372, 23.2439,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        'plovdiv', 'BG0051A', NULL,
        'AMS Kamenitsa - Plovdiv',
        'background', 'urban',
        'urban_background',
        42.1429, 24.7652,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        'varna', 'BG0075A', NULL,
        'SOU Angel Kanchev - Varna',
        'background', 'urban',
        'urban_background',
        43.2244, 27.9157,
        'EEA measurements and manually verified station identity'

    UNION ALL SELECT
        'varna', 'BG0012A', 'Vn2',
        'Varna - BG0012A (station name pending confirmation)',
        'traffic', 'urban',
        'traffic_urban',
        NULL, NULL,
        'EEA station metadata TSV'

    UNION ALL SELECT
        'ruse', 'BG0045A', NULL,
        'AMS Vazrazhdane - Ruse',
        'background', 'urban',
        'urban_background',
        43.8514, 25.9641,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        'stara-zagora', 'BG0039A', NULL,
        'RIEW - Stara Zagora',
        'background', 'urban',
        'urban_background',
        42.4342, 25.6315,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        'veliko-tarnovo', 'BG0020A', NULL,
        'RIEW - Veliko Tarnovo',
        'background', 'suburban',
        'suburban_background',
        43.0742, 25.6049,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        'pernik', 'BG0046A', NULL,
        'Tsarkva - Pernik',
        'background', 'suburban',
        'suburban_background',
        42.5783, 23.1140,
        'EEA station metadata and spatial layer'

    UNION ALL SELECT
        NULL, 'BG0053R', NULL,
        'AMS Rozhen Peak',
        'background', 'rural-regional',
        'rural_regional',
        41.6943, 24.7381,
        'EEA station metadata and spatial layer'
) AS seed
LEFT JOIN cities c
    ON c.slug = seed.city_slug

ON DUPLICATE KEY UPDATE
    city_id = VALUES(city_id),
    national_station_code = VALUES(national_station_code),
    station_name = VALUES(station_name),
    station_type = VALUES(station_type),
    station_area = VALUES(station_area),
    comparison_group = VALUES(comparison_group),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    metadata_source = VALUES(metadata_source),
    metadata_verified_at = CURRENT_TIMESTAMP,
    is_active = TRUE;
