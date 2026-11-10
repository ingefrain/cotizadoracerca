<?php

namespace App\Policies;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\User;

class CatalogItemPolicy
{
    public function __construct(private readonly CatalogPolicy $catalogs) {}

    public function viewAny(User $user, Catalog $catalog): bool
    {
        return $this->catalogs->view($user, $catalog) && CatalogPolicy::permits($user, 'catalog_items.view');
    }

    public function view(User $user, CatalogItem $item): bool
    {
        return $this->viewAny($user, $item->catalog);
    }

    public function create(User $user, Catalog $catalog): bool
    {
        return $this->viewAny($user, $catalog) && CatalogPolicy::permits($user, 'catalog_items.create');
    }

    public function update(User $user, CatalogItem $item): bool
    {
        return $this->view($user, $item) && CatalogPolicy::permits($user, 'catalog_items.update');
    }

    public function disable(User $user, CatalogItem $item): bool
    {
        return $this->view($user, $item) && CatalogPolicy::permits($user, 'catalog_items.disable');
    }
}
