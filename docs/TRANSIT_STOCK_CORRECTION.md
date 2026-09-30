# TRANSIT STOCK CORRECTION — REV 3 (APPROVED — IMPLEMENTED AS PHASE 9)

Status: **REV 3 approved and implemented** (three implementation rulings on
mixed-source derivation, return authorization, and the reversible allocation
boundary). DDL applied to both databases; canonical `ddl.sql` updated. See
`app/app/Services/TransitService.php` and the Phase 9 tests
(`TransitTest`, `TransitConcurrencyTest`).

REV 3 changes vs REV 2 (per review rulings):

1. **`PENDING_SOURCE_RECEIPT` KEPT** — an `AT_SOURCE` claim never restores
   source inventory directly; separate source-side verification → VAN_RETURN
   → source unrestricted += qty → RETURNED. **The claiming employee can never
   verify their own receipt.**
2. **Actors ruled** — holding employee: reallocate own REUSABLE stock,
   initiate return, report operational discrepancy resolution;
   COMPANY_ADMIN: view/manage company transit, resolve/override
   discrepancies, verify source receipts, perform WRITTEN_OFF / LOSS;
   SUPERADMIN: cross-company. All checks company-scoped and custody-aware.
3. **Employee handover DEFERRED** — no TRANSFER workflow in Phase 9.
4. **Customer refusal ruled** — `CUSTOMER_REJECTED` + intact goods retained by
   the delivery employee = REUSABLE.
5. **Terminology** — disposition `WITH_SALES` renamed **`WITH_EMPLOYEE`**
   (custody is `holding_employee_id`).
6. **`delivery.fulfillment_source` REMOVED** — fulfillment source cannot live
   at header level: one Delivery may legitimately combine source STOCK and
   TRANSIT portions on one item (10 CTN = 8 stock + 2 transit). Mixed-source
   allocation is designed explicitly (§6); `delivery` itself is NOT modified.
7. **Split-row model demonstrated** with the required 5 = 2 + 3 worked
   database example (§10) — kept; no generic ledger introduced.

Approved unchanged from REV 2: reason × physical-disposition model,
discrepancy handling, immutable GOODS_ISSUE, explicit source return, damage
accountability, conservation rule, Finance boundary, transit identity,
employee-as-holder, shipment-COMPLETED-ends-nothing.

---

## 1. Dispositions and state derivation (REV 3 terms)

POD input `difference_disposition` (required when `difference_qty ≠ 0`):
`WITH_EMPLOYEE` | `DAMAGED` | `AT_SOURCE` | `UNKNOWN`. Invalid reason ×
disposition combinations rejected 422.

| Reason | Disposition | Initial transit state |
|---|---|---|
| `CUSTOMER_REJECTED` | `WITH_EMPLOYEE` | **REUSABLE** (ruled) |
| `CUSTOMER_REJECTED` | `UNKNOWN` | **DISCREPANCY** |
| `EMPLOYEE_DAMAGE` | `DAMAGED` | **DAMAGED** (liability_party = EMPLOYEE) |
| `DISTRIBUTOR_DAMAGE` | `DAMAGED` | **DAMAGED** (liability_party = DISTRIBUTOR) |
| `RETURNED` | `WITH_EMPLOYEE` | **REUSABLE** |
| `RETURNED` | `AT_SOURCE` | **PENDING_SOURCE_RECEIPT** |
| `SHORT_DELIVERY` | *any* | **DISCREPANCY** — never auto-stock |
| `OTHER` | *any* | **DISCREPANCY** — no inference |

Damage is always a damage reason. `SHORT_DELIVERY` / `OTHER` never infer
stock state.

## 2. Transit identity (unchanged, restated)

Every transit row carries: company → origin **shipment** → origin **delivery**
→ origin **delivery item** → source customer → product → quantity/basic unit
→ `holding_employee_id` → `transit_status` (+ liability when damaged). The
immutable GOODS_ISSUE is reachable transitively via the origin delivery item.
No anonymous product-level pool ever exists.

## 3. Holder model (unchanged, restated)

Holder = **employee** (`holding_employee_id` FK employee_master), matching
`delivery_confirmation.confirmed_by`. A shipment reaching COMPLETED changes
nothing about transit rows: transport closure ≠ custody end. Rows are held by
the employee until an explicit exit consumes them. Employee-to-employee
TRANSFER is deferred (ruled).

