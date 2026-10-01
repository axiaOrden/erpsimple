/* ============================================================
   INCREMENTAL SCHEMA — CONTROLLED SO REJECTION REASONS (2026-10-01)

   Canonical baseline: ddl.sql / database/schema/erp-schema.sql
   (already updated with these objects — this file exists so an
   EXISTING database can be brought forward in place).

   Apply to every database (dev + test):

     mariadb -uroot -p <db> < 2026_10_01_rejection_reason_master.sql

   What changes
     1. NEW sales_order_rejection_reason — an authoritative lookup of
        rejection reasons. No free-text reasons are accepted from users
        any more: the UI offers only `user_selectable = TRUE` rows, and
        the application resolves them by stable reason_id / reason_code
        (never by display label).
     2. NEW sales_order_item.rejection_reason_id FK → the lookup.
        `rejection_reason` (VARCHAR) is KEPT as the legacy/audit text
        column: existing history is never rewritten or discarded, and the
        service snapshots the reason name into it for readability.
     3. DATA MIGRATION: rows whose legacy free text clearly matches a
        controlled reason are linked to it; every other historical value
        keeps its text and stays UNLINKED (rejection_reason_id NULL) so no
        audit detail is invented or lost.

   reason 0 SYSTEM_DEFAULT is automation-only: it is used when the
   application closes dependent DEAL/free demand because its parent paid
   demand is terminally rejected. It is never offered to a user.
   ============================================================ */

CREATE TABLE IF NOT EXISTS sales_order_rejection_reason (
    reason_id TINYINT UNSIGNED NOT NULL,

    reason_code VARCHAR(50) NOT NULL,
    reason_name VARCHAR(100) NOT NULL,

    user_selectable BOOLEAN NOT NULL DEFAULT TRUE,
    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (reason_id),

    UNIQUE KEY uq_sorr_code (reason_code)
) ENGINE=InnoDB;

INSERT INTO sales_order_rejection_reason
    (reason_id, reason_code, reason_name, user_selectable, active)
VALUES
    (0, 'SYSTEM_DEFAULT', 'System Default', FALSE, TRUE),
    (1, 'CUSTOMER_REQUEST', 'Customer Request', TRUE, TRUE),
    (2, 'UNAVAILABLE_STOCK', 'Unavailable Stock', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    reason_code = VALUES(reason_code),
    reason_name = VALUES(reason_name),
    user_selectable = VALUES(user_selectable),
    active = VALUES(active);

ALTER TABLE sales_order_item
    ADD COLUMN rejection_reason_id TINYINT UNSIGNED NULL AFTER rejection_reason,
    ADD CONSTRAINT fk_soi_reject_reason
        FOREIGN KEY (rejection_reason_id)
        REFERENCES sales_order_rejection_reason(reason_id);

/* Recognizable legacy free text → controlled reason. Case-insensitive,
   deliberately conservative: only unambiguous phrases are linked. */
UPDATE sales_order_item
   SET rejection_reason_id = 1
 WHERE rejection_status = 'REJECTED'
   AND rejection_reason_id IS NULL
   AND (LOWER(rejection_reason) LIKE '%customer request%'
        OR LOWER(rejection_reason) LIKE '%customer asked%'
        OR LOWER(rejection_reason) LIKE '%customer declined%'
        OR LOWER(rejection_reason) LIKE '%customer rejected%');

UPDATE sales_order_item
   SET rejection_reason_id = 2
 WHERE rejection_status = 'REJECTED'
   AND rejection_reason_id IS NULL
   AND (LOWER(rejection_reason) LIKE '%unavailable%'
        OR LOWER(rejection_reason) LIKE '%out of stock%'
        OR LOWER(rejection_reason) LIKE '%no stock%'
        OR LOWER(rejection_reason) LIKE '%not available%');

/* Audit aid: the rows that keep a legacy text and remain unlinked. */
SELECT COUNT(*) AS unlinked_legacy_rejections
  FROM sales_order_item
 WHERE rejection_status = 'REJECTED'
   AND rejection_reason_id IS NULL;
