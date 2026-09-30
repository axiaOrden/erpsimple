# Phase 7 — Delivery Confirmation / POD — DESIGN

Status: **APPROVED — four rulings received (see §9); implementation may proceed.**
No POD code existed at design time.

Confirmed boundary chain (unchanged):

**SO = demand → Delivery = reversible allocation → Shipment START = irreversible
Goods Issue → POD = what the customer confirms was actually received.**

Core invariant: **START records what physically left the source; POD records what
arrived.** POD never modifies, deletes, replaces or rewrites the original
`GOODS_ISSUE` ledger movement. 10 CTN shipped / 8 accepted / 2 short ⇒ the
ledger keeps `−10 CTN`; the confirmation keeps `confirmed 8 + difference 2`.

---

## 1. POD quantity semantics (per Delivery Item)

One **authoritative** confirmation per shipped Delivery Item
(`delivery_no`, `delivery_item_no`). The DDL's `idx_dconf_item` is non-unique,
so uniqueness is enforced by the service **under lock**, not by the schema.
No silent overwrite: a second submission for an already-confirmed item is
rejected (or idempotently replayed when it is the SAME logical submission,
see §7).

Input: `confirmed_qty` + `confirmed_unit` (user-facing business unit).
The comparison quantity is normalized to the product BASIC unit via
`product_unit_conversion` (`ProductUnitService`, `Decimal` string math — no
floats). `difference_qty` is stored in the SAME business unit as the input,
computed as `shipped − confirmed` in basic terms and converted back for
display only; the authoritative comparison is always basic-vs-basic.

Derived status (exactly per spec):

| Condition (basic units) | Status | Difference | Reason |
|---|---|---|---|
| confirmed == shipped | `CONFIRMED` | 0 | must be `NONE` |
| 0 < confirmed < shipped | `PARTIAL` | shipped − confirmed | non-`NONE` REQUIRED |
| confirmed == 0 | `REJECTED` | shipped | non-`NONE` REQUIRED |

Validation: negative or non-numeric input rejected; `confirmed > shipped`
(basic units) rejected with a clear message — over-delivery is a future
explicit design, not an accident; missing/non-`NONE` reason rules per table.

The confirmation row stores: confirmed qty/unit, difference qty/unit, reason,
status, `confirmed_by` (employee), `confirmation_date`, optional remarks.
Free/deal items confirm like any physical item (zero price is irrelevant);
their confirmations count toward Delivery/SO derivation like any other.

## 2. Unit normalization

- Shipped basis: `delivery_item.allocated_qty / delivery_unit` → basic via the
  item's product conversions (same conversion the GOODS_ISSUE used).
- Confirmed basis: input unit → basic (missing conversion = validation error).
- Status thresholds and the over-delivery guard compare basic-to-basic with
  `Decimal::compare`.
- Display difference is computed in the INPUT unit (shipped_basic −
  confirmed_basic re-expressed through the input unit) so the screen shows
  coherent user-facing numbers.

## 3. Authorization (documented BEFORE coding — see §9.1 for the ruling request)

- **COMPANY_ADMIN**: any POD within `shipment.company_id`.
- **SUPERADMIN**: existing active-company/scoping conventions (cross-company
  administration).
- **SALES_EMPLOYEE**: **source-assignment rule** — the confirming employee must
  have the shipment's `source_customer_id` in their `customer_employee`
  assignments. This is the SAME operational-scope rule Phase 6 uses for
  shipments (and deliberately NOT `created_by == sales_employee_id`: a
  shipment may combine deliveries of several salespeople, ruling Phase 6 §3).
  Company context derives from shipment/delivery/product records — never from
  `customer_master`.

> ⚠️ §9.1 asked you to confirm this rule. **RULED: destination/sold-to
> assignment** — the confirming employee must be assigned (customer_employee)
> to the delivery's **`sold_to_customer_id`**. Dispatch (START) is a
> source-side operation; POD is the receiving side and must follow the
> receiver relationship — an employee assigned to MIMZA must not be able to
> confirm Mama Chi's receipt merely because MIMZA shipped the goods.

