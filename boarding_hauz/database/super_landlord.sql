-- Apply once to an existing database to enable the Super Landlord role.
-- The existing branches.landlord_id ownership model grants access only to
-- branches explicitly owned by each landlord account.
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'super_admin', 'landlord', 'super_landlord', 'tenant')
        NOT NULL DEFAULT 'tenant';
