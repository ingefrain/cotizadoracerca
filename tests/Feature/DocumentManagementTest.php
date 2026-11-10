<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_documents_screen_uses_the_reusable_dropzone_for_upload_and_replacement(): void
    {
        $admin = $this->adminUser();

        $this->actingAsAdmin($admin)->get(route('admin.documents.index'))
            ->assertOk()
            ->assertSee('data-file-dropzone', false)
            ->assertSee('name="file"', false)
            ->assertSee('application/pdf,image/jpeg,image/png,text/plain,text/csv', false)
            ->assertSee('data-dropzone', false);
    }

    public function test_upload_stores_private_uuid_file_and_polymorphic_metadata_with_checksum_and_audit(): void
    {
        $admin = $this->adminUser();
        $response = $this->actingAsAdmin($admin)->post(route('admin.documents.store'), [
            'file' => $this->pdf('contract.pdf'),
            'category' => 'contract',
            'title' => 'Contrato firmado',
            'description' => 'Contrato inicial',
        ]);

        $response->assertRedirect(route('admin.documents.index'));
        $document = Document::query()->firstOrFail();
        $this->assertTrue(Str::isUuid($document->uuid));
        $this->assertSame(User::class, $document->documentable_type);
        $this->assertSame((string) $admin->id, $document->documentable_id);
        $this->assertSame($admin->id, $document->uploaded_by);
        $this->assertSame('contract.pdf', $document->original_name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('pdf', $document->extension);
        $this->assertSame(hash('sha256', '%PDF-1.4'."\nDocumento de prueba"), $document->checksum);
        $this->assertSame('documents/'.$document->stored_name, $document->path);
        $this->assertNotSame($document->original_name, $document->stored_name);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame('private', config('filesystems.disks.local.visibility'));
        $this->assertDatabaseHas('admin_audit_events', [
            'action' => 'document.uploaded',
            'entity_type' => 'document',
            'entity_id' => $document->uuid,
        ]);
        $this->assertArrayNotHasKey('path', AdminAuditEvent::query()->where('action', 'document.uploaded')->firstOrFail()->after);
    }

    public function test_upload_rejects_oversized_disallowed_mime_blocked_extension_empty_and_dangerous_name(): void
    {
        $admin = $this->adminUser();
        config(['documents.max_size_kb' => 1]);
        $this->actingAsAdmin($admin)->post(route('admin.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 2048)),
            'category' => 'evidence',
        ])->assertSessionHasErrors('file');

        config(['documents.max_size_kb' => 10240]);
        $this->post(route('admin.documents.store'), ['file' => UploadedFile::fake()->createWithContent('payload.jpg', 'This is plain text, not an image'), 'category' => 'evidence'])
            ->assertSessionHasErrors('file');
        $this->post(route('admin.documents.store'), ['file' => UploadedFile::fake()->createWithContent('script.php', 'not a script'), 'category' => 'evidence'])
            ->assertSessionHasErrors('file');
        $this->post(route('admin.documents.store'), ['file' => UploadedFile::fake()->createWithContent('empty.txt', ''), 'category' => 'evidence'])
            ->assertSessionHasErrors('file');
        $this->post(route('admin.documents.store'), ['file' => UploadedFile::fake()->createWithContent('..traversal.txt', 'texto seguro'), 'category' => 'evidence'])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
    }

    public function test_metadata_rejects_secret_fields_and_public_storage_disk(): void
    {
        $admin = $this->adminUser();
        $service = app(DocumentService::class);
        try {
            $service->upload($this->pdf('safe.pdf'), $admin, $admin, 'receipt', metadata: ['api_token' => 'never-store']);
            $this->fail('Secret metadata should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('metadata', $exception->errors());
        }

        config(['documents.disk' => 'public']);
        $this->actingAsAdmin($admin)->post(route('admin.documents.store'), ['file' => $this->pdf('safe.pdf'), 'category' => 'receipt'])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_controlled_metadata_is_json_and_keeps_uuid_polymorphic_owner_keys(): void
    {
        $admin = $this->adminUser();
        $document = app(DocumentService::class)->upload(
            $this->pdf('metadata.pdf'),
            $admin,
            $admin,
            'evidence',
            metadata: ['source' => 'import', 'reviewed' => true],
        );

        $this->assertSame(['source' => 'import', 'reviewed' => true], $document->fresh()->metadata);
        $this->assertContains(Schema::getColumnType('documents', 'documentable_id'), ['string', 'varchar']);
    }

    public function test_polymorphic_service_accepts_a_future_owner_with_uuid_primary_key(): void
    {
        Schema::create('document_test_uuid_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });
        $owner = DocumentTestUuidOwner::query()->create(['id' => (string) Str::uuid(), 'name' => 'Future owner']);
        $actor = $this->adminUser();

        $document = app(DocumentService::class)->upload($this->pdf('uuid-owner.pdf'), $owner, $actor, 'evidence');

        $this->assertSame($owner->getKey(), $document->documentable_id);
        $this->assertSame($owner->getKey(), $document->fresh()->documentable->getKey());
    }

    public function test_storage_failure_does_not_create_a_row(): void
    {
        $admin = $this->adminUser();
        Storage::shouldReceive('disk')->twice()->with('local')->andThrow(new RuntimeException('storage unavailable'));
        $this->actingAsAdmin($admin)->post(route('admin.documents.store'), ['file' => $this->pdf('failed.pdf'), 'category' => 'evidence'])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_storage_failure_does_not_remove_existing_replacement(): void
    {
        $admin = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->pdf('current.pdf'), $admin, $admin, 'evidence');
        $oldPath = $document->path;
        $disk = Storage::disk('local');
        Storage::shouldReceive('disk')->twice()->with('local')->andThrow(new RuntimeException('storage unavailable'));
        try {
            app(DocumentService::class)->update($document, ['title' => 'Not changed'], $admin, $this->pdf('cannot-store.pdf'));
            $this->fail('Expected a storage validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertSame($oldPath, $document->fresh()->path);
        $disk->assertExists($oldPath);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_download_requires_permission_owner_access_and_existing_file_and_uses_attachment(): void
    {
        $owner = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->pdf('safe name.pdf'), $owner, $owner, 'receipt');
        $route = route('admin.documents.download', $document);

        $this->actingAsAdmin($owner)->get($route)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $response = $this->get($route);
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));

        $other = $this->userWithPermissions(['documents.download']);
        $this->actingAs($other)->withSession(['auth_session_version' => $other->session_version])
            ->get($route)->assertForbidden();

        $viewerOnly = $this->userWithPermissions(['documents.view']);
        $this->actingAs($viewerOnly)->withSession(['auth_session_version' => $viewerOnly->session_version])
            ->get($route)->assertForbidden();

        $downloader = $this->userWithPermissions(['documents.download']);
        $ownDocument = app(DocumentService::class)->upload($this->pdf('own.pdf'), $downloader, $downloader, 'receipt');
        $this->actingAs($downloader)->withSession(['auth_session_version' => $downloader->session_version])
            ->get(route('admin.documents.download', $ownDocument))->assertOk();
        $this->put(route('admin.documents.update', $ownDocument), ['category' => 'receipt'])->assertForbidden();
        $this->delete(route('admin.documents.destroy', $ownDocument))->assertForbidden();

        Storage::disk('local')->delete($document->path);
        $this->actingAsAdmin($owner)->get($route)->assertNotFound();
    }

    public function test_listing_hides_paths_and_other_users_documents_and_filters_and_paginates(): void
    {
        $owner = $this->adminUser();
        $own = app(DocumentService::class)->upload($this->pdf('visible.pdf'), $owner, $owner, 'invoice', ['title' => 'Visible record']);
        $other = User::factory()->create();
        $foreign = app(DocumentService::class)->upload($this->pdf('hidden.pdf'), $other, $owner, 'contract', ['title' => 'Foreign record']);

        $this->actingAsAdmin($owner)->get(route('admin.documents.index'))
            ->assertOk()->assertSee('Visible record')->assertDontSee('Foreign record')
            ->assertDontSee($own->path)->assertDontSee($own->stored_name)->assertDontSee($own->checksum);
        $this->get(route('admin.documents.index', ['q' => 'Visible']))->assertSee('Visible record')->assertDontSee('Foreign record');
        $this->get(route('admin.documents.index', ['category' => 'contract']))->assertDontSee('Foreign record');

        for ($i = 0; $i < 21; $i++) {
            $this->storedDocumentRow($owner, 'record-'.$i.'.pdf', 'invoice');
        }
        $firstPage = $this->get(route('admin.documents.index'));
        $firstPage->assertOk()->assertSee('page=2');
        $this->assertSame(20, $firstPage->viewData('documents')->count());
        $this->assertSame(2, $firstPage->viewData('documents')->lastPage());
        $this->assertNotSame($own->documentable_id, $foreign->documentable_id);
    }

    public function test_permission_specific_settings_panel_and_routes_are_protected(): void
    {
        $viewer = $this->userWithPermissions(['documents.view']);
        $this->actingAs($viewer)->withSession(['auth_session_version' => $viewer->session_version])
            ->get(route('admin.settings.index'))->assertOk()->assertSee('Consulta y administra archivos almacenados en el sistema.');
        $this->post(route('admin.documents.store'), ['file' => $this->pdf('no.pdf'), 'category' => 'x'])->assertForbidden();
        $this->get(route('admin.documents.index'))->assertOk()->assertDontSee('Subir documento');

        $noView = $this->userWithPermissions(['dashboard.view']);
        $this->actingAs($noView)->withSession(['auth_session_version' => $noView->session_version])
            ->get(route('admin.documents.index'))->assertForbidden();
        $this->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_replacement_updates_metadata_and_removes_old_file_only_after_success(): void
    {
        $admin = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->pdf('before.pdf'), $admin, $admin, 'contract', ['title' => 'Before']);
        $oldPath = $document->path;

        $this->actingAsAdmin($admin)->put(route('admin.documents.update', $document), [
            'category' => 'contract',
            'title' => 'After',
            'description' => 'Updated',
            'file' => $this->pdf('after.pdf'),
        ])->assertRedirect(route('admin.documents.index'));

        $document->refresh();
        $this->assertSame('After', $document->title);
        $this->assertSame('after.pdf', $document->original_name);
        $this->assertNotSame($oldPath, $document->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.replaced', 'entity_id' => $document->uuid]);
    }

    public function test_database_failure_compensates_upload_and_replacement_without_losing_existing_file(): void
    {
        $admin = $this->adminUser();
        $this->mock(AdminAuditLogger::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('database audit failed'));
        });
        try {
            app(DocumentService::class)->upload($this->pdf('rolled-back.pdf'), $admin, $admin, 'evidence');
            $this->fail('Expected the transaction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database audit failed', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
        $this->assertSame(0, Document::query()->count());

        $this->forgetMock(AdminAuditLogger::class);
        $document = app(DocumentService::class)->upload($this->pdf('original.pdf'), $admin, $admin, 'evidence');
        $oldPath = $document->path;
        $this->mock(AdminAuditLogger::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('database audit failed'));
        });
        try {
            app(DocumentService::class)->update($document, ['title' => 'new'], $admin, $this->pdf('replacement.pdf'));
            $this->fail('Expected the replacement transaction to fail.');
        } catch (RuntimeException) {
            // The old database row and object remain usable after compensation.
        }
        $this->assertSame($oldPath, $document->fresh()->path);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('documents'));
    }

    public function test_soft_delete_keeps_file_and_prune_is_dry_run_until_forced(): void
    {
        $admin = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->pdf('retained.pdf'), $admin, $admin, 'receipt');
        $this->actingAsAdmin($admin)->delete(route('admin.documents.destroy', $document))->assertRedirect(route('admin.documents.index'));
        $this->assertNotNull($document->fresh()->deleted_at);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.deleted', 'entity_id' => $document->uuid]);

        DB::table('documents')->where('id', $document->id)->update(['deleted_at' => now()->subDays(31)]);
        config(['documents.retention_days' => 30]);
        $this->artisan('documents:prune', ['--dry-run' => true])->expectsOutputToContain('Modo de revisión')->assertExitCode(0);
        $this->assertTrue(Document::withTrashed()->whereKey($document->id)->exists());
        Storage::disk('local')->assertExists($document->path);

        $this->assertSame(1, Document::onlyTrashed()->where('deleted_at', '<=', now()->subDays(30))->count());
        $this->artisan('documents:prune', ['--purge' => true])->assertExitCode(0);
        $this->assertFalse(Document::withTrashed()->whereKey($document->id)->exists());
        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.purged', 'entity_id' => $document->uuid]);
    }

    public function test_old_orphans_are_reported_then_purged_only_with_explicit_option(): void
    {
        $orphanPath = 'documents/'.Str::uuid().'.txt';
        Storage::disk('local')->put($orphanPath, 'orphan content');
        touch(Storage::disk('local')->path($orphanPath), now()->subDays(31)->getTimestamp());
        config(['documents.orphan_retention_days' => 30]);

        $this->artisan('documents:prune', ['--dry-run' => true, '--orphans' => true, '--limit' => 10])
            ->expectsOutputToContain('Archivos huérfanos vencidos dentro del límite: 1')
            ->assertExitCode(0);
        Storage::disk('local')->assertExists($orphanPath);

        $this->artisan('documents:prune', ['--purge-orphans' => true, '--limit' => 10])->assertExitCode(0);
        Storage::disk('local')->assertMissing($orphanPath);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'document.orphan_file_purged']);
    }

    public function test_storage_audit_is_read_only_bounded_and_reports_orphans_missing_files_and_bad_metadata(): void
    {
        $admin = $this->adminUser();
        $document = app(DocumentService::class)->upload($this->pdf('missing.pdf'), $admin, $admin, 'evidence');
        Storage::disk('local')->delete($document->path);
        Storage::disk('local')->put('documents/orphan.txt', 'orphan');
        $tampered = app(DocumentService::class)->upload($this->pdf('tampered.pdf'), $admin, $admin, 'evidence');
        Storage::disk('local')->put($tampered->path, str_repeat('x', $tampered->size_bytes));
        $legacy = new Document;
        $legacy->documentable()->associate($admin);
        $legacy->fill([
            'uuid' => (string) Str::uuid(),
            'category' => 'legacy',
            'original_name' => 'legacy.pdf',
            'stored_name' => 'bad-path.pdf',
            'disk' => 'local',
            'path' => '../escape.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1,
            'checksum' => str_repeat('a', 64),
        ]);
        $legacy->save();

        $this->artisan('documents:audit-storage', ['--limit' => 10])
            ->expectsOutputToContain('archivos faltantes')
            ->expectsOutputToContain('archivos sin fila')
            ->expectsOutputToContain('metadata inconsistente')
            ->assertExitCode(0);
        Storage::disk('local')->assertExists('documents/orphan.txt');
        $this->assertDatabaseHas('documents', ['path' => '../escape.pdf']);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocumento de prueba");
    }

    private function storedDocumentRow(User $owner, string $name, string $category): Document
    {
        $uuid = (string) Str::uuid();

        $document = new Document;
        $document->documentable()->associate($owner);
        $document->fill([
            'uuid' => $uuid,
            'category' => $category,
            'original_name' => $name,
            'stored_name' => $uuid.'.pdf',
            'disk' => 'local',
            'path' => 'documents/'.$uuid.'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', $name),
            'uploaded_by' => $owner->id,
        ]);
        $document->save();

        return $document;
    }
}

class DocumentTestUuidOwner extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'document_test_uuid_owners';

    protected $fillable = ['id', 'name'];
}
