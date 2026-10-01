# Simple Multi-Company ERP — Architecture

Source of truth: `ddl.sql` (MariaDB 13) + the requirements brief. Nothing in the DDL was redesigned; Laravel adapts to it.

---

## 1. Overview

A multi-company field-sales distribution ERP. Sales employees work on phones in
low-connectivity areas; company admins and superadmins work on desktop/tablet.

The domain flows in one direction:

```
Master data ──► Sales Order (DEMAND ONLY; confirmation freezes the commercial
                     │         snapshot — it creates NO receivable)
                     ▼
               Delivery (inventory ALLOCATION: unrestricted → restricted)
                     │ grouped into Shipment (same company + source customer)
                     ▼
               Shipment START (restricted → 0, immutable GOODS_ISSUE ledger entry)
                     │
                     ▼
               Delivery Confirmation / POD ──► Invoice (accepted billable quantity
                     │                          at billing-terminal state; differences
                     ▼                          stay in transit / return workflows —
               Payment / Credit Allocation ──► invoice settled → unblocks customer
```

Key invariants (enforced in services, not the schema):

- **Sales Order = demand.** No inventory effect, no reservation. Confirmed demand is immutable.
- **Delivery = allocation.** `unrestricted_qty -= x`, `restricted_qty += x`. On-hand unchanged. Row-locked, transactional.
- **Shipment START = physical issue.** `restricted_qty -= x` + `GOODS_ISSUE` movement. Transactional and idempotent (status guard + row lock).
- **Issued-but-unaccepted stock stays in transit.** GOODS_ISSUE is immutable and POD restores nothing to the source automatically; unaccepted quantity becomes traceable transit stock in the delivering employee's custody until it is reallocated, returned to the source (with verification) or written off — the implemented Phase 9 model (docs/TRANSIT_STOCK_CORRECTION.md).
- **SO confirmation = commercial snapshot.** It freezes pricing/deals/terms on `sales_order_item` (immutable, never rewritten when master data changes). It creates NO receivable; the Invoice is generated ONLY when the SO reaches billing-terminal state, from POD-accepted quantities priced off that snapshot.
- **Credit blocking** is implemented per the Phase 8 rules and is independent of stock availability: a sold-to debtor with positive net exposure cannot confirm another SO, and the same guard blocks new Delivery allocation and Shipment START. POD / rejection / transit / return of stock that already left the source are deliberately NEVER blocked.
- **Inventory can belong to PRIMARY / SHIP_TO / VAN** customers only (`inventory.customer_id`).
- **A VAN is a commercial Secondary with ONE unresolved cycle at a time** (audited 2026-10-01): same SO → Delivery → Shipment START → POD → Invoice → Payment lifecycle, no VAN inventory-count / closing-stock / route-settlement subsystem, and a new VAN SO is refused server-side while the previous cycle has unfinished operational work or a positive net exposure (§14).

## 2. Laravel / directory strategy

Plain Laravel, no CQRS/DDD/repository layers. Substantial business logic lives in
`app/Services/*` services called from controllers; policies in `app/Policies`;
request validation in `app/Http/Requests`.

