ALTER TABLE tenants
    MODIFY COLUMN status ENUM('pending', 'active', 'rejected', 'inactive')
    NOT NULL DEFAULT 'pending';
