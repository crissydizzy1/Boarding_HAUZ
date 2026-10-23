-- One-time migration for an existing Boarding Hauz database.
-- Back up the database first. Existing administrator accounts become landlords,
-- each with one generated main branch; review the generated names and locations.
-- Ensure the public_inquiries and maintenance_requests tables have already been installed.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'super_admin', 'landlord', 'tenant')
        NOT NULL DEFAULT 'tenant';

UPDATE users SET role = 'landlord' WHERE role = 'admin';

ALTER TABLE users
    MODIFY COLUMN role ENUM('super_admin', 'landlord', 'tenant')
        NOT NULL DEFAULT 'tenant';

CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    branch_name VARCHAR(120) NOT NULL,
    location VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_branches_landlord_id (landlord_id),
    CONSTRAINT fk_branches_landlord FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO branches (landlord_id, branch_name, location)
SELECT id, CONCAT(username, ' - Main Branch'), 'Update location'
FROM users
WHERE role = 'landlord';

ALTER TABLE rooms ADD COLUMN branch_id INT NULL AFTER id;
ALTER TABLE tenants ADD COLUMN branch_id INT NULL AFTER id;
ALTER TABLE payments ADD COLUMN branch_id INT NULL AFTER id;
ALTER TABLE public_inquiries ADD COLUMN branch_id INT NULL AFTER id;
ALTER TABLE maintenance_requests ADD COLUMN branch_id INT NULL AFTER id;

ALTER TABLE rooms ENGINE=InnoDB;
ALTER TABLE tenants ENGINE=InnoDB;
ALTER TABLE payments ENGINE=InnoDB;
ALTER TABLE public_inquiries ENGINE=InnoDB;
ALTER TABLE maintenance_requests ENGINE=InnoDB;

UPDATE rooms r
JOIN branches b ON b.landlord_id = r.admin_id
SET r.branch_id = b.id;

UPDATE tenants t
JOIN branches b ON b.landlord_id = t.admin_id
SET t.branch_id = b.id;

UPDATE payments p
JOIN tenants t ON t.id = p.tenant_id
SET p.branch_id = t.branch_id;

UPDATE public_inquiries pi
JOIN rooms r ON r.id = pi.room_id
SET pi.branch_id = r.branch_id
WHERE pi.room_id IS NOT NULL;

UPDATE maintenance_requests mr
LEFT JOIN rooms r ON r.id = mr.room_id
JOIN tenants t ON t.id = mr.tenant_id
SET mr.branch_id = COALESCE(r.branch_id, t.branch_id);

ALTER TABLE rooms
    MODIFY COLUMN branch_id INT NOT NULL,
    ADD INDEX idx_rooms_branch_id (branch_id),
    DROP INDEX room_number,
    ADD UNIQUE KEY uq_rooms_branch_room_number (branch_id, room_number),
    ADD CONSTRAINT fk_rooms_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE tenants
    ADD INDEX idx_tenants_branch_id (branch_id),
    ADD INDEX idx_tenants_room_status (room_id, status),
    ADD CONSTRAINT fk_tenants_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE payments
    MODIFY COLUMN branch_id INT NOT NULL,
    ADD INDEX idx_payments_branch_id (branch_id),
    ADD CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE public_inquiries
    ADD INDEX idx_public_inquiries_branch_id (branch_id),
    ADD CONSTRAINT fk_public_inquiries_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE maintenance_requests
    ADD INDEX idx_maintenance_branch_id (branch_id),
    ADD CONSTRAINT fk_maintenance_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- A super admin must be created explicitly by a trusted database operator.
-- Promote a trusted, active user account after verifying its identity:
-- UPDATE users SET role = 'super_admin', status = 'active' WHERE id = <trusted_user_id>;
