/* ============================================================
   SIMPLE ERP
   MariaDB 13.x
   ============================================================ */



/* ============================================================
   1. COMPANY MASTER
   ============================================================ */

CREATE TABLE company_master (
    company_id VARCHAR(20) NOT NULL,
    company_name VARCHAR(255) NOT NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (company_id)
) ENGINE=InnoDB;


/* ============================================================
   2. UNIT MASTER
   ============================================================ */

CREATE TABLE unit_master (
    unit_code VARCHAR(20) NOT NULL,
    unit_description VARCHAR(100) NOT NULL,

    PRIMARY KEY (unit_code)
) ENGINE=InnoDB;


/* ============================================================
   3. PRODUCT MASTER
   ============================================================ */

CREATE TABLE product_master (
    product_id VARCHAR(50) NOT NULL,
    company_id VARCHAR(20) NOT NULL,

    product_description VARCHAR(255) NOT NULL,
    product_category VARCHAR(100) NULL,

    product_sku VARCHAR(100) NOT NULL,
    sku_description VARCHAR(255) NULL,

    basic_unit VARCHAR(20) NOT NULL,

    ext_product_id VARCHAR(100) NULL,
    issuing_company VARCHAR(255) NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (product_id),

    UNIQUE KEY uq_pm_company_sku (
        company_id,
        product_sku
    ),

    CONSTRAINT fk_pm_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_pm_basic_unit
        FOREIGN KEY (basic_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   4. PRODUCT UNIT CONVERSION
   alt qty * numerator / denominator = basic qty
   Example:
   1 CTN = 24 PCS
   numerator = 24
   denominator = 1
   ============================================================ */

CREATE TABLE product_unit_conversion (
    product_id VARCHAR(50) NOT NULL,
    alternative_unit VARCHAR(20) NOT NULL,

    numerator DECIMAL(18,6) NOT NULL DEFAULT 1,
    denominator DECIMAL(18,6) NOT NULL DEFAULT 1,

    PRIMARY KEY (
        product_id,
        alternative_unit
    ),

    CONSTRAINT fk_puc_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_puc_unit
        FOREIGN KEY (alternative_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   5. CUSTOMER MASTER

   PRIMARY   = Distributor / stock holder
   SECONDARY = Secondary customer
   VAN       = Van customer
   SHIP_TO   = Child delivery location of Primary
   ============================================================ */

CREATE TABLE customer_master (
    customer_id VARCHAR(50) NOT NULL,

    business_name VARCHAR(255) NOT NULL,

    customer_type ENUM(
        'PRIMARY',
        'SECONDARY',
        'VAN',
        'SHIP_TO'
    ) NOT NULL,

    parent_customer_id VARCHAR(50) NULL,

    contact_person VARCHAR(255) NULL,
    phone_number VARCHAR(50) NULL,
    email_address VARCHAR(255) NULL,

    gps_latitude DECIMAL(10,7) NULL,
    gps_longitude DECIMAL(10,7) NULL,

    address VARCHAR(255) NULL,
    address2 VARCHAR(255) NULL,
    state VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    postal_code VARCHAR(30) NULL,
    country VARCHAR(100) NULL,

    sales_region VARCHAR(100) NULL,
    market VARCHAR(100) NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (customer_id),

    KEY idx_cm_parent (
        parent_customer_id
    ),

    KEY idx_cm_type (
        customer_type
    ),

    CONSTRAINT fk_cm_parent
        FOREIGN KEY (parent_customer_id)
        REFERENCES customer_master(customer_id)

) ENGINE=InnoDB;


/* ============================================================
   6. EMPLOYEE MASTER
   ============================================================ */

CREATE TABLE employee_master (
    employee_id VARCHAR(50) NOT NULL,
    company_id VARCHAR(20) NOT NULL,

    employee_name VARCHAR(255) NOT NULL,

    email_address VARCHAR(255) NULL,
    phone_number VARCHAR(50) NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (employee_id),

    KEY idx_em_company (
        company_id
    ),

    CONSTRAINT fk_em_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id)

) ENGINE=InnoDB;


/* ============================================================
   7. APPLICATION USER
   ============================================================ */

CREATE TABLE app_user (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    employee_id VARCHAR(50) NULL,
    company_id VARCHAR(20) NULL,

    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    role ENUM(
        'SUPERADMIN',
        'COMPANY_ADMIN',
        'SALES_EMPLOYEE'
    ) NOT NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id),

    UNIQUE KEY uq_au_email (
        email
    ),

    UNIQUE KEY uq_au_employee (
        employee_id
    ),

    CONSTRAINT fk_au_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_au_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id)

) ENGINE=InnoDB;


/* ============================================================
   8. CUSTOMER / EMPLOYEE ASSIGNMENT
   ============================================================ */

CREATE TABLE customer_employee (
    customer_id VARCHAR(50) NOT NULL,
    employee_id VARCHAR(50) NOT NULL,

    role VARCHAR(30) NOT NULL DEFAULT 'SE',

    valid_from DATE NULL,
    valid_to DATE NULL,

    PRIMARY KEY (
        customer_id,
        employee_id,
        role
    ),

    CONSTRAINT fk_ce_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_ce_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   9. EMPLOYEE PRODUCT SCOPE

   No records for employee = all active company products.
   Records exist = employee limited to these products.
   ============================================================ */

CREATE TABLE employee_product (
    employee_id VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    PRIMARY KEY (
        employee_id,
        product_id
    ),

    CONSTRAINT fk_ep_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_ep_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id)

) ENGINE=InnoDB;


/* ============================================================
   10. FIXED JOURNEY PLAN
   ============================================================ */

CREATE TABLE customer_fjp (
    fjp_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id VARCHAR(20) NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    preferred_week TINYINT UNSIGNED NULL,

    preferred_day ENUM(
        'MONDAY',
        'TUESDAY',
        'WEDNESDAY',
        'THURSDAY',
        'FRIDAY',
        'SATURDAY',
        'SUNDAY'
    ) NOT NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (fjp_id),

    KEY idx_fjp_employee (
        employee_id
    ),

    KEY idx_fjp_customer (
        customer_id
    ),

    CONSTRAINT fk_fjp_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_fjp_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_fjp_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id)

) ENGINE=InnoDB;


