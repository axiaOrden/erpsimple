# Phase 8 — Finance (Invoices / Payments / Credits) — DESIGN (REV 2)

Status: **APPROVED FOR IMPLEMENTATION (REV 2 + implementation rulings §23).
No schema changes; Finance code follows this document.**

Approved boundary chain: SO = demand → Delivery = reversible allocation →
Shipment START = irreversible Goods Issue → **POD = customer-confirmed receipt
= the billing boundary**.

- **Debtor (ruled)**: `invoice.customer_id = sales_order.sold_to_customer_id`
  — the sold-to Secondary. A Primary is a stock holder / supplying customer,
  never a debtor; erpSimple has no Primary→Company AP workflow.
- **Model A (ruled)**: one final invoice per SO, `uq_invoice_so` preserved.

---

## 1. Invoice generation boundary

The final receivable arises ONLY from authoritative POD outcomes. Nothing in
SO confirm, Delivery allocation, Shipment READY or START/Goods Issue creates a
receivable (a read-only "expected invoice" preview is allowed later).

Generation is triggered automatically inside the POD confirmation transaction
that first reaches **billing-terminal** state (idempotent, §15).

### Billing-terminal definition (CORRECTED — terminal outcomes, not only CONFIRMED)

A **terminal POD outcome** is `CONFIRMED`, `PARTIAL` **or** `REJECTED` — any
final disposition recorded through authoritative POD.

An SO is **billing-terminal** when every SO item has reached a final
disposition through:

- an authoritative terminal POD outcome for **every** shipped Delivery Item of
  the item, **and/or**
- SO rejection (Phase 4) of the remaining unfulfilled demand.

Only actual POD `confirmed_qty` contributes to invoice quantity — terminality
is about *finality of disposition*, not about full acceptance. A shipment that
was short-delivered is still finished business.

Examples:

