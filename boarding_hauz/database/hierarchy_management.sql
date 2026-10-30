-- One-time migration for existing installations. Back up the database first.
-- Roles, branches, and branch-scoped tenant records are already defined by the
-- application schema and database/multi_branching.sql.
ALTER TABLE users ROW_FORMAT=DYNAMIC;

ALTER TABLE users
    ADD COLUMN created_by INT NULL AFTER status,
    ADD INDEX idx_users_created_by (created_by),
    ADD CONSTRAINT fk_users_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;
