# Simple Multi-Company ERP — Architecture

Source of truth: `ddl.sql` (MariaDB 13) + the requirements brief. Nothing in the DDL was redesigned; Laravel adapts to it.

---

## 1. Overview

A multi-company field-sales distribution ERP. Sales employees work on phones in
low-connectivity areas; company admins and superadmins work on desktop/tablet.

The domain flows in one direction:

```
Master data ──► Sales Order (DEMAND ONLY)
                     │ confirmed → Invoice snapshot (credit blocking anchor)
                     ▼
               Delivery (inventory ALLOCATION: unrestricted → restricted)
                     │ grouped into Shipment (same company + source customer)
                     ▼
               Shipment START (restricted → 0, immutable GOODS_ISSUE ledger entry)
                     │
                     ▼
               Delivery Confirmation / POD ──► final invoice (confirmed qty only;
                     │                          differences stay in transit / return
                     ▼                          workflows — never auto-credited)
               Payment / Credit Allocation ──► invoice settled → unblocks customer
```

Key invariants (enforced in services, not the schema):

- **Sales Order = demand.** No inventory effect, no reservation. Confirmed demand is immutable.
- **Delivery = allocation.** `unrestricted_qty -= x`, `restricted_qty += x`. On-hand unchanged. Row-locked, transactional.
- **Shipment START = physical issue.** `restricted_qty -= x` + `GOODS_ISSUE` movement. Transactional and idempotent (status guard + row lock).
- **Issued-but-unaccepted stock stays in transit.** GOODS_ISSUE is immutable and POD restores nothing to the source automatically; unaccepted quantity remains in the delivery operation's possession until reallocated or explicitly returned (proposal: docs/TRANSIT_STOCK_CORRECTION.md).
- **Invoice = snapshot** created at SO confirmation; never rewritten when master pricing changes.
- **Credit blocking** is independent of stock availability: a customer with a not-fully-settled invoice cannot create another order. (Audit correction: the block is enforced at every safe post-confirmation boundary too — new Delivery allocation and Shipment START. POD / rejection / transit / return of stock that already left the source are deliberately NEVER blocked.)
- **Inventory can belong to PRIMARY / SHIP_TO / VAN** customers only (`inventory.customer_id`).

## 2. Laravel / directory strategy

Plain Laravel, no CQRS/DDD/repository layers. Substantial business logic lives in
`app/Services/*` services called from controllers; policies in `app/Policies`;
request validation in `app/Http/Requests`.

```
app/
  Enums/                  role, status enums mirrored from DDL ENUMs (PHP casts)
  Models/                 one per DDL table (33)
  Services/
    SalesOrderService     create/confirm, deal evaluation, invoice snapshot
    DeliveryAllocationService  row-locked allocation
    ShipmentService       attach, start (goods issue), complete
    StockCountService     submit + authoritative baseline (restricted==0 guard)
    InvoiceService        status recalculation
    PaymentService        record + allocate
    CreditService         issue + allocate
    SyncService           idempotent offline ingestion
  Http/
    Controllers/ (Web + Api/Offline namespaces)
    Middleware/ EnsureActiveUser, EnsureCompanyContext
    Requests/
  Policies/
resources/
  views/  layouts/ components/ (Material 3-flavored Blade components)
  js/     pwa (service worker registration, IndexedDB outbox, sync queue)
public/   manifest.webmanifest, sw.js, offline icons
```

ID generation: document numbers (`sales_order_no`, `delivery_no`, `invoice_no`,
`count_no`, `credit_no`, `deal_no`, `condition_price_no`) are generated in
services (company prefix + year + zero-padded sequence guarded by the enclosing
transaction), not by the DB.

## 3. Authentication & authorization

- **Auth against `app_user`** — Laravel's default `users` table is NOT used.
  - `Auth::login` uses the `AppUser` model (`user_id` PK, `password` column aliased
    to `password_hash`, `active` flag).
  - `EnsureActiveUser` middleware rejects `active = false` (also re-checked on login).
  - The default `users`, `password_reset_tokens`, `sessions`, `cache`,
    `jobs` migrations are removed from the erpsimple schema; Laravel's own
    session/cache/queue/password-reset tables are added as **infrastructure**
    migrations (prefixed, no FKs) — they are not ERP data.
  - Breeze Blade pages are adapted: login form, no registration/self-service;
    password reset writes `password_hash` via the model.
