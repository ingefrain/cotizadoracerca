<?php

namespace App\Policies;

use App\Models\Catalog;
use App\Models\User;
use App\Support\ImpersonationManager;

class CatalogPolicy
{
    public static function permits(User $user, string $permission): bool
    {
        if (! config('catalogs.enabled')) {
            return false;
        }

        $session = app(ImpersonationManager::class);
        $attributes = request()->attributes;
        $key = self::class.':'.$user->getKey().':'.(int) $session->active();
        if (! $attributes->has($key)) {
            $permissions = $user->permissionKeys();
            if ($session->active()) {
                $permissions = array_intersect($permissions, $session->actor()?->permissionKeys() ?? []);
            }
            $attributes->set($key, $permissions);
        }

        return in_array($permission, $attributes->get($key), true);
    }

    public function viewAny(User $user): bool
    {
        return self::permits($user, 'catalogs.view');
    }

    public function view(User $user, Catalog $catalog): bool
    {
        return $this->viewAny($user)
            && ($catalog->organization_id === null || self::permits($user, 'organizations.view'));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && self::permits($user, 'catalogs.create');
    }

    public function update(User $user, Catalog $catalog): bool
    {
        return $this->view($user, $catalog) && self::permits($user, 'catalogs.update');
    }

    public function disable(User $user, Catalog $catalog): bool
    {
        return $this->view($user, $catalog) && self::permits($user, 'catalogs.disable');
    }
}