## 4. Authorization matrix (ruled — company-scoped + custody-aware)

| Action | Holding employee | Company admin (own company) | Superadmin |
|---|---|---|---|
| View own transit rows | ✓ | ✓ (company-wide) | ✓ (all companies) |
| Reallocate REUSABLE | ✓ **own rows only** | — (admin does not hold custody) | ✓ |
| Initiate return (PENDING_SOURCE_RECEIPT claim / return request) | ✓ own rows | ✓ | ✓ |
| **Verify source receipt** (→ VAN_RETURN → RETURNED) | ✗ **never own claim** | ✓ | ✓ |
| Report operational discrepancy resolution (found → REUSABLE/DAMAGED) | ✓ own rows | ✓ | ✓ |
| Resolve/override discrepancy (incl. LOSS) | — | ✓ | ✓ |
| WRITTEN_OFF | — | ✓ | ✓ |

Enforced in the service (app convention), not the schema:
`isSalesEmployee()` + `currentCompanyId()` + row custody check;
`isCompanyAdmin()` + company scope; `isSuperadmin()` bypass. Self-verification
is structurally blocked: the verifier's `employee_id` must differ from
`claimed_by_employee_id` (stored on the row at claim time) — the service
rejects 422 otherwise, superadmin included.

## 5. Mixed-source Delivery allocation (the §-level structural fix)

**Problem (ruled)**: fulfillment source cannot be a `delivery` header
attribute — one delivery item may combine 8 CTN from source stock with 2 CTN
from reusable transit. `delivery` stays untouched; the fulfillment mix lives
where it is true: per allocation portion.

### 5.1 Line-level fulfillment model

A Delivery Item's physical backing = the sum of its **portions**:

- **STOCK portion** — the existing mechanics, byte-for-byte:
  `allocateLine`'s unrestricted → restricted transfer on the source inventory
  row (START later writes the GI for exactly this portion).
- **TRANSIT portion** — a `transit_stock_allocation` link row: consumes
  REUSABLE `transit_stock` quantity into this delivery item (no GI ever, no
  inventory touch).

The portion split is persisted on the `transit_stock_allocation` rows for the
transit side; the stock side remains implicit exactly as today (delivery_item
+ the locked inventory row). Nothing about existing pure-stock behavior
changes — a delivery with zero transit portions behaves identically to Phase
6/7.

### 5.2 Input shape (no DDL, no API break)

`createAndAllocate` line input gains an OPTIONAL per-line transit component:

```php
['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'CTN',
 'transit_qty' => '2']   // optional; default '0' = pure stock (today's behavior)
```

`transit_qty` is basic-converted and validated ≤ requested qty. Stock portion
= requested − transit. `reallocation of transit WITHOUT demand` remains
impossible: the input still targets an SO item line.

### 5.3 The transaction (order of operations inside one DB transaction)

1. `prepareLines` as today (item eligibility, unit conversion).
2. Lock the **SO row FOR UPDATE** (demand anchor — see 5.4) and lock the
   source inventory row (product order, as today).
3. Compute `remainingBasicQty(item)` under the locks.
4. Validate: `requested_basic ≤ remaining` **and**
   `transit_basic ≤ Σ REUSABLE transit of (product, source_customer = SO
   source, holding_employee = actor)` — both checks against locked state.
5. Stock portion: existing unrestricted → restricted transfer (guarded,
   all-or-nothing).
6. Transit portion: consume REUSABLE rows FIFO by `created_at`, split-row
   model (§10), writing `transit_stock_allocation` links
   (`transit_id, delivery_no, delivery_item_no, allocated_qty`);
   REUSABLE → REALLOCATED with `resolved_to_delivery_no/item_no`.
7. `delivery_item` created once with the FULL requested qty/unit (the POD and
   invoice quantity — exactly as the ruled example: item says 10 CTN).
8. Delivery → ALLOCATED; SO fulfillment recomputed (unchanged).

Partial-failure safety: steps 5–7 are in the same transaction; any violation
rolls back everything (never a half-mixed delivery).

### 5.4 Over-allocation safety — the demand-anchor rule (why the SO lock)

Today's race safety comes from locking the inventory row and computing
`remainingBasicQty` under it. A TRANSIT portion bypasses the inventory row,
so the demand check needs its own serialization point:

