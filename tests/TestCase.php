<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        Support\TestDatabaseSafety::install($app);
        // Layouts also maintain derived branding files; tests must never touch local originals or caches.
        Storage::fake('local');

        return $app;
    }
}