```
app/
  Enums/                  role, status enums mirrored from DDL ENUMs (PHP casts)
  Models/                 one per ERP table as applicable
  Services/
    SalesOrderService     create/confirm, deal evaluation, controlled rejections,
                          dependent DEAL/free closure, commercial snapshot,
                          VAN one-cycle guard (§14)
    DeliveryAllocationService  row-locked allocation (+ DEAL/free entitlement via
                          DealEntitlementService)
    ShipmentService       attach, start (goods issue), complete
    StockCountService     submit + authoritative baseline (restricted==0 guard)
    InvoiceService        billing-terminal detection + final invoice generation
    FinanceService        payments/credits/allocation, exposure & blocking
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
  sales_order.sold_to_customer_id` and the final invoice is generated from
  billing-terminal POD-accepted quantities, priced from the SO item snapshot —
  never from GOODS_ISSUE and never at SO confirmation; see §11 Finance design.
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
| Delivery allocation | `sales_order` row, then ALL its `sales_order_item` rows, then the `inventory` row (customer, product) — all `FOR UPDATE` | validate SO confirmed, item not rejected, remaining demand ≥ qty, unrestricted ≥ qty; a DEAL/free line may additionally claim only the deal entitlement its paid parent line has earned (`DealEntitlementService`, §13.3); move unrestricted→restricted; NO movement row |
| Delivery release (pre-shipment) | `delivery` + affected `inventory` rows `FOR UPDATE` (deterministic order) | ALLOCATED → DRAFT; restricted→unrestricted; NO movement row; forbidden once attached to a non-DRAFT shipment or `shipped_at` set |
| Shipment START (Phase 6) | `shipment` → attached `delivery` rows (delivery_no order) → `inventory` rows (customer,product order), all `FOR UPDATE` | READY only; grouped restricted check under lock; restricted -= issued (unrestricted untouched); ONE `GOODS_ISSUE` movement PER DELIVERY ITEM (`reference_type=DELIVERY`, `reference_no=delivery_no`, `reference_item_no=item_no`); deliveries ALLOCATED→SHIPPED; retry `attempts: 2` for deadlocks/MariaDB 1020; idempotent via `shipment_start` scope |
| SO confirm | `sales_order`, `customer` outstanding (exposure) check, pricing/deal read | FREEZES the commercial snapshot on `sales_order_item` (immutable pricing/deals); creates NO receivable; blocks when the debtor's net exposure is positive; transitions DRAFT→CONFIRMED→OPEN_DELIVERY. The Invoice comes later, at billing-terminal POD state (§11) |
| SO item rejection | `sales_order_item` | marks remaining demand rejected with a CONTROLLED reason (`sales_order_rejection_reason`, never free text); existing allocations untouched, no NEW allocation; unearned dependent DEAL/free demand closes automatically with the SYSTEM_DEFAULT reason (§13.3) |
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
- **Purchase-history guidance at order capture**: `GET /orders/customer-history`
  returns the newest ≤5 non-DRAFT order lines for the selected sold-to customer,
  scoped to the employee's company and `employee_product` scope and limited to
  the employee's assigned customers. GUIDANCE ONLY — rendered read-only on the
  capture screen; it never creates lines, mandates products/quantities or
  constrains the order.
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
  parent_item_no, deal_no, unit_price=0`. The emitted reward is DEPENDENT
  fulfilment: it is an independent physical inventory line (§7) whose
  fulfilment entitlement is earned by its paid parent line and constrained by
  `DealEntitlementService` (§13.3). Qualifiers are all-AND (schema PK
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
  recomputes (PARTIALLY/COMPLETELY_REJECTED). Phase 5 enforces that rejection
  blocks NEW delivery allocations only, and §13.3 adds the dependent rule: the
  parent's rejection automatically closes UNEARNED dependent DEAL/free demand
  with the automation-only SYSTEM_DEFAULT reason, while free quantity that is
  already committed and still earned stays deliverable for Shipment/POD
  accountability.
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
- **Invoice-based credit blocking** — HISTORICAL (written during Phase 6, before
  Phase 8 existed): this bullet said blocking was "NOT implemented". Phase 8 has
  since implemented it (net-exposure guard at SO confirm, Delivery allocation and
  Shipment START — see §11); the stale claim is superseded and kept only as a
  marker for readers of the phase history.

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
  − already allocated, in basic units), unrestricted ≥ requested, and — for a
  DEAL/free line — that the request stays within the deal entitlement its paid
  parent line has actually earned (`DealEntitlementService`, §13.3). Then it
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
  unrestricted by rejection. Since §13.3 the parent's rejection also closes its
  UNEARNED dependent DEAL/free demand automatically (SYSTEM_DEFAULT reason);
  committed free quantity that is still earned survives untouched like any
  other allocation.
- **Free deal lines: physical stock with DEPENDENT fulfilment (corrected in
  §13.3)**: a DEAL reward item is NOT "allocated with its parent" in the
  inventory sense — it is an independent physical inventory line with a zero
  commercial price. It gets its OWN Delivery Item (own `product_id`, own
  quantity/unit, own basic-unit conversion via `product_unit_conversion`), its
  own unrestricted-stock check at the Delivery source, its own
  unrestricted → restricted transfer, and its own GOODS_ISSUE in Phase 6. What
  is NOT independent is its FULFILMENT ENTITLEMENT: the quantity a free line may
  claim is bounded by what its paid parent line has actually earned —
  `floor(parent_eligible / qualifier) × reward`, cumulative across Deliveries —
  enforced by `DealEntitlementService` inside the allocation transaction
  (§13.3). A free line whose parent is terminally rejected, or whose earned
  entitlement is exhausted, can never be newly allocated, and a free line
  without a resolvable parent fails closed. Allocating the free line in the SAME
  Delivery as its parent is what lets the parent's quantity earn the entitlement
  in that same operation (the UI may present them together for that reason). If
  the reward product lacks unrestricted stock, the whole Delivery fails
  validation — no stock is invented and the free line is never silently treated
  as allocated. `unit_price = 0` stays a purely commercial fact; inventory
  behavior is independent of price (proven by tests: independent stock effects,
  missing reward stock blocks, no movement from allocation), while fulfilment is
  never independent of the parent's paid demand.
- **No inventory movement for allocation**: allocation is a stock-state
  transfer, not a physical change — the `inventory_movement` ledger records
  PHYSICAL changes only. Phase 5 implements GOODS_RECEIPT / ADJUSTMENT /
  DAMAGE (admin adjustments, immutable rows, negative adjustments can never
  push unrestricted below zero — restricted stock is untouchable by
  administration); GOODS_ISSUE / returns belong to the physical-issue phase
  (implemented in Phase 6). No silent direct
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
  visible as `stock_count_item.variance_qty`). SECONDARY_OBSERVATION and
  VAN_CLOSING are stored/report-only and never touch inventory — no VAN count
  is required by the sales flow (§14).
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
  ordinary workflow (no CANCELLED, no undo-START per ruling — mis-starts need
  an explicit compensating physical transaction, which no phase has yet
  defined).
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
- **COMPLETED derivation (implemented in Phase 7)**: `IN_TRANSIT → COMPLETED`
  fires automatically inside the POD-confirmation transaction when every
  attached delivery has an outcome for every item (RULED §9.3) — this Phase 6
  note originally recorded only that COMPLETED was deliberately deferred out
  of Phase 6.
  A VAN is a valid stock holder and shipments from a VAN source issue goods
  exactly like shipments from a Primary — there is NO separate VAN logistics
  model. VAN inventory counting, closing stock, a VAN replenishment/route
  settlement subsystem and VAN-specific returns do not exist and are NOT
  required by the current sales flow: a VAN runs the ordinary commercial
  cycle (see §14). POD difference reasons carry no automatic stock destination
  in Phase 6/7 itself — the implemented Phase 9 transit model (§9,
  docs/TRANSIT_STOCK_CORRECTION.md) now owns those rules.
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
  issued goods the customer does NOT accept do not vanish. Per the IMPLEMENTED
  Phase 9 transit model (docs/TRANSIT_STOCK_CORRECTION.md REV 3): the POD
  difference is recorded as traceable TRANSIT STOCK per the
  (reason × disposition) matrix — REUSABLE custody can be reallocated to another
  delivery by the holding employee; RETURNED_AT_SOURCE creates a
  PENDING_SOURCE_RECEIPT that a source-side verification restores (the claiming
  employee can never verify their own receipt); DAMAGED rows carry liability;
  SHORT_DELIVERY / OTHER / unknown whereabouts never manufacture stock. POD
  itself still restores
  nothing to the source automatically. Receiving-side differences also never
  auto-create credits: unaccepted quantity is simply never invoiced
  (POD_DAMAGE ≠ customer credit).
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

Explicit sequence — one number per delivered slice:

```
Phase 1   Foundation
Phase 2   Master Data
Phase 3   Field Sales
Phase 4   Commercial / Sales Order
Phase 5   Inventory / Delivery Allocation
Phase 6   Shipment / Goods Issue
Phase 7   POD / Delivery Confirmation
Phase 8   Finance
Phase 9   Transit Stock
Phase 10  Reporting / Operational Dashboards — next
Phase 11  PWA / Offline Hardening
Phase 12  Production Readiness / UAT / Deployment
```

Detail of the completed core phases:

- **Phase 1 Foundation** — Laravel + adapted Breeze, app_user auth, roles/company
  scoping, Material 3 mobile-first shell, PWA + offline indicator, dashboards. *(complete)*
- **Phase 2 Master Data** — companies, units, products, unit conversions,
  customers, employees, assignments, product scope, searchable selectors. *(complete)*
- **Phase 3 Field Sales** — FJP, attendance (offline + idempotent sync), customer views. *(complete)*
- **Phase 4 Commercial / Sales Order** — pricing, trade deals, Sales Orders
  (online + offline drafts, sync/conflict), credit blocking. *(complete)*
- **Phase 5 Inventory / Delivery Allocation** — inventory, stock counts, delivery
  allocation (restricted/unrestricted). *(complete)*
- **Phase 6 Shipment / Goods Issue** — shipments (planning, READY composition
  lock, START = irreversible goods issue). *(complete)*
- **Phase 7 POD / Delivery Confirmation** — receiving-side confirmation, POD
  difference recording, transit hand-off, delivery/shipment/SO derivation. *(complete)*
- **Phase 8 Finance** — invoices at the POD billing boundary, payments,
  credits, allocations, exposure blocking. *(complete)*
- **Phase 9 Transit Stock** — custody/traceability for issued-but-unaccepted
  stock (docs/TRANSIT_STOCK_CORRECTION.md REV 3). *(complete)*
- **Phase 10 Reporting / Operational Dashboards** — **next**.
- **Phase 11 PWA / Offline Hardening**.
- **Phase 12 Production Readiness / UAT / Deployment**.

**Field UX (§13–§13.3) is cross-cutting post-Phase-9 implementation/UAT work, not
another numbered core phase**: it hardened the sales-employee workspace (field
workspace, customer registration, lifecycle dashboard, public invoice, field
settlement, DEAL/free entitlement, controlled rejection reasons, per-unit
pipeline, public payment evidence) on top of the Phase 1–9 semantics without
moving any of them.

## 11. Finance design summary (Phase 8 — implemented)

Implemented as designed; full detail in `docs/PHASE8_DESIGN.md`. Key positions,
all arising only from authoritative POD outcomes:

- **Billing boundary**: the final invoice is created ONLY from POD-confirmed
  quantities × the SO item price snapshot (`sales_order_item.unit_price`,
  price basis converted consistently with quantity — basic-unit fallback
  divides the price by the factor too). SO confirm, allocation, READY and
  GOODS_ISSUE never create receivables — the invoice is generated only at the
  billing-terminal POD state. A short/never-confirmed quantity is
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
  **Serialization (audit hardening):** the debtor's `customer_master` row is
  locked `FOR UPDATE` both by invoice creation and by every eligibility check
  (SO confirm, allocation, START) — always as the transaction's **last** lock,
  so the order stays `customer → invoices → credits → …`, acyclic. The
  eligibility read is a CURRENT (locking) read: under REPEATABLE READ a plain
  snapshot would miss an invoice committed while the check waited on the lock.
  Allocation/confirm transactions retry once (`attempts: 2`) on MariaDB 1020
  (the same remedy START already uses).

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
   allocation, own GOODS_ISSUE) while their FULFILMENT ENTITLEMENT stays
   dependent on the paid parent line (DealEntitlementService, §13.3).
5. **Price override**: `recommended_price` stored per SO item at order time;
   master pricing changes never touch it (snapshot discipline).
6. **Multiple orders while unsettled** (rule 21): strictly enforced at confirm
   time on the server, independent of stock. VAN orders included.
7. **Stock count authority**: PRIMARY_OPERATIONAL submission replaces the baseline
   for counted products at that customer; blocked if any counted product has
   `restricted_qty > 0` at that customer. SECONDARY_OBSERVATION and
   VAN_CLOSING are stored/report-only and NEVER touch inventory —
   `StockCountService::submitCount()` posts an authoritative baseline only for
   PRIMARY_OPERATIONAL, so a VAN closing count records an observation
   (`stock_count_item.variance_qty`) and changes nothing. No VAN inventory
   count, closing-stock or replenishment workflow is required by the current
   model (§14).
8. **`inventory` rows for products not yet stocked**: created lazily on first
   receipt/allocation (row insert is part of the allocation transaction).
9. **Rejection math**: order 30, allocated 15 → reject remaining 15; the 15 stay
   restricted and ship. Original `order_qty` never changes; status moves to
   `PARTIALLY_REJECTED` / `COMPLETELY_REJECTED`. Dependent DEAL/free demand the
   rejected 15 was supposed to earn closes automatically (§13.3).
10. **MariaDB 13**: Laravel 12 supports MariaDB; `utf8mb4_unicode_ci` kept.
11. **SKU-level payment allocation is NOT derivable (truthful limit).** An
   invoice may carry several SKUs and be partially settled. `FinanceService`
   settles PAYMENTS against an invoice and credits it with generic credit
   notes; it deliberately does not attribute money to individual invoice lines,
   and no schema column exists to do so. The field lifecycle therefore counts
   only PAID invoices as GREEN, keeps every accepted-but-not-fully-settled
   quantity in BLUE, and reports the partial settlement as AMOUNTS
   (`invoice.settled_amount`, `credit_amount`, `outstandingAmount()`) in a
   "partially settled invoices" note attached to the SKU row. No paid quantity
   is ever fabricated. Implementing per-SKU settlement would be a schema and
   business change (explicit line-level allocation with a business ruling),
   out of scope for this redesign.
12. **No tax model exists (conditional display only).** The schema carries no
   taxable flag or tax rate: the only tax concept is
   `price_condition_item.tax_type` (OUTPUT_TAX / INPUT_TAX / NONE) plus
   `*_tax_amount` columns that default to zero. No rate is invented anywhere.
   The public invoice page and the invoice JPG render Tax (and Discount,
   Settled, Outstanding, due date) rows ONLY when the value is non-zero or
   applicable, so a non-taxable sale shows `Subtotal / Total` alone. A real tax
   model would need a minimal requirement first: a rate source, a taxable
   classification per product, and a rounding/collection ruling.

## 13. Sales-employee field UX (implemented)

The sales-employee experience was rebuilt as a mobile field-sales workspace
(trading-app information density and navigation principles; erpSimple's
Material 3 identity). No Phase 1–9 semantic moved: SO = demand, Delivery =
reversible allocation, Shipment START = irreversible goods issue, POD =
acceptance, Invoice = accepted billable quantity, transit = issued-but-
unaccepted stock, payment = settlement.

**Information architecture** — one responsive IA for phone and desktop
(`resources/views/components/nav-links.blade.php`, `layouts/app.blade.php`):
five persistent destinations Home / FJP / Orders / Inventory / More, rendered
as a bottom bar on mobile and the same five as a rail on desktop. Home carries
the context tabs Primary / Secondary / More (`primary.index`,
`secondary.index`, `more.index`) as compact context switches over one dataset,
not separate module menus; administrators' navigation is unchanged.

**Today's lifecycle** (`SalesLifecycleService::todayLifecycle`, read-only).
Demand = SOs CONFIRMED today for the signed-in employee in the signed-in
company; products outside the employee's `employee_product` scope are
excluded. HISTORICAL NOTE: the ORIGINAL Phase-13 pipeline converted every
product to CTN through `product_unit_conversion` (showing a "no CTN
conversion" note otherwise) and presented one bar per SKU; §13.3 REPLACED that
with grouping by ORDER QUANTITY UNIT — no global CTN conversion — with one
pipeline per unit and a RED rejected bucket:

```
ORANGE  open / not allocated
YELLOW  allocated (into a Delivery without a terminal POD outcome; this
        includes dispatched-but-unaccepted transit custody, because transit is
        the same allocation ladder — never a second quantity)
