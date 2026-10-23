-- Run only after a verified full backup of the boarding_hauz database.
-- Adds per-lease paid-through tracking and the explicit payment review states.
-- Legacy values are retained in the enum and normalized below.

ALTER TABLE tenant_leases
    ADD COLUMN paid_until DATE NULL AFTER move_in_date;

ALTER TABLE payments
    MODIFY status ENUM(
        'Paid',
        'Pending',
        'Overdue',
        'Verified',
        'Rejected',
        'Pending Verification',
        'Approved'
    ) DEFAULT 'Pending Verification';

UPDATE payments
SET status = 'Pending Verification'
WHERE status = 'Pending';

UPDATE payments
SET status = 'Approved'
WHERE status = 'Verified';
