# Phase 6 — Shipment / Goods Issue DESIGN

Status: **DESIGN — awaiting approval. No Shipment implementation code exists.**

Confirmed boundaries:

- **SO = demand**
- **Delivery = reversible physical-stock allocation** (allocate / release)
- **Shipment START = irreversible physical Goods Issue**
- **POD = later confirmation of what actually arrived**

---

## 1. DDL compatibility report

| Table | Verdict | Detail |
|---|---|---|
| `shipment` | ✅ sufficient | `shipment_status ENUM('DRAFT','READY','IN_TRANSIT','COMPLETED')`; `source_customer_id` (one source per shipment); `company_id`; `created_by`; nullable `carrier_employee_id`, `vehicle_reference`, `remarks`; timestamps `created_on / ready_on / started_on / completed_on` — maps 1:1 onto the lifecycle. `started_on` supports the START stamp ("set started_at if supported by DDL" → yes). No CANCELLED — per ruling, none is added. |
| `shipment_delivery` | ✅ sufficient | PK (`shipment_no`,`delivery_no`) + **`UNIQUE uq_sd_delivery (delivery_no)`** = a Delivery belongs to **at most one** Shipment — the schema itself enforces "no partial shipment / delivery is the physical unit" and "not in another Shipment". `sequence_no` gives load order. |
| `delivery` | ✅ sufficient | `delivery_status ENUM('DRAFT','ALLOCATED','SHIPPED','PARTIALLY_DELIVERED','DELIVERED')` + `shipped_at`. START writes ALLOCATED→SHIPPED + `shipped_at`. POD statuses are already present for Phase 6b. |
| `delivery_item` | ✅ sufficient | Per-item `allocated_qty`/`delivery_unit` + `is_free_item`; FK to `delivery_item(delivery_no,item_no)` from confirmations. One GOODS_ISSUE row per Delivery Item can reference it. |
| `inventory_movement` | ⚠️ one semantic gap (see §2) | `GOODS_ISSUE` exists; `reference_type VARCHAR(50)`, `reference_no VARCHAR(50)`, `reference_item_no INT UNSIGNED` nullable, PK auto-increment, `idx_im_reference` **non-unique**. |
| `delivery_confirmation` | ✅ untouched in this phase | POD differences (reason enum incl. CUSTOMER_REJECTED/RETURNED/SHORT_DELIVERY) — stock consequences deliberately deferred. |

**No DDL changes are required for Phase 6.**

## 2. Movement-reference conflict analysis

Exact schema capability:

- PK: `movement_id BIGINT AUTO_INCREMENT` — no natural-key constraint to fight.
- `idx_im_reference (reference_type, reference_no)` is a **non-unique KEY** → multiple rows per reference are legal.
- `reference_type VARCHAR(50)`, `reference_no VARCHAR(50)`, `reference_item_no INT UNSIGNED NULL`.

**The conflict:** you require one GOODS_ISSUE row **per Delivery Item** with per-delivery traceability. `reference_no` can carry `shipment_no` **or** `delivery_no`, but not both; `reference_item_no` is a plain INT, and Delivery Item numbers restart at 1 per delivery — so `reference_no=SHP-001, reference_item_no=1` is ambiguous between Delivery A item 1 and Delivery B item 1.

**Recommendation (smallest clean solution — no DDL change, no invented encoding):**

- `reference_type = 'DELIVERY'`, `reference_no = <delivery_no>`, `reference_item_no = <delivery item_no>`, `quantity = −<issued basic qty>`, `company_id/customer_id/product_id/basic_unit` from the item, `created_by` = START actor, `remarks = 'Goods issue via shipment '.$shipmentNo` (shipment traceability preserved in free text).
- Ledger reads: per-delivery-item issue rows are unique by (reference_type, reference_no, reference_item_no); the shipment's full issue set is found via `remarks LIKE` or, better, via the join shipment → shipment_delivery → delivery → delivery_item. Stock-line reporting can aggregate.
- This mirrors how receipts reference their own documents; it keeps every physical issue row pointing at the exact line that left.

Alternative considered and rejected: `reference_no = shipment_no` with `reference_item_no` as a running sequence — invents an item-numbering scheme nothing else in the system uses.

## 3. Shipment state machine

