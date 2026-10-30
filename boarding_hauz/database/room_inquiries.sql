-- Safely add room-specific inquiry fields to the existing public_inquiries table.
-- Compatible with MySQL 5.7 and safe to run more than once.

SET @room_inquiries_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'public_inquiries' AND COLUMN_NAME = 'room_id') = 0,
    'ALTER TABLE public_inquiries ADD COLUMN room_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE room_inquiries_statement FROM @room_inquiries_sql;
EXECUTE room_inquiries_statement;
DEALLOCATE PREPARE room_inquiries_statement;

SET @room_inquiries_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'public_inquiries' AND COLUMN_NAME = 'target_move_in_date') = 0,
    'ALTER TABLE public_inquiries ADD COLUMN target_move_in_date DATE NULL AFTER room_id',
    'SELECT 1'
);
PREPARE room_inquiries_statement FROM @room_inquiries_sql;
EXECUTE room_inquiries_statement;
DEALLOCATE PREPARE room_inquiries_statement;

SET @room_inquiries_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'public_inquiries' AND COLUMN_NAME = 'expected_stay_duration') = 0,
    'ALTER TABLE public_inquiries ADD COLUMN expected_stay_duration VARCHAR(100) NULL AFTER target_move_in_date',
    'SELECT 1'
);
PREPARE room_inquiries_statement FROM @room_inquiries_sql;
EXECUTE room_inquiries_statement;
DEALLOCATE PREPARE room_inquiries_statement;

SET @room_inquiries_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'public_inquiries' AND INDEX_NAME = 'idx_public_inquiries_room_id') = 0,
    'ALTER TABLE public_inquiries ADD INDEX idx_public_inquiries_room_id (room_id)',
    'SELECT 1'
);
PREPARE room_inquiries_statement FROM @room_inquiries_sql;
EXECUTE room_inquiries_statement;
DEALLOCATE PREPARE room_inquiries_statement;

SET @room_inquiries_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'public_inquiries'
       AND CONSTRAINT_NAME = 'fk_public_inquiries_room'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE public_inquiries ADD CONSTRAINT fk_public_inquiries_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE room_inquiries_statement FROM @room_inquiries_sql;
EXECUTE room_inquiries_statement;
DEALLOCATE PREPARE room_inquiries_statement;
