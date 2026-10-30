-- Run once on an existing Boarding Hauz database.
USE boarding_hauz;

ALTER TABLE rooms
    ADD COLUMN image_path VARCHAR(255) NULL AFTER status;