```
DRAFT ──(attach/detach ALLOCATED deliveries)── DRAFT
DRAFT ──(READY, authorized)─────────────────── READY      (no inventory effect, no movement)
READY ──(BACK TO DRAFT, authorized)─────────── DRAFT      (no inventory effect; allowed before START per ruling)
READY ──(START)─────────────────────────────── IN_TRANSIT (PHYSICAL: goods issue, irreversible)
IN_TRANSIT ──(POD confirmation complete)────── COMPLETED  (Phase 6b, not START — see §8)
```

- DRAFT ↔ READY is operational planning, reversible.
- READY → IN_TRANSIT is the physical boundary; IN_TRANSIT → READY/DRAFT **forbidden**; no CANCELLED; no Undo START (per ruling — a mis-start requires a future explicit compensating physical transaction, out of scope for START).

### DRAFT composition rules (validated at attach AND at READY AND at START)

All attached Deliveries must: belong to the same `company_id`; share the shipment's `source_customer_id` exactly; be `ALLOCATED`; not belong to another Shipment (schema-unique); have `shipped_at IS NULL`; and still hold their required restricted inventory at the source. Different SOs, sales employees, sold-to customers and destinations are explicitly allowed.

## 4. Delivery state interaction

| Delivery state | Shipment DRAFT | Shipment READY | Shipment IN_TRANSIT+ |
|---|---|---|---|
| ALLOCATED | attach/detach allowed | attach allowed (or already queued); **release forbidden** | — |
| SHIPPED | — | — | queued (START wrote it); release forbidden |
| DRAFT (released) | never queued (auto-detach on release) | — | — |

- **Release vs DRAFT shipment**: release succeeds and **auto-detaches** the delivery in the same transaction (implemented + regression-tested).
- **Release vs READY shipment**: rejected with "move the shipment back to DRAFT first" (implemented + regression-tested).
- **Release vs IN_TRANSIT/COMPLETED or `shipped_at` set**: rejected — goods issue is never reversed by release.
- START flips every attached delivery ALLOCATED → SHIPPED atomically; nothing else may set SHIPPED in Phase 6.
- START requires every attached delivery still ALLOCATED **under lock** — a delivery released (via DRAFT-shipment path) between READY and START makes START fail loudly rather than issue phantom goods.

## 5. Sales Order status matrix (proposal)

The enum cannot distinguish "shipped / in transit" from "delivered". **No existing value truthfully says "goods issued, not yet confirmed."** Recommendation: keep the SO on `OPEN_DELIVERY` through the shipped phase — the header meaning becomes "in fulfillment, not yet POD-confirmed" — and never light PARTIALLY/COMPLETELY_DELIVERED until POD. This is the honest option; abusing PARTIALLY_DELIVERED at START would contradict "POD = arrival confirmation".

| # | Situation | SO status | Rationale |
|---|---|---|---|
| 1 | Confirmed, nothing allocated | CONFIRMED | unchanged |
| 2 | Partially allocated (rest open) | OPEN_DELIVERY | unchanged |
| 3 | Fully allocated | OPEN_DELIVERY | unchanged (documented §7 ambiguity) |
| 4 | Partially goods-issued, rest allocated | OPEN_DELIVERY | goods left, nothing confirmed |
| 5 | Fully goods-issued | OPEN_DELIVERY | "issued ≠ delivered" — POD pending |
| 6 | Issued + open demand mixture | OPEN_DELIVERY | same; per-item detail lives in delivery rows, not the header |
| 7 | Rejected remainder + issued quantity | PARTIALLY_REJECTED | Phase 4 rejection semantics dominate (existing behavior); issued lines still POD later |
| 8 | POD: some lines confirmed | PARTIALLY_DELIVERED | first truthful use — Phase 6b |
| 9 | POD: all lines confirmed | COMPLETELY_DELIVERED | Phase 6b |

