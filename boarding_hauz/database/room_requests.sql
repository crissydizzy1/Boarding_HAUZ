SET @room_request_table_engine = (
    SELECT ENGINE
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
);
SET @room_request_engine_ddl = IF(
    COALESCE(@room_request_table_engine, '') <> 'InnoDB',
    'ALTER TABLE users ENGINE = InnoDB',
    'SELECT ''users already supports transactions'' AS migration_status'
);
PREPARE room_request_engine_statement FROM @room_request_engine_ddl;
EXECUTE room_request_engine_statement;
DEALLOCATE PREPARE room_request_engine_statement;

SET @room_request_table_engine = (
    SELECT ENGINE
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms'
);
SET @room_request_engine_ddl = IF(
    COALESCE(@room_request_table_engine, '') <> 'InnoDB',
    'ALTER TABLE rooms ENGINE = InnoDB',
    'SELECT ''rooms already supports transactions'' AS migration_status'
);
PREPARE room_request_engine_statement FROM @room_request_engine_ddl;
EXECUTE room_request_engine_statement;
DEALLOCATE PREPARE room_request_engine_statement;

SET @room_request_table_engine = (
    SELECT ENGINE
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
);
SET @room_request_engine_ddl = IF(
    COALESCE(@room_request_table_engine, '') <> 'InnoDB',
    'ALTER TABLE tenants ENGINE = InnoDB',
    'SELECT ''tenants already supports transactions'' AS migration_status'
);
PREPARE room_request_engine_statement FROM @room_request_engine_ddl;
EXECUTE room_request_engine_statement;
DEALLOCATE PREPARE room_request_engine_statement;

CREATE TABLE IF NOT EXISTS room_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX idx_room_requests_tenant_status (tenant_id, status),
    INDEX idx_room_requests_room_status (room_id, status),
    INDEX idx_room_requests_reviewed_by (reviewed_by),
    CONSTRAINT fk_room_requests_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_room_requests_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_room_requests_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @room_request_reviewer_fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_room_requests_reviewer'
      AND TABLE_NAME = 'room_requests'
);

SET @room_request_reviewer_fk_ddl = IF(
    @room_request_reviewer_fk_exists = 0,
    'ALTER TABLE room_requests ADD CONSTRAINT fk_room_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT ''room_requests reviewer foreign key already exists'' AS migration_status'
);

PREPARE room_request_reviewer_fk_statement FROM @room_request_reviewer_fk_ddl;
EXECUTE room_request_reviewer_fk_statement;
DEALLOCATE PREPARE room_request_reviewer_fk_statement;
