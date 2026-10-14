<?php

namespace Pterodactyl\Extensions\AntiDDoS\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Prologue\Alerts\Facades\Alert;
use Pterodactyl\Extensions\AntiDDoS\Support\Sidebar;

class SidebarController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $show = $request->boolean('show');

        Sidebar::set($show);

        Alert::success($show
            ? 'Anti-DDoS y Optimización queda fijado en el menú lateral.'
            : 'Anti-DDoS y Optimización ya no aparece en el menú lateral: se abre desde Vexa Hub.')->flash();

        return redirect()->back();
    }
}