BLUE    delivered · unpaid (POD-accepted quantity whose invoice is not fully
        settled)
GREEN   delivered · paid (POD-accepted quantity on a PAID invoice)
RED     rejected · not delivered (terminally rejected demand — never also
        orange; buckets are mutually exclusive)
```

The buckets are partitioned from the same POD/outcome rows InvoiceService
bills from, so one physical quantity sits in exactly one bucket. Colour is
never the only indicator: every bucket has a label and an accessible name.

**Derived order categories** (`classifyOrders` / `orderAnalysis`; no new SO
status): ONGOING = remaining fulfillable demand, an unfinished
allocation/shipment/POD, or outstanding money; COMPLETED = none of those (a
fully rejected SO with nothing left to process is COMPLETED). `orders.index`
defaults its window to today → today and splits the two categories into tabs;
`orders.show` renders the document lifecycle SO → Delivery → Shipment → POD →
Invoice → Payment with the contextual next action, and the customer context
page (`visits.customer`) exposes Inventory Count / Create New Order plus open
orders, recent purchases, plan and exposure so the employee never has to leave
the customer and re-search a global module.

**Field writes introduced** (thin endpoints reusing existing services):

| area | entry point | ruling |
|------|-------------|--------|
| register customer | `secondary.create` / `secondary.store` → `CustomerRegistrationService` | creates a SECONDARY customer + `customer_employee` assignment; editing existing customers stays admin-only |
| GPS capture | hidden inputs + `geo.reverse` → `ReverseGeocoder` | coordinates come from the device only (server-validated, never typed); reverse geocoding pre-fills text and never moves the marker or overwrites a correction |
| phone | `PhoneNumberService` | dial code fixed (+234); `0801…`, `801…`, `+234801…`, `234801…` all canonicalize to `+2348012345678`; exact national length; server-authoritative |
| duplicate guard | advisory lock + `uq_cm_phone_canonical` | check + create serialized per canonical number (`GET_LOCK`), 1062 → "Customer already exists" prompt |
| preferred visit | `CustomerRegistrationService::syncPreferredVisits` | writes `customer_fjp` (the project's one schedule model); a match with today joins today's FJP, and registration never writes attendance |
| record payment | `payments.record.create` / `payments.record.store` → `FinanceService` | field methods only: BANK_TRANSFER_TO_PRIMARY / POS_AT_PRIMARY / CASH_AT_PRIMARY (the customer pays the Primary; the employee never receives cash); confirmation updates finance state through the existing service |
| payment evidence | `PaymentEvidenceService` (`payment_evidence`) | MIME/real-image/size/ownership validated BEFORE any money is recorded; watermark text is presentation only, GPS is stored as structured columns |
| customer invoice | `invoice.public` + `invoice.public.image` | 43-char random token (32 random bytes, base64url) — never a sequential id; public page is read-only and contains no form |
| invoice image | `InvoiceImageService` | GD JPEG (~1240 px) generated from invoice data with conditional rows, never a dashboard screenshot |

**Client assets** (`resources/js/customer-map.js`, `resources/js/payment-proof.js`):
capture-only GPS with `[Refresh GPS]`, MapLibre + CARTO raster preview with the
marker (the map library is a lazy ~1 MB chunk fetched only on the registration
screen — the field bundle stays small), and EXIF-aware resize/watermark/JPEG
conversion of payment proof before upload. Client work is optimization only:
the server re-validates type, size, ownership, the invoice relationship and the
image itself for every artifact.

**Schema deltas** (mirrored in `ddl.sql`, `app/database/schema/erp-schema.sql`
and the incremental `app/database/schema/2026_10_01_sales_employee_ux.sql`):
`customer_master.phone_canonical` + `uq_cm_phone_canonical`;
`invoice.public_token` + `uq_invoice_public_token`; `payment.payment_method`
extended with the three Primary-routed field methods (legacy values retained);
new `payment_evidence` table (payment FK, stored path/original name/MIME/bytes,
structured GPS, capture time, watermark text, uploading employee).

### 13.1 UAT correction pass (2026-10-01)

The UAT round kept every semantic above and corrected the surrounding detail.

**Invoice identity.** `InvoicePartyService` resolves the invoice's parties: the
SELLER is the supplying customer (Primary, i.e. the bill-to party that supplies
the goods); the DEBTOR is `invoice.customer` (the sold-to Secondary, unchanged);
a separate warehouse party is shown only when source and supplying customer
differ. The public page header, the finance invoice screen and the JPG all use
that resolution, and the footer reads
`Powered by {employee_id} - {employee_name}` plus the company name and a
`Scan Me` QR. The raw public URL is deliberately never drawn or printed — the
QR is the only carrier of the link.

**Invoice image.** `InvoiceImageService` renders A4 portrait at 150 dpi
(1240 × 1754). Content taller than one sheet is uniformly downscaled (never
cropped, never paginated into a second file) and the footer strip is pinned to
the sheet bottom. Tax, Discount, Settled, Outstanding and due date rows are
conditional; an invoice without tax shows Subtotal / Total only. Column
geometry is measured so totals never overlap.

**Unit of measure continuity.** A product's BASE unit comes from
`product_master.basic_unit`; alternate selectable units are only those with a
maintained `product_unit_conversion` row (non-zero numerator and denominator).
The chosen SO line unit is carried through allocation, shipment and POD as a
hidden field rendered as a read-only badge — the fulfilment forms never offer a
unit picker, and `ProductUnitService` keeps the exact rational conversion
internally (6 CTN → 4 CTN + 4 CTN + 4 CTN stays CTN). Stock Count rows read the
base unit from the product and display it read-only. Quantity inputs are fluid
(`w-full min-w-0`) so no 360–450 px viewport overflows.

**FJP weekday.** `customer_fjp.preferred_day` is numeric `TINYINT UNSIGNED`
(0 = Sunday … 6 = Saturday) with `idx_fjp_plan (company_id, employee_id,
preferred_day)`; `preferred_week` stays numeric. The migration
`app/database/schema/2026_10_01_fjp_numeric_day.sql` converts legacy ENUM names
case-insensitively, asserts zero unmapped rows and only then tightens the
column. `App\Enums\Weekday` owns parsing/labels, `CustomerFjp::visitChip()`
renders the compact `[W1-Mon]` chip used by FJP, today's plan and the customer
profile, and every FJP query is scoped company + employee + active.

**Today's lifecycle proof.** The regression fixture pins 100 CTN of demand to
20 open / 30 allocated / 25 accepted-unpaid / 25 accepted-paid and asserts
exact conservation (110 with a rejected 10 CTN order), so the dashboard
visualisation can never hide an arithmetic error behind styling. (The fixture
was rewritten in §13.3 to the 20/20/20/25/15 five-bucket shape with a RED
rejected bucket.)

**Test totals.** 325 tests / 1441 assertions before this pass → 345 / 1632
after (`php artisan test`), Pint clean, `npm run build` green (pre-existing
chunk-size and ineffective-dynamic-import warnings only).

### 13.2 Manual-UAT defect pass (2026-10-01)

Three reported defects, no semantic change to any phase.

**Invoice header collision.** The renderer drew the `INVOICE` title and the
invoice number at the SAME hard-coded baseline (96), so any invoice number
visibly overlapped the title. `InvoiceImageService::headerGeometry()` is now the
single source of truth for a measured two-column header: the right column's rows
(`INVOICE`, invoice no, invoice date, sales order, optional due date) are stacked
using real FreeType ink metrics (`imagettfbbox`, offset by 1 px) with a guaranteed
8 px gap, and every row is shrink-wrapped and wrapped inside a budget; the left
seller column is constrained by the measured right column. One uniform scale
(floor 0.6) shrinks the whole header for pathological identifiers, and strings
are broken at `/ _ . -` (then per character) so nothing is ever dropped or leaves
the A4 text area. Seller = supplying Primary, debtor = sold-to Secondary and the
powered-by/QR footer are unchanged.

**Header chrome painting pre-boot.** `x-show` elements are painted by the browser
as soon as the HTML is parsed, i.e. before `app.js` boots Alpine and writes its
inline `display: none`. With no `x-cloak` anywhere in the project, the profile
popover rendered open during that window on every navigation — the "menu opens by
itself while navigating" report. `[x-cloak] { display: none !important }` is now
in `resources/css/app.css` and every pre-boot `x-show` chrome element carries
`x-cloak` (profile popover, sync banner, FJP GPS/result/pending hints,
registration GPS note). Alpine components that already emit an inline
`display: none` (dropdown, modal) need none. The popover is also `w-64` below
`sm` so the right-anchored 288 px card cannot overhang a 360 px viewport.

**Home dashboard pipeline.** HISTORICAL: the Home originally showed an
order-count headline with aggregate status tiles; the 13.2 pass replaced that
with one bar per SKU. §13.3 has SINCE REPLACED the per-SKU pipeline with
GROUPING BY ORDER QUANTITY UNIT (no global CTN conversion): one
`x-lifecycle-bar` per unit — a single track scaled to that unit's active
lifecycle quantity whose widths ARE the quantities, split into the orange ORDER
segment and the amber/blue/green PROCESSING group plus the RED rejected
segment, with both sides repeated as numbers and labels, the compact five-row
legend, `N UNIT in play today` per group and the per-unit product/order
drill-down. The headline aggregate is a quantity, never an order count.

**Test totals.** 345 tests / 1632 assertions before this pass → 348 / 1733
after (`php artisan test`), Pint clean (202 files), `npm run build` green
(pre-existing chunk-size and ineffective-dynamic-import warnings only).

### 13.3 Live-UAT correction pass (2026-10-01)

Eighteen reported defects/requests. No phase invariant is changed: allocation
still serializes on the sales-order row, goods issue stays immutable, transit and
custody rules are untouched, finance still owns money and one final invoice per
sales order remains the model.

**A DEAL/free line can no longer outlive its parent.** A free line is DEPENDENT
fulfilment. `App\Services\DealEntitlementService` derives, per (parent line, deal,
reward product):

```
eligible_free = floor(parent_eligible_qty / qualifier_per_multiple_qty) × reward_qty
```

`parent_eligible_qty` is the paid parent's CUMULATIVE allocated quantity in basic
units (non-released deliveries only) PLUS the parent quantity requested in the
same operation — so splitting a deal across deliveries can never mint duplicate
entitlement, and free quantity can never move ahead of its parent. The
per-multiple basis is the deal's `for_each_qty` when maintained, else the
qualifier minimum, converted through the EXISTING `ProductUnitService` conversion
of the qualifier product (nothing is hard-coded to PCS); the reward is converted
to the free product's basic unit. Sibling free lines of the same parent/deal/reward
product SHARE one entitlement. `DeliveryService` enforces this inside the
allocation transaction after `SalesOrder` and `sales_order_item` are locked
(`lockedOrderItems()`, `requestedBasicByItem()`): an unresolvable parent fails
closed, a request above the earned entitlement is a 422 that prints the
entitlement note, and nothing is written. `allocationContext()` exposes
`effective_remaining_basic`, `dependency` and `dependency_note`, which the Create
Delivery screen renders as `Deal entitlement · parent fulfilled/allocated 6 / 12
PCS · free currently eligible 0 PCS` instead of presenting the free line as
independent demand.

**Rejecting the parent closes dependent free demand.** `SalesOrderService`
`rejectItem()` now takes a `SalesOrderRejectionReason` MODEL (free text is never
accepted) and, after the parent's rejection, auto-rejects every free line from
`DealEntitlementService::closableFreeLines()` with the automation-only
SYSTEM_DEFAULT reason. A line is closable when it still has unmet demand of its own
AND either its earned entitlement is exhausted or nothing of it was ever
committed; a free line that already committed quantity and still holds earned
entitlement is left deliverable, so dispatched free stock stays accountable
through Shipment/POD/custody — no Goods Issue or allocation row is ever erased.

**Controlled rejection reasons.** New master table
`sales_order_rejection_reason` (reason_id, reason_code, reason_name,
user_selectable, active) seeded 0 `SYSTEM_DEFAULT` (not selectable), 1
`CUSTOMER_REQUEST`, 2 `UNAVAILABLE_STOCK`; `sales_order_item.rejection_reason_id`
links it (FK `fk_soi_reject_reason`) while the legacy `rejection_reason` text column
keeps a readable snapshot. `app/database/schema/2026_10_01_rejection_reason_master.sql`
migrates recognizable legacy text to 1/2, preserves every other historical string
untouched (dev keeps one unlinked legacy row) and prints
`unlinked_legacy_rejections`. Both `ddl.sql` and the canonical mirror carry the
table, column and FK. The controller validates `rejection_reason_id` against
`Rule::exists(... user_selectable = TRUE AND active = TRUE)`, and the order screen
renders only `userSelectableOptions()`, printing a SYSTEM chip for automatic
closures.

**Invoicing only what the customer accepted.** `InvoiceService::invoiceableBasicQty()`
now walks the traceability chain SO item → Delivery item → Shipment → POD
confirmation → invoice item: only shipped rows (SHIPPED/PARTIALLY_DELIVERED/
DELIVERED) whose recorded product matches the SO item count; rows without a POD
outcome or with a POD REJECTED contribute zero; each confirmation contributes its
OWN confirmed unit converted to basic and is clamped to what that row shipped. The
ordered quantity, the SO completion status, the rejection status and the order
total no longer influence billable quantity. Free lines are structurally
zero-priced (unit price/discount/tax/subtotal forced to 0.00 and excluded from
gross/discount/tax), so a malformed legacy free-only delivery can never bill the
parent's value — it produces a 0.00 invoice with the accepted free quantity shown
for transparency.

**POD mobile layout and conditional fields.** `pod/show.blade.php` renders ONE
card per delivery item — product (+ FREE badge), `Shipped: {qty} {unit}`, the
Confirmed Qty input with a read-only unit badge, Reason for Difference, Stock
Custody Position, Other Remarks and the Confirm Item button, all stacked at mobile
width (nothing sits horizontally beside anything). Reason and custody are required
/enabled only while confirmed < shipped; an equal quantity keeps the system's NONE
semantics and needs neither. The card's attribute list is closed by the tag's own
`>` (an earlier `@endunless>` closed the `<div>` early and leaked
`data-pod-item="n">` into the page as text).

**Public invoice payment evidence.** The QR/public invoice now shows every payment
authoritatively ALLOCATED to that invoice (method, status, amount, applied amount,
reference, confirmation date) with its proof of payment. Evidence is served by a
new token-scoped read-only route
`/invoice/public/{token}/payments/{payment}/evidence/{evidence}` that must satisfy
four guards: the evidence belongs to that payment, the payment is allocated to the
token's own invoice, the payment matches the invoice's customer AND company, and
the stored mime is in `erp.payment_evidence.mimes` with a known extension and an
existing file. Any mismatch is a 404, the response carries a neutral filename and
`X-Content-Type-Options: nosniff` + `private, no-store`, and no internal storage
path or original filename is ever exposed. The page remains read-only (no form, no
POST verb) — it grants nothing beyond that invoice's payments and their proofs.

**Per-unit Home pipeline.** The Home lifecycle is grouped by ORDER QUANTITY UNIT
(no global CTN conversion): `SalesLifecycleService::unitGroups()` returns one group
per unit with ordered/allocated/unpaid/paid/rejected buckets, `bucket_sum`,
`balanced` and an order count, and `x-lifecycle-bar` renders one pipeline per unit —
open (orange), the allocated/unpaid/paid processing group (amber/sky/emerald) and
rejected (red, terminally rejected and NOT delivered, never also orange) with a
compact five-row legend, every quantity also printed as a number. The conservation
fixture pins 100 CTN to 20/20/20/25/15 and asserts the exact widths and totals.

**Searchable stock-count product selector.** The count screen's product field is an
Alpine combobox backed by the existing authorized `search.products` endpoint
(company ∩ `employee_product`, active, name OR sku, limit 15); its offline fallback
filters the already-scoped catalog. The submitted value is the chosen
`product_id` alone (a hidden input — never free text) and the unit is the selected
product's read-only base unit; `StockCountService` re-validates the scope server
side, so a crafted id is a 403.

**Incidental hardening.** `InvoiceImageService::wrap()` ignored its `$bold` flag,
so a BOLD block was measured against the regular face and a wrapped seller line
could overhang its column by a pixel or two; the wrap now measures the face it
draws, and the reported seller-column width rounds up like the measured line
widths so "every line fits" is an exact comparison.

**Test totals.** 348 tests / 1733 assertions before this pass → 368 / 1950
after (`php artisan test`), Pint clean, `npm run build` green (pre-existing
chunk-size and ineffective-dynamic-import warnings only). New coverage:
`DealFreeDependencyTest` (13 — the A–I/L/M business cases above, including
unit-generic entitlement and stock conservation), `DealEntitlementConcurrencyTest`
(a forked child commits a competing free allocation while holding the order lock;
the racing delivery blocks and is refused), `PublicInvoiceEvidenceTest` (2 —
evidence served through the correct token, every mismatched token/payment/evidence
combination 404) and `ProductSearchScopeTest` (3 — authorized results only, scoped
catalog on the page, server-side re-validation), plus the rewritten per-unit
dashboard tests in `SalesEmployeeUxTest`.

## 14. VAN operational model (audited 2026-10-01)

Business ruling: a VAN is **commercially a Secondary customer**. It follows the
ordinary lifecycle — no VAN inventory-count, closing-stock, route-settlement or
replenishment subsystem exists or is required:

```
Create VAN Sales Order → Delivery / source allocation → Shipment → READY
(composition locked) → START (GOODS_ISSUE from the actual source) → POD →
Invoice → Payment / settlement → cycle closed
```

- **No VAN stock counting is required**: `CountType::VAN_CLOSING` is
  stored/report-only and posts nothing (`StockCountService::submitCount()` is
  authoritative for PRIMARY_OPERATIONAL only — §7, risk 7). The field directory
  merely SUGGESTS the matching count type for a VAN customer; no step of the
  VAN sales flow requires a count and nothing reads it back.
- **A POD difference never becomes VAN stock.** The unaccepted quantity moves
  into the implemented Phase 9 transit/custody workflow (§9) — REUSABLE custody
  the holder can reallocate, PENDING_SOURCE_RECEIPT verified at the source,
  DAMAGED liability, or a DISCREPANCY when the whereabouts are unknown. No VAN
  inventory balance is invented and no count is needed to resolve it.
- **One unresolved cycle at a time.** Before a new VAN SO is created or
  confirmed, the server requires that the VAN has no unfinished previous cycle
  and no positive net exposure. "Unfinished" is DERIVED from existing states
  only (no new status enum): open demand not yet allocated; a released
  allocation awaiting re-allocation; an allocated delivery not yet dispatched;
  dispatched quantity awaiting its POD outcome; POD-accepted quantity not yet
  invoiced. The financial condition is the EXISTING Phase 8 net exposure
  `max(0, invoice_outstanding − available_credit)` — a fully settled invoice or
  sufficient customer credit never blocks, and terminal history
  (COMPLETELY_REJECTED with nothing left to process, a settled completed cycle)
  never blocks forever. A DRAFT order is not demand and never blocks another
  order (it creates no physical or financial obligation and no delete/cancel
  workflow exists for it), but it can only be confirmed once the VAN is clear.
- **Where it is enforced**: one centralized guard,
  `SalesOrderService::vanCycleStatus()` (derivation, reusing
  `SalesLifecycleService` so the lifecycle screen and the rule can never
  disagree) with `assertVanCycleClear()` called by `createDraft()` (online and
  `sync/order-drafts`) and the same determination called by `confirm()` — never
  in a controller, never in the UI. The order-capture screen only EXPLAINS the
  refusal and links the blocking document(s).
- **Concurrency/locking**: enforcement runs inside the creating/confirming
  transaction, which locks the VAN's `customer_master` row `FOR UPDATE`. For a
  VAN order the sold-to customer IS the Phase 8 debtor, so this is the exact
  serialization point finance already uses (invoice creation, exposure checks,
  allocation, START) — the lock order is unchanged and the row stays a lock-order
  **sink**: nothing below ever waits on a lock while holding it (only the
  transaction's own draft rows, locked earlier, are written). `confirm()` takes
  the row with the same locking read that identifies the customer and derives
  the state BEFORE any plain read, so a competing confirmation that committed
  while this request waited on the row is always visible (locking reads do not
  establish the REPEATABLE READ snapshot) and the loser is refused with the
  blocking document rather than allowed to open a second cycle.
- **Rejected demand** keeps its existing terminal semantics: a terminally
  rejected VAN order with no remaining physical or financial work is COMPLETED
  and does not block; dispatched quantity followed by rejection stays blocked
  until its POD outcome is recorded, and the accepted quantity is still
  invoiced from POD.
- **Ordinary Secondary customers are unaffected**: the guard returns
  immediately unless the sold-to customer is a VAN, so several unresolved
  Secondary cycles remain possible exactly as before.

Regression coverage: `VanCycleTest` (A–K plus a Secondary control) and
`VanCycleConcurrencyTest` (two concurrent VAN confirmations — one wins, one is
refused, exactly one active cycle; plus a no-contention canary).