| SO situation | Terminal? | Invoice |
|---|---|---|
| 30 ordered, 30 shipped, 30 CONFIRMED | yes | 30 |
| 30 ordered, 30 shipped, 20 CONFIRMED + 10 PARTIAL(SHORT) | **yes** (all outcome'd) | **20** |
| 30 ordered, 10 shipped 10 confirmed, 20 open | no (open demand) | none yet |
| 30 ordered, 10 shipped 10 confirmed, 20 REJECTED | **yes** (rejection disposition) | 10 |
| 30 ordered, all REJECTED outcomes | yes | 0-qty lines / no monetary invoice (§6) |
| 30 ordered, all rejected before allocation | yes | **none** |

## 2. Debtor identity

`invoice.customer_id = sales_order.sold_to_customer_id`, always server-side.
`supplying_customer_id` / `source_customer_id` never receive receivables.
Money handed over at the Primary still pays the Secondary's invoice — the
debtor comes from the invoice/SO record, never from the location of the cash.

## 3. POD → invoice quantity mapping (CORRECTED)

Per SO item, in BASIC units:

```
invoiceable_basic = Σ over shipped Delivery Items of the item:
    outcome CONFIRMED → shipped_basic          (full)
    outcome PARTIAL   → confirmed_basic        (the acknowledged part)
    outcome REJECTED  → 0
```

The earlier REV-1 text ("PARTIAL contributes 0") was **wrong** and is
superseded: GI 10 / POD 8 SHORT 2 invoices **8**, exactly per ruling §21.2.
Items never shipped (or rejected before allocation) contribute 0. Unshipped
open demand keeps the SO non-terminal (no invoice yet).

## 4. SO price snapshot → invoice pricing (CORRECTED: consistent basis)

`sales_order_item.unit_price` is denominated in the SO item's ORDER UNIT.
Quantity and price basis must therefore always be converted **together** —
never multiply a basic-unit quantity by a CTN-denominated price.

Line selection per SO item (Decimal math throughout):

1. **Order-unit line (exact case)**: if `invoiceable_basic` converts back to
   the order unit **without remainder** (integer quotient — whole cartons),
   then:
   `invoice_qty = quotient`, `invoice_unit = order_unit`,
   `unit_price = SO snapshot unit_price`,
   `subtotal = invoice_qty × unit_price`.
   Example: 1 CTN = 24 PCS, price 24,500/CTN, POD 8 CTN → 8 × 24,500 = 196,000.
2. **Basic-unit line (fallback case)**: otherwise — and *also* when the order
   unit is the basic unit — price basis converts with the quantity:
   `price_per_basic = order_price ÷ factor`,
   `invoice_qty = invoiceable_basic`, `invoice_unit = basic_unit`,
   `subtotal = invoice_qty × price_per_basic`.
   Example: price 24,000/CTN, confirmed 30 PCS → 24,000/24 = 1,000/PCS →
   30 × 1,000 = 30,000 (the ruling's example, verbatim).

Edge flagged for implementation (not a blocker): when the fallback division
`order_price ÷ factor` is itself not exact at 2 decimals (e.g. 24,500/24 =
1,020.8333…), compute `subtotal = (invoiceable_basic × order_price) ÷ factor`
as ONE final Decimal division (authoritative) and store the closest-2dp
`unit_price` for display; noted in §22.1 — alternatively such SO prices could
be validated at capture time. Header totals: `gross = Σ subtotal`,
`invoice_amount = gross − discount + tax`, `currency = SO currency`.
`recommended_price` / `condition_price_no` are history only; `price_condition`
is never re-resolved at invoice time.

## 5. Unit conversion

- POD quantities are already normalized to basic units (Phase 7); the
  invoiceable sum is basic.
- Exact back-conversion = factor divides the basic quantity **without
  remainder** (whole order units — 192/24 = 8 ✓; 180/24 = 7.5 ✗ falls back).
- Fallback keeps quantity AND price basis in the basic unit (§4.2) — never a
  basic-unit quantity with an order-unit price.
- `invoice_item.invoice_unit` FK to unit_master; `is_free_item`,
  `sales_order_no`, `sales_order_item_no` preserve traceability.

## 6. Free DEAL lines

Confirmed free lines appear on the invoice with `is_free_item = true`,
`unit_price = 0`, `subtotal_amount = 0` — visible/auditable, zero money.
Unconfirmed/never-shipped free lines are omitted. Free lines never delay
invoicing of paid lines. (A fully-rejected SO: all lines at 0/omitted —
generation still records nothing monetary; §21.4 keeps one-invoice-per-SO so
the natural outcome is simply that no meaningful invoice is produced; see
§22.2 for the zero-invoice edge.)

## 7. One SO / multiple Deliveries / multiple Shipments (Model A — RULED)

One invoice per SO, generated at the billing-terminal moment irrespective of
how many Deliveries/Shipments/confirmation sessions produced it.
`uq_invoice_so` enforces it at the schema level. Partial confirmations delay
the single generation; no incremental invoicing, no per-delivery documents.

## 8. Invoice status / lifecycle (RULED §21.5)

- Numbering `INV-<company>-<year>-#####`.
- **`payment_term = IMMEDIATE`, `due_date = invoice_date`** — automatic
  generation never asks an admin to choose terms. If PAY_LATER is needed
  later, the agreed term is snapshotted upstream (SO/customer commercial
  rule) and inherited deterministically — not chosen at invoice time.
- `payment_status` derived from applied money (§13 formulas): UNPAID /
  PARTIALLY_PAID / PAID, recomputed in-transaction.
- Quantities/prices immutable after generation; corrections are credits (§11).

## 9. Payment model

`payment` rows against the debtor: company, customer_id (sold-to), date,
method (CASH/TRANSFER/POS/OTHER), amount, reference, status
PENDING → CONFIRMED (→ CANCELLED per ruling §23.2: only PENDING or
CONFIRMED-without-allocations may cancel — allocated payments cannot be
cancelled, and allocation rows are immutable history).
Only CONFIRMED payments allocate. Unallocated remainders may later convert
to OVERPAYMENT credit (§11).

## 10. Payment allocation

`payment_allocation` (PK payment+invoice), same debtor + company:

- Σ allocations ≤ payment.amount (remainder stays unallocated);
- each allocation ≤ invoice outstanding;
- immutable history; mistakes are corrected by compensating entries, never
  edits;
- default UX auto-FIFO by invoice_date with per-invoice override; invoice
  `settled_amount`/`payment_status` re-derived in the same transaction.

## 11. Customer credits

`customer_credit` = a later financial adjustment to an amount actually
invoiced — never an automatic GOODS_ISSUE−POD difference (RULED §21.2).

- **Implemented in Phase 8**: `MANUAL_ADJUSTMENT`, `OVERPAYMENT` (conversion
  of an unallocated payment remainder). A converted remainder is spent —
  `unallocatedAmount` nets it off, so it can never be converted twice or
  allocated after conversion.
- **Enum-reserved, deferred (RULED §21.6)**: `POD_DAMAGE`, `RETURN` — until
  their explicit workflows are designed. A POD difference never auto-creates
  a customer credit (unaccepted quantity is never invoiced).
- `original_amount`/`remaining_amount` maintained; `OPEN → PARTIALLY_USED →
  USED` derived from allocations; CANCELLED releases.

## 12. Credit allocation

`credit_allocation` (PK credit+invoice): same debtor/company; Σ ≤
credit.remaining_amount; per invoice ≤ outstanding; immutable; FIFO default
with override; invoice statuses re-derived in-transaction.

## 13. Outstanding / exposure math (CORRECTED — three separate values)

```
invoice_outstanding = Σ invoice.invoice_amount − Σ invoice.settled_amount
available_credit    = Σ remaining_amount of credits in OPEN / PARTIALLY_USED
net_exposure        = max(0, invoice_outstanding − available_credit)
```

- Unused credit **reduces** exposure (never adds to outstanding — the REV-1
  formula wrongly added open credit to the debt).
- All three values are kept SEPARATE in UI/reporting: outstanding debt,
  available credit, net exposure.
- Worked check: outstanding 100,000; OPEN credit remaining 20,000 →
  outstanding 100,000, available 20,000, net exposure **80,000**.
- Aging buckets (0-30/31-60/61-90/90+) from invoice_date on the unpaid
  remainder — reported, not enforced. The supplying Primary appears nowhere.

## 14. Credit blocking (RULED §21.7 — no configurable limit facility)

The existing Phase 4 business rule is preserved with the resolved debtor
identity: **a sold-to debtor with positive net exposure cannot confirm another
Sales Order.** Available customer credit offsets exposure (§13). Blocking is
evaluated server-side at confirm time via the existing ConfirmationConflict
mechanism. NO `config/finance.php` credit limit, NO customer credit-limit
columns — a conventional limit facility will be built only when the business
explicitly requires one.

## 15. Idempotency

- **Invoice generation** happens inside the first billing-terminal POD
  transaction, guarded by the SO lock + `uq_invoice_so`; duplicate attempts
  are no-ops returning the existing invoice; the triggering POD submission
  itself replays via the existing `pod_confirm` idempotency scope.
- Payments `'payment_create'`, allocations `'payment_allocate'`, credits
  `'credit_create'` / `'credit_allocate'` via `SyncService` — lost-response
  retries replay; failures release keys (64-char composite discipline).

## 16. Authorization

| Action | SALES_EMPLOYEE | COMPANY_ADMIN | SUPERADMIN |
|---|---|---|---|
| View invoices/payments/credits/statements | own assigned sold-to customers | own company | all |
| Create invoice | never (automatic) | — | — |
| Record payment / allocate payment or credit | never | own company | any |
| MANUAL_ADJUSTMENT credit / OVERPAYMENT conversion | never | own company | any |

Company context from the financial records (never customer_master); debtor
identity from the SO — never from who handed over cash.

## 17. Concurrency

- Generation races only with the final POD confirmation — serialized on the
  SO lock inside that transaction; `uq_invoice_so` backstops.
- Allocations: lock the payment (or credit) row, then touched invoices
  `FOR UPDATE` in deterministic `invoice_no` order; validate remaining/
  outstanding under lock; FIFO order is deterministic; `DB::transaction(
  attempts: 2)` on the deadlock-sensitive paths (Phase 6 pattern).
- All money math `Decimal` strings; money columns 2dp.

## 18. Mobile-first UI (Material 3)

- **Invoices** list + detail (lines with qty/unit/unit_price/subtotal, FREE
  chip, SO link, settled breakdown, IMMEDIATE/due date, status chip).
- **Payments** list + create + allocation screen (FIFO suggestion, live
  remainder, override) — admins only.
- **Credits** list + create (MANUAL_ADJUSTMENT) + allocation; OVERPAYMENT
  conversion from unallocated payment remainders — admins only.
- **Customer statement** per sold-to debtor: invoices, payments, credits,
  running outstanding — and the THREE exposure values (§13) shown separately.
- Nav "Finance": admins full; sales employees read-only statements of their
  assigned customers.

## 19. Test plan

1. Invoice auto-generated at first billing-terminal POD (30/30 CONFIRMED →
   30 × snapshot).
2. PARTIAL terminality: GI 10, POD 8 SHORT 2 → invoice **8**; no credit rows.
3. PARTIALLY_REJECTED remainder: order 30, confirmed 10, 20 rejected →
   invoice 10 (single invoice).
4. Fully-rejected / fully-rejected-before-allocation SO → no monetary invoice.
5. Free line confirmed → invoice line at 0; unconfirmed free line omitted.
6. Exact conversion: 240 PCS → 8 CTN line × 24,500 (integer back-conversion).
7. Inexact conversion with divisible price: 30 PCS, 24,000/CTN → 30 × 1,000
   (basis converted consistently).
8. Snapshot price honored (24,500 not 25,000; master change irrelevant).
9. `uq_invoice_so` + SO-lock guard: no duplicate invoice; POD retry replays.
10. Payment create/allocate FIFO → PARTIALLY_PAID → PAID; over-allocation
    rejected; cross-debtor rejected.
11. MANUAL_ADJUSTMENT credit → allocate → statuses; OVERPAYMENT conversion.
12. §23.2 cancellation: PENDING/CONFIRMED-unallocated → CANCELLED; CONFIRMED
    with ≥1 allocation REJECTED (422) and every allocation row and invoice
    settled value left untouched (allocation history is immutable).
    Concurrency: two allocations cannot exceed one payment remainder; two
    billing-terminal generations produce exactly one invoice.
13. Exposure math: outstanding/available/net kept separate; unused credit
    REDUCES net exposure; positive net exposure blocks SO confirm via
    ConfirmationConflict; fully allocated credit unblocks.
14. Authorization matrix (employee read-own; foreign admin 403; sales
    employee cannot move money).
15. Overpayment remainder: a converted remainder is not money anymore —
    re-conversion and re-allocation are both rejected; exactly one credit
    per payment remainder.
16. Debtor identity: Mama Chi's payments/credits never touch MIMZA; Primary
    absent from all receivable sums.

## 20. Re-checked worked examples (corrections applied)

1. **CTN-priced SO confirmed in CTN**: 10 CTN @ 24,500; GI 10; POD 8 SHORT 2
   → 192 basic, exact back-conversion 8 CTN → `8 × 24,500 = 196,000.00`.
2. **CTN-priced partially confirmed in PCS**: 24,000/CTN; GI 240 PCS; POD 180
   PCS (7.5 CTN — non-integer) → basic fallback `24,000/24 = 1,000/PCS` →
   `180 × 1,000 = 180,000.00` (qty AND price basis both basic).
3. **Exact vs inexact**: 192 PCS → 8 CTN line (order unit); 30 PCS → basic
   line 30 × 1,000 = 30,000 (the ruling's verbatim example).
4. **PARTIAL POD terminality**: GI 10 / POD 8 SHORT 2 → terminal; invoice 8;
   the 2 creates no credit and no stock movement.
5. **PARTIALLY_REJECTED terminality**: order 30, POD-confirmed 10, remaining
   20 SO-rejected → terminal; invoice 10.
6. **Unused credit reduces exposure**: outstanding 100,000 + OPEN credit
   20,000 → outstanding 100,000, available 20,000, net exposure 80,000
   (never 120,000).

SO status note (unchanged Phase 7 behavior, documented): an SO whose shipped
items all have outcomes but some are PARTIAL/REJECTED stays
PARTIALLY_DELIVERED — the header describes physical delivery, while
billing-terminality (§1) drives invoicing. These are deliberately different
axes; GOODS_ISSUE alone still never makes an SO COMPLETELY_DELIVERED.

## 21. Rulings (BINDING — applied throughout)

1. PARTIALLY_REJECTED SO with confirmed remainder → one final invoice for the
   confirmed quantity.
2. PARTIAL POD → invoice confirmed quantity only; no credit for the
   never-invoiced difference.
3. Inexact unit conversion → basic-unit fallback with CONSISTENTLY converted
   quantity AND price basis.
4. Model A approved — one invoice per SO; `uq_invoice_so` kept.
5. `payment_term = IMMEDIATE`, `due_date = invoice_date`; no term choice at
   generation; PAY_LATER later via upstream commercial snapshot.
6. POD_DAMAGE / RETURN credits deferred (enum reserved). A POD difference is
   NOT a customer credit: unaccepted quantity is simply never invoiced, so
   there is nothing to credit (finance corollary of the transit-stock
   correction — see docs/TRANSIT_STOCK_CORRECTION.md).
7. No config credit limit; existing rule stands: positive net exposure blocks
   SO confirmation; available credit offsets; sold-to identity.
8. Tax/discount inherited from SO snapshots as-is (zeros stay zeros); no new
   tax engine.

## 22. Residual implementation notes (non-blocking)

1. **RULED (implementation ruling 1) — authoritative subtotal on inexact
   fallback**: when `(qty × order_price) ÷ factor` exceeds 2dp precision,
   `subtotal_amount` is computed at full Decimal precision and rounded ONCE
   to money precision — it is AUTHORITATIVE. The persisted basic-unit
   `unit_price` may be the closest representable 2dp display/reference value
   (e.g. 24,500/24 → 1,020.83), but the subtotal is NEVER recomputed from it
   (30 × 1,020.83 = 30,624.90 ✗; authoritative `(30 × 24,500)/24 = 30,625.00`
   ✓). Otherwise-valid SO prices are never rejected for repeating decimals.
2. Fully-rejected SO under Model A: no meaningful invoice is generated; if a
   zero-amount audit document is ever wanted, that is a future decision.
3. PAY_LATER inheritance and POD_DAMAGE/RETURN workflows: future designs.

## 23. Implementation rulings (BINDING — REV 2 approval)

1. **Authoritative subtotal** (as §22.1): proportional calculation at Decimal
   precision; round only the final monetary subtotal; persisted unit_price is
   display/reference; never re-derive the subtotal from the rounded price;
   never reject prices with repeating basic-unit decimals.
2. **Payment cancellation keeps allocation history immutable**:
   - PENDING → CANCELLED: allowed.
   - CONFIRMED with zero allocations: cancellation allowed.
   - CONFIRMED with ≥1 allocation: cancellation REJECTED (422).
   - `payment_allocation` rows are never deleted or mutated; no refund /
     reversal / negative-allocation semantics in Phase 8 — a future explicit
     reversal workflow will handle already-applied payments.
   Test expectation: cancelling a CONFIRMED, allocated payment fails with a
   clear conflict and leaves every allocation row and invoice settled value
   untouched.

---

*End of design REV 2. No schema changes and no Finance implementation until
this revision is accepted.*
