-- Bonsai Uptime Monitor — schema
-- Run once: mysql -u youruser -p yourdb < schema.sql

CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    check_interval_seconds INT NOT NULL DEFAULT 300,
    expected_status_code INT NOT NULL DEFAULT 200,
    timeout_seconds INT NOT NULL DEFAULT 15,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    current_status ENUM('up', 'down', 'unknown') NOT NULL DEFAULT 'unknown',
    last_checked_at DATETIME NULL,
    monitor_ssl TINYINT(1) NOT NULL DEFAULT 1,
    ssl_expires_at DATETIME NULL,
    ssl_checked_at DATETIME NULL,
    ssl_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
    monitor_domain TINYINT(1) NOT NULL DEFAULT 1,
    domain_expires_at DATETIME NULL,
    domain_checked_at DATETIME NULL,
    domain_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
    agency ENUM('Bonsai Digital', 'SPS Marketing', 'Webheads', 'TTNG', 'Realityhouse', 'Seedbed', 'twohundred.digital') NOT NULL DEFAULT 'Bonsai Digital',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_paths (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    path VARCHAR(255) NOT NULL,
    label VARCHAR(100) NULL,
    expected_status_code INT NOT NULL DEFAULT 200,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    current_status ENUM('up', 'down', 'unknown') NOT NULL DEFAULT 'unknown',
    last_checked_at DATETIME NULL,
    last_http_code INT NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    site_path_id INT NULL,
    checked_at DATETIME NOT NULL,
    status ENUM('up', 'down') NOT NULL,
    http_code INT NULL,
    response_time_ms INT NULL,
    error_message VARCHAR(500) NULL,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (site_path_id) REFERENCES site_paths(id) ON DELETE CASCADE,
    INDEX idx_site_time (site_id, checked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    duration_seconds INT NULL,
    cause VARCHAR(500) NULL,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    INDEX idx_site_started (site_id, started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB;

-- Example seed rows — replace with your real sites, or add via a small admin insert later
-- INSERT INTO sites (name, url) VALUES ('Example Client Site', 'https://example.com');

-- --------------------------------------------------------------------------
-- Migration: run this once against an existing database that predates SSL/
-- domain expiry tracking. CREATE TABLE IF NOT EXISTS above will not alter an
-- already-existing `sites` table, so these columns need adding by hand.
-- --------------------------------------------------------------------------
-- ALTER TABLE sites
--     ADD COLUMN monitor_ssl TINYINT(1) NOT NULL DEFAULT 1,
--     ADD COLUMN ssl_expires_at DATETIME NULL,
--     ADD COLUMN ssl_checked_at DATETIME NULL,
--     ADD COLUMN ssl_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
--     ADD COLUMN monitor_domain TINYINT(1) NOT NULL DEFAULT 1,
--     ADD COLUMN domain_expires_at DATETIME NULL,
--     ADD COLUMN domain_checked_at DATETIME NULL,
--     ADD COLUMN domain_alert_sent TINYINT(1) NOT NULL DEFAULT 0;

-- --------------------------------------------------------------------------
-- Migration: run this once against an existing database that predates the
-- agency field, so existing sites can be filtered by client agency.
-- --------------------------------------------------------------------------
-- ALTER TABLE sites
--     ADD COLUMN agency ENUM('Bonsai Digital', 'SPS Marketing', 'Webheads', 'Realityhouse', 'Seedbed') NOT NULL DEFAULT 'Bonsai Digital';

-- --------------------------------------------------------------------------
-- Migration: run this once against an existing database whenever
-- um_agencies() in includes/functions.php gains a new agency — the ENUM
-- must be kept in sync by hand, MODIFY COLUMN is additive-safe (existing
-- rows keep their value) as long as you only add options, never remove or
-- rename one that's still in use.
-- --------------------------------------------------------------------------
-- ALTER TABLE sites
--     MODIFY COLUMN agency ENUM('Bonsai Digital', 'SPS Marketing', 'Webheads', 'TTNG', 'Realityhouse', 'Seedbed') NOT NULL DEFAULT 'Bonsai Digital';

-- --------------------------------------------------------------------------
-- Migration: run this once against an existing database to add
-- twohundred.digital as an agency option.
-- --------------------------------------------------------------------------
-- ALTER TABLE sites
--     MODIFY COLUMN agency ENUM('Bonsai Digital', 'SPS Marketing', 'Webheads', 'TTNG', 'Realityhouse', 'Seedbed', 'twohundred.digital') NOT NULL DEFAULT 'Bonsai Digital';

-- --------------------------------------------------------------------------
-- Migration: run this once against an existing database that predates
-- multiple check paths per site (e.g. checking /wp-login.php or
-- /posts?per_page=1 in addition to the main URL). CREATE TABLE IF NOT EXISTS
-- above will not add the site_paths table or the new checks column to an
-- already-existing database, so both need adding by hand.
-- --------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS site_paths (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     site_id INT NOT NULL,
--     path VARCHAR(255) NOT NULL,
--     label VARCHAR(100) NULL,
--     expected_status_code INT NOT NULL DEFAULT 200,
--     is_active TINYINT(1) NOT NULL DEFAULT 1,
--     current_status ENUM('up', 'down', 'unknown') NOT NULL DEFAULT 'unknown',
--     last_checked_at DATETIME NULL,
--     last_http_code INT NULL,
--     last_error VARCHAR(500) NULL,
--     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
-- ) ENGINE=InnoDB;
--
-- ALTER TABLE checks
--     ADD COLUMN site_path_id INT NULL AFTER site_id,
--     ADD FOREIGN KEY (site_path_id) REFERENCES site_paths(id) ON DELETE CASCADE;