- **Every mixed/pure allocation locks the SO row FOR UPDATE** before
  computing `remainingBasicQty`. Two concurrent allocations of the same SO
  item (stock, transit, or mixed) serialize on the SO row → the sum of their
  portions can never exceed remaining demand.
- **Two concurrent transit consumptions of the same transit row** serialize
  on that row's `lockForUpdate` (FIFO consumption order) → cannot
  double-consume; each sees the other's committed decrement.
- Invariant enforced after consumption: per transit row,
  `Σ allocated_qty (links) ≤ original_quantity`; per SO item,
  `Σ delivery_item basic ≤ order_qty basic` (re-derivable, asserted in
  tests).

### 5.5 Shipment START with mixed portions

- STOCK portion: exactly today's behavior — restricted → 0, ONE GI movement
  per delivery item for the stock-portion basic qty.
- TRANSIT portion: **no movement, no inventory touch** (goods were issued
  once, at the origin GI). START remains all-or-nothing: it re-verifies under
  lock that the stock portion's restricted quantity is still present; the
  transit portion needs no stock check — the link rows are the proof of
  possession.
- Origin lineage survives START: `transit_stock_allocation` rows are never
  deleted; the origin delivery item and shipment remain reachable from the
  transit row.

### 5.6 POD / finance on mixed deliveries

POD operates on the combined `allocated_qty` normally (10 CTN); confirmed qty
invoices the sold-to customer (Phase 8 untouched). A **new** difference on the
second delivery creates a **new** transit row whose origin is the second
delivery item — hop-by-hop lineage. The first delivery's transit link rows
stay REALLOCATED (history).

## 6. Return to source (ruled flow, unchanged)

```
employee (holding) initiates: REUSABLE → PENDING_SOURCE_RECEIPT
  (claimed_by_employee_id stamped, claim remarks)
company admin verifies (NOT the claimant):
  1. inventory_movement: VAN_RETURN, customer_id = source, +qty,
     reference_type='TRANSIT_RETURN', reference_no=transit_id
  2. inventory.unrestricted_qty += qty (source row, locked, created if absent)
  3. transit_status = RETURNED (verified_by_employee_id stamped)
```

Original GI never reversed/edited/deleted. Discrepancy "found at source"
resolves through the same receipt action.

## 7. Damage and discrepancy (unchanged, restated)

- DAMAGED rows are non-terminal, represented until WRITTEN_OFF (admin/
  superadmin); liability_party captured at creation; liability accounting
  deferred.
- DISCREPANCY resolutions: found → REUSABLE; damaged → DAMAGED (+liability);
  lost → LOSS; found at source → source receipt (→ RETURNED). Admin can
  override any operational resolution; LOSS/WRITTEN_OFF are admin-only.

## 8. Final minimal DDL (Phase 9 — pending approval)

Two additive structures; **`delivery`, all enums, all existing columns
untouched**.

