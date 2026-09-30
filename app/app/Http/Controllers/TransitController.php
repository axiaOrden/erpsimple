<?php

namespace App\Http\Controllers;

use App\Enums\LiabilityParty;
use App\Models\AppUser;
use App\Models\TransitStock;
use App\Services\TransitService;
use Illuminate\Http\Request;

/**
 * Phase 9 — transit stock screens. Custody-aware, company-scoped:
 * the holding sales employee sees and operates THEIR rows; company admins
 * manage the company's transit (verify source receipts, resolve
 * discrepancies, LOSS / WRITTEN_OFF); superadmin crosses companies.
 */
class TransitController extends Controller
{
    public function __construct(
        private readonly TransitService $transit,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $rows = TransitStock::query()
            ->with(['product', 'holder'])
            ->when(! $user->isSuperadmin(), fn ($q) => $q->where('company_id', $this->companyOf($user)))
            ->when($user->isSalesEmployee() && ! $user->isCompanyAdmin(), fn ($q) => $q->where('holding_employee_id', $user->employee_id))
            ->orderByDesc('transit_id')
            ->paginate(20)
            ->withQueryString();

        return view('transit.index', ['rows' => $rows]);
    }

    public function show(Request $request, TransitStock $transit)
    {
        $this->assertCanView($request->user(), $transit);

        return view('transit.show', [
            'transit' => $transit->load(['product', 'holder']),
            'canAct' => $this->canOperate($request->user(), $transit),
            'isAdmin' => $this->isAdminFor($request->user(), $transit),
        ]);
    }

    public function initiateReturn(Request $request, TransitStock $transit)
    {
        $user = $request->user();

        // Custody rule (ruling 2): ONLY the physically holding employee may
        // initiate a return — admins and superadmin do not bypass custody.
        if ($transit->holding_employee_id !== $user->employee_id) {
            abort(403, 'Only the employee physically holding this transit stock can initiate its return.');
        }

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:255']]);

        $updated = $this->transit->initiateReturn($user->employee, $transit, $validated['remarks'] ?? null);

        return redirect()
            ->route('transit.show', $updated)
            ->with('status', 'Return claim recorded — a company admin must verify the source receipt.');
    }

    public function verifyReceipt(Request $request, TransitStock $transit)
    {
        $this->assertAdminFor($request->user(), $transit);

        $updated = $this->transit->verifySourceReceipt($request->user(), $transit);

        return redirect()
            ->route('transit.show', $updated)
            ->with('status', 'Source receipt verified — VAN_RETURN written, source availability restored.');
    }

    public function resolveFound(Request $request, TransitStock $transit)
    {
        $this->assertCanOperateResolved($request->user(), $transit);

        $updated = $this->transit->resolveFound($request->user(), $transit);

        return redirect()->route('transit.show', $updated)
            ->with('status', 'Discrepancy resolved: stock found intact — REUSABLE.');
    }

    public function resolveDamaged(Request $request, TransitStock $transit)
    {
        $this->assertCanOperateResolved($request->user(), $transit);

        $validated = $request->validate(['liability_party' => ['required', 'in:EMPLOYEE,DISTRIBUTOR']]);

        $updated = $this->transit->resolveDamaged(
            $request->user(),
            $transit,
            LiabilityParty::from($validated['liability_party']),
        );

        return redirect()->route('transit.show', $updated)
            ->with('status', 'Discrepancy resolved: stock confirmed damaged ('.$validated['liability_party'].' liability).');
    }

    public function resolveLost(Request $request, TransitStock $transit)
    {
        $this->assertAdminFor($request->user(), $transit);

        $updated = $this->transit->resolveLost($request->user(), $transit);

        return redirect()->route('transit.show', $updated)
            ->with('status', 'Discrepancy resolved: stock confirmed lost (LOSS — liability accounting deferred).');
    }

    public function writeOff(Request $request, TransitStock $transit)
    {
        $this->assertAdminFor($request->user(), $transit);

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:255']]);

        $updated = $this->transit->writeOff($request->user(), $transit, $validated['remarks'] ?? null);

        return redirect()->route('transit.show', $updated)
            ->with('status', 'Damaged transit stock written off.');
    }

    // ---- Authorization helpers -------------------------------------------

    private function companyOf(AppUser $user): string
    {
        $companyId = $user->currentCompanyId();

        abort_if($companyId === null, 403, 'No company scope.');

        return $companyId;
    }

    private function assertCanView(AppUser $user, TransitStock $transit): void
    {
        if ($user->isSuperadmin()) {
            return;
        }

        if ($transit->company_id !== $this->companyOf($user)) {
            abort(403, 'This transit record belongs to another company.');
        }

        if ($user->isSalesEmployee() && ! $user->isCompanyAdmin()
            && $transit->holding_employee_id !== $user->employee_id) {
            abort(403, 'Sales employees only see their own transit stock.');
        }
    }

    private function isAdminFor(AppUser $user, TransitStock $transit): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $transit->company_id === $this->companyOf($user);
    }

    private function canOperate(AppUser $user, TransitStock $transit): bool
    {
        return $user->isSalesEmployee() && $transit->holding_employee_id === $user->employee_id;
    }

    /** Operational discrepancy reporting: custody OR company admin. */
    private function assertCanOperateResolved(AppUser $user, TransitStock $transit): void
    {
        if (! $this->canOperate($user, $transit) && ! $this->isAdminFor($user, $transit)) {
            abort(403, 'Only the holding employee or a company admin can report this resolution.');
        }
    }

    private function assertAdminFor(AppUser $user, TransitStock $transit): void
    {
        if (! $this->isAdminFor($user, $transit)) {
            abort(403, 'Only a company admin (or superadmin) can perform this action.');
        }
    }
}
