-- Apply once to an existing Boarding Hauz database.
-- The current tenants.room_id relationship already supports multiple tenants per room.
ALTER TABLE tenants
    ADD INDEX idx_tenants_room_status (room_id, status);
