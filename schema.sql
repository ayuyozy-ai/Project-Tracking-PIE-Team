CREATE DATABASE IF NOT EXISTS project_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE project_tracker;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user', 'viewer', 'contributor') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    login_key CHAR(64) NOT NULL,
    failed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (login_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
    id CHAR(36) NOT NULL,
    code VARCHAR(80) NOT NULL DEFAULT '',
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    status VARCHAR(50) NOT NULL DEFAULT 'On Progress',
    priority VARCHAR(50) NOT NULL DEFAULT 'Medium',
    pic VARCHAR(100) NOT NULL DEFAULT '',
    area VARCHAR(100) NOT NULL DEFAULT '',
    start_date DATE NULL,
    end_date DATE NULL,
    progress INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    vendor VARCHAR(255) NOT NULL DEFAULT '',
    budget DECIMAL(18,2) NOT NULL DEFAULT 0,
    actual DECIMAL(18,2) NOT NULL DEFAULT 0,
    po VARCHAR(120) NOT NULL DEFAULT '',
    metadata JSON NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_projects_status (status),
    KEY idx_projects_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_files (
    id CHAR(36) NOT NULL,
    project_id CHAR(36) NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    stored_name VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_files_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_quotations (
    id CHAR(36) NOT NULL,
    project_id CHAR(36) NOT NULL,
    vendor VARCHAR(255) NOT NULL DEFAULT '',
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'Diterima',
    quotation_no VARCHAR(120) NOT NULL DEFAULT '',
    valid_until DATE NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quotation_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_stages (
    id CHAR(36) NOT NULL,
    project_id CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    pic VARCHAR(100) NOT NULL DEFAULT '',
    start_date DATE NULL,
    end_date DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Not Started',
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stage_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_milestones (
    id CHAR(36) NOT NULL,
    project_id CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    due_date DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_milestone_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_logs (
    id CHAR(36) NOT NULL,
    project_id CHAR(36) NOT NULL,
    actor VARCHAR(100) NOT NULL DEFAULT '',
    action TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_log_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(80) NOT NULL,
    value_json JSON NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (key_name, value_json) VALUES
('app', '{"appName":"PI Project Management","currency":"IDR","settingsAt":0}'),
('categories', '{"items":[{"name":"Improvement","color":"#2563eb"},{"name":"New Machine","color":"#f97316"}]}')
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json);
