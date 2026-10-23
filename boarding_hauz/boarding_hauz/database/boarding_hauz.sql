CREATE TABLE users (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_created_by (created_by),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    branch_name VARCHAR(120) NOT NULL,
    location VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_branches_landlord_id (landlord_id),
    CONSTRAINT fk_branches_landlord FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    admin_id INT NOT NULL DEFAULT 3,
    room_number VARCHAR(20) NOT NULL,
    room_type VARCHAR(50) NOT NULL,
    capacity INT NOT NULL,
    monthly_rate DECIMAL(10,2) NOT NULL,
    status ENUM('Available', 'Occupied', 'Maintenance') DEFAULT 'Available',
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_admin_id (admin_id),
    INDEX idx_rooms_branch_id (branch_id),
    UNIQUE KEY uq_rooms_branch_room_number (branch_id, room_number),
    CONSTRAINT fk_rooms_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    admin_id INT NOT NULL DEFAULT 3,
    user_id INT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20),
    room_id INT NULL,
    move_in_date DATE,
    status ENUM('pending', 'active', 'rejected', 'inactive') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenants_admin_id (admin_id),
    INDEX idx_tenants_branch_id (branch_id),
    INDEX idx_tenants_room_status (room_id, status),

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    CONSTRAINT fk_tenants_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    tenant_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('Cash', 'GCash', 'Bank Transfer') DEFAULT 'Cash',
    status ENUM('Paid', 'Pending', 'Overdue') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_payments_branch_id (branch_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE room_requests (
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
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