- **Roles**: `SUPERADMIN` (all companies + app administration), `COMPANY_ADMIN`
  (their `company_id` only), `SALES_EMPLOYEE` (their employee's company, assigned
  customers, product scope). Enforced by:
  - `App\Models\Concerns\BelongsToCompany` scope helpers (`scopeForCompany`, `scopeForUser`),
    applied in controllers/services via a `CompanyContext` singleton (superadmin can switch).
  - Gates: `view-company`, `manage-master-data`, `manage-own-operations`, plus
    policies per model (`SalesOrderPolicy`, `CustomerPolicy`, …).
  - **Server-side re-validation on every write**: company/customer/product
    identifiers from the frontend are never trusted. Offline sync payloads go
    through the same validation.
- `employee_product`: empty rows ⇒ all active products of the employee's company.
- Assignment rules: sales employee must have an active `customer_employee` row for
  the customer they sell to; supplying customer must be PRIMARY (or the SHIP_TO's
  parent) AND assigned to the employee via `customer_employee` (corrected in
  Phase 4 — see "Supplying-Primary eligibility" in §6); source = supplying
  primary (or its SHIP_TO child).
- SO customer identifiers (`supplying` / `source` / `sold_to`) are stored and
  validated independently (see risk 3 — the debtor is the sold-to Secondary).
- **POD is the billing boundary** (Phase 7/8): `invoice.customer_id =
  sales_order.sold_to_customer_id` and final invoice quantities come from
  POD-confirmed quantities, priced from the SO item snapshot — never from
  GOODS_ISSUE; see §10 Finance design.
