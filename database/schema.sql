-- ---------------------------------------------------------------------------
-- Search Place - database schema for collected places
-- ---------------------------------------------------------------------------
-- Requires MySQL 5.7+ / MariaDB 10.2+ (JSON column type).
--
-- Three application tables:
--
--   contacts        -> normalized phone numbers; saved Balad places link a
--                      valid available number while Divar phone collection remains
--                      a later step
--   accommodations  -> collected provider places/listings
--   call_logs       -> leftover table; search no longer writes call logs.
--                      Saving an accommodation (and its contact phone) is enough.
--
-- Indexes for the search-page stored-phone lookup:
--   The unique key uq_provider_external_id (provider, external_id) already
--   answers "is this listing stored?" for the current page (an IN list of
--   ~24 tokens). Phones are then read through accommodations.contact_id
--   (idx_accommodations_contact_id) joining contacts.id (PRIMARY KEY).
--   contacts.phone is already unique. A second (provider, external_id)
--   index would only duplicate the unique key, so it is not added.
--
-- The file is idempotent: every statement uses CREATE TABLE IF NOT EXISTS,
-- so it is safe to run as many times as you want.
--
-- The application also runs this file automatically (see Src\Support\Schema),
-- so normally you do NOT have to execute it by hand. If you prefer to do it
-- manually:
--
--   mysql -u root -p search_place < database/schema.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    phone VARCHAR(20) NOT NULL,
    name VARCHAR(255) NULL,
    notes TEXT NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_contacts_phone (phone)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accommodations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    contact_id BIGINT UNSIGNED NULL,

    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    province VARCHAR(100) NULL,
    city VARCHAR(150) NULL,
    category VARCHAR(100) NULL,
    address TEXT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    price DECIMAL(15, 2) NULL,

    provider VARCHAR(30) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    url TEXT NULL,

    provider_data JSON NULL,
    raw_data JSON NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_accommodations_contact
        FOREIGN KEY (contact_id)
        REFERENCES contacts (id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_provider_external_id (
        provider,
        external_id
    ),

    INDEX idx_accommodations_contact_id (contact_id),
    INDEX idx_accommodations_title (title),
    INDEX idx_accommodations_city (city),
    INDEX idx_accommodations_province (province)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    place_id VARCHAR(255) NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    city VARCHAR(150) NULL,
    category VARCHAR(100) NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_call_logs_place_phone (place_id(120), phone_number),
    INDEX idx_call_logs_status_created (status, created_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