/* ============================================================
   11. CUSTOMER VISIT ATTENDANCE

   Multiple records per day allowed.
   MIN attendance_datetime = effective check-in
   MAX attendance_datetime = effective check-out
   ============================================================ */

CREATE TABLE customer_visit_attendance (
    attendance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id VARCHAR(20) NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    attendance_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    /* Device-claimed capture time (esp. offline); attendance_datetime
       remains the authoritative server event timestamp. */
    device_captured_at DATETIME NULL,
    device_timestamp_flag BOOLEAN NOT NULL DEFAULT FALSE,

    gps_latitude DECIMAL(10,7) NULL,
    gps_longitude DECIMAL(10,7) NULL,
    gps_accuracy DECIMAL(10,2) NULL,

    remarks VARCHAR(255) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (attendance_id),

    KEY idx_cva_employee_date (
        employee_id,
        attendance_datetime
    ),

    KEY idx_cva_customer_date (
        customer_id,
        attendance_datetime
    ),

    CONSTRAINT fk_cva_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_cva_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_cva_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id)

) ENGINE=InnoDB;


/* ============================================================
   12. PRICE CONDITION HEADER
   ============================================================ */

CREATE TABLE price_condition (
    condition_price_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,

    sales_region VARCHAR(100) NULL,

    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (condition_price_no),

    KEY idx_pc_company_validity (
        company_id,
        valid_from,
        valid_to
    ),

    CONSTRAINT fk_pc_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id)

) ENGINE=InnoDB;


/* ============================================================
   13. PRICE CONDITION ITEM
   ============================================================ */

CREATE TABLE price_condition_item (
    condition_price_no VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    price DECIMAL(18,2) NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    tax_type ENUM(
        'OUTPUT_TAX',
        'INPUT_TAX',
        'NONE'
    ) NOT NULL DEFAULT 'OUTPUT_TAX',

    PRIMARY KEY (
        condition_price_no,
        product_id
    ),

    CONSTRAINT fk_pci_header
        FOREIGN KEY (condition_price_no)
        REFERENCES price_condition(condition_price_no),

    CONSTRAINT fk_pci_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id)

) ENGINE=InnoDB;


/* ============================================================
   14. TRADE DEAL HEADER
   ============================================================ */

CREATE TABLE deal_condition (
    deal_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,

    deal_description VARCHAR(255) NOT NULL,

    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (deal_no),

    KEY idx_dc_company_validity (
        company_id,
        valid_from,
        valid_to
    ),

    CONSTRAINT fk_dc_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id)

) ENGINE=InnoDB;


/* ============================================================
   15. TRADE DEAL QUALIFIER
   Example: Buy 10 CTN Product A
   ============================================================ */