- FJP rotation (business correction): `customer_fjp.preferred_week` is a position
  (1–4) in a **continuous 4-week field-sales rotation** — NOT week-of-month, and
  it never resets at month/year boundaries. The cycle Week 1→2→3→4→1… is anchored
  to a configured Monday (`FJP_ROTATION_ANCHOR` / `config/fjp.php`, validated to
  be a Monday): `rotation_week = floor(weeks_since_anchor % 4) + 1`.
  `preferred_day` is the weekday inside that rotation week; Today's Visits match
  (current rotation week + today's weekday). `preferred_week = NULL` means every
  week on the configured day.

## 4. PWA / offline architecture

**Online-first.** Anything touching stock, credit eligibility, pricing, deals,
allocations, shipment start, payments/credits requires live server state.

- Manifest + service worker (`sw.js`): precache app shell/static assets;
  network-first for pages with offline fallback; never cache authenticated HTML
  across users.
- **IndexedDB outbox** (`resources/js/pwa/outbox.js`): offline-captured operations
  (Phase 3: attendance; later: stock count drafts, order drafts) stored as
  `{ uuid, type, payload, local_created_at, state, retries, last_error, server_ref }`
  with sync states `LOCAL_ONLY → PENDING_SYNC → SYNCING → SYNCED | CONFLICT | FAILED`.
- **Idempotency** (`SyncService::process`): every sync-capable endpoint requires a
  client-generated UUID (header `X-Idempotency-Key` or payload) and records it in
  the `idempotency_keys` infrastructure table, scoped **endpoint + user + key**.
  First execution stores the serialized response; a retry replays it (header
  `X-Idempotent-Replay: 1`) instead of duplicating the transaction. A failed
  execution releases the key so a corrected retry can re-run. A lost HTTP
  response followed by a retry therefore cannot create duplicate attendance —
  proven by test (`test_sync_retry_does_not_duplicate_attendance`).
- **Device timestamps are not authoritative**: the device-claimed capture time is
  PERSISTED in `customer_visit_attendance.device_captured_at` (added by migration,
  also reflected in the canonical ddl.sql) and echoed back by the sync response,
  but the server's receive time (`attendance_datetime`) orders attendance (MIN/MAX).
  Clock differences beyond ±30 minutes (or unparseable values) set the persisted
  audit flag `device_timestamp_flag = true` and are reported in the sync response
  (`device_timestamp_flag` message) — never silently applied or rewritten.
- **Sync revalidation**: offline payloads go through the same authorization as
  online submissions — assignment, company scope and active flags are re-checked
  at sync time (a previously authenticated device proves nothing). 422/403
  rejections mark the queued op CONFLICT (kept for inspection); 5xx/network
  errors leave it PENDING for the next automatic or manual drain.
- **Offline order drafts**: created locally as LOCAL_ONLY drafts; on reconnect the
  SyncService validates access/credit/pricing/deals, returns material differences
  as a CONFLICT for user confirmation, then creates the authoritative server order.
- **Network UX**: small persistent status pill (ONLINE / OFFLINE / SYNCING /
  PENDING / SYNC ERROR), no intrusive dialogs; online-required actions explain why
  ("Delivery allocation requires a current stock check. Connect to continue.").

## 5. Transaction boundaries

| Operation | Locks | Notes |
|---|---|---|
| Delivery allocation | `inventory` row (customer, product) `FOR UPDATE` | validate unrestricted ≥ qty, remaining demand ≥ qty, item not rejected; move unrestricted→restricted; NO movement row |
| Delivery release (pre-shipment) | `delivery` + affected `inventory` rows `FOR UPDATE` (deterministic order) | ALLOCATED → DRAFT; restricted→unrestricted; NO movement row; forbidden once attached to a non-DRAFT shipment or `shipped_at` set |
| Shipment START (Phase 6) | `shipment` → attached `delivery` rows (delivery_no order) → `inventory` rows (customer,product order), all `FOR UPDATE` | READY only; grouped restricted check under lock; restricted -= issued (unrestricted untouched); ONE `GOODS_ISSUE` movement PER DELIVERY ITEM (`reference_type=DELIVERY`, `reference_no=delivery_no`, `reference_item_no=item_no`); deliveries ALLOCATED→SHIPPED; retry `attempts: 2` for deadlocks/MariaDB 1020; idempotent via `shipment_start` scope |
| SO confirm | `sales_order`, `customer` outstanding check, pricing/deal read | creates immutable invoice snapshot; transitions DRAFT→CONFIRMED→OPEN_DELIVERY |
| SO item rejection | `sales_order_item` | marks remaining demand rejected; existing allocations untouched, no NEW allocation |
| Authoritative stock count submit | `inventory` rows for count items `FOR UPDATE` | requires `restricted_qty = 0` per customer+product; replaces baseline with ONE immutable `STOCK_COUNT_BASELINE` movement per counted row (`STOCK_COUNT_VARIANCE` deliberately unused — see §7) |
| Payment/credit allocation | `payment`/`customer_credit` + `invoice` rows | never exceed payment remaining / credit remaining / invoice balance; recompute invoice status |
| Number generation | inside the service transaction | e.g. `SELECT MAX(...)` or dedicated sequence row |

## 6. Phase 4 — commercial engine decisions

- **SO company derivation**: customers are global (no `company_id`); the SO's
  `company_id` is derived SERVER-SIDE from the sales employee's employer. A
  client-sent company is always ignored. Globality never widens authorization:
  the company context follows the employee, not the customer.
- **Supplying-Primary eligibility**: a Sales Employee may supply only through
  PRIMARY customers assigned to them via `customer_employee`. There is
  deliberately NO fixed Primary → Secondary mapping: a Secondary (e.g. Mama
  Chi) may be served through any Primary assigned to that employee, but never
  through an unassigned one. Enforced server-side at every path — capture
  dropdowns (create/edit), `/orders/context`, draft creation
  (`validateCustomers`, 403), draft update, offline sync (`/sync/order-drafts`
  returns 403 → the client marks the outbox record CONFLICT) and confirmation
  re-validation (a revoked assignment becomes a confirmation conflict; the
  order stays an untouched DRAFT). COMPANY_ADMIN / SUPERADMIN retain their
  broader administrative access, but never via widened order-capture rules.
  UI filtering is a convenience only, never the enforcement point.
- **Product scope at capture**: only active products of the SO company
  (derived from the employee, same rule as the SO itself), filtered by
  `employee_product` (no rows = all company products), are exposed
  via `/orders/context`; `replaceItems` re-validates company/active/unit/conversion
  **and the `employee_product` scope** on every save (empty scope = all company
  products) — request spoofing can neither introduce foreign-company products
  nor out-of-scope products. Confirmation re-checks the scope and raises a
  `ConfirmationConflict` if it was narrowed after drafting (audit correction).
- **Recommended price resolution** (`PricingService`): applicable conditions =
  active + company + product + validity window (+ optional region match).
  Precedence: exactly one region-specific condition beats generic ones; any
  other overlap (two generics, two same-region) is AMBIGUOUS → reported with
  the concrete condition numbers, never guessed. Drafts tolerate ambiguity
  (recommended = null); CONFIRMATION BLOCKS on it.
- **Price override**: requires a reason (validated at input AND kept in the
  snapshot); `recommended_price` and `condition_price_no` snapshot at save;
  master-data changes never touch confirmed orders (proven by test).
- **Deal evaluation** (`DealService`): always server-side, against basic-unit
  quantities (unit conversions applied — 10 CTN = 240 PCS against a 10-CTN
  qualifier). Rewards emit `line_source=DEAL, is_free_item=true,
  parent_item_no, deal_no, unit_price=0`. Qualifiers are all-AND (schema PK
  implies conjunction). `for_each_qty` grants floor(multiple) rewards. MULTIPLE
  distinct qualifying deals = AMBIGUOUS (no stacking rule) → reported, blocks
  confirmation.
- **Confirmation is all-or-nothing** (`ConfirmationConflict`): re-prices from
  current master data, re-evaluates deals, then either commits CONFIRMED or
  rolls back entirely leaving an untouched DRAFT with a human-readable conflict
  list (ambiguous conditions by number / ambiguous deals by number).
- **Immutability & rejection**: only DRAFTs are editable (checked under
  `lockForUpdate` against concurrent confirms). `rejectItem` marks the item
  REJECTED with reason/actor/time while preserving `order_qty`; header status
  recomputes (PARTIALLY/COMPLETELY_REJECTED). Phase 5 will enforce that
  rejection blocks NEW delivery allocations only.
- **Demand-only invariant** (proven by test): draft create, edit and confirm
  perform zero inventory writes — no unrestricted/restricted change, no
  `inventory_movement` rows — and demand may exceed stock.
- **Offline drafts**: LOCAL DRAFT → PENDING SYNC → SERVER DRAFT → CONFIRMED.
  `/sync/order-drafts` (idempotent, endpoint+user+UUID scoped) revalidates
  access/assignment/products and compares device-cached prices with current
  server pricing. `pricing_changes` are always reported; confirmation via sync
  happens ONLY when nothing changed and no ambiguity exists — a stale cached
  price never silently becomes a confirmed order (proven by test). Retries
  replay the stored response; no duplicate orders.
- **Invoice-based credit blocking is NOT implemented** — it requires the
  unresolved invoice-debtor decision (risk 3) and stays unimplemented by design.

## 7. Phase 5 — inventory & delivery allocation decisions

- **SO = demand, Delivery = allocation, Shipment = physical issue.** A Sales
  Order never reserves or touches stock (proven by test). A Delivery is a HARD
  allocation: it moves stock from `unrestricted_qty` to `restricted_qty` at the
  SO's source customer. Restricted means "allocated to a delivery, not yet
  physically issued" — Shipment (Phase 6) will convert restricted into a real
  `GOODS_ISSUE` movement. There is no `stock_reservation` table and no stored
  `on_hand_qty`: **ON HAND = unrestricted + restricted**, always computed
  (`Inventory::onHandQty()`), and it never changes during allocation.
- **Unit normalization**: inventory is maintained in the product's BASIC unit.
  Every operational quantity (delivery lines, adjustments, counted quantities)
  is converted via `product_unit_conversion` with `Decimal` string math — never
  floats. The `delivery_item` keeps the USER's business-facing
  `allocated_qty`/`delivery_unit` (10 CTN stays 10 CTN) while 240 PCS moves in
  inventory.
- **Source rules**: the Delivery's source is ALWAYS the SO's
  `source_customer_id`, re-validated server-side (never trusted from the
  client). A SHIP_TO source allocates SHIP_TO stock only; a Primary source
  allocates Primary stock only; there is NO automatic fallback in either
  direction — changing source is an explicit business decision, and the
  Delivery header preserves full traceability (Delivery → SO → Delivery Item →
  SO Item → Product).
- **Allocation transaction**: one DB transaction per Delivery. Each affected
  `inventory` row is loaded `SELECT ... FOR UPDATE` with customer+product scope
  ONLY (never the whole customer). Under the lock the service re-validates: SO
  confirmed, item not rejected, remaining demand ≥ requested (confirmed demand
  − already allocated, in basic units), unrestricted ≥ requested. Then it
  transfers unrestricted → restricted (guarded against any negative value) and
  creates the Delivery/DeliveryItem. Multi-line deliveries are ALL-OR-NOTHING:
  one failing line rolls back the whole document. Original `order_qty` is
  never mutated.
- **Status semantics (Phase 5)**: Delivery DRAFT → ALLOCATED. The SO moves
  CONFIRMED → OPEN_DELIVERY when any allocation exists. Documented ambiguity:
  the enum cannot express "fully allocated, awaiting shipment", so
  OPEN_DELIVERY carries that meaning; PARTIALLY/COMPLETELY_DELIVERED are
  reserved for Phase 6 Shipment/POD (allocation never claims "delivered"). A
  fully-rejected single-item order reads COMPLETELY_REJECTED even if an earlier
  allocation survives on that item.
- **Rejection interaction**: a REJECTED SO item accepts NO new allocation, but
  existing allocations survive untouched (stock stays restricted and continues
  to Shipment in Phase 6). Remaining rejected demand is never returned to
  unrestricted by rejection.
- **Free deal lines are physical stock (corrected)**: a DEAL reward item is
  NOT "allocated with its parent" — it is ordinary physical inventory with a
  zero commercial price. It gets its OWN Delivery Item (own `product_id`, own
  quantity/unit, own basic-unit conversion via `product_unit_conversion`), its
  own unrestricted-stock check at the Delivery source, and its own
  unrestricted → restricted transfer; Phase 6 will issue its own GOODS_ISSUE.
  The allocation UI may pre-select the free line alongside its parent for
  convenience, but the server allocates each line independently. If the reward
  product lacks unrestricted stock, the whole Delivery fails validation — no
  stock is invented and the free line is never silently treated as allocated.
  `unit_price = 0` stays a purely commercial fact; inventory behavior is
  independent of price (proven by tests: independent stock effects, missing
  reward stock blocks, no movement from allocation).
- **No inventory movement for allocation**: allocation is a stock-state
  transfer, not a physical change — the `inventory_movement` ledger records
  PHYSICAL changes only. Phase 5 implements GOODS_RECEIPT / ADJUSTMENT /
  DAMAGE (admin adjustments, immutable rows, negative adjustments can never
  push unrestricted below zero — restricted stock is untouchable by
  administration); GOODS_ISSUE / returns belong to Phase 6+. No silent direct
  editing of inventory quantities exists anywhere.
- **Concurrency**: mandatory row locking, proven by a REAL MariaDB test
  (`DeliveryConcurrencyTest`): a forked second connection holds `FOR UPDATE`
  on the inventory row while the service allocates — the allocation blocks
  until the lock is released and applies its authoritative check against the
  post-lock state, so two competing allocations can never over-consume stock
  (100 PCS cannot become 130 allocated).
- **Idempotency boundary**: `POST /orders/{order}/deliveries` runs through
  `SyncService::process('delivery_allocation', ...)` (endpoint + user + client
  key). A lost response + retry replays the original result instead of
  double-allocating. The composite key is always truncated to the schema's
  64-char limit by cutting the CLIENT tail while preserving the
  `endpoint|user|` scope prefix (regression-tested after a real overflow 500
  was found in browser verification — the same incident also proved the
  lost-response replay end-to-end).
- **Release before shipment (Phase 5 operational capability)**: an ALLOCATED
  delivery can be RELEASED back to DRAFT while it is still only an allocation
  (goods have not left the source). One transaction: the delivery row and the
  affected inventory rows (locked `FOR UPDATE` in deterministic `product_id`
  order) transfer `restricted → unrestricted` — ON HAND unchanged, NO
  inventory_movement — and the document returns to DRAFT with `allocated_at`
  cleared. `allocatedBasicQty` counts only non-DRAFT deliveries, so released
  demand is immediately re-allocatable and the SO status recomputes (e.g. back
  to CONFIRMED when nothing else is allocated). There is deliberately NO
  partial mutation of an ALLOCATED delivery: to change 80 CTN to 40, release
  the delivery, edit it while DRAFT, allocate again — the same document is
  re-used (clean audit boundary, no duplicates). Release is FORBIDDEN once the
  physical frontier is crossed: the delivery is attached to a non-DRAFT
  shipment (Phase 6 START = goods issue) or `shipped_at` is set — GOODS_ISSUE
  is never reversed by a release, and shared row locks mean Shipment START and
  release can never both succeed against the same delivery. A delivery queued
  in a DRAFT shipment (planning only) is AUTO-DETACHED inside the release
  transaction — a released delivery no longer represents reserved stock and
  must not stay queued; if the release fails the detachment rolls back with
  it. READY means a finalized dispatch plan, so its deliveries must first be
  un-queued deliberately via the shipment workflow (READY → DRAFT) before a
  release becomes possible. Both new endpoints
  (`deliveries.release`, `deliveries.reallocate`) are idempotent via
  `SyncService` (`delivery_release` / `delivery_reallocate` scopes): a lost
  response + retry never returns restricted stock twice or double-allocates.
  UI: Release button on ALLOCATED deliveries; a released DRAFT offers an
  edit-and-reallocate form pre-filled with its previous lines.
- **Stock-count authority**: only PRIMARY_OPERATIONAL counts become
  authoritative, per customer+product (never the whole customer): the latest
  submitted count sets on-hand := counted (unrestricted := counted,
  restricted := 0) and writes one immutable `STOCK_COUNT_BASELINE` movement.
  Submission is REJECTED (422) while `restricted_qty > 0` on any counted row —
  a count must never destroy an active allocation. `STOCK_COUNT_VARIANCE` is
  deliberately NOT emitted: the DDL offers both types but no rule for which
  applies when a count replaces the baseline wholesale, so per the spec the
  ambiguity is reported instead of inventing accounting semantics (variance is
  visible as `stock_count_item.variance_qty`). SECONDARY_OBSERVATION (and
  VAN_CLOSING for now) are stored/report-only and never touch inventory.
  Submitted counts are immutable; creation is scoped to the employee's company
  + `employee_product` scope, and count type must match customer type.
- **Eloquent limitation (documented)**: `DeliveryItem::salesOrderItem` is a
  composite `(sales_order_no, item_no)` belongsTo with array keys; Laravel's
  stock BelongsTo cannot EAGER-load it (TypeError). It works via lazy loading;
  eager loads use `items.product` + `Delivery::salesOrder` instead.
- **Visibility**: sales employees see stock at their ASSIGNED customers only;
  company admins see their company-product stock across all holders;
  superadmins see everything. Customers stay global (no `company_id` on
  `customer_master`) — company context comes from the product/employee.

## 8. Phase 6 — shipment & goods issue decisions

- **START is THE physical boundary**: Shipment READY → IN_TRANSIT under one
  atomic transaction — shipments/deliveries/inventory locked in deterministic
  order, grouped restricted requirement verified per source+product, restricted
  decremented (unrestricted untouched, ON HAND drops), one immutable negative
  `GOODS_ISSUE` per Delivery Item, deliveries ALLOCATED→SHIPPED (`shipped_at`),
  shipment `started_on` stamped. DRAFT ↔ READY is planning with zero inventory
  effect; READY locks composition; IN_TRANSIT is irreversible through the
  ordinary workflow (no CANCELLED, no undo-START per ruling — mis-starts need a
  future explicit compensating physical transaction).
- **Ledger reference scheme (approved)**: `DELIVERY / delivery_no /
  delivery_item.item_no` per issue row — never consolidated, even when several
  items share a product (inventory state may group; the ledger stays per
  physical line). Shipment linkage is relational via
  shipment_delivery, not encoded into movement keys; the shipment number also
  appears in remarks (non-authoritative).
- **One Shipment = one company + one source; many salespeople**: deliveries
  from different SOs/employees/destinations combine freely — authorization is
  company/source operational scope (sales employees: the SOURCE must be in
  their `customer_employee` assignment), NEVER creator==salesperson. Customers
  stay global; company context comes from shipment/delivery/product records.
- **Delivery state interaction**: a released (DRAFT) delivery auto-detaches
  from a DRAFT shipment inside the release transaction (Phase 5); a READY
  shipment forbids release — operators must explicitly return it to DRAFT
  first; START re-verifies every delivery still ALLOCATED under lock, so a
  mid-READY release makes START fail loudly instead of issuing phantom goods.
- **SO status stance (approved)**: START does NOT rewrite the SO header —
  OPEN_DELIVERY continues to mean "in fulfillment, not yet POD-confirmed";
  PARTIALLY/COMPLETELY_DELIVERED stay dark until real POD confirmation;
  rejection statuses keep Phase 4 precedence.
- **COMPLETED deferred**: `IN_TRANSIT → COMPLETED` belongs to the POD
  workflow (all deliveries POD-terminal), deliberately not implemented in
  Phase 6. VAN sources issue goods exactly like Primaries; VAN
  replenishment/closing/return/route settlement remain separate future
  workflows. POD difference reasons carry NO automatic stock destination —
  issued ≠ returned; those rules are their own future design.
- **Concurrency**: two STARTs serialize on the shipment row; START vs release
  on the shared delivery row; all inventory locks follow the global
  (customer_id, product_id) order. MariaDB's `1020 Record has changed since
  last read` (surfaced by the real-locking tests) is handled by Laravel's
  native `DB::transaction(attempts: 2)` deadlock retry with full rollback —
  every check is re-read and re-validated inside the transaction.
- **Idempotency**: START runs through `SyncService::process('shipment_start')`
  — lost-response retries replay the original result (no double issue, no
  duplicate movements); business failures release the key.

## 9. Phase 7 — POD / delivery confirmation decisions

- **POD vs stock**: Shipment START already recorded what physically left the
  source (immutable GOODS_ISSUE), and POD never rewrites that ledger — but
  issued goods the customer does NOT accept do not vanish: they remain in the
  delivery operation's possession as reusable in-transit stock until they are
  reallocated to another delivery or explicitly returned to the source.
  Phase 6/7 deliberately implements no inventory movement for this yet (POD
  restores nothing to the source automatically); the proposed correction —
  including damaged-transit handling — is docs/TRANSIT_STOCK_CORRECTION.md
  (pending approval; NO schema changes proposed for Phase 8). Receiving-side
  differences also never auto-create credits: unaccepted quantity is simply
  never invoiced (POD_DAMAGE ≠ customer credit).
- **One authoritative confirmation per shipped Delivery Item**, enforced
  under the locked delivery row (the DDL index is non-unique). No overwrite,
  no corrections this phase (RULED §9.4). Quantities are normalized to basic
  units via `Decimal`; `confirmed > shipped` is rejected; the status matrix
  is CONFIRMED (full, reason NONE forced) / PARTIAL (reason REQUIRED) /
  REJECTED (zero confirmed, reason REQUIRED).
- **Authorization (RULED §9.1) — receiving side**: the confirming sales
  employee must be assigned (customer_employee) to the delivery's
  `sold_to_customer_id`; source assignment is deliberately NOT sufficient.
  Admins are company-scoped via the delivery; superadmin crosses companies.
- **Derivation**: every shipped item outcome'd → Delivery DELIVERED (all
  CONFIRMED) or PARTIALLY_DELIVERED (`delivered_at` stamped; forward-only).
  SO header derives from confirmations across its Delivery Items: any
  confirmed quantity ⇒ PARTIALLY_DELIVERED even while demand is still open
  (RULED §9.2); COMPLETELY_DELIVERED only when every shipped item is fully
  confirmed; Phase 4 rejection precedence preserved; START never writes the
  header. Shipment IN_TRANSIT → COMPLETED (outcome-only, RULED §9.3) when
  every attached delivery has an outcome per item; `completed_on` stamped.
- **Idempotent**: submissions run through `SyncService::process('pod_confirm')`
  — lost-response retries replay; duplicates lose at the locked existence
  check. Composite-keyed relations (`DeliveryItem::confirmations`) are not
  loadable in this Laravel version — lookups query `delivery_confirmation`
  directly.

## 10. Implementation phases

1. **Foundation** — Laravel + adapted Breeze, app_user auth, roles/company
   scoping, Material 3 mobile-first shell, PWA + offline indicator, dashboards. *(this phase)*
2. **Master data** — companies, units, products, unit conversions, customers,
   employees, assignments, product scope, searchable selectors.
3. **Field sales** — FJP, attendance (offline + idempotent sync), customer views.
4. **Commercial** — pricing, trade deals, Sales Orders (online + offline drafts,
   sync/conflict), credit blocking.
5. **Inventory** — inventory, stock counts, delivery allocation (restricted/unrestricted). *(complete)*
6. **Logistics** — shipments, goods issue, POD / delivery confirmation. *(complete)*
7. **Finance** — invoices, payments, credits, allocations. *(design prepared — see §10 and docs/PHASE8_DESIGN.md; implementation pending design review)*
8. **Reporting / hardening** — dashboards, audit, tests, performance, PWA sync tests.

## 11. Finance design summary (Phase 8 — pending review)

Full detail in `docs/PHASE8_DESIGN.md`. Key positions, all arising only from
authoritative POD outcomes:

- **Billing boundary**: the final invoice is created ONLY from POD-confirmed
  quantities × the SO item price snapshot (`sales_order_item.unit_price`,
  price basis converted consistently with quantity — basic-unit fallback
  divides the price by the factor too). SO confirm, allocation, READY and
  GOODS_ISSUE never create receivables. A short/never-confirmed quantity is
  simply never invoiced (no invoice-then-credit round trip). Free deal lines
  appear with `unit_price = 0`. Invoices are IMMEDIATE, `due_date =
  invoice_date`; tax/discount inherit the SO snapshots (zeros stay zeros).
- **One invoice per SO** (schema-mandated by `uq_invoice_so`): generated when
  the SO reaches **billing-terminal** state — every SO item has a final
  disposition via terminal POD outcomes (CONFIRMED, PARTIAL **or** REJECTED)
  and/or SO rejection of remaining demand; only POD `confirmed_qty` invoices
  (PARTIAL invoices the acknowledged part). Items rejected before allocation
  are excluded; a fully-rejected SO generates nothing monetary.
- **Debtor**: `invoice.customer_id = sales_order.sold_to_customer_id` (risk 3).
  Payments, allocations, credits, aging and exposure operate on that identity
  only.
- **Credits** are later financial adjustments (MANUAL_ADJUSTMENT + OVERPAYMENT
  implemented; POD_DAMAGE / RETURN enum-reserved) — never an automatic
  GOODS_ISSUE minus POD difference.
- **Exposure & blocking**: three separate values — `invoice_outstanding =
  Σ invoice_amount − Σ settled_amount`; `available_credit = Σ OPEN/PARTIALLY_USED
  remaining_amount`; `net_exposure = max(0, outstanding − available_credit)`
  (unused credit REDUCES exposure). Preserved business rule: a sold-to debtor
  with positive net exposure cannot confirm another SO (server-side
  ConfirmationConflict). Audit correction — the same exposure guard also blocks
  NEW Delivery allocation and Shipment START (server-side 422), so a debtor
  cannot keep drawing stock after defaulting; the guard is intentionally absent
  from POD, SO-item rejection, transit and return so already-dispatched stock
  stays accountable. NO configurable credit-limit facility.

## 12. Requirement risks & decisions

1. **`app_user` is a reserved-name-adjacent custom auth table.** Handled via
   `AppUser` model + custom provider, not a schema change. Laravel's
   sessions/cache/queue/password-reset tables are added as separate
   infrastructure migrations (no ERP columns) — required by the framework.
2. **DDL `DATETIME` vs Laravel `$timestamps`**: models disable automatic
   timestamp management where the column pair doesn't exist (e.g. `unit_master`,
   `inventory`) or map to the DDL column names otherwise.
2b. **Product unit conversions** (Phase 2 close-out): managed inline in the
   product form as a repeatable "Alternative units" section; the submitted set
   REPLACES the product's `product_unit_conversion` rows atomically
   (`ProductUnitConversionService::syncConversions`). Validation is server-side:
   unit must exist in `unit_master`, must differ from the product's basic unit,
   numerator > 0, denominator > 0, no duplicate alternative units per product,
   and the target product must pass company policy before any row is touched.
   Formula: alternative qty × numerator ÷ denominator = basic qty.
3. **Invoice debtor identity — RESOLVED (business ruling).** An SO carries three
   independent customer identifiers that are never collapsed:
   - `supplying_customer_id` — the Primary distributor commercially supplying the order;
   - `source_customer_id` — the physical stock source (the Primary or one of its SHIP_TO locations);
   - `sold_to_customer_id` — the Secondary customer whose demand the sales employee captures.

   For the erpSimple business model the **sold-to Secondary is the financial
   debtor**: `invoice.customer_id = sales_order.sold_to_customer_id`. A Primary
   (e.g. MIMZA) is a stock holder / supplying customer — it receives and
   distributes stock operationally, does NOT raise POs to the company in this
   workflow, and is NOT the debtor for Secondary demand; erpSimple has no
   Primary→Company purchasing/AP workflow, and none is to be introduced. All
   receivables (invoice outstanding, payments, allocations, credits, aging,
   exposure) operate against the sold-to identity. This ruling is specific to
   the current business model, not a general ERP principle; every write path
   still preserves the three identifiers independently.

   Related structural rule: one Secondary demand split across two supplying
   Primaries (e.g. MIMZA and ABC) is two separate Sales Orders — each SO has
   exactly one supplying Primary.
4. **Free items & invoicing**: deal reward lines are recorded as free SO/invoice
   items (`is_free_item`, unit_price 0). Gross includes them at 0 so quantity audit
   survives. Commercial price zero does NOT mean zero stock: since the Phase 5
   correction, free deal items carry real inventory (own Delivery Item, own
   allocation, own future goods issue).
5. **Price override**: `recommended_price` stored per SO item at order time;
   master pricing changes never touch it (snapshot discipline).
6. **Multiple orders while unsettled** (rule 21): strictly enforced at confirm
   time on the server, independent of stock. VAN orders included.
7. **Stock count authority**: PRIMARY_OPERATIONAL submission replaces the baseline
   for counted products at that customer; blocked if any counted product has
   `restricted_qty > 0` at that customer. SECONDARY/VAN counts are recorded
   observationally (VAN closing updates VAN stock as variance).
8. **`inventory` rows for products not yet stocked**: created lazily on first
   receipt/allocation (row insert is part of the allocation transaction).
9. **Rejection math**: order 30, allocated 15 → reject remaining 15; the 15 stay
   restricted and ship. Original `order_qty` never changes; status moves to
   `PARTIALLY_REJECTED` / `COMPLETELY_REJECTED`.
10. **MariaDB 13**: Laravel 12 supports MariaDB; `utf8mb4_unicode_ci` kept.
