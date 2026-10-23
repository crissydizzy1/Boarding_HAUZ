CREATE DATABASE IF NOT EXISTS boarding_hauz
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE boarding_hauz;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'landlord', 'tenant') NOT NULL DEFAULT 'tenant',
    status ENUM('pending', 'active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    gcash_account_name VARCHAR(100) NULL,
    gcash_number VARCHAR(32) NULL,
    maya_account_name VARCHAR(100) NULL,
    maya_number VARCHAR(32) NULL,
    bank_name VARCHAR(80) NULL,
    bank_account_name VARCHAR(100) NULL,
    bank_account_number VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_created_by (created_by),
    CONSTRAINT fk_users_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    admin_id INT NOT NULL DEFAULT 3,
    room_number VARCHAR(20) NOT NULL,
    room_type VARCHAR(50) NOT NULL,
    capacity INT NOT NULL,
    monthly_rate DECIMAL(10,2) NOT NULL,
    status ENUM('Available', 'Occupied', 'Maintenance') NOT NULL DEFAULT 'Available',
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_admin_id (admin_id),
    INDEX idx_rooms_branch_id (branch_id),
    UNIQUE KEY uq_rooms_branch_room_number (branch_id, room_number),
    CONSTRAINT fk_rooms_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    admin_id INT NOT NULL DEFAULT 3,
    user_id INT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NULL,
    room_id INT NULL,
    move_in_date DATE NULL,
    status ENUM('pending', 'active', 'rejected', 'inactive') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenants_admin_id (admin_id),
    INDEX idx_tenants_branch_id (branch_id),
    INDEX idx_tenants_room_status (room_id, status),
    CONSTRAINT fk_tenants_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tenants_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tenants_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    tenant_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('Cash', 'GCash', 'Maya', 'Bank Transfer') NOT NULL DEFAULT 'Cash',
    reference_number VARCHAR(100) NULL,
    proof_path VARCHAR(255) NULL,
    status ENUM('Paid', 'Pending', 'Overdue', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_branch_id (branch_id),
    INDEX idx_payments_tenant_id (tenant_id),
    CONSTRAINT fk_payments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payments_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    room_id INT NULL,
    inquiry_type ENUM('property', 'platform', 'unassigned') NOT NULL DEFAULT 'property',
    target_move_in_date DATE NULL,
    expected_stay_duration VARCHAR(100) NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    preferred_room_type VARCHAR(50) NULL,
    message TEXT NOT NULL,
    reply_message TEXT NULL,
    status ENUM('Unread', 'Read') NOT NULL DEFAULT 'Unread',
    admin_status ENUM('Pending', 'In Progress', 'Resolved') NOT NULL DEFAULT 'Pending',
    escalated_at DATETIME NULL,
    escalated_by INT NULL,
    admin_reply TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME NULL,
    replied_by INT NULL,
    INDEX idx_public_inquiries_status_created (status, created_at),
    INDEX idx_public_inquiries_room_id (room_id),
    INDEX idx_public_inquiries_branch_id (branch_id),
    INDEX idx_public_inquiries_replied_by (replied_by),
    INDEX idx_public_inquiries_admin_queue (inquiry_type, admin_status, created_at),
    INDEX idx_public_inquiries_escalated (escalated_at, branch_id),
    CONSTRAINT fk_public_inquiries_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_public_inquiries_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_public_inquiries_replied_by
        FOREIGN KEY (replied_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_public_inquiries_escalated_by
        FOREIGN KEY (escalated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
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

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_sender_receiver_created (sender_id, receiver_id, created_at),
    INDEX idx_messages_receiver_unread (receiver_id, is_read),
    CONSTRAINT fk_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_messages_receiver
        FOREIGN KEY (receiver_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    tenant_id INT NOT NULL,
    room_id INT NULL,
    category VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'In Progress', 'Resolved', 'Closed') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_maintenance_tenant (tenant_id),
    INDEX idx_maintenance_room (room_id),
    INDEX idx_maintenance_branch_id (branch_id),
    CONSTRAINT fk_maintenance_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
