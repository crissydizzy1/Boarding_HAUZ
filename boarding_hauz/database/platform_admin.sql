-- Apply once to an existing Boarding Hauz database before deploying the
-- platform inquiry, reporting, and audit-log pages.
ALTER TABLE public_inquiries
    ADD COLUMN inquiry_type ENUM('property', 'platform', 'unassigned') NOT NULL DEFAULT 'property' AFTER room_id,
    ADD COLUMN admin_status ENUM('Pending', 'In Progress', 'Resolved') NOT NULL DEFAULT 'Pending' AFTER status,
    ADD COLUMN escalated_at DATETIME NULL AFTER admin_status,
    ADD COLUMN escalated_by INT NULL AFTER escalated_at,
    ADD COLUMN admin_reply TEXT NULL AFTER escalated_by,
    ADD INDEX idx_public_inquiries_admin_queue (inquiry_type, admin_status, created_at),
    ADD INDEX idx_public_inquiries_escalated (escalated_at, branch_id),
    ADD CONSTRAINT fk_public_inquiries_escalated_by
        FOREIGN KEY (escalated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- Existing submissions without a property association need Admin triage.
UPDATE public_inquiries pi
INNER JOIN rooms r ON r.id = pi.room_id
SET pi.branch_id = r.branch_id
WHERE pi.branch_id IS NULL;

UPDATE public_inquiries
SET inquiry_type = 'unassigned'
WHERE branch_id IS NULL AND room_id IS NULL;

CREATE TABLE admin_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(60) NOT NULL,
    target_id BIGINT NULL,
    details VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_created (created_at, id),
    INDEX idx_admin_audit_actor (actor_user_id, created_at),
    INDEX idx_admin_audit_target (target_type, target_id, created_at),
    CONSTRAINT fk_admin_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_admin_audit_logs_no_update
BEFORE UPDATE ON admin_audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit log records are immutable';

CREATE TRIGGER trg_admin_audit_logs_no_delete
BEFORE DELETE ON admin_audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin audit log records cannot be deleted';
