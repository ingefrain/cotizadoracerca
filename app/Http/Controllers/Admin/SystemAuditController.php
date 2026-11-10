<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SystemAuditController extends Controller
{
    public function __invoke(): View
    {
        $cards = collect(config('admin_system_audit.cards', []))
            ->filter(fn (array $card): bool => Gate::allows($card['permission']))
            ->values();

        abort_if($cards->isEmpty(), 403);

        return view('admin.system-audit.index', compact('cards'));
    }
}
