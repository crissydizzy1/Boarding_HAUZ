ALTER TABLE users
    ADD COLUMN gcash_account_name VARCHAR(100) NULL,
    ADD COLUMN gcash_number VARCHAR(32) NULL,
    ADD COLUMN maya_account_name VARCHAR(100) NULL,
    ADD COLUMN maya_number VARCHAR(32) NULL,
    ADD COLUMN bank_name VARCHAR(80) NULL,
    ADD COLUMN bank_account_name VARCHAR(100) NULL,
    ADD COLUMN bank_account_number VARCHAR(40) NULL;

ALTER TABLE rooms
    ADD COLUMN admin_id INT NOT NULL DEFAULT 3,
    ADD INDEX idx_rooms_admin_id (admin_id);

ALTER TABLE tenants
    ADD COLUMN admin_id INT NOT NULL DEFAULT 3,
    ADD INDEX idx_tenants_admin_id (admin_id);

UPDATE rooms SET admin_id = 3 WHERE admin_id IS NULL OR admin_id = 0;
UPDATE tenants SET admin_id = 3 WHERE admin_id IS NULL OR admin_id = 0;
