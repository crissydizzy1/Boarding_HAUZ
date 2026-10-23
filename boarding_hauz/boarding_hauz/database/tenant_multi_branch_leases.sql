-- Run once after taking a verified full backup of boarding_hauz.
-- This preserves tenant profiles and legacy room assignment columns.
-- Existing historical leases cannot be reconstructed from the old one-room profile.

CREATE TABLE tenant_leases (
    id INT NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    monthly_rate DECIMAL(10,2) NOT NULL,
    move_in_date DATE NULL,
    ended_at DATETIME NULL,
    status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_leases_tenant_status (tenant_id, status),
    KEY idx_tenant_leases_room_status (room_id, status),
    CONSTRAINT fk_tenant_leases_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_leases_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenant_leases (tenant_id, room_id, monthly_rate, move_in_date, status)
SELECT t.id, r.id, r.monthly_rate, t.move_in_date, 'active'
FROM tenants t
INNER JOIN rooms r ON r.id = t.room_id AND r.branch_id = t.branch_id
WHERE t.status = 'active'
  AND t.room_id IS NOT NULL;

ALTER TABLE room_requests
    ADD COLUMN lease_id INT NULL AFTER room_id,
    ADD KEY idx_room_requests_lease_id (lease_id),
    ADD CONSTRAINT fk_room_requests_lease
        FOREIGN KEY (lease_id) REFERENCES tenant_leases(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

UPDATE room_requests rr
INNER JOIN tenant_leases l
    ON l.tenant_id = rr.tenant_id
   AND l.room_id = rr.room_id
   AND l.status = 'active'
SET rr.lease_id = l.id
WHERE rr.status = 'Approved';

ALTER TABLE payments ENGINE=InnoDB;

ALTER TABLE payments
    ADD COLUMN lease_id INT NULL AFTER tenant_id,
    ADD KEY idx_payments_lease_id (lease_id),
    ADD CONSTRAINT fk_payments_lease
        FOREIGN KEY (lease_id) REFERENCES tenant_leases(id)
        ON UPDATE CASCADE ON DELETE RESTRICT;