CREATE TABLE deal_qualifier (
    deal_no VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    minimum_qty DECIMAL(18,3) NOT NULL,
    qualifier_unit VARCHAR(20) NOT NULL,

    PRIMARY KEY (
        deal_no,
        product_id
    ),

    CONSTRAINT fk_dq_deal
        FOREIGN KEY (deal_no)
        REFERENCES deal_condition(deal_no),

    CONSTRAINT fk_dq_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_dq_unit
        FOREIGN KEY (qualifier_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   16. TRADE DEAL REWARD
   Example: Get 1 CTN Product B
   ============================================================ */

CREATE TABLE deal_reward (
    deal_no VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    reward_qty DECIMAL(18,3) NOT NULL,
    reward_unit VARCHAR(20) NOT NULL,

    for_each_qty DECIMAL(18,3) NULL,
    for_each_unit VARCHAR(20) NULL,

    PRIMARY KEY (
        deal_no,
        product_id
    ),

    CONSTRAINT fk_dr_deal
        FOREIGN KEY (deal_no)
        REFERENCES deal_condition(deal_no),

    CONSTRAINT fk_dr_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_dr_reward_unit
        FOREIGN KEY (reward_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_dr_each_unit
        FOREIGN KEY (for_each_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   17. INVENTORY

   ON HAND = unrestricted_qty + restricted_qty

   unrestricted = available for new Delivery allocation
   restricted   = allocated but Shipment not started
   ============================================================ */

CREATE TABLE inventory (
    customer_id VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    unrestricted_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
    restricted_qty DECIMAL(18,3) NOT NULL DEFAULT 0,

    basic_unit VARCHAR(20) NOT NULL,

    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (
        customer_id,
        product_id
    ),

    CONSTRAINT fk_inv_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_inv_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_inv_unit
        FOREIGN KEY (basic_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   18. STOCK COUNT HEADER
   ============================================================ */

CREATE TABLE stock_count (
    count_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,
    employee_id VARCHAR(50) NOT NULL,

    count_type ENUM(
        'PRIMARY_OPERATIONAL',
        'SECONDARY_OBSERVATION',
        'VAN_CLOSING'
    ) NOT NULL,

    count_status ENUM(
        'DRAFT',
        'SUBMITTED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'DRAFT',

    count_date DATE NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,

    PRIMARY KEY (count_no),

    KEY idx_sc_customer_date (
        customer_id,
        count_date
    ),

    CONSTRAINT fk_sc_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_sc_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_sc_employee
        FOREIGN KEY (employee_id)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   19. STOCK COUNT ITEM
   ============================================================ */

CREATE TABLE stock_count_item (
    count_no VARCHAR(50) NOT NULL,
    item_no INT UNSIGNED NOT NULL,

    product_id VARCHAR(50) NOT NULL,

    expected_qty DECIMAL(18,3) NULL,
    counted_qty DECIMAL(18,3) NOT NULL,
    variance_qty DECIMAL(18,3) NULL,

    count_unit VARCHAR(20) NOT NULL,

    PRIMARY KEY (
        count_no,
        item_no
    ),

    UNIQUE KEY uq_sci_count_product (
        count_no,
        product_id
    ),

    CONSTRAINT fk_sci_header
        FOREIGN KEY (count_no)
        REFERENCES stock_count(count_no),

    CONSTRAINT fk_sci_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_sci_unit
        FOREIGN KEY (count_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   20. SALES ORDER HEADER

   Sales Order = DEMAND ONLY.
   It does not reserve/deduct inventory.
   ============================================================ */

CREATE TABLE sales_order (
    sales_order_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,

    supplying_customer_id VARCHAR(50) NOT NULL,
    source_customer_id VARCHAR(50) NOT NULL,
    sold_to_customer_id VARCHAR(50) NOT NULL,

    sales_employee_id VARCHAR(50) NOT NULL,

    order_type ENUM(
        'STANDARD',
        'VAN_ORDER'
    ) NOT NULL DEFAULT 'STANDARD',

    order_status ENUM(
        'DRAFT',
        'CONFIRMED',
        'OPEN_DELIVERY',
        'PARTIALLY_DELIVERED',
        'COMPLETELY_DELIVERED',
        'PARTIALLY_REJECTED',
        'COMPLETELY_REJECTED'
    ) NOT NULL DEFAULT 'DRAFT',

    order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pricing_date DATE NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

    confirmed_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (sales_order_no),

    KEY idx_so_company (
        company_id
    ),

    KEY idx_so_supplier (
        supplying_customer_id
    ),

    KEY idx_so_soldto (
        sold_to_customer_id
    ),

    KEY idx_so_employee (
        sales_employee_id
    ),

    KEY idx_so_date (
        order_date
    ),

    CONSTRAINT fk_so_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_so_supplier
        FOREIGN KEY (supplying_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_so_source
        FOREIGN KEY (source_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_so_soldto
        FOREIGN KEY (sold_to_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_so_employee
        FOREIGN KEY (sales_employee_id)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   21. SALES ORDER ITEM
   ============================================================ */

CREATE TABLE sales_order_item (
    sales_order_no VARCHAR(50) NOT NULL,
    item_no INT UNSIGNED NOT NULL,

    product_id VARCHAR(50) NOT NULL,

    line_source ENUM(
        'MANUAL',
        'DEAL'
    ) NOT NULL DEFAULT 'MANUAL',

    is_free_item BOOLEAN NOT NULL DEFAULT FALSE,

    parent_item_no INT UNSIGNED NULL,

    order_qty DECIMAL(18,3) NOT NULL,
    order_unit VARCHAR(20) NOT NULL,

    recommended_price DECIMAL(18,2) NULL,
    unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,

    price_overridden BOOLEAN NOT NULL DEFAULT FALSE,
    price_override_reason VARCHAR(255) NULL,

    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    subtotal_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

    condition_price_no VARCHAR(50) NULL,
    deal_no VARCHAR(50) NULL,

    deal_blocked BOOLEAN NOT NULL DEFAULT FALSE,

    rejection_status ENUM(
        'NONE',
        'REJECTED'
    ) NOT NULL DEFAULT 'NONE',

    rejection_reason VARCHAR(255) NULL,
    rejected_by VARCHAR(50) NULL,
    rejected_at DATETIME NULL,

    PRIMARY KEY (
        sales_order_no,
        item_no
    ),

    KEY idx_soi_product (
        product_id
    ),

    KEY idx_soi_parent (
        sales_order_no,
        parent_item_no
    ),

    CONSTRAINT fk_soi_header
        FOREIGN KEY (sales_order_no)
        REFERENCES sales_order(sales_order_no),

    CONSTRAINT fk_soi_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_soi_unit
        FOREIGN KEY (order_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_soi_price
        FOREIGN KEY (condition_price_no)
        REFERENCES price_condition(condition_price_no),

    CONSTRAINT fk_soi_deal
        FOREIGN KEY (deal_no)
        REFERENCES deal_condition(deal_no),

    CONSTRAINT fk_soi_rejected_by
        FOREIGN KEY (rejected_by)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_soi_parent
        FOREIGN KEY (
            sales_order_no,
            parent_item_no
        )
        REFERENCES sales_order_item(
            sales_order_no,
            item_no
        )

) ENGINE=InnoDB;


/* ============================================================
   22. DELIVERY HEADER

   Delivery = inventory allocation.
   ============================================================ */

CREATE TABLE delivery (
    delivery_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,
    sales_order_no VARCHAR(50) NOT NULL,

    source_customer_id VARCHAR(50) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    delivery_status ENUM(
        'DRAFT',
        'ALLOCATED',
        'SHIPPED',
        'PARTIALLY_DELIVERED',
        'DELIVERED'
    ) NOT NULL DEFAULT 'DRAFT',

    created_by VARCHAR(50) NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    allocated_at DATETIME NULL,
    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,

    PRIMARY KEY (delivery_no),

    KEY idx_del_so (
        sales_order_no
    ),

    KEY idx_del_source (
        source_customer_id
    ),

    KEY idx_del_customer (
        customer_id
    ),

    CONSTRAINT fk_del_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_del_so
        FOREIGN KEY (sales_order_no)
        REFERENCES sales_order(sales_order_no),

    CONSTRAINT fk_del_source
        FOREIGN KEY (source_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_del_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_del_creator
        FOREIGN KEY (created_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   23. DELIVERY ITEM
   ============================================================ */

CREATE TABLE delivery_item (
    delivery_no VARCHAR(50) NOT NULL,
    item_no INT UNSIGNED NOT NULL,

    sales_order_no VARCHAR(50) NOT NULL,
    sales_order_item_no INT UNSIGNED NOT NULL,

    product_id VARCHAR(50) NOT NULL,

    is_free_item BOOLEAN NOT NULL DEFAULT FALSE,

    allocated_qty DECIMAL(18,3) NOT NULL,
    delivery_unit VARCHAR(20) NOT NULL,

    PRIMARY KEY (
        delivery_no,
        item_no
    ),

    KEY idx_deli_soitem (
        sales_order_no,
        sales_order_item_no
    ),

    KEY idx_deli_product (
        product_id
    ),

    CONSTRAINT fk_deli_header
        FOREIGN KEY (delivery_no)
        REFERENCES delivery(delivery_no),

    CONSTRAINT fk_deli_soitem
        FOREIGN KEY (
            sales_order_no,
            sales_order_item_no
        )
        REFERENCES sales_order_item(
            sales_order_no,
            item_no
        ),

    CONSTRAINT fk_deli_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_deli_unit
        FOREIGN KEY (delivery_unit)
        REFERENCES unit_master(unit_code)

) ENGINE=InnoDB;


/* ============================================================
   24. SHIPMENT

   Shipment groups one or more Deliveries from same source.
   Shipment START = physical goods issue.
   ============================================================ */

CREATE TABLE shipment (
    shipment_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,
    source_customer_id VARCHAR(50) NOT NULL,

    shipment_status ENUM(
        'DRAFT',
        'READY',
        'IN_TRANSIT',
        'COMPLETED'
    ) NOT NULL DEFAULT 'DRAFT',

    created_by VARCHAR(50) NOT NULL,
    carrier_employee_id VARCHAR(50) NULL,

    vehicle_reference VARCHAR(100) NULL,

    created_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_on DATETIME NULL,
    started_on DATETIME NULL,
    completed_on DATETIME NULL,

    remarks VARCHAR(255) NULL,

    PRIMARY KEY (shipment_no),

    KEY idx_ship_company (
        company_id
    ),

    KEY idx_ship_source (
        source_customer_id
    ),

    CONSTRAINT fk_ship_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_ship_source
        FOREIGN KEY (source_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_ship_creator
        FOREIGN KEY (created_by)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_ship_carrier
        FOREIGN KEY (carrier_employee_id)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   25. SHIPMENT / DELIVERY
   One Delivery belongs to at most one Shipment.
   ============================================================ */

CREATE TABLE shipment_delivery (
    shipment_no VARCHAR(50) NOT NULL,
    delivery_no VARCHAR(50) NOT NULL,

    sequence_no INT UNSIGNED NULL,

    attached_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        shipment_no,
        delivery_no
    ),

    UNIQUE KEY uq_sd_delivery (
        delivery_no
    ),

    CONSTRAINT fk_sd_shipment
        FOREIGN KEY (shipment_no)
        REFERENCES shipment(shipment_no),

    CONSTRAINT fk_sd_delivery
        FOREIGN KEY (delivery_no)
        REFERENCES delivery(delivery_no)

) ENGINE=InnoDB;


/* ============================================================
   26. INVENTORY MOVEMENT

   Physical stock ledger.
   Allocation itself is NOT a physical movement.
   ============================================================ */

CREATE TABLE inventory_movement (
    movement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,
    product_id VARCHAR(50) NOT NULL,

    movement_type ENUM(
        'STOCK_COUNT_BASELINE',
        'STOCK_COUNT_VARIANCE',
        'GOODS_RECEIPT',
        'GOODS_ISSUE',
        'VAN_RETURN',
        'CUSTOMER_RETURN',
        'DAMAGE',
        'ADJUSTMENT'
    ) NOT NULL,

    quantity DECIMAL(18,3) NOT NULL,
    basic_unit VARCHAR(20) NOT NULL,

    reference_type VARCHAR(50) NULL,
    reference_no VARCHAR(50) NULL,
    reference_item_no INT UNSIGNED NULL,

    movement_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    created_by VARCHAR(50) NULL,

    remarks VARCHAR(255) NULL,

    PRIMARY KEY (movement_id),

    KEY idx_im_stock (
        customer_id,
        product_id,
        movement_datetime
    ),

    KEY idx_im_reference (
        reference_type,
        reference_no
    ),

    CONSTRAINT fk_im_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_im_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_im_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_im_unit
        FOREIGN KEY (basic_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_im_creator
        FOREIGN KEY (created_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   27. DELIVERY CONFIRMATION / POD
   ============================================================ */

CREATE TABLE delivery_confirmation (
    confirmation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    delivery_no VARCHAR(50) NOT NULL,
    delivery_item_no INT UNSIGNED NOT NULL,

    confirmed_qty DECIMAL(18,3) NOT NULL,
    confirmed_unit VARCHAR(20) NOT NULL,

    difference_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
    difference_unit VARCHAR(20) NOT NULL,

    difference_reason ENUM(
        'NONE',
        'EMPLOYEE_DAMAGE',
        'DISTRIBUTOR_DAMAGE',
        'CUSTOMER_REJECTED',
        'SHORT_DELIVERY',
        'RETURNED',
        'OTHER'
    ) NOT NULL DEFAULT 'NONE',

    confirmation_status ENUM(
        'CONFIRMED',
        'PARTIAL',
        'REJECTED'
    ) NOT NULL,

    confirmation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    confirmed_by VARCHAR(50) NOT NULL,

    remarks VARCHAR(255) NULL,

    PRIMARY KEY (confirmation_id),

    KEY idx_dconf_item (
        delivery_no,
        delivery_item_no
    ),

    CONSTRAINT fk_dconf_item
        FOREIGN KEY (
            delivery_no,
            delivery_item_no
        )
        REFERENCES delivery_item(
            delivery_no,
            item_no
        ),

    CONSTRAINT fk_dconf_confirm_unit
        FOREIGN KEY (confirmed_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_dconf_diff_unit
        FOREIGN KEY (difference_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_dconf_employee
        FOREIGN KEY (confirmed_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   28. INVOICE HEADER
   ============================================================ */

CREATE TABLE invoice (
    invoice_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    sales_order_no VARCHAR(50) NOT NULL,

    invoice_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date DATE NULL,

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

    invoice_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    settled_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

    payment_status ENUM(
        'UNPAID',
        'PARTIALLY_PAID',
        'PAID'
    ) NOT NULL DEFAULT 'UNPAID',

    payment_term ENUM(
        'IMMEDIATE',
        'PAY_LATER'
    ) NOT NULL DEFAULT 'IMMEDIATE',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (invoice_no),

    UNIQUE KEY uq_invoice_so (
        sales_order_no
    ),

    KEY idx_invoice_customer_status (
        customer_id,
        payment_status
    ),

    CONSTRAINT fk_invoice_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_invoice_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_invoice_so
        FOREIGN KEY (sales_order_no)
        REFERENCES sales_order(sales_order_no)

) ENGINE=InnoDB;


/* ============================================================
   29. INVOICE ITEM
   ============================================================ */

CREATE TABLE invoice_item (
    invoice_no VARCHAR(50) NOT NULL,
    item_no INT UNSIGNED NOT NULL,

    product_id VARCHAR(50) NOT NULL,

    is_free_item BOOLEAN NOT NULL DEFAULT FALSE,

    quantity DECIMAL(18,3) NOT NULL,
    invoice_unit VARCHAR(20) NOT NULL,

    unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,

    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    subtotal_amount DECIMAL(18,2) NOT NULL DEFAULT 0,

    sales_order_no VARCHAR(50) NOT NULL,
    sales_order_item_no INT UNSIGNED NOT NULL,

    PRIMARY KEY (
        invoice_no,
        item_no
    ),

    KEY idx_ii_soitem (
        sales_order_no,
        sales_order_item_no
    ),

    CONSTRAINT fk_ii_header
        FOREIGN KEY (invoice_no)
        REFERENCES invoice(invoice_no),

    CONSTRAINT fk_ii_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_ii_unit
        FOREIGN KEY (invoice_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_ii_soitem
        FOREIGN KEY (
            sales_order_no,
            sales_order_item_no
        )
        REFERENCES sales_order_item(
            sales_order_no,
            item_no
        )

) ENGINE=InnoDB;


/* ============================================================
   30. PAYMENT
   ============================================================ */

CREATE TABLE payment (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    payment_method ENUM(
        'CASH',
        'TRANSFER',
        'POS',
        'OTHER'
    ) NOT NULL,

    amount DECIMAL(18,2) NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    payment_reference VARCHAR(100) NULL,

    payment_status ENUM(
        'PENDING',
        'CONFIRMED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (payment_id),

    KEY idx_payment_customer (
        customer_id,
        payment_date
    ),

    CONSTRAINT fk_pay_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_pay_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id)

) ENGINE=InnoDB;


/* ============================================================
   31. PAYMENT ALLOCATION
   ============================================================ */

CREATE TABLE payment_allocation (
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_no VARCHAR(50) NOT NULL,

    allocated_amount DECIMAL(18,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        payment_id,
        invoice_no
    ),

    CONSTRAINT fk_pa_payment
        FOREIGN KEY (payment_id)
        REFERENCES payment(payment_id),

    CONSTRAINT fk_pa_invoice
        FOREIGN KEY (invoice_no)
        REFERENCES invoice(invoice_no)

) ENGINE=InnoDB;


/* ============================================================
   32. CUSTOMER CREDIT
   ============================================================ */

CREATE TABLE customer_credit (
    credit_no VARCHAR(50) NOT NULL,

    company_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(50) NOT NULL,

    credit_source ENUM(
        'POD_DAMAGE',
        'RETURN',
        'MANUAL_ADJUSTMENT',
        'OVERPAYMENT'
    ) NOT NULL,

    source_reference VARCHAR(100) NULL,

    original_amount DECIMAL(18,2) NOT NULL,
    remaining_amount DECIMAL(18,2) NOT NULL,

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    credit_status ENUM(
        'OPEN',
        'PARTIALLY_USED',
        'USED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'OPEN',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    remarks VARCHAR(255) NULL,

    PRIMARY KEY (credit_no),

    KEY idx_cc_customer_status (
        customer_id,
        credit_status
    ),

    CONSTRAINT fk_cc_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_cc_customer
        FOREIGN KEY (customer_id)
        REFERENCES customer_master(customer_id)

) ENGINE=InnoDB;


/* ============================================================
   33. CREDIT ALLOCATION
   ============================================================ */

CREATE TABLE credit_allocation (
    credit_no VARCHAR(50) NOT NULL,
    invoice_no VARCHAR(50) NOT NULL,

    allocated_amount DECIMAL(18,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        credit_no,
        invoice_no
    ),

    CONSTRAINT fk_ca_credit
        FOREIGN KEY (credit_no)
        REFERENCES customer_credit(credit_no),

    CONSTRAINT fk_ca_invoice
        FOREIGN KEY (invoice_no)
        REFERENCES invoice(invoice_no)

) ENGINE=InnoDB;


/* ============================================================
   SEED BASIC COMPANIES
   ============================================================ */

INSERT INTO company_master (
    company_id,
    company_name
) VALUES
    ('EMANL', 'Euro Mega Atlantic Nigeria LTD'),
    ('PB',    'Prime Bisco Limited'),
    ('NB',    'New Bisco Limited'),
    ('PF',    'Primera Food Limited');


/* ============================================================
   SEED BASIC UNITS
   ============================================================ */

INSERT INTO unit_master (
    unit_code,
    unit_description
) VALUES
    ('PCS', 'Pieces'),
    ('CTN', 'Carton'),
    ('KG',  'Kilogram'),
    ('TON', 'Ton');


/* ============================================================
   END
   ============================================================ */


/* ============================================================
   33. TRANSIT STOCK  (Phase 9)
   Issued-but-unaccepted stock held by the delivery employee.
   GOODS_ISSUE stays immutable; a POD difference either sits here
   (REUSABLE / DAMAGED / DISCREPANCY / PENDING_SOURCE_RECEIPT)
   or was resolved explicitly (REALLOCATED / RETURNED / LOSS /
   WRITTEN_OFF). original_quantity is immutable audit history;
   quantity is the remaining open balance.
   ============================================================ */

CREATE TABLE transit_stock (
    transit_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id              VARCHAR(20)  NOT NULL,
    origin_shipment_no      VARCHAR(50)  NOT NULL,
    origin_delivery_no      VARCHAR(50)  NOT NULL,
    origin_delivery_item_no INT UNSIGNED NOT NULL,
    source_customer_id      VARCHAR(50)  NOT NULL,
    product_id              VARCHAR(50)  NOT NULL,

    original_quantity       DECIMAL(18,3) NOT NULL,
    quantity                DECIMAL(18,3) NOT NULL,

    basic_unit              VARCHAR(20)  NOT NULL,

    holding_employee_id     VARCHAR(50)  NOT NULL,
    claimed_by_employee_id  VARCHAR(50)  NULL,
    verified_by_employee_id VARCHAR(50)  NULL,

    transit_status ENUM(
        'REUSABLE',
        'DAMAGED',
        'DISCREPANCY',
        'PENDING_SOURCE_RECEIPT',
        'REALLOCATED',
        'RETURNED',
        'LOSS',
        'WRITTEN_OFF'
    ) NOT NULL,

    liability_party ENUM(
        'NONE',
        'EMPLOYEE',
        'DISTRIBUTOR'
    ) NOT NULL DEFAULT 'NONE',

    origin_confirmation_id  BIGINT UNSIGNED NOT NULL,

    parent_transit_id       BIGINT UNSIGNED NULL,
    resolved_to_delivery_no VARCHAR(50)  NULL,
    resolved_movement_id    BIGINT UNSIGNED NULL,
    resolved_by             VARCHAR(50)  NULL,
    resolved_at             DATETIME NULL,
    remarks                 VARCHAR(255) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,

    PRIMARY KEY (transit_id),

    KEY idx_ts_holder (holding_employee_id, transit_status),
    KEY idx_ts_origin (origin_delivery_no, origin_delivery_item_no),
    KEY idx_ts_source (source_customer_id, product_id, transit_status),
    KEY idx_ts_company (company_id, transit_status),

    CONSTRAINT fk_ts_company
        FOREIGN KEY (company_id)
        REFERENCES company_master(company_id),

    CONSTRAINT fk_ts_shipment
        FOREIGN KEY (origin_shipment_no)
        REFERENCES shipment(shipment_no),

    CONSTRAINT fk_ts_delivery
        FOREIGN KEY (origin_delivery_no)
        REFERENCES delivery(delivery_no),

    CONSTRAINT fk_ts_source
        FOREIGN KEY (source_customer_id)
        REFERENCES customer_master(customer_id),

    CONSTRAINT fk_ts_product
        FOREIGN KEY (product_id)
        REFERENCES product_master(product_id),

    CONSTRAINT fk_ts_unit
        FOREIGN KEY (basic_unit)
        REFERENCES unit_master(unit_code),

    CONSTRAINT fk_ts_holder
        FOREIGN KEY (holding_employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_ts_claimed_by
        FOREIGN KEY (claimed_by_employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_ts_verified_by
        FOREIGN KEY (verified_by_employee_id)
        REFERENCES employee_master(employee_id),

    CONSTRAINT fk_ts_confirmed
        FOREIGN KEY (origin_confirmation_id)
        REFERENCES delivery_confirmation(confirmation_id),

    CONSTRAINT fk_ts_parent
        FOREIGN KEY (parent_transit_id)
        REFERENCES transit_stock(transit_id),

    CONSTRAINT fk_ts_resolved_delivery
        FOREIGN KEY (resolved_to_delivery_no)
        REFERENCES delivery(delivery_no),

    CONSTRAINT fk_ts_resolved_movement
        FOREIGN KEY (resolved_movement_id)
        REFERENCES inventory_movement(movement_id),

    CONSTRAINT fk_ts_resolved_by
        FOREIGN KEY (resolved_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;


/* ============================================================
   34. TRANSIT STOCK ALLOCATION  (Phase 9)
   REUSABLE transit quantity reserved for a Delivery Item.

   Lifecycle (implementation ruling 3): delivery allocation is
   REVERSIBLE until Shipment START.
     ACTIVE     - reserved against the transit balance
     RELEASED   - delivery released before START: quantity returned
                  to the transit row (REUSABLE again); row kept
     FINALIZED  - Shipment START: physically irreversible; only
                  POD/return/resolution may consume it further
   Surrogate PK: an audit-safe lifecycle may create several rows
   for the same (transit, delivery item) over time; history is
   never deleted. delivery_item_no is recorded but intentionally NOT
   FK-bound: Delivery re-allocation legitimately rebuilds delivery_item
   rows while released reservation history is kept. ACTIVE/FINALIZED
   allocations are service-enforced to reference existing items.

   INTENTIONAL RELATIONAL CHARACTERISTIC: transit_stock_allocation.
   delivery_no + delivery_item_no is a HISTORICAL application-level
   reference, not a guaranteed live FK identity. A RELEASED reservation
   may survive the rebuilding/removal of its corresponding Delivery Item
   (re-allocation replaces draft lines wholesale). Code consuming
   historical allocation rows must NOT assume the referenced Delivery
   Item still exists — resolve through the delivery and tolerate a
   missing item for RELEASED rows.
   ============================================================ */

CREATE TABLE transit_stock_allocation (
    transit_allocation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    transit_id            BIGINT UNSIGNED NOT NULL,
    delivery_no           VARCHAR(50) NOT NULL,
    delivery_item_no      INT UNSIGNED NOT NULL,

    allocated_qty         DECIMAL(18,3) NOT NULL,
    allocated_by          VARCHAR(50)  NOT NULL,
    allocated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    alloc_status ENUM(
        'ACTIVE',
        'RELEASED',
        'FINALIZED'
    ) NOT NULL DEFAULT 'ACTIVE',

    released_at  DATETIME NULL,
    finalized_at DATETIME NULL,

    PRIMARY KEY (transit_allocation_id),

    KEY idx_tsa_transit (transit_id),
    KEY idx_tsa_delivery (delivery_no, delivery_item_no),

    CONSTRAINT fk_tsa_transit
        FOREIGN KEY (transit_id)
        REFERENCES transit_stock(transit_id),

    CONSTRAINT fk_tsa_delivery
        FOREIGN KEY (delivery_no)
        REFERENCES delivery(delivery_no),

    CONSTRAINT fk_tsa_by
        FOREIGN KEY (allocated_by)
        REFERENCES employee_master(employee_id)

) ENGINE=InnoDB;
