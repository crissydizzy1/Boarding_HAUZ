CREATE TABLE IF NOT EXISTS public_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    room_id INT NULL,
    target_move_in_date DATE NULL,
    expected_stay_duration VARCHAR(100) NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    preferred_room_type VARCHAR(50) NULL,
    message TEXT NOT NULL,
    status ENUM('Unread', 'Read') NOT NULL DEFAULT 'Unread',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_inquiries_status_created (status, created_at),
    INDEX idx_public_inquiries_room_id (room_id),
    INDEX idx_public_inquiries_branch_id (branch_id),
    CONSTRAINT fk_public_inquiries_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_inquiries_room
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE public_inquiries
    ADD COLUMN reply_message TEXT NULL AFTER message,
    ADD COLUMN replied_at DATETIME NULL AFTER created_at,
    ADD COLUMN replied_by INT NULL AFTER replied_at,
    ADD INDEX idx_public_inquiries_replied_by (replied_by);