## 4. Delivery status derivation (from item confirmations)

Delivery keeps `SHIPPED` (with `shipped_at`) as the "awaiting POD" state and
derives forward only — derivation never regresses a state:

- every shipped item has an authoritative confirmation AND every confirmation
  status = `CONFIRMED` → Delivery `DELIVERED`, `delivered_at` stamped;
- every shipped item confirmed, at least one `PARTIAL`/`REJECTED` →
  Delivery `PARTIALLY_DELIVERED` (delivered_at also stamped: something was
  received);
- any shipped item still lacking a confirmation → Delivery stays `SHIPPED`.

Differences do NOT restore inventory (§7). Confirmations are immutable once
written; corrections are a future design (§9.4).

## 5. Proposed SO fulfillment / status derivation (AFTER POD)

SO status is recalculated from **actual confirmed quantities across its
Delivery Items in basic units**, layered over the Phase 4/5 rules:

```
for each SO item (in basic units):
    ordered      = order_qty → basic
    allocated    = Σ delivery_item qty of NON-DRAFT deliveries
    shipped      = Σ delivery_item qty of SHIPPED/DELIVERED_/PARTIALLY_ deliveries
    confirmed    = Σ authoritative confirmations (CONFIRMED: full item qty;
                   PARTIAL: confirmed part; REJECTED: 0)
    rejectedOpen = (item rejected) ? allocatedRemaining : 0

itemState:
    FULLY_CONFIRMED  confirmed ≥ shipped-of-fully-covered demand …
```

Concretely, the header rules:

- any rejected item (unchanged Phase 4 precedence) → `PARTIALLY_REJECTED`
  (or `COMPLETELY_REJECTED` if all items rejected) — rejection still wins
  over fulfillment labels;
- else if **every** item has all its shipped quantity POD-confirmed **and**
  nothing remains unshipped-without-confirmation → `COMPLETELY_DELIVERED`;
- else if **some** confirmation exists on any item → `PARTIALLY_DELIVERED`;
- else (allocations/shipping exist but no POD yet) → stays `OPEN_DELIVERY`.

### Worked examples (order qty in CTN; one product for clarity)

1. **order 30, shipped 30, confirmed 30** → item fully confirmed →
   SO `COMPLETELY_DELIVERED`; delivery `DELIVERED`.
2. **order 30, shipped 30, confirmed 20, difference 10** → all items confirmed
   (one PARTIAL) → SO `PARTIALLY_DELIVERED` (not COMPLETELY — 10 never
   arrived); delivery `PARTIALLY_DELIVERED`. Difference creates NO stock, NO
   credit, NO invoice change.
3. **order 30, shipped 10, remaining 20 open** → nothing POD-confirmed yet →
   SO stays `OPEN_DELIVERY`; when the 10 is confirmed: every shipped item is
   confirmed but demand remains open unshipped → per rule above the SO shows
   `PARTIALLY_DELIVERED` (some confirmation exists) while 20 remain open —
   documented nuance: PARTIALLY_DELIVERED covers "partially fulfilled AND
   partially confirmed"; OPEN_DELIVERY remains for nothing-confirmed-yet.
4. **order 30, shipped 10, then remaining 20 rejected** → rejection semantics
   (Phase 4) mark the item rejected with `PARTIALLY_REJECTED` precedence;
   after the 10 confirms, the SO keeps `PARTIALLY_REJECTED` (rejection wins
   the header label); delivery shows DELIVERED for the 10. No restoration of
   the rejected 20; it simply never ships (already enforced in Phase 5).
5. **order 30, two Deliveries of 10 + 20, both eventually confirmed** →
   after first delivery confirms: SO `PARTIALLY_DELIVERED`; after the second:
   `COMPLETELY_DELIVERED`. Multiple deliveries per SO item aggregate via the
   confirmed-basis sums above.
