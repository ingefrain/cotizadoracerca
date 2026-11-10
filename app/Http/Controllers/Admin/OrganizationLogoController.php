<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationLogoController extends Controller
{
    public function __invoke(Request $request, Organization $organization, ManagedDocumentPath $paths): StreamedResponse
    {
        abort_unless($request->user()->can('view', $organization), 403);
        $logo = $organization->logo;
        abort_unless($logo && $logo->category === 'organization.logo', 404);

        return AvatarController::response($logo, $paths, false, $request, 'organization.logo');
    }
}
