SET @room_capacity_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = 'capacity'
);

SET @room_capacity_ddl = IF(
    @room_capacity_exists = 0,
    'ALTER TABLE rooms ADD COLUMN capacity INT NOT NULL DEFAULT 1 AFTER room_type',
    'SELECT ''rooms.capacity already exists; no change made'' AS migration_status'
);

PREPARE room_capacity_statement FROM @room_capacity_ddl;
EXECUTE room_capacity_statement;
DEALLOCATE PREPARE room_capacity_statement;
