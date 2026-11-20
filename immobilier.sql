-- Select the target database in phpMyAdmin before importing this complete schema.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    bio VARCHAR(500) DEFAULT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME DEFAULT NULL,
    unlock_code_hash VARCHAR(255) DEFAULT NULL,
    unlock_code_expires_at DATETIME DEFAULT NULL,
    unlock_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    unlock_code_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_at DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS unlock_code_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS unlock_code_expires_at DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS unlock_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS unlock_code_sent_at DATETIME DEFAULT NULL;

INSERT INTO users (first_name, last_name, email, password_hash, role, must_change_password)
SELECT 'Administrateur', 'Habita', 'admin@habita.local', SHA2('123admin', 256), 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@habita.local');

UPDATE users
SET first_name = 'Administrateur', last_name = 'Habita',
    password_hash = SHA2('123admin', 256), role = 'admin', must_change_password = 1
WHERE email = 'admin@habita.local' AND role <> 'admin';

CREATE TABLE IF NOT EXISTS properties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) DEFAULT NULL,
    transaction_type ENUM('sale', 'rent') NOT NULL,
    property_type ENUM('apartment', 'house', 'land', 'commercial') NOT NULL,
    price BIGINT UNSIGNED NOT NULL,
    area SMALLINT UNSIGNED NOT NULL,
    bedrooms TINYINT UNSIGNED NOT NULL DEFAULT 0,
    image_url VARCHAR(1000) NOT NULL,
    status ENUM('pending', 'published', 'rejected', 'archived') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(1000) DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY properties_listing_filter (status, transaction_type, price, created_at),
    KEY properties_city (city),
    KEY properties_user (user_id),
    CONSTRAINT properties_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE properties MODIFY status ENUM('pending', 'published', 'rejected', 'archived') NOT NULL DEFAULT 'pending';
ALTER TABLE properties ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(1000) DEFAULT NULL AFTER status;
ALTER TABLE properties ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED DEFAULT NULL AFTER rejection_reason;
ALTER TABLE properties ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by;

CREATE TABLE IF NOT EXISTS property_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(1000) NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY property_images_position_unique (property_id, position),
    KEY property_images_property (property_id),
    CONSTRAINT property_images_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    guest_token CHAR(64) DEFAULT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY favorites_user_property_unique (user_id, property_id),
    UNIQUE KEY favorites_guest_property_unique (guest_token, property_id),
    KEY favorites_property (property_id),
    CONSTRAINT favorites_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT favorites_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE favorites MODIFY user_id BIGINT UNSIGNED DEFAULT NULL;
ALTER TABLE favorites ADD COLUMN IF NOT EXISTS guest_token CHAR(64) DEFAULT NULL;

SET @favorites_guest_unique_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'favorites'
      AND index_name = 'favorites_guest_property_unique'
);
SET @favorites_guest_unique_sql = IF(
    @favorites_guest_unique_exists = 0,
    'ALTER TABLE favorites ADD UNIQUE KEY favorites_guest_property_unique (guest_token, property_id)',
    'SELECT 1'
);
PREPARE favorites_guest_unique_statement FROM @favorites_guest_unique_sql;
EXECUTE favorites_guest_unique_statement;
DEALLOCATE PREPARE favorites_guest_unique_statement;

CREATE TABLE IF NOT EXISTS momo_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    reference CHAR(64) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    operator VARCHAR(40) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    status ENUM('simulated') NOT NULL DEFAULT 'simulated',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY momo_payments_reference_unique (reference),
    KEY momo_payments_property (property_id),
    CONSTRAINT momo_payments_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO property_images (property_id, image_url, position)
SELECT id, image_url, 1 FROM properties;

