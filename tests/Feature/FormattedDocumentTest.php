<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Document;
use App\Models\FormattedDocument;
use App\Models\Permission;
use App\Services\Pdf\CorporatePdfRenderer;
use App\Support\AdminAuditLogger;
use Database\Seeders\FormattedDocumentPermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class FormattedDocumentTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Documento ficticio', 'body_markdown' => "\n    Código  \r\n\r\n:::page-break\r\nEspañol: áéíóú ñ ü. $12.50  \r\n", 'notes' => "Nota interna\n"];
    }

    public function test_create_and_reopen_preserve_exact_source_and_do_not_generate_a_pdf(): void
    {
        $actor = $this->adminUser();
        $this->actingAsAdmin($actor);
        $this->mock(CorporatePdfRenderer::class)->shouldNotReceive('render');
        $response = $this->postJson(route('admin.pdf.store'), $this->payload(['created_by' => 999, 'uuid' => 'forged']));
        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('can_update', true);
        $document = FormattedDocument::sole();
        $this->assertSame($this->payload()['body_markdown'], $document->body_markdown);
        $this->assertSame($this->payload()['notes'], $document->notes);
        $this->assertSame($actor->id, $document->created_by);
        $this->assertNull($document->updated_by);
        $this->assertNotSame('forged', $document->uuid);
        $this->assertDatabaseCount('documents', 0);
        $this->get($response->json('edit_url'))->assertOk()->assertViewHas('document', fn ($value) => $value->body_markdown === $document->body_markdown)
            ->assertSee('Guardar borrador')->assertSee('data-draft-initial', false);
    }

    public function test_update_preserves_author_and_audits_only_field_names_and_real_actor(): void
    {
        $admin = $this->adminUser();
        $document = FormattedDocument::factory()->create();
        $author = $document->created_by;
        $this->actingAsAdmin($admin)->putJson(route('admin.pdf.update', $document), $this->payload(['created_by' => $admin->id]))->assertOk();
        $document->refresh();
        $this->assertSame($author, $document->created_by);
        $this->assertSame($admin->id, $document->updated_by);
        $this->assertSame($this->payload()['body_markdown'], $document->body_markdown);
        $event = AdminAuditEvent::where('action', 'formatted_document.updated')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($document->uuid, $event->entity_id);
        $this->assertEqualsCanonicalizing(['title', 'body_markdown', 'notes'], $event->context['changed_fields']);
        $this->assertNull($event->before);
        $this->assertNull($event->after);
        $this->assertStringNotContainsString('Nota interna', $event->toJson());
        $this->assertStringNotContainsString('Documento ficticio', $event->toJson());
    }

    public function test_validation_limits_and_failures_leave_saved_content_unchanged(): void
    {
        $this->actingAsAdmin();
        $document = FormattedDocument::factory()->create();
        foreach ([['title' => '   '], ['title' => str_repeat('a', 201)], ['title' => "Título\n"],
            ['body_markdown' => "\r\n\t"], ['body_markdown' => ['bad']], ['body_markdown' => "a\0b"],
            ['body_markdown' => str_repeat('é', 51201)], ['notes' => str_repeat('ñ', 5001)]] as $invalid) {
            $this->putJson(route('admin.pdf.update', $document), $this->payload($invalid))
                ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid));
        }
        $this->assertSame('# Documento'."\n\nContenido ficticio.\n", $document->fresh()->body_markdown);
        $this->assertSame(0, AdminAuditEvent::where('entity_type', 'formatted_document')->count());
        $this->assertNull(session()->getOldInput('body_markdown'));
        $this->postJson(route('admin.pdf.store'), $this->payload(['body_markdown' => str_repeat('é', 51200), 'notes' => null]))->assertCreated();
        $this->assertSame(102400, strlen(FormattedDocument::latest('id')->first()->body_markdown));
    }

    public function test_json_is_required_and_malformed_input_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.pdf.store'), $this->payload())->assertStatus(415);
        $this->call('POST', route('admin.pdf.store'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad')->assertStatus(422);
        $this->assertDatabaseCount('formatted_documents', 0);
    }

    public function test_uuid_does_not_grant_read_or_write_access(): void
    {
        $document = FormattedDocument::factory()->create();
        $this->get(route('admin.pdf.show', $document))->assertRedirect(route('admin.login'));
        $this->seedAccessCatalog();
        $this->actingAs($this->userWithPermissions(['pdf.preview', 'documents.view']));
        foreach (['index', 'create', 'show', 'edit'] as $route) {
            $this->get(route('admin.pdf.'.$route, in_array($route, ['show', 'edit']) ? $document : []))->assertForbidden();
        }
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertForbidden();
        $this->putJson(route('admin.pdf.update', $document), $this->payload())->assertForbidden();
    }

    public function test_separate_permissions_allow_readers_but_not_edits_or_creation(): void
    {
        $this->seedAccessCatalog();
        $document = FormattedDocument::factory()->create();
        $this->actingAs($this->userWithPermissions(['pdf-documents.view']));
        $this->get(route('admin.pdf.index'))->assertOk()->assertSee($document->title)->assertDontSee('Nuevo documento')->assertDontSee('Abrir/editar');
        $this->get(route('admin.pdf.show', $document))->assertOk()->assertSee('Markdown guardado');
        $this->get(route('admin.pdf.edit', $document))->assertForbidden();
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertForbidden();
        $this->putJson(route('admin.pdf.update', $document), $this->payload())->assertForbidden();
        $this->actingAs($this->userWithPermissions(['pdf-documents.update']));
        $this->putJson(route('admin.pdf.update', $document), $this->payload())->assertForbidden();
    }

    public function test_create_without_update_or_preview_permission_does_not_grant_either(): void
    {
        $this->seedAccessCatalog();
        $this->actingAs($this->userWithPermissions(['pdf-documents.view', 'pdf-documents.create']));
        $this->get(route('admin.pdf.create'))->assertOk()->assertSee('data-preview-allowed="false"', false);
        $response = $this->postJson(route('admin.pdf.store'), $this->payload())->assertCreated()->assertJsonPath('can_update', false);
        $this->get($response->json('edit_url'))->assertOk()->assertSee('Información del documento');
        $this->putJson($response->json('update_url'), $this->payload())->assertForbidden();
    }

    public function test_seeder_only_adds_administrator_grants_and_is_idempotent(): void
    {
        $admin = $this->adminUser();
        $operator = $this->userWithRole('operator');
        $before = Permission::count();
        $this->seed(FormattedDocumentPermissionSeeder::class);
        $this->seed(FormattedDocumentPermissionSeeder::class);
        foreach (['view', 'create', 'update'] as $operation) {
            $this->assertTrue($admin->hasPermissionTo('pdf-documents.'.$operation));
            $this->assertFalse($operator->hasPermissionTo('pdf-documents.'.$operation));
        }
        $this->assertSame($before, Permission::count());
    }

    public function test_impersonation_requires_both_users_and_attributes_changes_to_real_actor(): void
    {
        $actor = $this->adminUser();
        $target = $this->userWithPermissions(['pdf-documents.view', 'pdf-documents.create', 'pdf-documents.update']);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertCreated();
        $document = FormattedDocument::sole();
        $this->assertSame($actor->id, $document->created_by);
        $event = AdminAuditEvent::where('action', 'formatted_document.created')->sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($target->id, $event->context['impersonation']['target_id']);
        $this->putJson(route('admin.pdf.update', $document), $this->payload(['title' => 'Otra edición']))->assertOk();
        $this->assertSame($actor->id, $document->fresh()->updated_by);
        $actor->roles()->first()->permissions()->detach(Permission::where('key', 'pdf-documents.update')->first());
        $this->putJson(route('admin.pdf.update', $document), $this->payload())->assertForbidden();
        $target->roles()->first()->permissions()->detach(Permission::where('key', 'pdf-documents.view')->first());
        $this->get(route('admin.pdf.show', $document))->assertForbidden();
    }

    public function test_impersonated_user_cannot_borrow_administrators_document_permissions(): void
    {
        $actor = $this->adminUser();
        $target = $this->userWithPermissions([]);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->get(route('admin.pdf.index'))->assertForbidden();
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertForbidden();
    }

    public function test_list_is_paginated_and_ordered_by_last_change(): void
    {
        $actor = $this->adminUser();
        FormattedDocument::factory()->count(16)->create(['created_by' => $actor->id, 'updated_at' => now()->subDay()]);
        $latest = FormattedDocument::factory()->create(['created_by' => $actor->id, 'title' => 'Último cambio']);
        $this->actingAsAdmin($actor)->get(route('admin.pdf.index'))->assertOk()->assertViewHas('documents', fn ($items) => $items->total() === 17 && $items->count() === 15 && $items->first()->is($latest));
        $this->get(route('admin.pdf.index', ['page' => 2]))->assertOk()->assertViewHas('documents', fn ($items) => $items->count() === 2);
    }

    public function test_preview_uses_unsaved_source_and_never_includes_notes_or_writes_document(): void
    {
        $actor = $this->adminUser();
        $document = FormattedDocument::factory()->create(['notes' => 'NOTA SOLO INTERNA']);
        $before = $document->fresh()->getAttributes();
        $files = Document::count();
        $source = "# Contenido aún sin guardar\n\n:::page-break\n\nOtro texto";
        $this->mock(CorporatePdfRenderer::class)->shouldReceive('render')->once()->with($source)->andReturn('%PDF-sin-notas');
        $this->actingAsAdmin($actor)->call('POST', route('admin.pdf.preview'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $source)
            ->assertOk()->assertContent('%PDF-sin-notas');
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertSame($files, Document::count());
        $this->assertSame(0, AdminAuditEvent::where('entity_type', 'formatted_document')->count());
    }

    public function test_stored_html_stays_inert_in_editor_and_information_views(): void
    {
        $this->actingAsAdmin();
        $source = "</script><script>alert('private')</script>\n:::page-break\n[unsafe](javascript:alert(1))";
        $this->postJson(route('admin.pdf.store'), $this->payload(['body_markdown' => $source, 'title' => '<img src=x onerror=alert(1)>', 'notes' => '</textarea><script>bad()</script>']))->assertCreated();
        $document = FormattedDocument::sole();
        $this->assertSame($source, $document->body_markdown);
        foreach (['show', 'edit'] as $route) {
            $this->get(route('admin.pdf.'.$route, $document))->assertOk()->assertDontSee('<script>alert', false)
                ->assertDontSee('<img src=x', false)->assertDontSee('<script>bad()', false);
        }
    }

    public function test_transaction_rolls_back_and_sql_errors_do_not_log_markdown(): void
    {
        $this->actingAsAdmin();
        $this->mock(AdminAuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('SQL private Markdown'));
        Log::shouldReceive('warning')->once()->with('Draft persistence failed.', ['exception_type' => \RuntimeException::class]);
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertStatus(503)->assertDontSee('private Markdown');
        $this->assertDatabaseCount('formatted_documents', 0);
    }

    public function test_save_requires_csrf_and_repeated_unchanged_updates_do_not_create_audit_events(): void
    {
        $this->actingAsAdmin();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson(route('admin.pdf.store'), $this->payload())->assertStatus(419);
        $headers = ['X-CSRF-TOKEN' => session()->token()];
        $this->postJson(route('admin.pdf.store'), $this->payload(), $headers)->assertCreated();
        $document = FormattedDocument::sole();
        $this->putJson(route('admin.pdf.update', $document), $this->payload(), $headers)->assertOk();
        $this->assertSame(1, AdminAuditEvent::where('entity_type', 'formatted_document')->count());
        $this->assertNull($document->fresh()->updated_by);
    }
}