```sql
/* 33. TRANSIT STOCK — issued-but-unaccepted stock held by an employee */
CREATE TABLE transit_stock (
    transit_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    company_id              VARCHAR(20)  NOT NULL,      -- FK company_master
    origin_shipment_no      VARCHAR(50)  NOT NULL,      -- FK shipment
    origin_delivery_no      VARCHAR(50)  NOT NULL,      -- FK delivery
    origin_delivery_item_no INT UNSIGNED NOT NULL,
    source_customer_id      VARCHAR(50)  NOT NULL,      -- FK customer_master
    product_id              VARCHAR(50)  NOT NULL,      -- FK product_master

    original_quantity       DECIMAL(18,3) NOT NULL,     -- immutable (audit)
    quantity                DECIMAL(18,3) NOT NULL,     -- remaining open qty
    basic_unit              VARCHAR(20)  NOT NULL,      -- FK unit_master

    holding_employee_id     VARCHAR(50)  NOT NULL,      -- FK employee_master
    claimed_by_employee_id  VARCHAR(50)  NULL,          -- AT_SOURCE claimant
    verified_by_employee_id VARCHAR(50)  NULL,          -- source-receipt verifier

    transit_status          ENUM('REUSABLE','DAMAGED','DISCREPANCY',
                                 'PENDING_SOURCE_RECEIPT',
                                 'REALLOCATED','RETURNED','LOSS',
                                 'WRITTEN_OFF') NOT NULL,

    liability_party         ENUM('NONE','EMPLOYEE','DISTRIBUTOR')
                            NOT NULL DEFAULT 'NONE',

    origin_confirmation_id  BIGINT UNSIGNED NOT NULL,   -- FK delivery_confirmation
    parent_transit_id       BIGINT UNSIGNED NULL,        -- split lineage
    resolved_to_delivery_no VARCHAR(50)  NULL,           -- REALLOCATED target
    resolved_movement_id    BIGINT UNSIGNED NULL,        -- RETURNED: VAN_RETURN id
    resolved_by             VARCHAR(50)  NULL,           -- FK employee_master
    resolved_at             DATETIME NULL,
    remarks                 VARCHAR(255) NULL,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NULL,

    PRIMARY KEY (transit_id),
    KEY idx_ts_holder  (holding_employee_id, transit_status),
    KEY idx_ts_origin  (origin_delivery_no, origin_delivery_item_no),
    KEY idx_ts_source  (source_customer_id, product_id, transit_status),
    KEY idx_ts_company (company_id, transit_status)
    -- FKs per project convention
) ENGINE=InnoDB;

/* 34. TRANSIT STOCK ALLOCATION — REUSABLE qty consumed into a delivery item */
CREATE TABLE transit_stock_allocation (
    transit_id       BIGINT UNSIGNED NOT NULL,   -- FK transit_stock
    delivery_no      VARCHAR(50) NOT NULL,       -- FK delivery
    delivery_item_no INT UNSIGNED NOT NULL,      -- FK delivery_item

    allocated_qty    DECIMAL(18,3) NOT NULL,     -- basic units
    allocated_by     VARCHAR(50)  NOT NULL,      -- FK employee_master
    allocated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (transit_id, delivery_no, delivery_item_no),
    KEY idx_tsa_delivery (delivery_no, delivery_item_no)
    -- FKs per project convention
) ENGINE=InnoDB;
```

Service-enforced rules (no DB triggers, app convention): one non-terminal row
per origin delivery item (splits consume from that row); `quantity ≤
original_quantity` always; `original_quantity` written once at creation and
never updated; terminal rows immutable. Composite-PK link rows keep the
extend-row pattern if the same delivery item ever links the same transit row
again.

## 9. Authorization & concurrency tests (committed with Phase 9)

1. Employee A cannot reallocate/consume Employee B's transit stock (422).
2. Employee A cannot verify their own AT_SOURCE return (422).
3. Company admin verifies source receipt → VAN_RETURN + source unrestricted
   += qty + RETURNED.
4. Foreign-company admin cannot view or act on the transit row (403/404).
5. Reallocation cannot exceed reusable balance (422, no partial consume).
6. Concurrent reallocations cannot double-consume (pcntl row-lock race, two
   racers, Σ consumed = balance, one racer may legally lose).
7. Combined STOCK + TRANSIT allocation cannot exceed remaining SO demand —
   including a concurrent stock-vs-transit race on the same SO item
   (serialized on the SO row lock).
8. Split + partial consumption conservation (Example A, §10).
9. Mixed delivery START: exactly one GI for the stock portion; transit
   portion generates no movement.
10. POD on mixed delivery invoices the combined confirmed qty only.

## 10. Split-row worked example (required — partial consumption then return)

Existing state (all basic units, one product, one origin delivery item I1 of
delivery DEL-A, shipment SHP-A, source MIMZA):

```
transit_stock
  transit_id=100 | company C | origin SHP-A/DEL-A/I1 | source MIMZA
  original_quantity=5 | quantity=5 | holder=EMP-X
  status=REUSABLE | origin_confirmation_id=… | parent=NULL
```

**Action 1 — EMP-X reallocates 2 CTN to Delivery B, item 1** (valid SO demand
on file; one transaction):

| Row | Effect |
|---|---|
| `transit_stock` id=100 | `quantity 5 → 3` (original stays 5); status stays **REUSABLE** (open remainder) |
| `transit_stock` id=101 | NEW child row: `parent_transit_id=100`, `original_quantity=2`, `quantity=2`, holder=EMP-X, same origin SHP-A/DEL-A/I1, status **REALLOCATED**, `resolved_to_delivery_no=DEL-B` |
| `transit_stock_allocation` | NEW: `(transit_id=101, DEL-B, item 1, allocated_qty=2, allocated_by=EMP-X)` |