Implementation note: START must NOT call the existing `refreshOrderFulfillmentStatus()` (it would be a no-op anyway since allocation state didn't change — only restricted→issued). SO header is untouched by START.

## 6. Transaction + lock order (START)

```
BEGIN
1.  lock shipment row FOR UPDATE           (PK order)
2.  verify shipment_status = READY         (only READY may START)
3.  lock attached deliveries FOR UPDATE    (ORDER BY delivery_no — deterministic)
4.  verify every delivery ALLOCATED + shipped_at IS NULL
5.  collect delivery items (with per-item basic qty via product_unit_conversion)
6.  group required qty by (source_customer_id, product_id)
7.  lock inventory rows FOR UPDATE         (ORDER BY customer_id, product_id — deterministic)
8.  verify grouped restricted_qty ≥ grouped required for EVERY source/product (all-or-nothing)
9.  create one GOODS_ISSUE movement per Delivery Item (§2 reference scheme)
10. per source/product: restricted_qty -= grouped total   (single write per row)
11. deliveries → SHIPPED, shipped_at = now()
12. shipment → IN_TRANSIT, started_on = now()
COMMIT
```

- After START: unrestricted unchanged; restricted = 0 for fully-issued rows; ON HAND = unrestricted (stock physically left the source).
- Any failure → full rollback (no movements, no status flips).
- **Lock ordering guarantees**: every Phase 5/6 flow takes inventory locks in the same (customer_id, product_id) order; releases/allocations take the delivery row first, START takes shipment → deliveries → inventory; two STARTs serialize on the shipment row; START vs release serialize on the shared delivery row. No cycle ⇒ no avoidable deadlock. Deadlock errors (1213/40001) retried once by the service wrapper for belt-and-braces.
- Cargo-cult guard: re-check attachment table under delivery locks (a delivery attached to a DIFFERENT shipment since load → abort).

## 7. Idempotency design

`POST /shipments/{shipment}/start` runs through `SyncService::process('shipment_start', $request, ...)` — the corrected scoped mechanism (endpoint|user|client-key, 64-char composite preserved by tail-truncation, regression-tested).

- Lost response + same-key retry → stored payload replayed; **no** second restricted decrement, no duplicate GOODS_ISSUE rows, no duplicate transition (`replayed=true` → UI notice, no state writes).
- First-execution failure (422/403/409) releases the key, so a corrected retry is possible — business failures are never sticky (established Phase 4/5 behavior).
- The handler itself re-verifies READY under lock, so even a crash between key-reserve and handler leaves a `_processing` row that a retry waits on/replays — worst case the retry re-enters and finds IN_TRANSIT → fails validation with the key released rather than mutating anything twice.
- Attach/detach/READY/READY→DRAFT: single-row idempotent operations, protected by status under lock; client key accepted but not required (recommendation: keep keys mandatory only for the physical boundary, matching Phase 5 where allocation/release carry keys).

## 8. Shipment completion (explicitly deferred)

`IN_TRANSIT → COMPLETED` should be driven by **POD completion, not by START**: a shipment is "completed" when every attached delivery has reached a POD terminal state (DELIVERED, or PARTIALLY_DELIVERED with its confirmation flow finished). This belongs to Phase 6b together with the POD stock-consequence rules (differences do NOT auto-restore stock; destinations are per-reason and undecided). Nothing in START touches COMPLETED. If operations later need a manual "vehicle returned empty" completion, that is a deliberate separate decision — not silently bundled.

## 9. Concurrency / race analysis + tests

| Race | Protection | Test |
|---|---|---|
| START vs START (double-click/retry) | shipment row lock + status check + idempotency key | second START blocked/replays; exactly one issue set |
| START vs Delivery Release | shared delivery row lock; release auto-detaches only from DRAFT shipments; START requires still-attached ALLOCATED | whichever wins, the loser fails cleanly; no phantom issue, no stuck restricted |
| START vs inventory op (receipt/adjust) on same source+product | same inventory row lock, same global order | receipt concurrent to START sees after-state; damage that would push restricted negative rejected |
| Two Shipments overlapping the same source+product | both take inventory locks in identical order; grouped check under lock | 100 restricted across SHP-A(60)+SHP-B(60) → exactly one succeeds, other 422 insufficient restricted |
| Release vs Release | delivery row lock (already tested) | existing test |

Real-locking pattern from `DeliveryConcurrencyTest` (forked connection holding `FOR UPDATE`, parent blocks, asserts post-lock state) reused for START-vs-START and START-vs-release; the race pair tests run both sides through the real services.

## 10. Authorization model

Company context always derives from the operation's records (shipment.company_id, delivery.company_id, product.company_id) — **never** from `customer_master` (customers are global).

| Action | SALES_EMPLOYEE | COMPANY_ADMIN | SUPERADMIN |
|---|---|---|---|
| Create shipment | only as `created_by`, source = an assigned supplying Primary (via `customer_employee`) | own company, any valid stock-holder source | any company |
| Attach/detach delivery | own deliveries (created_by) whose SO is within product scope; delivery source must equal shipment source | any delivery of own company meeting composition rules | any |
| READY | allowed for own shipments (dispatch finalization) — **recommended** since field staff prepare their own loads | own company | any |
| READY → DRAFT | allowed for own shipments before START | own company | any |
| START | own shipments (they are the dispatching party) | own company | any |
| View shipment | own shipments; admins: company; superadmin: all | own company | all |

All of it re-validated server-side; client-supplied shipment/delivery/product IDs never trusted. Delivery-side scope rules from Phase 5 (`assertCanFulfill`) are reused for attach eligibility.

## 11. Proposed UI (mobile-first, Material 3, same patterns as Phase 5)

- **Shipments list** (`/shipments`): status chips (DRAFT/READY/IN_TRANSIT/COMPLETED), source, delivery count, started/created dates; filters by status/source. Scoping per §10.
- **Shipment create/edit** (`/shipments/create`, `/shipments/{no}/edit` — DRAFT only): source selector (eligible stock holders per role); eligible-ALLOCATED-delivery picker (card list: delivery_no, SO ref, sold-to, item summary chips, quantities, FREE indicator, destination; invalid candidates greyed with reason — wrong source/company/already queued); attached-deliveries section with remove; running totals per product in basic units; validation summary.
- **Shipment detail** (`/shipments/{no}`): status chip + timestamps (created_on/ready_on/started_on/completed_on), source, carrier/vehicle/remarks; attached deliveries with per-item lines (qty, unit, FREE chip, SO link); aggregated issue preview (per product: basic qty); actions by state: DRAFT → attach/edit + "Mark READY"; READY → "Back to DRAFT" + **START**; IN_TRANSIT → read-only + POD hint (Phase 6b); COMPLETED → read-only.
- **START confirmation**: explicit dialog — "Start shipment? This physically issues the goods: restricted stock is decremented and GOODS_ISSUE is recorded. This cannot be undone through the shipment workflow." Requires the same native-confirm pattern used by allocation (per your earlier instruction, automation limitations don't drive UX).
- Nav: "Shipments" entry for all roles; orders/deliveries screens keep their existing actions.

## 12. Test plan

1. DRAFT: create with wrong-source delivery rejected; attach ok; attach twice (unique) rejected; attach non-ALLOCATED rejected; detach ok.
2. Composition totals per product/basic-unit shown; free items included in issue preview.
3. READY: allowed → composition locked (attach/detach now rejected); no inventory effect, no movement.
4. READY → DRAFT: allowed, no inventory effect; re-editable.
5. START happy path: restricted -= grouped totals (grouped-across-deliveries case: 10+5 same product → one row −15, TWO movement rows −10/−5 per delivery item); unrestricted unchanged; on-hand drops; deliveries SHIPPED + shipped_at; shipment IN_TRANSIT + started_on; immutable negative GOODS_ISSUE rows (§2 references).
6. START from DRAFT rejected; START twice → second blocked (idempotent replay via same key); START with a delivery released mid-READY → 422 all-or-nothing (no partial issue, status untouched).
7. Free DEAL item issues physically like any item (zero price irrelevant).
8. Insufficient restricted at START → 422, everything rolled back.
9. Release interactions: already covered (auto-detach / READY rejection) + START-vs-release real-locking test.
10. Concurrency: START vs START; two shipments overlapping inventory; START vs adjust (all real-locking fork pattern).
11. Authorization matrix: employee own-only, admin company-wide, foreign-company 403, product-scope respected on attach.
12. Idempotency: lost-response retry replays; failure releases key.
13. SO header: START does not change SO status (matrix rows 4–6 assert OPEN_DELIVERY persists).

## 13. Unresolved questions (business decisions needed)

1. **READY permission for sales employees** — I recommend YES (they prepare their own dispatch); confirm or restrict to admins?
2. **START actor** — the shipment's creating employee only, or any authorized user of the company? (Table allows company admins; field reality?)
3. **COMPLETED trigger** — confirm deferral to POD completion (§8); is a manual "vehicle returned" completion ever wanted?
4. **POD stock consequences** (Phase 6b, blocking COMPLETED semantics): per `difference_reason` destinations — e.g. RETURNED→unrestricted + CUSTOMER_RETURN/VAN_RETURN movement vs CUSTOMER_REJECTED→write-off? Needs its own design + your ruling before any POD code.
5. **Ship-to-destination mapping for VAN flows** — shipments from a VAN source: any restriction now, or defer with VAN reconciliation (already deferred)?

---

*End of design. No implementation until approved.*
