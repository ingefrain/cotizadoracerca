<?php

namespace Tests\Feature;

use App\Models\ApplicationBranding;
use App\Models\User;
use App\Services\BrandingFavicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class BrandingFaviconTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
    }

    public function test_logo_replacement_and_deletion_refresh_the_favicon_and_maintenance_snapshot(): void
    {
        $service = app(BrandingFavicon::class);
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('wide.png', 200, 100),
        ])->assertRedirect();
        $logo = ApplicationBranding::current()->logo;
        $original = Storage::disk('local')->get($logo->path);
        $wide = $service->cached();
        $this->assertStringStartsWith('data:image/png;base64,', $wide);
        $this->assertSame($wide, $service->forLogo($logo));
        $image = $this->png($wide);
        $this->assertSame(64, imagesx($image));
        $this->assertSame(64, imagesy($image));
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 32, 15))['alpha']);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 32, 16))['alpha']);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 32, 47))['alpha']);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 32, 48))['alpha']);
        imagedestroy($image);
        $this->assertSame($original, Storage::disk('local')->get($logo->path));
        $this->assertFileDoesNotExist(public_path($logo->path));

        $this->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('tall.jpg', 100, 200),
        ])->assertRedirect();
        $tall = $service->cached();
        $this->assertNotSame($wide, $tall);
        $image = $this->png($tall);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 15, 32))['alpha']);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 16, 32))['alpha']);
        imagedestroy($image);
        $this->assertSame($tall, $service->forLogo(ApplicationBranding::current()->logo));
        $this->delete(route('admin.branding.logo.delete'))->assertRedirect();
        $this->assertSame($service->fallback(), $service->cached());
        $this->assertSame($service->fallback(), $service->forLogo(ApplicationBranding::current()->logo));
    }

    public function test_guest_and_admin_layouts_embed_only_the_small_representation_with_a_fallback(): void
    {
        $service = app(BrandingFavicon::class);
        $this->get(route('admin.login'))->assertOk()->assertSee('rel="icon"', false)->assertSee($service->fallback(), false);
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('brand.webp', 300, 300),
        ])->assertRedirect();
        $favicon = $service->cached();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('sizes="64x64"', false)->assertSee($favicon, false);
        auth()->logout();
        $this->get(route('admin.login'))->assertOk()->assertSee($favicon, false);
    }

    public function test_maintenance_embeds_the_snapshot_without_any_database_queries_and_survives_missing_cache(): void
    {
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('brand.png', 100, 100),
        ])->assertRedirect();
        $service = app(BrandingFavicon::class);
        $favicon = $service->cached();
        DB::connection()->beforeExecuting(function (): void {
            throw new \RuntimeException('Maintenance must not query the database.');
        });
        $html = view('errors.503')->render();
        $this->assertStringContainsString($favicon, $html);
        $this->assertStringContainsString('rel="icon"', $html);
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $maintenance = app()->maintenanceMode();
        $maintenance->activate(['template' => $html, 'status' => 503, 'retry' => 60]);
        try {
            $this->get(route('admin.login'))->assertStatus(503)->assertHeader('Retry-After', '60')->assertSee($favicon, false);
        } finally {
            $maintenance->deactivate();
        }
        $service->forLogo(null);
        $this->assertStringContainsString($service->fallback(), view('errors.503')->render());
    }

    public function test_generic_document_changes_and_soft_delete_also_invalidate_the_snapshot(): void
    {
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('brand.png'),
        ])->assertRedirect();
        $logo = ApplicationBranding::current()->logo;
        $service = app(BrandingFavicon::class);
        $favicon = $service->cached();
        $logo->update(['category' => 'misc']);
        $this->assertSame($service->fallback(), $service->cached());
        $logo->update(['category' => 'branding.logo']);
        $this->assertSame($favicon, $service->cached());
        $logo->delete();
        $this->assertSame($service->fallback(), $service->cached());
        $logo->restore();
        $this->assertSame($favicon, $service->cached());
    }

    public function test_a_rolled_back_logo_change_does_not_replace_the_snapshot(): void
    {
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('brand.png'),
        ])->assertRedirect();
        $service = app(BrandingFavicon::class);
        $favicon = $service->cached();
        DB::beginTransaction();
        try {
            ApplicationBranding::current()->logo->delete();
            $this->assertSame($favicon, $service->cached());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($favicon, $service->cached());
        $this->assertNotNull(ApplicationBranding::current()->logo);
    }

    public function test_unrelated_private_documents_unsafe_paths_and_invalid_images_are_not_exposed(): void
    {
        $this->actingAsAdmin()->put(route('admin.branding.update'), [
            'logo' => UploadedFile::fake()->image('brand.png'),
        ])->assertRedirect();
        $logo = ApplicationBranding::current()->logo;
        $service = app(BrandingFavicon::class);
        foreach ([
            ['category' => 'user.avatar'],
            ['documentable_type' => (new User)->getMorphClass()],
            ['documentable_id' => 2],
            ['path' => '../.env'],
            ['disk' => 'public'],
            ['mime_type' => 'image/svg+xml'],
            ['checksum' => str_repeat('0', 64)],
        ] as $attributes) {
            $invalid = clone $logo;
            $invalid->forceFill($attributes);
            $this->assertSame($service->fallback(), $service->forLogo($invalid));
        }
        $this->assertStringStartsWith('data:image/png;base64,', $service->forLogo($logo));
        Storage::disk('local')->delete($logo->path);
        $this->assertSame($service->fallback(), $service->forLogo($logo));
        $this->assertSame($service->fallback(), $service->cached());
        $contents = '<svg onload="alert(1)"/>';
        Storage::disk('local')->put($logo->path, $contents);
        $logo->checksum = hash('sha256', $contents);
        $this->assertSame($service->fallback(), $service->forLogo($logo));
    }

    private function png(string $uri): \GdImage
    {
        $png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($png)[2]);

        return imagecreatefromstring($png);
    }
}
