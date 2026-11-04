CREATE TABLE IF NOT EXISTS datasets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  source ENUM('upload','paste','generated','derived','sample') NOT NULL DEFAULT 'paste',
  description VARCHAR(255) NULL,
  raw_content LONGTEXT NOT NULL,
  has_header TINYINT(1) NOT NULL DEFAULT 1,
  n_rows INT UNSIGNED NOT NULL DEFAULT 0,
  n_cols INT UNSIGNED NOT NULL DEFAULT 0,
  missing_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  profile_json LONGTEXT NULL,
  checksum CHAR(64) NOT NULL UNIQUE,
  auto_run TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_parent (parent_id),
  KEY idx_auto (auto_run, processed_at),
  CONSTRAINT fk_ds_parent FOREIGN KEY (parent_id) REFERENCES datasets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id CHAR(12) NULL,
  module ENUM('pipeline','explore','clean','stats','split') NOT NULL,
  dataset_id INT UNSIGNED NULL,
  params_json TEXT NULL,
  output LONGTEXT NULL,
  summary_json LONGTEXT NULL,
  status ENUM('ok','error') NOT NULL DEFAULT 'ok',
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_batch (batch_id),
  KEY idx_mod (module, created_at),
  KEY idx_ds (dataset_id),
  CONSTRAINT fk_run_ds FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