Delivery B item 1 = 2 CTN backed 100% by a TRANSIT portion (no inventory row
touched, no GI). Origin chain of id=101 → parent 100 → DEL-A/I1 → SHP-A.

**Action 2 — EMP-X initiates return of the remaining 3; admin (NOT EMP-X)
verifies** (one transaction):

| Row | Effect |
|---|---|
| `transit_stock` id=100 | status **REUSABLE → RETURNED**, `quantity=0`, `resolved_by`=admin, `resolved_movement_id`=VAN_RETURN id |
| `inventory_movement` | NEW: VAN_RETURN, MIMZA, +3, reference TRANSIT_RETURN/100 |
| `inventory` (MIMZA, product) | `unrestricted_qty += 3` |

**Proof of conservation and audit integrity:**

```
5 (original_quantity of root row 100)
= 2 REALLOCATED (child row 101 → consumed by DEL-B)
+ 3 RETURNED   (root row 100 → VAN_RETURN + 3)
```

- No quantity was overwritten destructively: `original_quantity=5` is
  immutable on both rows; the root's `quantity` went 5 → 3 → 0 in logged,
  transactional steps; the consumed 2 lives on its own child row.
- The full lineage DEL-A/I1 → (100 → 101 → DEL-B) and (100 → VAN_RETURN)
  remains queryable forever; terminal rows are history, never deleted.
- Σ allocations (link rows) per transit row ≤ original_quantity holds (2 ≤ 2
  on 101; root consumed via its own lifecycle).

## 11. Finance boundary (unchanged, restated)

Invoice quantity = POD accepted/confirmed quantity; reusable, damaged,
rejected, missing or returned quantities never accepted are never invoiced to
that customer; no customer credit for never-invoiced quantity. Phase 9 adds
no invoice/credit/payment behavior.

## 12. State machine (REV 3 — actors on transitions)

```
        POD difference (reason × disposition)
                     │
   ┌─────────────────┼──────────────────────────┐
   ▼                 ▼                          ▼
REUSABLE ◄─ FOUND ─ DISCREPANCY ─ DAMAGED_CONFIRMED → DAMAGED
   │  (emp own /   (SHORT/OTHER/      (admin may override op. resolution)
   │   admin)       UNKNOWN)             │
   │                                    │ (future liability workflow)
   ├─ REALLOCATE (holding emp,          ├─ WRITTEN_OFF (admin/super) [terminal]
   │  SO demand, ≤ balance)             │
   │   → REALLOCATED [terminal]         │
   │                                    │
   ├─ INITIATE RETURN (holding emp / admin)
   │   → PENDING_SOURCE_RECEIPT ── VERIFY (admin/super, ≠ claimant)
   │            ▲                        → VAN_RETURN + source += → RETURNED
   └─ (RETURNED + AT_SOURCE at POD) ──────┘        [terminal]

DISCREPANCY → LOSS (admin/super) [terminal; liability deferred]
DISCREPANCY → found at source → (receipt) → RETURNED

Terminal: REALLOCATED · RETURNED · LOSS · WRITTEN_OFF
Non-terminal: REUSABLE · DAMAGED · DISCREPANCY · PENDING_SOURCE_RECEIPT
```

Every transition: explicit service action, DB transaction, `lockForUpdate`,
`resolved_by` / `resolved_at` / reference stamps.

## 13. Invariants preserved

- GOODS_ISSUE immutable; START writes GI only for the STOCK portion of a
  delivery item; transit portions never generate a second GI.
- Unaccepted stock never vanishes: REUSABLE / DAMAGED / DISCREPANCY /
  PENDING_SOURCE_RECEIPT — always a row, never silence.
- Source available stock changes only via verified return-to-source /
  source receipt (never by the claimant themselves).
- No restore-before-return; reallocation never pretends a Primary round trip.
- Damaged stock is never reusable; discrepancy never manufactures stock.
- Reallocation requires confirmed SO demand; total allocation (stock +
  transit) can never exceed remaining SO demand (SO-row serialization).
- Consumption ≤ reusable transit balance, split rows preserve full audit.
- Custody-aware, company-scoped authorization per the ruled matrix.
- Finance boundary untouched (Phase 8 accepted behavior).

*End of REV 3 proposal. No DDL and no service changes until approved as
Phase 9.*
