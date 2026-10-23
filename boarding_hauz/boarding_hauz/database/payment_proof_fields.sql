SET @payment_reference_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'reference_number'
);
SET @payment_reference_column_ddl = IF(
    @payment_reference_column_exists = 0,
    'ALTER TABLE payments ADD COLUMN reference_number VARCHAR(100) NULL AFTER payment_method',
    'SELECT ''payments.reference_number already exists'' AS migration_status'
);
PREPARE payment_reference_column_statement FROM @payment_reference_column_ddl;
EXECUTE payment_reference_column_statement;
DEALLOCATE PREPARE payment_reference_column_statement;

SET @payment_proof_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'proof_path'
);
SET @payment_proof_column_ddl = IF(
    @payment_proof_column_exists = 0,
    'ALTER TABLE payments ADD COLUMN proof_path VARCHAR(255) NULL AFTER reference_number',
    'SELECT ''payments.proof_path already exists'' AS migration_status'
);
PREPARE payment_proof_column_statement FROM @payment_proof_column_ddl;
EXECUTE payment_proof_column_statement;
DEALLOCATE PREPARE payment_proof_column_statement;
