ALTER TABLE payments
    MODIFY payment_method ENUM('Cash', 'GCash', 'Maya', 'Bank Transfer') DEFAULT 'Cash',
    MODIFY status ENUM('Paid', 'Pending', 'Overdue', 'Verified', 'Rejected') DEFAULT 'Pending';
