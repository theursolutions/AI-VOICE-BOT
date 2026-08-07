<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-admin module switchboard. One page to switch any admin module
 * ON/OFF for the whole platform. A switched-off module disappears from the
 * customer sidebar and the Roles & Permissions matrix, and a direct hit
 * shows the "under development" page.
 *
 * State is a single JSON array in `site_settings` (key `modules.disabled`),
 * read everywhere through App\Support\Modules.
 */
class ModulesController extends Controller
{
    public function index(Request $request): View
    {
        $title    = 'Modules';
        $modules  = Modules::all();             // key => [label, routes]
        $disabled = Modules::disabledKeys();    // currently OFF
        $alwaysOn = Modules::ALWAYS_ON;

        return view('ops.modules.index', compact('title', 'modules', 'disabled', 'alwaysOn'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled'   => ['array'],
            'enabled.*' => ['string'],
        ]);

        // Checkbox is present (checked) → module stays ON. Anything
        // toggleable that wasn't submitted is switched OFF. Always-on
        // modules can never be disabled (Modules::setDisabled sanitises).
        $enabled  = (array) $request->input('enabled', []);
        $disabled = array_values(array_diff(Modules::toggleableKeys(), $enabled));

        Modules::setDisabled($disabled);

        AuditLog::record('modules.update', [
            'payload' => [
                'disabled' => $disabled,
                'enabled'  => Modules::enabledKeys(),
            ],
        ]);

        $msg = empty($disabled)
            ? 'All modules are switched on.'
            : count($disabled) . ' module(s) switched off: ' .
              implode(', ', array_map(fn ($k) => Modules::label($k), $disabled)) . '.';

        return back()->with('success', $msg);
    }
}