-- Extend existing listings with operational and location details without changing moderation status.
ALTER TABLE properties
    MODIFY property_type ENUM('apartment', 'house', 'land', 'commercial', 'villa', 'studio', 'office', 'shop', 'building') NOT NULL,
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL AFTER district,
    ADD COLUMN IF NOT EXISTS location_url VARCHAR(1000) DEFAULT NULL AFTER address,
    ADD COLUMN IF NOT EXISTS location_latitude DECIMAL(10,7) DEFAULT NULL AFTER location_url,
    ADD COLUMN IF NOT EXISTS location_longitude DECIMAL(10,7) DEFAULT NULL AFTER location_latitude,
    ADD COLUMN IF NOT EXISTS living_rooms TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER bedrooms,
    ADD COLUMN IF NOT EXISTS bathrooms TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER living_rooms,
    ADD COLUMN IF NOT EXISTS operational_status ENUM('available', 'reserved', 'rented', 'sold', 'maintenance') NOT NULL DEFAULT 'available' AFTER status,
    ADD COLUMN IF NOT EXISTS owner_id BIGINT UNSIGNED DEFAULT NULL AFTER user_id;

-- Keep real-estate owner records separate from marketplace login accounts.
CREATE TABLE IF NOT EXISTS property_owners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    residence_address VARCHAR(255) DEFAULT NULL,
    identity_number VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_owners_name (last_name, first_name),
    KEY property_owners_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store confidential owner identity and supporting documents outside public media.
