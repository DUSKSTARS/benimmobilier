-- Select the target database in phpMyAdmin before importing this file.
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

DELETE FROM properties
WHERE user_id IS NULL
  AND title IN (
    'Villa lumineuse avec jardin tropical',
    'Appartement contemporain, prêt à vivre',
    'Maison familiale au calme',
    'Studio meublé au cœur de la ville',
    'Terrain prêt pour votre projet',
    'Appartement avec terrasse ensoleillée'
);

INSERT IGNORE INTO property_images (property_id, image_url, position)
SELECT id, image_url, 1 FROM properties;
