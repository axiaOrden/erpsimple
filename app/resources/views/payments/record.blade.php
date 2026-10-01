<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1>Record payment</h1>
                <p class="text-xs text-on-surface-variant">
                    {{ $invoice->invoice_no }} · {{ $invoice->customer?->business_name ?? $invoice->customer_id }}
                </p>
            </div>
            <a href="{{ route('finance.invoices.show', $invoice) }}" class="m-chip shrink-0">← Invoice</a>
        </div>
    </x-slot>

    @php
        $proofConfig = [
            'employeeName' => $employee->employee_name,
            'employeeId' => $employee->employee_id,
            'customerName' => $invoice->customer?->business_name ?? $invoice->customer_id,
            'invoiceNo' => $invoice->invoice_no,
            'amount' => $outstanding,
            'maxEdge' => 1600,
            'quality' => 0.82,
        ];
        $invoiceItems = $invoice->items->count();
    @endphp

    <div class="space-y-3">
        <div class="m-card p-4">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-xs uppercase tracking-wide text-on-surface-variant">Amount due</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ $invoice->currency }} {{ number_format((float) $outstanding, 2) }}</p>
                </div>
                <span class="m-chip shrink-0 {{ $invoice->payment_status->value === 'UNPAID' ? 'm-chip-error' : '' }}">
                    {{ str_replace('_', ' ', $invoice->payment_status->value) }}
                </span>
            </div>
            <p class="mt-1 text-xs text-on-surface-variant">
                {{ $invoiceItems }} line(s) · invoice total {{ number_format((float) $invoice->invoice_amount, 2) }}
                @if ((float) $invoice->settled_amount > 0)
                    · already settled {{ number_format((float) $invoice->settled_amount, 2) }}
                @endif
            </p>
        </div>

        <div class="rounded-m bg-tertiary-container p-3 text-xs text-on-tertiary-container">
            <p class="font-semibold">The customer settles at the Primary — never with you.</p>
            <p class="mt-1">
                Bank transfers go to the Primary's account, POS runs through the Primary's terminal and cash is
                handed over at the Primary office. You verify the settlement and capture the proof.
            </p>
        </div>

        @if ($errors->any())
            <div class="m-card border border-error/40 p-3 text-sm text-error">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('payments.record.store', $invoice) }}" enctype="multipart/form-data"
              class="space-y-3" x-data='paymentProof(@json($proofConfig))'>
            @csrf

            <section class="m-card p-4 space-y-2">
                <h2 class="text-base font-semibold">How was it settled?</h2>

                @foreach ($methods as $method)
                    <label class="flex cursor-pointer items-start gap-3 rounded-m border border-outline-variant p-3">
                        <input type="radio" name="payment_method" value="{{ $method->value }}" required
                               class="mt-0.5" @checked(old('payment_method') === $method->value)>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ $method->label() }}</span>
                            <span class="block text-[11px] text-on-surface-variant">{{ $method->evidenceHint() }}</span>
                        </span>
                    </label>
                @endforeach

                <p class="text-[11px] text-on-surface-variant">
                    A generic “cash” option is deliberately not offered: the money is never received by the sales employee.
                </p>
            </section>

            <section class="m-card p-4 space-y-3">
                <h2 class="text-base font-semibold">Amount &amp; reference</h2>

                <div>
                    <label for="amount" class="block text-xs font-medium text-on-surface-variant">Amount being paid *</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                           value="{{ old('amount', $outstanding) }}"
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface tabular-nums">
                    <p class="mt-1 text-[11px] text-on-surface-variant">
                        Anything above the outstanding amount stays unallocated on the payment for a finance administrator.
                    </p>
                </div>

                <div>
                    <label for="payment_reference" class="block text-xs font-medium text-on-surface-variant">Reference (transfer id, POS slip no.)</label>
                    <input id="payment_reference" name="payment_reference" type="text" maxlength="100"
                           value="{{ old('payment_reference') }}"
                           class="mt-1 w-full rounded-m border-outline-variant bg-surface">
                </div>
            </section>

            <section class="m-card p-4 space-y-3">
                <h2 class="text-base font-semibold">Proof of payment *</h2>

                <input type="file" name="proof" accept="image/*" capture="environment" required @change="onPick($event)"
                       class="w-full rounded-m border-outline-variant bg-surface text-sm">

                <p class="text-[11px] text-on-surface-variant" x-show="status" x-text="status"></p>
                <p class="text-[11px] text-error" x-show="error" x-text="error"></p>
                <p class="text-[11px] text-on-surface-variant" x-show="deviceNote" x-text="deviceNote"></p>

                <template x-if="previewUrl">
                    <img :src="previewUrl" alt="Proof of payment preview"
                         class="w-full rounded-m border border-outline-variant">
                </template>

                <div class="rounded-m bg-surface-container-high/60 p-3 text-[11px]">
                    <div class="flex justify-between gap-2">
                        <span class="text-on-surface-variant">Watermark</span>
                        <span class="font-medium">Simple ERP · sales employee · customer · invoice · timestamp</span>
                    </div>
                    <div class="mt-1 flex justify-between gap-2">
                        <span class="text-on-surface-variant">Embedded GPS</span>
                        <span class="font-medium tabular-nums" x-text="lat !== null && lng !== null ? lat.toFixed(5) + ', ' + lng.toFixed(5) + (accuracy ? ' ±' + accuracy + 'm' : '') : 'not available'"></span>
                    </div>
                    <p class="mt-1 text-on-surface-variant">
                        The image is resized and watermarked on the device, then validated again on the server
                        (type, real image, size, authorization).
                    </p>
                </div>

                {{-- Structured evidence: never inferred from watermark pixels. --}}
                <input type="hidden" name="gps_latitude" :value="lat">
                <input type="hidden" name="gps_longitude" :value="lng">
                <input type="hidden" name="gps_accuracy" :value="accuracy">
                <input type="hidden" name="captured_at" :value="capturedAt">
                <input type="hidden" name="watermark_text" :value="watermarkText">
            </section>

            <div class="flex gap-2">
                <a href="{{ route('finance.invoices.show', $invoice) }}"
                   class="inline-flex h-12 flex-1 items-center justify-center rounded-full bg-surface-variant text-sm font-medium text-on-surface-variant">
                    Cancel
                </a>
                <button type="submit" :disabled="processing"
                        class="inline-flex h-12 flex-[2] items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary disabled:opacity-50">
                    <span x-show="!processing">Confirm settlement</span>
                    <span x-show="processing">Preparing proof…</span>
                </button>
            </div>

            <p class="text-[11px] text-on-surface-variant">
                Confirming records the payment, marks it verified and applies it to this invoice through the
                existing finance service — the debt block clears when the invoice is fully settled.
            </p>
        </form>
    </div>
</x-app-layout>
