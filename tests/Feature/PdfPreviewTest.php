<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Permission;
use App\Services\Pdf\BlockedAssetFetcher;
use App\Services\Pdf\CorporatePdfRenderer;
use App\Support\BasePermissions;
use Database\Seeders\PdfPreviewPermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mpdf\Exception\AssetFetchingException;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class PdfPreviewTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    public function test_both_routes_require_login_and_a_dedicated_permission(): void
    {
        $this->get(route('admin.pdf.index'))->assertRedirect(route('admin.login'));
        $this->preview('Texto')->assertRedirect(route('admin.login'));
        $this->seedAccessCatalog();
        $user = $this->userWithPermissions(['dashboard.view', 'documents.view', 'documents.upload']);
        $this->actingAs($user)->get(route('admin.pdf.index'))->assertForbidden();
        $this->preview('Texto')->assertForbidden();
        $this->get(route('admin.dashboard'))->assertDontSee('Documentos PDF');
    }

    public function test_editor_reuses_markdown_without_html_preview_and_exposes_the_menu(): void
    {
        $this->actingAsAdmin()->get(route('admin.pdf.create'))->assertOk()
            ->assertSee('Documentos PDF')->assertSee('data-markdown-editor', false)
            ->assertSee('data-pdf-frame', false)->assertSee('Actualizar vista previa')
            ->assertSee('Descargar PDF')->assertSee(':::page-break')
            ->assertDontSee('data-markdown-preview', false)->assertDontSee('Barbería Reyes');
    }

    public function test_permission_installation_is_additive_and_does_not_grant_other_roles_access(): void
    {
        $this->seedAccessCatalog();
        $operator = $this->userWithRole('operator');
        $admin = $this->adminUser();
        $this->seed(PdfPreviewPermissionSeeder::class);
        $this->seed(PdfPreviewPermissionSeeder::class);
        $this->assertFalse($operator->hasPermissionTo('pdf.preview'));
        $this->assertTrue($admin->hasPermissionTo('pdf.preview'));
        $this->assertDatabaseCount('permissions', count(BasePermissions::DEFINITIONS));
    }

    public function test_preview_requires_csrf(): void
    {
        $this->actingAsAdmin();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->preview('Texto')->assertStatus(419);
        $this->preview('Texto', ['HTTP_X_CSRF_TOKEN' => session()->token()])->assertOk();
    }

    public function test_impersonation_does_not_borrow_the_actors_pdf_permission(): void
    {
        $actor = $this->adminUser();
        $target = $this->userWithPermissions([]);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->get(route('admin.pdf.index'))->assertForbidden();
        $this->preview('Texto')->assertForbidden();
        $this->get(route('admin.profile.edit'))->assertDontSee('Documentos PDF');
    }

    public function test_impersonation_also_requires_the_real_actors_permission(): void
    {
        $actor = $this->adminUser();
        $target = $this->userWithPermissions(['pdf.preview']);
        $permission = Permission::query()->where('key', 'pdf.preview')->firstOrFail();
        $actor->roles()->firstOrFail()->permissions()->detach($permission);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->preview('Texto')->assertForbidden();
    }

    public function test_renderer_cleans_its_temporary_directory_after_a_failure(): void
    {
        $this->actingAsAdmin();
        $tempRoot = storage_path('app/private/pdf-preview');
        $previous = File::isDirectory($tempRoot) ? File::directories($tempRoot) : [];
        config(['pdf.presenter' => []]);
        $this->preview('Texto')->assertStatus(503);
        $this->assertSame($previous, File::directories($tempRoot));
    }

    public function test_preview_validates_bytes_utf8_plain_text_and_empty_input(): void
    {
        $this->actingAsAdmin();
        config(['pdf.preview_max_bytes' => 4]);
        $this->preview('éé')->assertOk();
        $this->preview('ééé')->assertStatus(413);
        $this->preview("\xFF")->assertStatus(422);
        $this->preview("a\0b")->assertStatus(422);
        $this->preview(" \r\n\t")->assertStatus(422);
        $this->postJson(route('admin.pdf.preview'), ['markdown' => 'abc'])->assertStatus(415);
    }

    public function test_raw_markdown_including_indentation_is_passed_unchanged(): void
    {
        $this->actingAsAdmin();
        $source = "    texto\r\n\r\n:::page-break\r\nFinal  \r\n";
        $this->mock(CorporatePdfRenderer::class)->shouldReceive('render')->once()->with($source)->andReturn('%PDF-1.4');
        $this->preview($source)->assertOk();
    }

    public function test_binary_preview_supports_manual_breaks_without_persisting_documents(): void
    {
        $this->actingAsAdmin();
        $this->withSession(['last_activity_recorded_at' => now()->timestamp]);
        $before = Document::count();
        $tempRoot = storage_path('app/private/pdf-preview');
        $previousDirs = File::isDirectory($tempRoot) ? File::directories($tempRoot) : [];
        DB::enableQueryLog();
        $response = $this->preview("# Primera\n\nEspañol: áéíóú ñ ü. $12,000.\n\n:::page-break\n\n## Segunda\n\n[Acerca](https://acerca.mx)");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $bytes = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringEndsWith('%%EOF', trim($bytes));
        $reader = new PdfReader(new PdfParser(StreamReader::createByString($bytes)));
        $this->assertSame(2, $reader->getPageCount());
        $this->assertSame($before, Document::count());
        $writes = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^\s*(insert|delete|update|replace)\b/i', $query['query'])
            && ! str_starts_with($query['query'], 'update "users" set "last_activity_at" ='));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($previousDirs, File::directories($tempRoot));
    }

    public function test_generation_errors_do_not_disclose_internal_details(): void
    {
        $this->actingAsAdmin();
        $this->mock(CorporatePdfRenderer::class)->shouldReceive('render')->once()
            ->andThrow(new \RuntimeException('private/path secret-token source-content'));
        $this->preview('Texto')->assertStatus(503)->assertJsonStructure(['message'])
            ->assertDontSee('private/path')->assertDontSee('secret-token')->assertDontSee('source-content');
    }

    public function test_assets_cannot_be_fetched_from_local_or_remote_paths(): void
    {
        foreach (['file:///private.txt', 'https://example.com/image.png', 'http://127.0.0.1/', 'C:\\private.txt', '../private.txt'] as $path) {
            try {
                (new BlockedAssetFetcher)->fetchDataFromPath($path);
                $this->fail('Unexpected asset access');
            } catch (AssetFetchingException $exception) {
                $this->assertSame('PDF asset resolution is disabled.', $exception->getMessage());
            }
        }
    }

    public function test_markdown_cannot_supply_active_html_css_or_images(): void
    {
        $this->actingAsAdmin();
        $source = "<script>alert(1)</script>\n\n<img src=\"file:///private.txt\">\n\n<style>@import url(http://127.0.0.1/);</style>\n\n![no](https://example.com/no.png)\n\n[local](file:///private.txt) [js](javascript:alert(1)) [web](https://example.com)";
        $bytes = $this->preview($source)->assertOk()->getContent();
        $this->assertStringNotContainsString('/JavaScript', $bytes);
        $this->assertStringNotContainsString('/Launch', $bytes);
        $this->assertStringNotContainsString('/URI (file:', $bytes);
        $this->assertStringContainsString('/URI (https://example.com)', $bytes);
    }

    public function test_requests_are_rate_limited(): void
    {
        $this->actingAsAdmin();
        $this->mock(CorporatePdfRenderer::class)->shouldReceive('render')->times(10)->andReturn('%PDF-1.4');
        for ($i = 0; $i < 10; $i++) {
            $this->preview('Texto')->assertOk();
        }
        $this->preview('Texto')->assertStatus(429);
    }

    private function preview(string $source, array $headers = [])
    {
        return $this->call('POST', route('admin.pdf.preview'), [], [], [], $headers + ['CONTENT_TYPE' => 'text/plain'], $source);
    }
}
