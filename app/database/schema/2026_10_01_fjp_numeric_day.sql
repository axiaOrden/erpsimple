/* ============================================================
   INCREMENTAL SCHEMA — FJP WEEKDAY AS NUMERIC INDEX (2026-10-01)

   Canonical baseline: ddl.sql / database/schema/erp-schema.sql
   (already updated with this definition — this file exists so an
   EXISTING database can be brought forward in place, without losing
   a single FJP row).

   Apply to every database (dev + test):

     mariadb -uroot -p <db> < 2026_10_01_fjp_numeric_day.sql

   What changes
     customer_fjp.preferred_day
       ENUM('MONDAY' … 'SUNDAY')
       → TINYINT UNSIGNED, 0 = Sunday … 6 = Saturday
         (the same index as JS Date#getDay() / Carbon dayOfWeek)

   What does NOT change
     - preferred_week stays the numeric rotation-week position
       (NULL = every week). It is never rewritten.
     - No combined string ('W1-Mon') is ever stored: W1-Mon is a
       presentation chip rendered from preferred_week + preferred_day.
     - FJP still means "preferred visit schedule". It never records
       attendance and never implies a supplying Primary.

   The conversion is a straight data copy guarded by CASE, so existing
   schedules survive with the same weekday they had before.
   ============================================================ */

ALTER TABLE customer_fjp
    ADD COLUMN preferred_day_numeric TINYINT UNSIGNED NULL AFTER preferred_day;

UPDATE customer_fjp
   SET preferred_day_numeric = CASE UPPER(TRIM(preferred_day))
           WHEN 'SUNDAY' THEN 0
           WHEN 'MONDAY' THEN 1
           WHEN 'TUESDAY' THEN 2
           WHEN 'WEDNESDAY' THEN 3
           WHEN 'THURSDAY' THEN 4
           WHEN 'FRIDAY' THEN 5
           WHEN 'SATURDAY' THEN 6
           ELSE NULL
       END;

/* A row that cannot be mapped would silently vanish from the journey plan,
   so the conversion is asserted before the old column is dropped. */
SELECT COUNT(*) AS unmapped_fjp_rows
  FROM customer_fjp
 WHERE preferred_day_numeric IS NULL;

ALTER TABLE customer_fjp
    DROP COLUMN preferred_day,
    CHANGE COLUMN preferred_day_numeric preferred_day TINYINT UNSIGNED NOT NULL;

ALTER TABLE customer_fjp
    ADD KEY idx_fjp_plan (company_id, employee_id, preferred_day);
