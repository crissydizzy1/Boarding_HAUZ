-- Apply once to an existing database before enabling public landlord registration.
ALTER TABLE users
    ADD COLUMN full_name VARCHAR(100) NULL AFTER password,
    ADD COLUMN contact_number VARCHAR(20) NULL AFTER full_name;
