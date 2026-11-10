<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\View\View;

class PermissionController extends Controller
{
    public function index(): View
    {
        $permissions = Permission::query()->withCount('roles')->orderBy('key')->get()
            ->groupBy(fn (Permission $permission) => str($permission->key)->before('.')->toString());

        return view('admin.permissions.index', compact('permissions'));
    }
}