6. **free/deal items** — confirmed like any item; they count toward their
   Delivery's derivation and appear in SO sums only as their own line (which
   is free); a confirmed free item with unconfirmed paid sibling keeps the SO
   `PARTIALLY_DELIVERED`; invoice value of a free line stays 0 (finance later).

> ⚠️ §9.2 asks you to confirm one debatable choice in this matrix: in example
> 3, after confirming 10 of 30 with 20 still open-and-unshipped, I propose
> `PARTIALLY_DELIVERED`. The stricter alternative is to keep `OPEN_DELIVERY`
> until *every* shipped item of the order is confirmed (i.e. partial
> confirmation alone doesn't flip the header while demand is still in
> fulfillment). Both are defensible; the design needs your pick.

## 6. Proposed Shipment completion rule (documented before implementing)

`IN_TRANSIT → COMPLETED` fires automatically inside the POD-confirmation
transaction when, after the confirmation is committed, **every attached
Delivery has an authoritative confirmation for every shipped item** (i.e. no
attached delivery is still plain `SHIPPED` — all are `DELIVERED` or
`PARTIALLY_DELIVERED`), and `completed_on` is stamped. This makes COMPLETED a
pure derivation of POD terminality, never a manual shortcut and never
triggered by START. Deliveries remain `SHIPPED→DELIVERED/PARTIALLY_DELIVERED`
independently.

> ⚠️ §9.3 asks whether COMPLETED should also require a minimum status beyond
> "every item has an outcome" (e.g. forbid COMPLETED while any confirmation
> is REJECTED-with-pending-investigation). Default proposal: no extra
> requirement — REJECTED is a legitimate terminal outcome.

## 7. Explicitly DEFERRED stock-return behavior

POD differences create **no inventory changes of any kind** in this phase:

- `CUSTOMER_REJECTED` does NOT return stock to the source.
- `RETURNED` does NOT increase source stock — until a later explicit
  return-receipt workflow exists (which would then write
  `CUSTOMER_RETURN`/`VAN_RETURN` movements against its own document).
- `SHORT_DELIVERY` does NOT alter inventory.
- `EMPLOYEE_DAMAGE` / `DISTRIBUTOR_DAMAGE` do NOT alter inventory.
- GOODS_ISSUE rows remain untouched and immutable; POD writes only into
  `delivery_confirmation` (plus the derived Delivery/Shipment statuses).

## 8. Explicitly DEFERRED financial effects

POD must not create or alter invoice/credit/payment records:

- `DISTRIBUTOR_DAMAGE` may eventually create customer credit while preserving
  the original invoice — future, not here.
- `EMPLOYEE_DAMAGE` may eventually create employee liability — future, not here.
- The **invoice debtor rule remains UNRESOLVED** (Phase 7 must not infer
  `invoice.customer_id` from `sold_to_customer_id`; nothing in POD touches
  invoices). Finance implementation starts only after that ruling.

## 9. RULINGS (received — binding)

1. **Employee POD authorization**: **destination/sold-to assignment** — the
   confirming employee must hold a `customer_employee` assignment for the
   delivery's `sold_to_customer_id` (receiving side). Source assignment is
   deliberately NOT sufficient.
2. **SO header at partial confirmation with open demand** (example 3):
   **PARTIALLY_DELIVERED** (as soon as any shipped quantity is confirmed).
3. **Shipment COMPLETED**: **outcome-only** — every attached delivery has an
   authoritative outcome per item; REJECTED is a legitimate terminal outcome.
4. **POD corrections**: **none this phase** — the first confirmation per
   Delivery Item is authoritative and immutable; a correction flow is a
   future design.

---

*Implementation (service + endpoints + UI + tests per the approved list) starts
after §9 answers. Tests will include: full/partial/rejected confirmation, CTN↔PCS
normalization, over-delivery rejection, missing reason rejection, non-SHIPPED
rejection, duplicate rejection + idempotent replay, cross-company and employee
authorization, GOODS_ISSUE immutability, no inventory restoration, free-item and
multi-item/multi-delivery derivation, and concurrent duplicate submissions
(real-locking).*
