-- Phase 9: Remove plaintext invoice columns from orders table.
-- The canonical storage for sensitive invoice fields is now invoice_contact_enc
-- and invoice_address_enc (AES-256-CBC, added in migration 007).
-- The old invoice_contact_encrypted column was an intermediate artefact; also removed.
SET NAMES utf8mb4;

ALTER TABLE `orders`
    DROP COLUMN `invoice_contact`,
    DROP COLUMN `invoice_address`,
    DROP COLUMN `invoice_contact_encrypted`;
