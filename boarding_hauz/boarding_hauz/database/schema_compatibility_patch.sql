-- One-time schema patch for the legacy Boarding Hauz database.
-- Back up the database before running. This patch preserves every existing
-- user row, role value, username, email, and password.
USE boarding_hauz;

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'super_admin', 'landlord', 'tenant')
        NOT NULL DEFAULT 'tenant',
    ADD COLUMN created_by INT NULL AFTER status,
    ADD INDEX idx_users_created_by (created_by);

CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    branch_name VARCHAR(120) NOT NULL,
    location VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_branches_landlord_id (landlord_id),
    CONSTRAINT fk_branches_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rooms
    ADD COLUMN branch_id INT NULL AFTER id,
    ADD COLUMN image_path VARCHAR(255) NULL AFTER status,
    ADD INDEX idx_rooms_branch_id (branch_id);

ALTER TABLE tenants
    ADD COLUMN branch_id INT NULL AFTER id,
    ADD INDEX idx_tenants_branch_id (branch_id),
    ADD INDEX idx_tenants_room_status (room_id, status);

ALTER TABLE payments
    ADD COLUMN branch_id INT NULL AFTER id,
    ADD INDEX idx_payments_branch_id (branch_id);

ALTER TABLE public_inquiries
    ADD COLUMN branch_id INT NULL AFTER id,
    ADD INDEX idx_public_inquiries_branch_id (branch_id);

ALTER TABLE maintenance_requests
    ADD COLUMN branch_id INT NULL AFTER id,
    ADD INDEX idx_maintenance_branch_id (branch_id);

-- Create one branch per existing admin/landlord that owns rooms or tenants.
-- No users are inserted, updated, or deleted.
INSERT INTO branches (landlord_id, branch_name, location)
SELECT u.id, CONCAT('Main Branch ', u.id), 'Update location'
FROM users u
LEFT JOIN branches b ON b.landlord_id = u.id
WHERE b.id IS NULL
  AND u.role IN ('admin', 'landlord')
  AND (
      EXISTS (SELECT 1 FROM rooms r WHERE r.admin_id = u.id)
      OR EXISTS (SELECT 1 FROM tenants t WHERE t.admin_id = u.id)
  );

UPDATE rooms r
INNER JOIN branches b ON b.landlord_id = r.admin_id
SET r.branch_id = b.id
WHERE r.branch_id IS NULL;

UPDATE tenants t
INNER JOIN branches b ON b.landlord_id = t.admin_id
SET t.branch_id = b.id
WHERE t.branch_id IS NULL;

UPDATE payments p
INNER JOIN tenants t ON t.id = p.tenant_id
SET p.branch_id = t.branch_id
WHERE p.branch_id IS NULL;

UPDATE public_inquiries pi
INNER JOIN rooms r ON r.id = pi.room_id
SET pi.branch_id = r.branch_id
WHERE pi.branch_id IS NULL
  AND pi.room_id IS NOT NULL;

UPDATE maintenance_requests mr
LEFT JOIN rooms r ON r.id = mr.room_id
INNER JOIN tenants t ON t.id = mr.tenant_id
SET mr.branch_id = COALESCE(r.branch_id, t.branch_id)
WHERE mr.branch_id IS NULL;
