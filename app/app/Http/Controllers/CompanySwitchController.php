<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** SUPERADMIN company switcher (validated server-side; others are pinned). */
class CompanySwitchController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'exists:company_master,company_id'],
        ]);

        session(['company_context' => $validated['company']]);

        return back()->with('status', 'company-switched');
    }
}
