-- Frozen copies of admin/reporting.php for one school year + term, so a report used for action
-- research can't drift when grades are corrected, more grades are published or enrollment
-- changes later. Counts only — `data` (JSON, see includes/reporting.php) never holds student
-- names. Rows are append-only: an automatic one is taken once a term's grades have gone 10 days
-- without any change, and an admin can take a manual one at any time; a newer snapshot never
-- replaces an older one.
CREATE TABLE report_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    term TINYINT UNSIGNED NOT NULL,
    trigger_type ENUM('auto','manual') NOT NULL,
    note VARCHAR(255) NULL,
    data_as_of TIMESTAMP NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data LONGTEXT NOT NULL,
    CONSTRAINT fk_rs_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    CONSTRAINT fk_rs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_rs_term_range CHECK (term BETWEEN 1 AND 3)
);
CREATE INDEX idx_rs_year_term ON report_snapshots(school_year_id, term, created_at);
