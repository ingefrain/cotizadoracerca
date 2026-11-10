<?php

namespace Tests\Feature;

use App\Models\ApplicationBranding;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentOwnerAccess;
use App\Services\Documents\DocumentService;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class AvatarBrandingTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_profile_avatar_can_be_uploaded_replaced_served_and_deleted_with_fallbacks(): void
    {
        $user = $this->adminUser(['name' => 'Efraín González']);
        $this->actingAsAdmin($user)->get(route('admin.profile.edit'))->assertOk()->assertSee('EG');
        $this->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->image('avatar.png')])->assertRedirect();
        $avatar = Document::query()->where('category', 'user.avatar')->firstOrFail();
        $this->assertSame($user->id, $avatar->documentable->id);
        $this->assertNotNull($user->fresh()->avatar);
        $this->assertTrue(app(ManagedDocumentPath::class)->matches($avatar));
        Storage::disk('local')->assertExists($avatar->path);
        $this->get(route('admin.profile.avatar'))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $oldPath = $avatar->path;
        $this->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->image('replacement.jpg')])->assertRedirect();
        $avatar->refresh();
        $this->assertSame('image/webp', $avatar->mime_type);
        $this->assertNotSame($oldPath, $avatar->path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame(1, Document::query()->where('category', 'user.avatar')->count());
        $this->delete(route('admin.profile.avatar.delete'))->assertRedirect();
        $this->assertSame(0, Document::query()->where('category', 'user.avatar')->count());
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee('EG');
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.uploaded', 'entity_type' => 'document']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.replaced', 'entity_type' => 'document']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.deleted', 'entity_type' => 'document']);
    }

    public function test_avatar_rejects_non_images_svg_oversize_and_denies_other_users(): void
    {
        $owner = $this->adminUser();
        $this->actingAsAdmin($owner)->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->createWithContent('picture.jpg', 'plain text')])->assertSessionHasErrors('file');
        $this->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->createWithContent('active.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertSessionHasErrors('file');
        $rules = config('documents.category_rules');
        config(['documents.category_rules' => array_replace($rules, ['user.avatar' => array_replace($rules['user.avatar'], ['max_size_kb' => 1])])]);
        $this->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->createWithContent('large.png', str_repeat('x', 2048))])->assertSessionHasErrors('file');
        config(['documents.category_rules' => $rules]);
        $other = User::factory()->create();
        $this->post(route('admin.profile.avatar.update'), ['file' => UploadedFile::fake()->image('owner.png')]);
        $this->get(route('admin.users.avatar', $owner))->assertOk();
        $viewer = $this->userWithPermissions(['users.view']);
        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.users.avatar', $owner))->assertOk();
        $this->get(route('admin.users.avatar', $other))->assertNotFound();
        $private = app(DocumentService::class)->upload(
            UploadedFile::fake()->image('other.png'), $other, $owner, 'user.avatar',
        );
        $this->get('/admin/documents/'.$private->uuid.'/download')->assertForbidden();
    }

    public function test_branding_permission_logo_lifecycle_public_delivery_and_category_labels(): void
    {
        $this->seedAccessCatalog();
        $operator = $this->userWithRole('operator');
        $this->assertFalse($operator->can('branding.manage'));
        $this->actingAs($operator)->withSession(['auth_session_version' => $operator->session_version])
            ->get(route('admin.branding.edit'))->assertForbidden();

        $admin = $this->adminUser();
        $this->actingAsAdmin($admin)->get(route('admin.branding.edit'))->assertOk()->assertSee(config('app.name'));
        $this->put(route('admin.branding.update'), [
            'visible_name' => 'Sistema de Prueba',
            'logo' => UploadedFile::fake()->image('brand.png'),
        ])->assertRedirect();
        $branding = ApplicationBranding::query()->with('logo')->findOrFail(1);
        $logo = $branding->logo;
        $this->assertSame('branding.logo', $logo->category);
        $this->get(route('branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('ETag')->assertHeader('Cache-Control', 'max-age=3600, public, stale-while-revalidate=60');
        $this->get(route('branding.logo'), ['If-None-Match' => '"'.substr($logo->checksum, 0, 64).'"'])->assertStatus(304);
        auth()->logout();
        $this->get(route('admin.login'))->assertOk()->assertSee('Sistema de Prueba');
        $this->actingAsAdmin($admin)->get(route('admin.settings.index'))->assertSee('Identidad visual');
        $documents = app(DocumentOwnerAccess::class)->scopeVisibleTo(Document::query(), $admin)->get();
        $this->assertTrue($documents->contains('category', 'branding.logo'));
        $oldPath = $logo->path;
        $this->put(route('admin.branding.update'), ['visible_name' => '', 'logo' => UploadedFile::fake()->image('new-logo.jpg')])->assertRedirect();
        $logo->refresh();
        $this->assertSame('image/webp', $logo->mime_type);
        Storage::disk('local')->assertMissing($oldPath);
        $this->delete(route('admin.branding.logo.delete'))->assertRedirect();
        $this->get(route('branding.logo'))->assertNotFound();
        $this->assertSame(config('app.name'), ApplicationBranding::current()->displayName());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'branding.name_updated']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.uploaded', 'entity_type' => 'document']);
    }

    public function test_user_list_renders_fallback_and_avatar_markup(): void
    {
        $admin = $this->adminUser(['name' => 'Efraín González']);
        $this->actingAsAdmin($admin)->get(route('admin.users.index'))->assertOk()->assertSee('EG');
    }
}