CREATE TABLE IF NOT EXISTS owner_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY owner_documents_owner (owner_id),
    CONSTRAINT owner_documents_owner_fk FOREIGN KEY (owner_id) REFERENCES property_owners (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register tenants and link occupancy to a property through dated rental contracts.
CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    residence_address VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY tenants_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent BIGINT UNSIGNED NOT NULL,
    contract_reference VARCHAR(100) DEFAULT NULL,
    status ENUM('active', 'ended', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY leases_property_dates (property_id, status, end_date),
    KEY leases_tenant (tenant_id),
    CONSTRAINT leases_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
    CONSTRAINT leases_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record rent collection, including unpaid and partially paid rent.
CREATE TABLE IF NOT EXISTS rent_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lease_id BIGINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount_due BIGINT UNSIGNED NOT NULL,
    amount_paid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paid_at DATE DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY rent_payments_due (due_date, amount_due, amount_paid),
    KEY rent_payments_lease (lease_id),
    CONSTRAINT rent_payments_lease_fk FOREIGN KEY (lease_id) REFERENCES leases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the actual date and amount of every partial or complete rent collection.
CREATE TABLE IF NOT EXISTS rent_collections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rent_payment_id BIGINT UNSIGNED NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    collected_at DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY rent_collections_date (collected_at),
    KEY rent_collections_payment (rent_payment_id),
    CONSTRAINT rent_collections_payment_fk FOREIGN KEY (rent_payment_id) REFERENCES rent_payments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track property operating expenses independently from collected rent.
CREATE TABLE IF NOT EXISTS property_expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED DEFAULT NULL,
    category VARCHAR(100) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    expense_date DATE NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_expenses_date (expense_date),
    KEY property_expenses_property (property_id),
    CONSTRAINT property_expenses_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record sale proceeds and other non-rent income for financial reporting.
CREATE TABLE IF NOT EXISTS income_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED DEFAULT NULL,
    income_type ENUM('sale', 'other') NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    income_date DATE NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY income_entries_date (income_date),
    KEY income_entries_property (property_id),
    CONSTRAINT income_entries_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep a lightweight audit trail for management activity and status changes.
CREATE TABLE IF NOT EXISTS property_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED DEFAULT NULL,
    actor_id BIGINT UNSIGNED DEFAULT NULL,
    activity VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_activity_recent (created_at),
    KEY property_activity_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Support several validated presentation-video links per property.
CREATE TABLE IF NOT EXISTS property_videos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    video_url VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_videos_property (property_id),
    CONSTRAINT property_videos_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep buyer contact details reusable across negotiations, reservations, and sales.
CREATE TABLE IF NOT EXISTS buyers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY buyers_name (last_name, first_name),
    KEY buyers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record each purchase offer and its outcome for a complete negotiation history.
CREATE TABLE IF NOT EXISTS sale_negotiations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    offer_amount BIGINT UNSIGNED NOT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('open', 'accepted', 'rejected', 'withdrawn') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY sale_negotiations_property (property_id, created_at),
    KEY sale_negotiations_buyer (buyer_id),
    CONSTRAINT sale_negotiations_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
    CONSTRAINT sale_negotiations_buyer_fk FOREIGN KEY (buyer_id) REFERENCES buyers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track reservation dates, negotiated price, and lifecycle independently of listing moderation.
CREATE TABLE IF NOT EXISTS property_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    agreed_price BIGINT UNSIGNED NOT NULL,
    reserved_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status ENUM('active', 'converted', 'cancelled', 'expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_reservations_property (property_id, status, expires_at),
    KEY property_reservations_buyer (buyer_id),
    CONSTRAINT property_reservations_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
    CONSTRAINT property_reservations_buyer_fk FOREIGN KEY (buyer_id) REFERENCES buyers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One payment ledger covers reservation deposits and later sale installments.
CREATE TABLE IF NOT EXISTS sale_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED DEFAULT NULL,
    sale_id BIGINT UNSIGNED DEFAULT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    payment_type ENUM('deposit', 'installment', 'balance') NOT NULL,
    payment_method VARCHAR(80) DEFAULT NULL,
    payment_reference VARCHAR(120) DEFAULT NULL,
    paid_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY sale_payments_reservation (reservation_id, paid_at),
    KEY sale_payments_sale (sale_id, paid_at),
    CONSTRAINT sale_payments_reservation_fk FOREIGN KEY (reservation_id) REFERENCES property_reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store each completed transfer and preserve its final agreed sale price.
CREATE TABLE IF NOT EXISTS property_sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    reservation_id BIGINT UNSIGNED DEFAULT NULL,
    sale_price BIGINT UNSIGNED NOT NULL,
    sale_date DATE NOT NULL,
    contract_reference VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY property_sales_reservation_unique (reservation_id),
    KEY property_sales_property (property_id, sale_date),
    KEY property_sales_buyer (buyer_id),
    CONSTRAINT property_sales_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE RESTRICT,
    CONSTRAINT property_sales_buyer_fk FOREIGN KEY (buyer_id) REFERENCES buyers (id) ON DELETE RESTRICT,
    CONSTRAINT property_sales_reservation_fk FOREIGN KEY (reservation_id) REFERENCES property_reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link recorded sale payments to completed sales after the sale table has been created.
SET @sale_payments_fk_exists = (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'sale_payments'
      AND constraint_name = 'sale_payments_sale_fk'
);
SET @sale_payments_fk_sql = IF(
    @sale_payments_fk_exists = 0,
    'ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_sale_fk FOREIGN KEY (sale_id) REFERENCES property_sales (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE sale_payments_fk_statement FROM @sale_payments_fk_sql;
EXECUTE sale_payments_fk_statement;
DEALLOCATE PREPARE sale_payments_fk_statement;

-- Persist prospect and assigned-agent contact information with every scheduled visit.
CREATE TABLE IF NOT EXISTS real_estate_agents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    portal_token_hash CHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY real_estate_agents_name (last_name, first_name),
    KEY real_estate_agents_active (active),
    UNIQUE KEY real_estate_agents_portal_token (portal_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE real_estate_agents ADD COLUMN IF NOT EXISTS portal_token_hash CHAR(64) DEFAULT NULL;

-- Store prospect details, visit outcomes, and follow-up information.
CREATE TABLE IF NOT EXISTS property_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED DEFAULT NULL,
    prospect_name VARCHAR(160) NOT NULL,
    prospect_email VARCHAR(190) DEFAULT NULL,
    prospect_phone VARCHAR(40) DEFAULT NULL,
    scheduled_at DATETIME NOT NULL,
    status ENUM('scheduled', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    interested TINYINT(1) NOT NULL DEFAULT 0,
    report TEXT DEFAULT NULL,
    follow_up_note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY property_visits_schedule (scheduled_at, status),
    KEY property_visits_property (property_id, scheduled_at),
    KEY property_visits_agent (agent_id, scheduled_at),
    KEY property_visits_interested (interested, scheduled_at),
    CONSTRAINT property_visits_property_fk FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE,
    CONSTRAINT property_visits_agent_fk FOREIGN KEY (agent_id) REFERENCES real_estate_agents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE property_visits MODIFY agent_id BIGINT UNSIGNED DEFAULT NULL;
