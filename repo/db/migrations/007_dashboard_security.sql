-- Phase 7: Dashboard layouts, favorites, exports; encrypted field columns
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS dashboard_layouts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL DEFAULT 'My Dashboard',
    layout_json   LONGTEXT NOT NULL COMMENT 'JSON array of widget descriptors',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dl_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_favorites (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    layout_id   BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_df_user_layout (user_id, layout_id),
    CONSTRAINT fk_df_user   FOREIGN KEY (user_id)   REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_df_layout FOREIGN KEY (layout_id) REFERENCES dashboard_layouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_exports (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    layout_id    BIGINT UNSIGNED,
    format       ENUM('png','pdf','xlsx') NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_de_user   FOREIGN KEY (user_id)   REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_de_layout FOREIGN KEY (layout_id) REFERENCES dashboard_layouts(id) ON DELETE SET NULL,
    INDEX idx_de_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Encrypted sensitive columns on orders and users
-- invoice_contact_enc stores AES-256-CBC ciphertext (base64-prefixed with IV)
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS invoice_contact_enc TEXT NULL COMMENT 'AES-256-CBC encrypted contact name',
    ADD COLUMN IF NOT EXISTS invoice_address_enc TEXT NULL COMMENT 'AES-256-CBC encrypted address';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS passenger_id_enc TEXT NULL COMMENT 'AES-256-CBC encrypted passenger identifier';
