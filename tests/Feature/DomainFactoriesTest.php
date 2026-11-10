<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_factories_create_valid_records_and_required_parents(): void
    {
        $location = Location::factory()->create();
        $item = CatalogItem::factory()->create();

        $this->assertTrue($location->isOperationallyActive());
        $this->assertTrue($item->isOperationallyActive());
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('catalogs', 1);
        $this->assertFalse(User::factory()->inactive()->create()->is_active);
    }
}
