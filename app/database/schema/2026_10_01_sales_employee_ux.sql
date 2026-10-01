/* ============================================================
   INCREMENTAL SCHEMA — SALES EMPLOYEE UX REDESIGN (2026-10-01)

   Canonical baseline: ddl.sql / database/schema/erp-schema.sql
   (already updated with these objects — this file exists so an
   existing database can be brought forward in place).

   Apply to every database (dev + test):

     mariadb -uroot -p <db> < 2026_10_01_sales_employee_ux.sql

   Contents
     1. customer_master.phone_canonical + UNIQUE uq_cm_phone_canonical
        (server-derived canonical phone; DB backstop for duplicates)
     2. invoice.public_token + UNIQUE uq_invoice_public_token
        (unguessable public customer-invoice URL token)
     3. payment.payment_method enum extension
        (BANK_TRANSFER_TO_PRIMARY / POS_AT_PRIMARY / CASH_AT_PRIMARY)
     4. payment_evidence (proof-of-settlement audit artefact)

   No domain semantics change: SO = demand, Delivery = reversible
   allocation, Shipment START = irreversible Goods Issue, POD = acceptance,
   Invoice = accepted billable quantity, Transit = issued-but-unaccepted.
   ============================================================ */

ALTER TABLE customer_master
    ADD COLUMN phone_canonical VARCHAR(32) NULL AFTER phone_number,
    ADD UNIQUE KEY uq_cm_phone_canonical (phone_canonical);

ALTER TABLE invoice
    ADD COLUMN public_token CHAR(43) NULL AFTER payment_term,
    ADD UNIQUE KEY uq_invoice_public_token (public_token);

ALTER TABLE payment
    MODIFY payment_method ENUM(
        'CASH',
        'TRANSFER',
        'POS',
        'OTHER',
        'BANK_TRANSFER_TO_PRIMARY',
        'POS_AT_PRIMARY',
        'CASH_AT_PRIMARY'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS payment_evidence (
    evidence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    payment_id BIGINT UNSIGNED NOT NULL,

    stored_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,

    gps_latitude DECIMAL(10,7) NULL,
    gps_longitude DECIMAL(10,7) NULL,
    gps_accuracy DECIMAL(10,2) NULL,

    captured_at DATETIME NULL,
    watermark_text VARCHAR(255) NULL,

    uploaded_by VARCHAR(50) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (evidence_id),

    KEY idx_pe_payment (
        payment_id
    ),

    CONSTRAINT fk_pe_payment
        FOREIGN KEY (payment_id)
        REFERENCES payment(payment_id),

    CONSTRAINT fk_pe_employee
        FOREIGN KEY (uploaded_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;
